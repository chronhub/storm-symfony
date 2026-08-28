<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;

/**
 * Fixture for the routing compiler passes. ExtractSagaRoutingEventsPass reads `#[WaitFor]`
 * SHALLOWLY, reflection only, never a WorkflowBuilder run, so what matters here is the
 * WaitFor mixing an extracted event CLASS with a skipped stable string alias; the rest
 * is the minimal coherent shell around it.
 */
#[Workflow(name: 'routing-fixture')]
#[Start(state: 'await_capture')]
#[State(key: 'await_capture', type: 'wait')]
#[State(key: 'done', type: 'final')]
#[WaitFor(state: 'await_capture', events: [RoutedEvent::class, 'capture.done'])]
#[On(from: 'await_capture', trigger: 'event', to: 'done')]
final class RoutingWorkflow {}
