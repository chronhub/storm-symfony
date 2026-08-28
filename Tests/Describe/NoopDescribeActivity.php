<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Describe;

use Storm\Saga\Workflow\Activity;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\Metadata;
use Storm\Symfony\Describe\StormDescriptor;

/**
 * A NAMED no-op activity for the descriptor tests: the describe document renders an activity by
 * its class, and an anonymous class would put an unstable `class@anonymous` name in the expected
 * JSON. Never run; the descriptor only reads its class name.
 *
 * @see StormDescriptor
 */
final class NoopDescribeActivity implements Activity
{
    public function run(array $vars, Metadata $metadata): ActivityResult
    {
        return ActivityResult::success();
    }
}
