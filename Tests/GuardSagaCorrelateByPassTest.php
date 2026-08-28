<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Symfony\Compiler\ExtractSagaRoutingEventsPass;
use Storm\Symfony\Compiler\GuardSagaCorrelateByPass;
use Storm\Symfony\Tests\Fixture\AliasCorrelatingWorkflow;
use Storm\Symfony\Tests\Fixture\AmbientBehindUnknownEventWorkflow;
use Storm\Symfony\Tests\Fixture\AmbientPairWorkflow;
use Storm\Symfony\Tests\Fixture\CaptureDone;
use Storm\Symfony\Tests\Fixture\CorrelatingWorkflow;
use Storm\Symfony\Tests\Fixture\DivergentCorrelatingWorkflow;
use Storm\Symfony\Tests\Fixture\EmptyFieldCorrelatingWorkflow;
use Storm\Symfony\Tests\Fixture\InterfaceCorrelatingWorkflow;
use Storm\Symfony\Tests\Fixture\MixedWaitsWorkflow;
use Storm\Symfony\Tests\Fixture\PayloadlessCorrelatingWorkflow;
use Storm\Symfony\Tests\Fixture\RoutingWorkflow;
use Storm\Symfony\Tests\Fixture\UnlanedPairWorkflow;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * The guard runs AFTER the extract pass, mirroring the lane guard's boot order; each case processes
 * both on the same container. Unlike the lane guard, this pass reads no compiled parameter: it
 * re-reflects the tagged workflows itself, so the extract run here only keeps the cases boot-shaped.
 */
final class GuardSagaCorrelateByPassTest extends TestCase
{
    #[Test]
    public function passes_when_no_wait_declares_a_correlate_by(): void
    {
        $container = $this->containerWith(RoutingWorkflow::class, UnlanedPairWorkflow::class);

        $this->expectNotToPerformAssertions();
        $this->runPasses($container);
    }

    #[Test]
    public function passes_a_declared_external_wait_on_a_payload_event(): void
    {
        $container = $this->containerWith(CorrelatingWorkflow::class, RoutingWorkflow::class);

        $this->expectNotToPerformAssertions();
        $this->runPasses($container);
    }

    #[Test]
    public function refuses_two_workflows_declaring_different_fields_for_one_class(): void
    {
        // order_id versus shipment_id on the same event class: two correlation keys cannot merge,
        // and last-wins would silently route one workflow's outcomes by the other's key.
        $container = $this->containerWith(CorrelatingWorkflow::class, DivergentCorrelatingWorkflow::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('already declared');

        $this->runPasses($container);
    }

    #[Test]
    public function refuses_a_correlate_by_wait_declaring_an_empty_field(): void
    {
        // an empty string is not "no declaration": it opts the wait INTO the declared path and then names
        // no field to read the correlation key from, so every delivery would miss and the saga would hang
        // on a wait that can never match. Refused at compile, the only place it is still cheap.
        $container = $this->containerWith(EmptyFieldCorrelatingWorkflow::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('EMPTY');
        $this->runPasses($container);
    }

    #[Test]
    public function refuses_a_correlate_by_wait_holding_a_string_alias(): void
    {
        // aliases are skipped on the whole generic routing path: the declaration would be dead and
        // the outcome silently lost. The token RESOLVES here, so the extract pass is satisfied and
        // this guard is the one that must speak; an unresolvable token is refused a pass earlier,
        // and asserting on the token alone would let that other refusal answer for this one
        $container = $this->containerWith(AliasCorrelatingWorkflow::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('a stable string alias');

        $this->runPasses($container);
    }

    #[Test]
    public function refuses_a_correlate_by_wait_on_a_payloadless_event(): void
    {
        // the router reads the declared field through toPayload(); a class without the wire
        // contract cannot expose it.
        $container = $this->containerWith(PayloadlessCorrelatingWorkflow::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('SerializablePayload');

        $this->runPasses($container);
    }

    #[Test]
    public function refuses_a_correlate_by_wait_naming_an_interface(): void
    {
        // the interface satisfies the payload check, but the router resolves the map by the
        // delivered event's exact concrete class: the entry would never match, a dead declaration.
        $container = $this->containerWith(InterfaceCorrelatingWorkflow::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('INTERFACE');

        $this->runPasses($container);
    }

    #[Test]
    public function refuses_an_ambient_wait_on_a_declared_class(): void
    {
        // declared routing is total per class: the ambient waiter's outcomes would silently never
        // arrive, so the mixed topology refuses instead of starving one workflow.
        $container = $this->containerWith(CorrelatingWorkflow::class, AmbientPairWorkflow::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('AMBIENTLY');

        $this->runPasses($container);
    }

    #[Test]
    public function refuses_an_ambient_wait_sitting_behind_a_declaring_one_in_the_same_workflow(): void
    {
        // the walk skips the waits that declare a field, and it must keep walking: ending on the
        // first declaring wait would leave the ambient wait behind it unexamined, and one workflow
        // would starve a wait of its own outcomes
        $container = $this->containerWith(MixedWaitsWorkflow::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('AMBIENTLY');

        $this->runPasses($container);
    }

    #[Test]
    public function refuses_a_declared_class_listed_behind_an_undeclared_one_on_the_same_wait(): void
    {
        // one wait may await several classes, and the walk skips those nobody declares a field for;
        // ending on the first of them would let the declared class behind it through, the exact
        // ambient topology the guard exists to refuse
        $container = $this->containerWith(CorrelatingWorkflow::class, AmbientBehindUnknownEventWorkflow::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('AMBIENTLY');

        $this->runPasses($container);
    }

    /**
     * @param  class-string  ...$workflows
     */
    private function containerWith(string ...$workflows): ContainerBuilder
    {
        $container = new ContainerBuilder;
        // the scan product RoutingWorkflow's alias token ('capture.done') resolves against
        $container->setParameter('storm.event_classes', [CaptureDone::class]);
        foreach ($workflows as $workflow) {
            $container->setDefinition($workflow, new Definition($workflow))->addTag('storm.saga.workflow');
        }

        return $container;
    }

    private function runPasses(ContainerBuilder $container): void
    {
        new ExtractSagaRoutingEventsPass()->process($container);
        new GuardSagaCorrelateByPass()->process($container);
    }
}
