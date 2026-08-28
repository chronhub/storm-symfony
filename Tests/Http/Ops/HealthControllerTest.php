<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Http\Ops;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Symfony\Http\Ops\HealthController;
use Storm\Telemetry\Health\HealthCheck;
use Storm\Telemetry\Health\HealthCheckResult;
use Storm\Telemetry\HealthChecker;

final class HealthControllerTest extends TestCase
{
    #[Test]
    public function ok_serialises_the_checks_and_returns_200(): void
    {
        $response = $this->controllerFor(
            $this->check('database', HealthCheckResult::ok()),
        )();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'status' => 'ok',
            'checks' => ['database' => ['status' => 'ok', 'message' => null]],
        ], $this->decode($response->getContent()));
    }

    #[Test]
    public function a_degraded_component_still_returns_200(): void
    {
        $response = $this->controllerFor(
            $this->check('cache', HealthCheckResult::degraded('slow')),
        )();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('degraded', $this->decode($response->getContent())['status']);
    }

    #[Test]
    public function any_down_component_returns_503(): void
    {
        $response = $this->controllerFor(
            $this->check('database', HealthCheckResult::ok()),
            $this->check('broker', HealthCheckResult::down('connection refused')),
        )();

        self::assertSame(503, $response->getStatusCode());
        $body = $this->decode($response->getContent());
        self::assertSame('down', $body['status']);
        self::assertSame(['status' => 'down', 'message' => 'connection refused'], $body['checks']['broker']);
    }

    private function controllerFor(HealthCheck ...$checks): HealthController
    {
        return new HealthController(new HealthChecker($checks));
    }

    private function check(string $name, HealthCheckResult $result): HealthCheck
    {
        return new readonly class($name, $result) implements HealthCheck
        {
            public function __construct(private string $name, private HealthCheckResult $result) {}

            public function name(): string
            {
                return $this->name;
            }

            public function check(): HealthCheckResult
            {
                return $this->result;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string|false $json): array
    {
        self::assertIsString($json);

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
