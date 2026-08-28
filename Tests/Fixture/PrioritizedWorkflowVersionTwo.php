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
 * Co-registered version 2 of `PrioritizedWorkflow`, same name, declaring a DIVERGENT level:
 * the pass must refuse the pair, since `max()` would silently overrule a version that downgrades.
 */
#[Workflow(name: 'prioritized-fixture', version: 2)]
#[Prioritized(40)]
#[Start(state: 'await_capture')]
#[State(key: 'await_capture', type: 'wait')]
#[State(key: 'done', type: 'final')]
#[WaitFor(state: 'await_capture', events: [RoutedEvent::class])]
#[On(from: 'await_capture', trigger: 'event', to: 'done')]
final class PrioritizedWorkflowVersionTwo {}
