<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Prioritized;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;

/**
 * Fixture for the workflow-priority compile product. ExtractSagaRoutingEventsPass reads the
 * class-level `#[Prioritized]` next to `#[Workflow]` SHALLOWLY, reflection only, so what matters
 * here is the pair of name and level; the rest is the minimal coherent shell around it.
 */
#[Workflow(name: 'prioritized-fixture')]
#[Prioritized(10)]
#[Start(state: 'await_capture')]
#[State(key: 'await_capture', type: 'wait')]
#[State(key: 'done', type: 'final')]
#[WaitFor(state: 'await_capture', events: [RoutedEvent::class])]
#[On(from: 'await_capture', trigger: 'event', to: 'done')]
final class PrioritizedWorkflow {}
