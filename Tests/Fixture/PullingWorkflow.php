<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;

/**
 * Fixture for the signal-lane compiler passes: awaits ONE of the pair's events at a HIGHER lane, so
 * the highest-wins merge pulls that class upward, the move that splits another workflow's pair and
 * must be refused by the guard pass. Shallow-reflected only.
 */
#[Workflow(name: 'pulling-fixture')]
#[Start(state: 'await_one')]
#[State(key: 'await_one', type: 'wait')]
#[State(key: 'done', type: 'final')]
#[WaitFor(state: 'await_one', events: [RoutedEvent::class], lane: 50)]
#[On(from: 'await_one', trigger: 'event', to: 'done')]
final class PullingWorkflow {}
