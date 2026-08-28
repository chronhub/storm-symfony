<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot;

use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Signal;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;
use Storm\Saga\Workflow\SignalResult;
use Storm\Symfony\Tests\Boot\Domain\PingHappened;

/**
 * The smallest VALID workflow with a LANED wait. On a real kernel boot, like `PingWorkflow` proves
 * the bare registry, it proves the whole signal-lane chain:
 *
 * - The extract pass compiles the class-to-level map
 * - The guard pass accepts it, since a one-class wait cannot split
 * - The publisher receives its level-to-transport wiring
 *
 * It also carries the ONE `#[Signal]` declaration of the harness, on the scanned `PingHappened`:
 * the describe document must list this workflow under that entry's `consumed_by.workflows`, the
 * signal-handler leg of the catalog cross-reference.
 */
#[Workflow(name: 'storm_bundle_awaiting_test', globalTimeout: 3600)]
#[Start(state: 'await_pong')]
#[State(key: 'await_pong', type: 'wait')]
#[State(key: 'done', type: 'final')]
#[WaitFor(state: 'await_pong', events: PongReceived::class, lane: 40)]
#[On(from: 'await_pong', trigger: 'event', to: 'done')]
#[Signal(signal: PingHappened::class, handler: 'onPing')]
final class AwaitingWorkflow
{
    /**
     * @param  array<string, mixed>  $vars
     */
    public function onPing(PingHappened $signal, array $vars): SignalResult
    {
        return SignalResult::stay($vars);
    }
}
