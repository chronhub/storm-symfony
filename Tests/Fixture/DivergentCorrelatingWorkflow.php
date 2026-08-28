<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;

/**
 * Fixture for the correlate-by guard: awaits the SAME event class as `CorrelatingWorkflow` but
 * declares a DIFFERENT payload field. Two correlation keys cannot merge for one class; boot both
 * and the guard must refuse the compile.
 */
#[Workflow(name: 'divergent-correlating-fixture')]
#[Start(state: 'await_outcome')]
#[State(key: 'await_outcome', type: 'wait')]
#[State(key: 'done', type: 'final')]
#[WaitFor(state: 'await_outcome', events: [CorrelatedEvent::class], correlateBy: 'shipment_id')]
#[On(from: 'await_outcome', trigger: 'event', to: 'done')]
final class DivergentCorrelatingWorkflow {}
