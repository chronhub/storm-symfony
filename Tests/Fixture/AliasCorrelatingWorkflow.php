<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;

/**
 * Fixture for the correlate-by guard: a `correlateBy:` wait whose events include a stable string
 * alias. The alias RESOLVES against the scan product, so the extract pass is satisfied and the
 * correlate-by guard is the one left to speak: aliases are skipped on the whole generic routing
 * path, the declaration would be dead, and the compile is refused. An unresolvable token never
 * reaches this guard; the extract pass refuses it first, and that is its own test's subject.
 */
#[Workflow(name: 'alias-correlating-fixture')]
#[Start(state: 'await_outcome')]
#[State(key: 'await_outcome', type: 'wait')]
#[State(key: 'done', type: 'final')]
#[WaitFor(state: 'await_outcome', events: [CorrelatedEvent::class, 'capture.done'], correlateBy: 'order_id')]
#[On(from: 'await_outcome', trigger: 'event', to: 'done')]
final class AliasCorrelatingWorkflow {}
