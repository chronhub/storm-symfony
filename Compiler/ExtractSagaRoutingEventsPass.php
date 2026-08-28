<?php

declare(strict_types=1);

namespace Storm\Symfony\Compiler;

use LogicException;
use Override;
use ReflectionClass;
use Storm\Message\EventType;
use Storm\Saga\Attributes\Prioritized;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;
use Storm\Saga\Build\WorkflowBuilder;
use Storm\Symfony\SagaOutcomeRouter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compile-time shallow extraction of what the saga wiring needs from `#[WaitFor]` and `#[Workflow]`,
 * published as container parameters for the companion passes.
 *
 * Four parameters, each with its own merge rule:
 *
 * - `storm.saga.routing_events`, the union of the event classes any `#[WaitFor]` in any `#[Workflow]`
 *   subscribes to, read by `BindSagaOutcomeRouterPass` so `SagaOutcomeRouter` subscribes to those
 *   classes rather than to every event on the bus.
 *
 * - `storm.saga.signal_lanes`, the class-to-level map of the waits that DECLARE a `lane:` ordinal.
 *   When the same event class is awaited at different levels, across waits or across workflows, the
 *   HIGHEST level wins, finish-over-start; the companion guard pass then re-checks that merge against
 *   each single wait, whose alternatives must never end up split across lanes. A class no wait gives a
 *   lane to is absent from the map: it rides the default signal lane.
 *
 * - `storm.saga.correlate_by`, the class-to-payload-field map of the waits that DECLARE
 *   `correlateBy:`, the external-event waits: the router extracts the correlation key from that field
 *   of the delivered event instead of the ambient correlation. Two waits declaring DIFFERENT fields
 *   for one class cannot merge, two keys having no `max()`; this pass records declarations as it finds
 *   them and `GuardSagaCorrelateByPass` refuses the divergence.
 *
 * - `storm.saga.workflow_priorities`, the name-to-level map of the `#[Workflow]` classes that pair a
 *   class-level `#[Prioritized]`: the workflow's own default for every command it issues whose class
 *   declares no `#[Prioritized]` of its own. The key is the workflow name, which IS the outbox row's
 *   `workflow_type`, the resolver's lookup key. Divergence is refused HERE, not in a companion guard:
 *   co-registered versions of one name disagreeing on the level need no config context to be judged,
 *   and `max()` would be wrong; a version that DOWNGRADES its priority would be silently overruled by
 *   the draining old one.
 *
 * Shallow on purpose: it reads `#[WaitFor]` via reflection on the workflow class, with no
 * `WorkflowBuilder` build, which would need the activity locator whose services aren't instantiable
 * at compile time. A stable string alias in `WaitFor::events` is RESOLVED to its `#[EventType]`
 * class through the compiled scan product `storm.event_classes`, published at a higher pass
 * priority, and enters the union as that class; routing keys off the delivered message class, so
 * the alias must become a class here or the router never subscribes to it. An unresolvable token
 * is refused LOUD at compile: the engine's `WaitState` matches aliases happily, so a silently
 * dropped one leaves a saga waiting for an outcome no handler will ever deliver; a declaration the
 * wiring cannot honor must fail the build, never the workflow's liveness.
 *
 * Full workflow validation, such as a malformed definition or illegal timeout transitions, stays
 * runtime and first-use via the existing `WorkflowBuilder` lazy build.
 *
 * @see BindSagaOutcomeRouterPass
 * @see GuardSagaSignalLanesPass
 * @see SagaOutcomeRouter
 * @see \Storm\Saga\Build\WorkflowBuilder
 */
final class ExtractSagaRoutingEventsPass implements CompilerPassInterface
{
    /**
     * {@inheritDoc}
     *
     * @throws LogicException when a `#[WaitFor]` names a token that is neither a class nor a
     *                        resolvable alias, two scanned `#[EventType]` classes claim one alias,
     *                        a class-level `#[Prioritized]` has no `#[Workflow]` to default for, or
     *                        co-registered versions of one workflow name declare divergent levels
     */
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        $events = [];
        $lanes = [];
        $correlateBy = [];
        $priorities = [];
        $aliasMap = null; // lazy: built from the scan product only when an alias token appears

