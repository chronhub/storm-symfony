<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Symfony\Compiler\ExtractSagaRoutingEventsPass;
use Storm\Symfony\Compiler\GuardSagaSignalLanesPass;
use Storm\Symfony\Tests\Fixture\CaptureDone;
use Storm\Symfony\Tests\Fixture\LanedPairWorkflow;
use Storm\Symfony\Tests\Fixture\PullingWorkflow;
use Storm\Symfony\Tests\Fixture\RoutingWorkflow;
use Storm\Symfony\Tests\Fixture\UnlanedPairWorkflow;
use Storm\Symfony\Tests\Fixture\ZeroLanedWorkflow;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * The guard runs AFTER the extract pass, so each case processes both, on the same container, in
 * that order: the merge the guard checks is the one the extract actually compiled.
 */
final class GuardSagaSignalLanesPassTest extends TestCase
{
    #[Test]
    public function passes_a_wait_whose_alternatives_share_their_lane(): void
    {
        // LanedPairWorkflow alone: both alternatives at 30; the per-wait declaration keeps them together.
        $container = $this->containerWith(LanedPairWorkflow::class);

        $this->expectNotToPerformAssertions();
        $this->runPasses($container);
    }

    #[Test]
    public function passes_when_no_wait_declares_a_lane(): void
    {
        // a single signal lane cannot split anything: the guard is a no-op.
        $container = $this->containerWith(RoutingWorkflow::class, UnlanedPairWorkflow::class);

        $this->expectNotToPerformAssertions();
        $this->runPasses($container);
    }

    #[Test]
    #[Group('adversarial')]
    public function refuses_a_pair_split_between_a_class_and_an_alias(): void
    {
        // RoutingWorkflow's wait holds a class AND a stable alias; PullingWorkflow pulls the class to
        // 50 while the alias resolves to an unlaned one. The wait is split exactly like any other,
        // and calling it a single-event wait was reading the DECLARATION rather than what the extract
        // pass compiled from it: an alias is laned under the class it names, so a guard that skipped
        // alias tokens answered for half a wait and blessed the split it exists to refuse.
        $container = $this->containerWith(PullingWorkflow::class, RoutingWorkflow::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('await_capture');
        $this->expectExceptionMessageIsOrContains(CaptureDone::class); // named by its CLASS, the key the lane map carries

        $this->runPasses($container);
    }

    #[Test]
    public function refuses_a_pair_split_by_the_highest_wins_merge(): void
    {
        // LanedPairWorkflow's pair rides at 30, until PullingWorkflow pulls RoutedEvent to 50 and the
        // captured-versus-voided guarantee silently breaks: refusal, not bypassable.
        $container = $this->containerWith(LanedPairWorkflow::class, PullingWorkflow::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('await_outcome');

        $this->runPasses($container);
    }

    #[Test]
    public function refuses_a_pair_split_between_a_lane_and_the_default(): void
    {
        // UnlanedPairWorkflow's pair rides the default lane together; PullingWorkflow lanes ONE of the
        // two classes, splitting the pair between a lane and the default: same refusal.
        $container = $this->containerWith(UnlanedPairWorkflow::class, PullingWorkflow::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('await_pair');

        $this->runPasses($container);
    }

    #[Test]
    public function refuses_a_pair_split_between_level_zero_and_the_default(): void
    {
        // level 0 is a legal bare ordinal and a DIFFERENT lane than the default; a loose comparison
        // collapses 0 onto null and would ship this split silently.
        $container = $this->containerWith(UnlanedPairWorkflow::class, ZeroLanedWorkflow::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('await_pair');

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
        new GuardSagaSignalLanesPass()->process($container);
    }
}
