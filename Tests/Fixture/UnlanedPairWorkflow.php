<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;

/**
 * Fixture for the signal-lane compiler passes: ONE wait, TWO alternative events, NO lane. Its pair
 * rides the default signal lane together, until another workflow lanes one of the two classes and
 * the merge splits them, laned versus default, the case the guard pass must also refuse.
 * Shallow-reflected only.
 */
#[Workflow(name: 'unlaned-pair-fixture')]
#[Start(state: 'await_pair')]
#[State(key: 'await_pair', type: 'wait')]
#[State(key: 'done', type: 'final')]
#[WaitFor(state: 'await_pair', events: [RoutedEvent::class, LanedEvent::class])]
#[On(from: 'await_pair', trigger: 'event', to: 'done')]
final class UnlanedPairWorkflow {}
