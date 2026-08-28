<?php

declare(strict_types=1);

namespace Storm\Symfony\Compiler;

use LogicException;
use Override;
use ReflectionClass;
use Storm\Contracts\Message\SerializablePayload;
use Storm\Saga\Attributes\WaitFor;
use Storm\Symfony\SagaOutcomeRouter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The correlateBy declaration gate: refuses, at compile, an external-event declaration the router
 * could not honor at delivery. The compiled map is keyed by event class, one payload field per
 * class, so an incoherent declaration would not fail where it was written; it would silently
 * misroute or dead-letter someone else's outcome. Refusal is not bypassable.
 *
 * The refusals:
 *
 * - Two waits declaring DIFFERENT fields for one event class, across waits or workflows: two
 *   correlation keys cannot merge, there is no `max()` between fields, and last-wins would silently
 *   route one workflow's outcomes by the other's key;
 *
 * - A wait awaiting a declared class WITHOUT declaring the field itself: declared routing is total
 *   per class, the router extracts the field for EVERY delivery, so the undeclared wait's ambient
 *   outcomes would silently never arrive;
 *
 * - A `correlateBy:` wait whose events include a stable string alias: every check below asks a
 *   question of the event CLASS, its payload contract, its concreteness, the field another wait
 *   already claimed for it, and an alias token answers none of them. The extract pass resolves the
 *   alias for the subscription, so a blessed declaration would be one this guard never inspected;
 *   naming the class is what makes the declaration checkable;
 *
 * - A `correlateBy:` wait naming an INTERFACE: the router resolves the map by the delivered
 *   event's exact concrete class, so an interface entry never matches and the declaration is dead
 *   the same way; declare the concrete event classes;
 *
 * - An event class that does not implement `SerializablePayload`: the router reads the declared
 *   field through `toPayload()`, the durable wire contract, which such a class does not expose.
 *
 * The field itself stays runtime-checked: the payload is schemaless, so a compile pass cannot
 * prove a field exists; a field missing or empty at delivery fails loud in the router instead.
 *
 * Reads nothing from the container but the tagged workflows, re-reflected SHALLOWLY like the
 * extract pass. Runs after the extract pass and before the router bind.
 *
 * @see ExtractSagaRoutingEventsPass compiles the class-to-field map this pass validates
 * @see \Storm\Saga\Attributes\WaitFor the per-wait correlateBy declaration
 * @see SagaOutcomeRouter reads the declared field at delivery and fails loud on a missing one
 */
final class GuardSagaCorrelateByPass implements CompilerPassInterface
{
    /**
     * {@inheritDoc}
     *
     * @throws LogicException when a `correlateBy:` declaration is empty, names an alias or an
     *                        interface, targets a payload-less event class, collides with another
     *                        wait's field for the same class, or a wait awaits a declared class
     *                        ambiently
     */
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        /** @var array<string, list<WaitFor>> $workflows */
        $workflows = [];
        foreach ($container->findTaggedServiceIds('storm.saga.workflow') as $id => $tags) {
            $class = $container->getDefinition($id)->getClass();
            if ($class === null || ! class_exists($class)) {
                continue;
            }

            foreach (new ReflectionClass($class)->getAttributes(WaitFor::class) as $attribute) {
                $workflows[$class][] = $attribute->newInstance();
            }
        }

        /** @var array<class-string, array{field: string, where: string}> $declared */
        $declared = [];
        foreach ($workflows as $workflowClass => $waits) {
            foreach ($waits as $waitFor) {
                if ($waitFor->correlateBy !== null) {
                    $this->guardWait($workflowClass, $waitFor, $declared);
                }
            }
        }

        // declared routing is total per class, so every OTHER wait on a declared class must say
        // the same thing: an ambient wait would silently never receive its outcomes
        foreach ($workflows as $workflowClass => $waits) {
            foreach ($waits as $waitFor) {
                if ($waitFor->correlateBy !== null) {
                    continue;
                }

                foreach ($waitFor->events as $eventClassOrAlias) {
                    $known = $declared[$eventClassOrAlias] ?? null;
                    if ($known === null) {
                        continue;
                    }

                    throw new LogicException(sprintf(
                        'correlateBy topology refused: wait "%s" on %s awaits %s AMBIENTLY while %s declares'
                        .' field "%s" for it. Declared routing is total per class, the router extracts that'
                        .' field for every delivery, so this wait\'s ambient outcomes would silently never'
                        .' arrive. Declare the same correlateBy on this wait, or split the event classes.',
                        $waitFor->state,
                        $workflowClass,
                        $eventClassOrAlias,
                        $known['where'],
                        $known['field'],
                    ));
                }
            }
        }
    }

    /**
     * @param  array<class-string, array{field: string, where: string}>  $declared  accumulated field per class, mutated as waits are walked
     *
     * @throws LogicException when the declaration is empty, names an alias or an interface, targets a payload-less event class, or collides with another wait's field for the same class
     */
    private function guardWait(string $workflowClass, WaitFor $waitFor, array &$declared): void
    {
        $field = $waitFor->correlateBy;
        // Unreachable: the sole caller only enters here under `correlateBy !== null`. Re-narrowed anyway so
        // the stored map is provably string, which is a type statement, not a branch to exercise.
        // @codeCoverageIgnoreStart
        if ($field === null) {
            return;
        }
        // @codeCoverageIgnoreEnd

        $where = sprintf('wait "%s" on %s', $waitFor->state, $workflowClass);

        if ($field === '') {
            throw new LogicException(sprintf(
                'correlateBy declaration refused: %s declares an EMPTY payload field. Name the top-level'
                .' wire payload field that carries the saga correlation key.',
                $where,
            ));
        }

        foreach ($waitFor->events as $eventClassOrAlias) {
            if (! class_exists($eventClassOrAlias) && ! interface_exists($eventClassOrAlias)) {
                throw new LogicException(sprintf(
                    'correlateBy declaration refused: %s awaits "%s", a stable string alias. Every check this'
                    .' guard makes asks a question of the event class, and an alias answers none of them, so'
                    .' the declaration would ship uninspected. Declare the event CLASS on an external-event wait.',
                    $where,
                    $eventClassOrAlias,
                ));
            }

            if (interface_exists($eventClassOrAlias)) {
                throw new LogicException(sprintf(
                    'correlateBy declaration refused: %s awaits the INTERFACE %s. The router resolves the'
                    .' declared field by the delivered event\'s exact concrete class, so an interface entry'
                    .' never matches and the declaration would be dead, the outcome silently lost. Declare'
                    .' the concrete event classes.',
                    $where,
                    $eventClassOrAlias,
                ));
            }

            if (! is_subclass_of($eventClassOrAlias, SerializablePayload::class)) {
                throw new LogicException(sprintf(
                    'correlateBy declaration refused: %s awaits %s, which does not implement %s. The router'
                    .' reads the declared field through toPayload(), the durable wire contract.',
                    $where,
                    $eventClassOrAlias,
                    SerializablePayload::class,
                ));
            }

            $known = $declared[$eventClassOrAlias] ?? null;
            if ($known !== null && $known['field'] !== $field) {
                throw new LogicException(sprintf(
                    'correlateBy declaration refused: %s declares field "%s" for %s, but %s already declared'
                    .' field "%s". Two correlation keys cannot merge for one event class; align the field, or'
                    .' the router would route one workflow\'s outcomes by the other\'s key.',
                    $where,
                    $field,
                    $eventClassOrAlias,
                    $known['where'],
                    $known['field'],
                ));
            }

            $declared[$eventClassOrAlias] = ['field' => $field, 'where' => $where];
        }
    }
}
