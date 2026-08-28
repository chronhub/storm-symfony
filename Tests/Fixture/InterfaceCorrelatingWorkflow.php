<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;

/**
 * Fixture for the correlate-by guard: a `correlateBy:` wait naming an INTERFACE. The delivered
 * implementors would miss the exact-class map and the declaration would be dead; the guard must
 * refuse the compile.
 */
#[Workflow(name: 'interface-correlating-fixture')]
#[Start(state: 'await_outcome')]
#[State(key: 'await_outcome', type: 'wait')]
#[State(key: 'done', type: 'final')]
#[WaitFor(state: 'await_outcome', events: [CorrelatedContract::class], correlateBy: 'order_id')]
#[On(from: 'await_outcome', trigger: 'event', to: 'done')]
final class InterfaceCorrelatingWorkflow {}
