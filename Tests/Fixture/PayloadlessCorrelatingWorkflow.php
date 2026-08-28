<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;

/**
 * Fixture for the correlate-by guard: a `correlateBy:` wait on an event class that does not
 * implement `SerializablePayload`. The router reads the declared field through `toPayload()`,
 * which such a class does not expose; the guard must refuse the compile.
 */
#[Workflow(name: 'payloadless-correlating-fixture')]
#[Start(state: 'await_outcome')]
#[State(key: 'await_outcome', type: 'wait')]
#[State(key: 'done', type: 'final')]
#[WaitFor(state: 'await_outcome', events: [RoutedEvent::class], correlateBy: 'order_id')]
#[On(from: 'await_outcome', trigger: 'event', to: 'done')]
final class PayloadlessCorrelatingWorkflow {}
