<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;

/**
 * Fixture for the correlate-by compiler passes: ONE external-event wait declaring the payload
 * field its outcome carries the correlation key in. Shallow-reflected only, like `RoutingWorkflow`.
 */
#[Workflow(name: 'correlating-fixture')]
#[Start(state: 'await_outcome')]
#[State(key: 'await_outcome', type: 'wait')]
#[State(key: 'done', type: 'final')]
#[WaitFor(state: 'await_outcome', events: [CorrelatedEvent::class], correlateBy: 'order_id')]
#[On(from: 'await_outcome', trigger: 'event', to: 'done')]
final class CorrelatingWorkflow {}
