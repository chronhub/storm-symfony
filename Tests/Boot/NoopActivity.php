<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot;

use Storm\Saga\Workflow\Activity;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\Metadata;

/**
 * Ctor-less on purpose: the workflow registry builds EAGERLY at boot, so PingWorkflow's activity
 * must resolve from the locator with no arguments to feed it.
 */
final class NoopActivity implements Activity
{
    public function run(array $vars, Metadata $metadata): ActivityResult
    {
        return ActivityResult::success();
    }
}
