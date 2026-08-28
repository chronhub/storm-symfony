<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;

/**
 * Fixture for the correlate-by guard: a `correlateBy:` wait declaring an EMPTY payload field. An empty
 * string is not "no declaration"; it opts the wait INTO the declared path, then names no field for the
 * router to read the correlation key from, so every delivery would miss. The guard must refuse the
 * compile rather than let the misdeclaration ship as a silently dead wait.
 */
#[Workflow(name: 'empty-field-correlating-fixture')]
#[Start(state: 'await_outcome')]
#[State(key: 'await_outcome', type: 'wait')]
#[State(key: 'done', type: 'final')]
#[WaitFor(state: 'await_outcome', events: [CorrelatedEvent::class], correlateBy: '')]
#[On(from: 'await_outcome', trigger: 'event', to: 'done')]
final class EmptyFieldCorrelatingWorkflow {}
