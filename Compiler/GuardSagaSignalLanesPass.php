<?php

declare(strict_types=1);

namespace Storm\Symfony\Compiler;

use LogicException;
use Override;
use ReflectionClass;
use Storm\Saga\Attributes\WaitFor;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The signal-lane topology gate: refuses, at compile, a lane layout that would split the
 * alternatives of ONE wait across lanes. A wait's `lane:` declares where THIS wait's signals ride,
 * for all its alternatives together; what this pass catches is the MERGE silently undoing that
 * declaration: the effective level of a class is the highest declared anywhere, so another workflow
 * pulling one class of a pair upward, or leaving one class unlaned while a sibling is laned, makes
 * the declaration lie about half its events. A split pair also degrades delivery: the engine
 * survives it, an early alternative is redelivered until the saga arrives at the wait, but the
 * inter-lane latency gap turns those redeliveries into a systematic tax, and the wait's deadlines
 * are tuned for ONE arrival profile, not two. Refusal is not bypassable: re-align the lanes, or
 * split the wait knowingly.
 *
 * Reads `%storm.saga.signal_lanes%`, the effective map the extract pass compiled, and re-reflects
 * each wait SHALLOWLY, the extract pass's constraint. Runs after the extract pass and before the
 * router bind.
 *
 * A wait's alias tokens are resolved through the same map the extract pass used, never skipped: that
 * pass lanes an alias under the CLASS it resolves to, so a wait mixing a class and an alias is as
 * splittable as any other, and a guard that looked at the class alone would answer for half a wait.
 *
 * @see ExtractSagaRoutingEventsPass compiles the effective class-to-level map this pass checks against
 * @see \Storm\Saga\Attributes\WaitFor the per-wait lane declaration
 */
final class GuardSagaSignalLanesPass implements CompilerPassInterface
{
    /**
     * {@inheritDoc}
     *
     * @throws LogicException when the highest-wins merge splits the alternatives of one wait across
     *                        different signal lanes
     */
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        /** @var array<class-string, int> $effective */
        $effective = $container->getParameter('storm.saga.signal_lanes');
        if ($effective === []) {
            return; // no wait declares a lane, so a single signal lane, nothing to split
        }

        $aliases = ExtractSagaRoutingEventsPass::aliasMap($container);

        foreach ($container->findTaggedServiceIds('storm.saga.workflow') as $id => $tags) {
            $class = $container->getDefinition($id)->getClass();
            if ($class === null || ! class_exists($class)) {
                continue;
            }

            foreach (new ReflectionClass($class)->getAttributes(WaitFor::class) as $attribute) {
                $this->guardWait($class, $attribute->newInstance(), $effective, $aliases);
            }
        }
    }

    /**
     * Every event of one wait must resolve to the SAME effective level, null meaning the default
     * signal lane. An alias is looked up as the class it names, since that is the key the effective
     * map carries; a token naming neither is another pass's refusal and is passed over here.
     *
     * @param  array<class-string, int>  $effective
     * @param  array<string, class-string>  $aliases
     *
     * @throws LogicException when the merge splits this wait's alternatives across lanes
     */
    private function guardWait(string $workflowClass, WaitFor $waitFor, array $effective, array $aliases): void
    {
        $levels = [];
        $distinct = [];
        foreach ($waitFor->events as $eventClassOrAlias) {
            $event = class_exists($eventClassOrAlias) || interface_exists($eventClassOrAlias)
                ? $eventClassOrAlias
                : $aliases[$eventClassOrAlias] ?? null;

            if ($event !== null) {
                $levels[$event] = $effective[$event] ?? null;
                // strict distinctness via array keys: level 0 and the default lane are DIFFERENT
                // lanes, which a loose comparison would collapse
                $distinct[$levels[$event] ?? 'default'] = true;
            }
        }

        if (count($distinct) <= 1) {
            return; // zero or one class-event, or all on the same effective lane
        }

        throw new LogicException(sprintf(
            'Signal-lane topology refused: the alternatives of wait "%s" on %s ride DIFFERENT lanes after the'
            .' highest-wins merge (%s): the wait declares ONE arrival profile and the merge silently gave it'
            .' two, taxing the split alternative with systematic redeliveries. Align the lane of every event'
            .' of this wait: declare lane: on the wait, or reconcile the other workflow pulling a class upward.',
            $waitFor->state,
            $workflowClass,
            implode(', ', array_map(
                static fn (string $type, ?int $level): string => sprintf('%s => %s', $type, $level ?? 'default'),
                array_keys($levels),
                $levels,
            )),
        ));
    }
}
