<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Symfony\Compiler\ExtractSagaRoutingEventsPass;
use Storm\Symfony\Tests\AliasCollision\CaptureDoneRival;
use Storm\Symfony\Tests\Fixture\CaptureDone;
use Storm\Symfony\Tests\Fixture\CorrelatedEvent;
use Storm\Symfony\Tests\Fixture\CorrelatingWorkflow;
use Storm\Symfony\Tests\Fixture\LanedEvent;
use Storm\Symfony\Tests\Fixture\LanedPairWorkflow;
use Storm\Symfony\Tests\Fixture\PrioritizedOrphan;
use Storm\Symfony\Tests\Fixture\PrioritizedWorkflow;
use Storm\Symfony\Tests\Fixture\PrioritizedWorkflowVersionTwo;
use Storm\Symfony\Tests\Fixture\PullingWorkflow;
use Storm\Symfony\Tests\Fixture\RoutedEvent;
use Storm\Symfony\Tests\Fixture\RoutingWorkflow;
use Storm\Symfony\Tests\Fixture\ScannerFixtureEvent;
use Storm\Symfony\Tests\RetiredAlias\RenamedCaptureDone;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class ExtractSagaRoutingEventsPassTest extends TestCase
{
    #[Test]
    public function extracts_fqc_n_event_classes_and_resolves_aliases_from_a_workflows_wait_for(): void
    {
        // RoutingWorkflow declares WaitFor with [RoutedEvent::class, 'capture.done']; the alias
        // RESOLVES to its scanned #[EventType] class; silently skipped, it would leave the saga
        // awaiting an outcome the router never subscribed to
        $container = $this->containerWithScan([CaptureDone::class]);
        $container->setDefinition(RoutingWorkflow::class, new Definition(RoutingWorkflow::class))->addTag('storm.saga.workflow');

        new ExtractSagaRoutingEventsPass()->process($container);

        $this->assertSame([RoutedEvent::class, CaptureDone::class], $container->getParameter('storm.saga.routing_events'));
    }

    #[Test]
    public function deduplicates_event_classes_across_multiple_workflows(): void
    {
        // both workflows declare WaitFor with RoutedEvent, so the union has one entry per class.
        $container = $this->containerWithScan([CaptureDone::class]);
        $container->setDefinition('wf-1', new Definition(RoutingWorkflow::class))->addTag('storm.saga.workflow');
        $container->setDefinition('wf-2', new Definition(RoutingWorkflow::class))->addTag('storm.saga.workflow');

        new ExtractSagaRoutingEventsPass()->process($container);

        $this->assertSame([RoutedEvent::class, CaptureDone::class], $container->getParameter('storm.saga.routing_events'));
    }

    #[Test]
    public function refuses_a_wait_for_token_no_scanned_event_type_carries(): void
    {
        // neither a class nor a known alias: the engine would match it, the router would never
        // subscribe. The declaration the wiring cannot honour fails the BUILD, not the liveness
        $container = new ContainerBuilder; // no scan product at all: every alias is unknown
        $container->setDefinition(RoutingWorkflow::class, new Definition(RoutingWorkflow::class))->addTag('storm.saga.workflow');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/capture\.done.*neither a class nor a stable alias/s');

        new ExtractSagaRoutingEventsPass()->process($container);
    }

    #[Test]
    #[Group('adversarial')]
    public function refuses_a_wait_for_naming_a_spelling_the_scan_only_carries_as_a_former_one(): void
    {
        // the alias map is CURRENT aliases only, and this is the half of that rule nobody could see:
        // the engine matches a delivered event by its current alias, so a wait on a retired one is
        // resolvable through the mapper and unreachable at runtime, the worst of the two. Widening
        // the map to `replaces` would subscribe to a spelling nothing ever arrives under, silently.
        $container = $this->containerWithScan([RenamedCaptureDone::class]);
        $container->setDefinition(RoutingWorkflow::class, new Definition(RoutingWorkflow::class))->addTag('storm.saga.workflow');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/capture\.done.*neither a class nor a stable alias/s');

        new ExtractSagaRoutingEventsPass()->process($container);
    }

    #[Test]
    public function refuses_an_alias_two_scanned_classes_claim(): void
    {
        $container = $this->containerWithScan([CaptureDone::class, CaptureDoneRival::class]);
        $container->setDefinition(RoutingWorkflow::class, new Definition(RoutingWorkflow::class))->addTag('storm.saga.workflow');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Two scanned .*capture\.done/s');

        new ExtractSagaRoutingEventsPass()->process($container);
    }

    /**
     * @param  list<class-string>  $eventClasses
     */
    private function containerWithScan(array $eventClasses): ContainerBuilder
    {
        // the shape RegisterEventTypesPass publishes at its higher priority: the scan product
        $container = new ContainerBuilder;
        $container->setParameter('storm.event_classes', $eventClasses);

        return $container;
    }

    #[Test]
    public function sets_an_empty_parameter_when_no_workflow_is_tagged(): void
    {
        $container = new ContainerBuilder;

        new ExtractSagaRoutingEventsPass()->process($container);

        $this->assertSame([], $container->getParameter('storm.saga.routing_events'));
    }

    #[Test]
    public function compiles_the_signal_lane_map_from_lane_declarations(): void
    {
        // LanedPairWorkflow declares WaitFor([RoutedEvent, LanedEvent], lane: 30): both classes at 30.
        $container = new ContainerBuilder;
        $container->setDefinition(LanedPairWorkflow::class, new Definition(LanedPairWorkflow::class))->addTag('storm.saga.workflow');

        new ExtractSagaRoutingEventsPass()->process($container);

        $this->assertSame(
            [RoutedEvent::class => 30, LanedEvent::class => 30],
            $container->getParameter('storm.saga.signal_lanes'),
        );
    }

    #[Test]
    public function merges_the_same_class_across_workflows_at_the_highest_level(): void
    {
        // PullingWorkflow awaits RoutedEvent at 50: the merge pulls it above LanedPairWorkflow's 30.
        $container = new ContainerBuilder;
        $container->setDefinition(LanedPairWorkflow::class, new Definition(LanedPairWorkflow::class))->addTag('storm.saga.workflow');
        $container->setDefinition(PullingWorkflow::class, new Definition(PullingWorkflow::class))->addTag('storm.saga.workflow');

        new ExtractSagaRoutingEventsPass()->process($container);

        $this->assertSame(
            [RoutedEvent::class => 50, LanedEvent::class => 30],
            $container->getParameter('storm.saga.signal_lanes'),
        );
    }

    #[Test]
    public function the_highest_level_wins_even_when_it_is_seen_first(): void
    {
        // The mirror of the merge above, and the half that proves it is a MAX rather than a
        // last-one-wins: here the 50 is registered before the 30. Registration order belongs to the
        // container, so a merge that kept whichever came last would demote an awaited event to the
        // slower lane on nothing but a service-definition reshuffle.
        $container = new ContainerBuilder;
        $container->setDefinition(PullingWorkflow::class, new Definition(PullingWorkflow::class))->addTag('storm.saga.workflow');
        $container->setDefinition(LanedPairWorkflow::class, new Definition(LanedPairWorkflow::class))->addTag('storm.saga.workflow');

        new ExtractSagaRoutingEventsPass()->process($container);

        $this->assertSame(
            [RoutedEvent::class => 50, LanedEvent::class => 30],
            $container->getParameter('storm.saga.signal_lanes'),
        );
    }

    #[Test]
    public function the_alias_map_carries_every_scanned_class_not_the_first(): void
    {
        // The token this workflow waits on belongs to the SECOND scanned class. A map built from
        // the scan but truncated anywhere still resolves the first alias, so a suite that only ever
        // scans one class cannot tell a complete map from a one-entry one, and the failure it hides
        // is a saga waiting on an outcome the router never subscribed to.
        $container = $this->containerWithScan([ScannerFixtureEvent::class, CaptureDone::class]);
        $container->setDefinition(RoutingWorkflow::class, new Definition(RoutingWorkflow::class))->addTag('storm.saga.workflow');

        new ExtractSagaRoutingEventsPass()->process($container);

        $this->assertSame([RoutedEvent::class, CaptureDone::class], $container->getParameter('storm.saga.routing_events'));
    }

    #[Test]
    public function leaves_unlaned_classes_out_of_the_lane_map(): void
    {
        // RoutingWorkflow declares no lane: the class joins the union, the lane map stays empty;
        // an unlaned awaited event rides the default signal lane.
        $container = $this->containerWithScan([CaptureDone::class]);
        $container->setDefinition(RoutingWorkflow::class, new Definition(RoutingWorkflow::class))->addTag('storm.saga.workflow');

        new ExtractSagaRoutingEventsPass()->process($container);

        $this->assertSame([RoutedEvent::class, CaptureDone::class], $container->getParameter('storm.saga.routing_events'));
        $this->assertSame([], $container->getParameter('storm.saga.signal_lanes'));
    }

    #[Test]
    public function compiles_the_correlate_by_map_from_external_wait_declarations(): void
    {
        // CorrelatingWorkflow declares WaitFor(CorrelatedEvent, correlateBy: 'order_id'): the class
        // routes by that payload field; undeclared classes stay out of the map, on the ambient path.
        $container = $this->containerWithScan([CaptureDone::class]);
        $container->setDefinition(CorrelatingWorkflow::class, new Definition(CorrelatingWorkflow::class))->addTag('storm.saga.workflow');
        $container->setDefinition(RoutingWorkflow::class, new Definition(RoutingWorkflow::class))->addTag('storm.saga.workflow');

        new ExtractSagaRoutingEventsPass()->process($container);

        $this->assertSame(
            [CorrelatedEvent::class => 'order_id'],
            $container->getParameter('storm.saga.correlate_by'),
        );
    }

    #[Test]
    public function leaves_the_correlate_by_map_empty_without_declarations(): void
    {
        // ambient-correlation routing only: the map compiles empty, the router keeps today's path.
        $container = $this->containerWithScan([CaptureDone::class]);
        $container->setDefinition(RoutingWorkflow::class, new Definition(RoutingWorkflow::class))->addTag('storm.saga.workflow');

        new ExtractSagaRoutingEventsPass()->process($container);

        $this->assertSame([], $container->getParameter('storm.saga.correlate_by'));
    }

    #[Test]
    public function compiles_the_workflow_priority_map_from_a_class_level_prioritized(): void
    {
        // PrioritizedWorkflow pairs #[Workflow(name: 'prioritized-fixture')] with #[Prioritized(10)];
        // RoutingWorkflow declares none, so absent from the map, no level, not level zero.
        $container = $this->containerWithScan([CaptureDone::class]);
        $container->setDefinition(PrioritizedWorkflow::class, new Definition(PrioritizedWorkflow::class))->addTag('storm.saga.workflow');
        $container->setDefinition(RoutingWorkflow::class, new Definition(RoutingWorkflow::class))->addTag('storm.saga.workflow');

        new ExtractSagaRoutingEventsPass()->process($container);

        $this->assertSame(
            ['prioritized-fixture' => 10],
            $container->getParameter('storm.saga.workflow_priorities'),
        );
    }

    #[Test]
    public function coregistered_versions_agreeing_on_the_level_compile_to_one_entry(): void
    {
        // the co-registration shape, same name, two services: agreeing levels dedupe, no refusal.
        $container = new ContainerBuilder;
        $container->setDefinition('wf-v1', new Definition(PrioritizedWorkflow::class))->addTag('storm.saga.workflow');
        $container->setDefinition('wf-v1-bis', new Definition(PrioritizedWorkflow::class))->addTag('storm.saga.workflow');

        new ExtractSagaRoutingEventsPass()->process($container);

        $this->assertSame(
            ['prioritized-fixture' => 10],
            $container->getParameter('storm.saga.workflow_priorities'),
        );
    }

    #[Test]
    public function refuses_divergent_priorities_across_coregistered_versions_of_one_name(): void
    {
        // v1 declares 10, v2 declares 40 under the same name: no max(); a version that DOWNGRADES
        // would be silently overruled by the draining old one; the build fails loud instead.
        $container = new ContainerBuilder;
        $container->setDefinition(PrioritizedWorkflow::class, new Definition(PrioritizedWorkflow::class))->addTag('storm.saga.workflow');
        $container->setDefinition(PrioritizedWorkflowVersionTwo::class, new Definition(PrioritizedWorkflowVersionTwo::class))->addTag('storm.saga.workflow');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('Workflow "prioritized-fixture" declares divergent #[Prioritized] levels');

        new ExtractSagaRoutingEventsPass()->process($container);
    }

    #[Test]
    public function refuses_a_prioritized_class_that_declares_no_workflow(): void
    {
        // only reachable by hand-tagging, since autoconfiguration tags #[Workflow] classes: the
        // declaration would be silently inert, so the pass refuses it at compile.
        $container = new ContainerBuilder;
        $container->setDefinition(PrioritizedOrphan::class, new Definition(PrioritizedOrphan::class))->addTag('storm.saga.workflow');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('has no #[Workflow] to default for');

        new ExtractSagaRoutingEventsPass()->process($container);
    }

    #[Test]
    public function leaves_the_priority_map_empty_without_class_level_declarations(): void
    {
        // per-leg #[Prioritized] on commands and the global default are untouched by this product:
        // a workflow with no class-level declaration contributes nothing.
        $container = $this->containerWithScan([CaptureDone::class]);
        $container->setDefinition(RoutingWorkflow::class, new Definition(RoutingWorkflow::class))->addTag('storm.saga.workflow');

        new ExtractSagaRoutingEventsPass()->process($container);

        $this->assertSame([], $container->getParameter('storm.saga.workflow_priorities'));
    }
}
