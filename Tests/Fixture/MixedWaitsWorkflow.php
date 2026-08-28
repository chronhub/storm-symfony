<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;

/**
 * Fixture for the correlate-by guard: ONE workflow carrying both kinds of wait on the same event
 * class, the declaring one FIRST and the ambient one behind it. The guard walks a workflow's waits
 * and skips the declaring ones, so the ambient wait it must refuse sits past a skip.
 */
#[Workflow(name: 'mixed-waits-fixture')]
#[Start(state: 'await_declared')]
#[State(key: 'await_declared', type: 'wait')]
#[State(key: 'await_ambient', type: 'wait')]
#[State(key: 'done', type: 'final')]
#[WaitFor(state: 'await_declared', events: [CorrelatedEvent::class], correlateBy: 'order_id')]
#[WaitFor(state: 'await_ambient', events: [CorrelatedEvent::class])]
#[On(from: 'await_declared', trigger: 'event', to: 'await_ambient')]
#[On(from: 'await_ambient', trigger: 'event', to: 'done')]
final class MixedWaitsWorkflow {}