        foreach ($container->findTaggedServiceIds('storm.saga.workflow') as $id => $tags) {
            $class = $container->getDefinition($id)->getClass();
            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            foreach ($reflection->getAttributes(WaitFor::class) as $attribute) {
                $waitFor = $attribute->newInstance();

                foreach ($waitFor->events as $eventClassOrAlias) {
                    $event = $eventClassOrAlias;

                    if (! class_exists($event) && ! interface_exists($event)) {
                        $aliasMap ??= self::aliasMap($container);
                        $event = $aliasMap[$event] ?? throw new LogicException(sprintf(
                            'Workflow "%s" declares #[WaitFor] on "%s", which is neither a class nor a'
                            .' stable alias any scanned #[EventType] class carries: the router would never'
                            .' subscribe to it and the saga would wait for an outcome no handler delivers.'
                            .' Fix the token, or add the class to `storm.event_paths` so the scan sees it.',
                            $class,
                            $eventClassOrAlias,
                        ));
                    }

                    $events[$event] = $event; // set semantics: only the keys are published below

                    if ($waitFor->lane !== null) {
                        $lanes[$event] = max($waitFor->lane, $lanes[$event] ?? $waitFor->lane);
                    }

                    if ($waitFor->correlateBy !== null) {
                        // as-found; the guard pass refuses two waits declaring different fields
                        $correlateBy[$event] = $waitFor->correlateBy;
                    }
                }
            }

            $prioritized = ($reflection->getAttributes(Prioritized::class)[0] ?? null)?->newInstance();
            if ($prioritized === null) {
                continue;
            }

            $name = ($reflection->getAttributes(Workflow::class)[0] ?? null)?->newInstance()->name;
            if ($name === null) {
                throw new LogicException(sprintf(
                    'A class-level #[Prioritized] on "%s" has no #[Workflow] to default for: the service is'
                    .' tagged as a saga workflow but declares no name — the declaration would be silently inert.',
                    $class,
                ));
            }

            if (($priorities[$name] ?? $prioritized->level) !== $prioritized->level) {
                throw new LogicException(sprintf(
                    'Workflow "%s" declares divergent #[Prioritized] levels across its co-registered versions'
                    .' (%d vs %d on "%s"): align the classes — the losing declaration would stay silently dead'
                    .' while the old version\'s instances drain.',
                    $name,
                    $priorities[$name],
                    $prioritized->level,
                    $class,
                ));
            }

            $priorities[$name] = $prioritized->level;
        }

        $container->setParameter('storm.saga.routing_events', array_keys($events));
        $container->setParameter('storm.saga.signal_lanes', $lanes);
        $container->setParameter('storm.saga.correlate_by', $correlateBy);
        $container->setParameter('storm.saga.workflow_priorities', $priorities);
    }

    /**
     * The alias-to-class view of the compiled scan product. CURRENT aliases only: the engine matches
     * a delivered event by `aliasFor()`, its current alias, so a `#[WaitFor]` naming a former
     * spelling, one listed in `replaces`, would never match at runtime either; it must be refused,
     * not resolved. Two classes claiming one alias cannot be resolved and are refused here; the
     * mapper's own boot-time story for collisions is separate, this pass just cannot pick a side.
     *
     * Shared with the lane guard rather than reimplemented there: the guard runs one priority later
     * over the map this pass compiled, so the two must agree on what a token resolves to, and two
     * copies of that rule would be two places to fix.
     *
     * @return array<string, class-string>
     *
     * @throws LogicException when two scanned classes claim one alias
     */
    public static function aliasMap(ContainerBuilder $container): array
    {
        // @phpstan-ignore ternary.alwaysTrue (a bare container has no parameter; only the phpdoc claims it always exists)
        $classes = $container->hasParameter('storm.event_classes') ? $container->getParameter('storm.event_classes') : [];
        $map = [];

        foreach ((array) $classes as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            $eventType = new ReflectionClass($class)->getAttributes(EventType::class)[0] ?? null;
            if ($eventType === null) {
                continue;
            }

            $alias = $eventType->newInstance()->alias;

            if (isset($map[$alias])) {
                throw new LogicException(sprintf(
                    'Two scanned #[EventType] classes claim the alias "%s" ("%s" and "%s") — a #[WaitFor]'
                    .' on that alias cannot be resolved to one subscription; align the aliases.',
                    $alias,
                    $map[$alias],
                    $class,
                ));
            }

            $map[$alias] = $class;
        }

        return $map;
    }
}
