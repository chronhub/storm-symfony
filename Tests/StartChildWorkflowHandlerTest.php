<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Storm\Saga\Child\ChildSpawner;
use Storm\Saga\Child\StartChildWorkflow;
use Storm\Saga\Engine\SagaStarter;
use Storm\Saga\Exception\ParentNotBornYet;
use Storm\Saga\Store\WorkflowFamilies;
use Storm\Saga\Store\WorkflowInstances;
use Storm\Symfony\StartChildWorkflowHandler;

/**
 * The wiring pins prove an envelope FINDS this handler; nothing there would notice a body that
 * dropped the command on the floor, since a handler that resolves and does nothing looks identical
 * to the locator. Two facts, then: the spawner reads THIS command, and its refusal is not swallowed
 * on the way out, because the dead-letter is what settles the parent's leg.
 */
final class StartChildWorkflowHandlerTest extends TestCase
{
    #[Test]
    public function the_command_reaches_the_spawner_and_its_refusal_bubbles(): void
    {
        $instances = $this->createMock(WorkflowInstances::class);
        $instances->expects($this->once())->method('findByCorrelation')
            ->with('parent-1')
            ->willReturn(null);

        $handler = new StartChildWorkflowHandler(new ChildSpawner(
            $this->createStub(SagaStarter::class),
            $instances,
            $this->createStub(WorkflowFamilies::class),
            $this->createStub(EventDispatcherInterface::class),
        ));

        $this->expectException(ParentNotBornYet::class);

        $handler(StartChildWorkflow::with('parent_type', 'parent-1', 'child_type', 'slot-a'));
    }
}
