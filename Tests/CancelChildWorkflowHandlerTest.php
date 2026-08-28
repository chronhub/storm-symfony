<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Storm\Saga\Child\CancelChildWorkflow;
use Storm\Saga\Child\ChildCanceller;
use Storm\Saga\Engine\SagaOperator;
use Storm\Saga\Store\WorkflowInstances;
use Storm\Symfony\CancelChildWorkflowHandler;

/**
 * The cascade twin of StartChildWorkflowHandlerTest: the wiring pins prove the envelope finds this
 * handler, and only the canceller's own read proves the command traveled rather than being dropped.
 * The child is gone here, the quiet no-op of a cascade landing after the race resolved, so the
 * handler must also return without a sound; dead-lettering would ask a settled pair to settle again.
 */
final class CancelChildWorkflowHandlerTest extends TestCase
{
    #[Test]
    public function the_command_reaches_the_canceller_and_a_vanished_child_stays_quiet(): void
    {
        $instances = $this->createMock(WorkflowInstances::class);
        $instances->expects($this->once())->method('findByCorrelation')
            ->with('child-1')
            ->willReturn(null);

        $handler = new CancelChildWorkflowHandler(new ChildCanceller(
            $this->createStub(SagaOperator::class),
            $instances,
            $this->createStub(EventDispatcherInterface::class),
        ));

        $handler(CancelChildWorkflow::with('parent_type', 'parent-1', 'child_type', 'child-1', null, false));
    }
}
