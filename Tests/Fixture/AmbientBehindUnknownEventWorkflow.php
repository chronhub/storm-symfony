<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;

/**
 * Fixture for the correlate-by guard: an AMBIENT wait whose event list opens on a class nobody
 * declares a field for and closes on one another workflow does. The guard walks the list and skips
 * the undeclared entries, so the entry it must refuse sits past a skip.
 */
#[Workflow(name: 'ambient-behind-unknown-fixture')]
#[Start(state: 'await_outcome')]
#[State(key: 'await_outcome', type: 'wait')]
#[State(key: 'done', type: 'final')]
#[WaitFor(state: 'await_outcome', events: [CaptureDone::class, CorrelatedEvent::class])]
#[On(from: 'await_outcome', trigger: 'event', to: 'done')]
final class AmbientBehindUnknownEventWorkflow {}
