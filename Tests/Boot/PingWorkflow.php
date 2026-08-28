<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot;

use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Prioritized;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\Workflow;

/**
 * The smallest VALID `#[Workflow]` carrier: the registry reflects every tagged workflow into a
 * definition at boot, eagerly, so a bare attributed class would fail the build; this one passes
 * the definition rules with one activity hop to a final state. The class-level `#[Prioritized]`
 * feeds the workflow-priority compile product, proven wired end-to-end by the boot test; with no
 * `saga.priority.lanes` configured the level maps to no lane, so nothing else changes.
 */
#[Workflow(name: 'storm_bundle_test')]
#[Prioritized(10)]
#[Start(state: 'ping')]
#[State(key: 'ping', type: 'activity', activity: NoopActivity::class)]
#[State(key: 'done', type: 'final')]
#[On(from: 'ping', trigger: 'success', to: 'done')]
final class PingWorkflow {}
