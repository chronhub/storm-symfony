<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;

/**
 * Fixture for the signal-lane guard: awaits ONE of the pair's events at level ZERO, a legal bare
 * ordinal that a loose comparison collapses onto the default lane. The merge must still refuse the
 * split it causes on another workflow's unlaned pair. Shallow-reflected only.
 */
#[Workflow(name: 'zero-laned-fixture')]
#[Start(state: 'await_zero')]
#[State(key: 'await_zero', type: 'wait')]
#[State(key: 'done', type: 'final')]
#[WaitFor(state: 'await_zero', events: [RoutedEvent::class], lane: 0)]
#[On(from: 'await_zero', trigger: 'event', to: 'done')]
final class ZeroLanedWorkflow {}
