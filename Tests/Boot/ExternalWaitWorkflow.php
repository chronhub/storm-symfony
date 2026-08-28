<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot;

use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;

/**
 * The smallest VALID workflow with an EXTERNAL-event wait. On a real kernel boot, like
 * `AwaitingWorkflow` proves the signal-lane chain, it proves the correlate-by chain:
 *
 * - The extract pass compiles the class-to-payload-field map
 * - The guard pass accepts it, a payload event with one declared field
 * - The router receives the map and routes the class by the declared field
 */
#[Workflow(name: 'storm_bundle_external_wait_test', globalTimeout: 3600)]
#[Start(state: 'await_outcome')]
#[State(key: 'await_outcome', type: 'wait')]
#[State(key: 'done', type: 'final')]
#[WaitFor(state: 'await_outcome', events: ExternalOutcomeArrived::class, correlateBy: 'order_id')]
#[On(from: 'await_outcome', trigger: 'event', to: 'done')]
final class ExternalWaitWorkflow {}
