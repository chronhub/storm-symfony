<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot;

use Storm\Telemetry\Health\HealthCheck;
use Storm\Telemetry\Health\HealthCheckResult;

/**
 * The `storm.health_check` carrier: implementing the interface must be enough for the
 * HealthChecker to pick it up, the promise the bundle writes at its registerForAutoconfiguration.
 */
final class AlwaysUpCheck implements HealthCheck
{
    public function name(): string
    {
        return 'storm_bundle_test';
    }

    public function check(): HealthCheckResult
    {
        return HealthCheckResult::ok('boot fixture');
    }
}
