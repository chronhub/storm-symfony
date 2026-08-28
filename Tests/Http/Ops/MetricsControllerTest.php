<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Http\Ops;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Symfony\Http\Ops\MetricsController;
use Storm\Telemetry\Metrics\MetricFamily;
use Storm\Telemetry\Metrics\MetricSample;
use Storm\Telemetry\Metrics\MetricsCollector;
use Storm\Telemetry\Metrics\MetricsExposition;
use Storm\Telemetry\Metrics\PrometheusTextRenderer;

final class MetricsControllerTest extends TestCase
{
    #[Test]
    public function the_exposition_is_served_as_prometheus_text_with_200(): void
    {
        $response = $this->controllerFor([
            MetricFamily::gauge('storm_inbox_rows', 'Inbox rows', [new MetricSample([], 7)]),
        ])();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain; version=0.0.4; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString("storm_inbox_rows 7\n", (string) $response->getContent());
    }

    #[Test]
    public function a_failing_collector_still_scrapes_200_with_the_error_gauge_in_the_body(): void
    {
        $throwing = new class() implements MetricsCollector
        {
            public function families(): array
            {
                throw new RuntimeException('boom');
            }
        };

        $response = new MetricsController(new MetricsExposition([$throwing], new PrometheusTextRenderer, $this->createStub(Connection::class), statementTimeoutMs: 0))();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('storm_telemetry_collector_errors', (string) $response->getContent());
    }

    /**
     * @param  list<MetricFamily>  $families
     */
    private function controllerFor(array $families): MetricsController
    {
        $collector = new readonly class($families) implements MetricsCollector
        {
            /**
             * @param  list<MetricFamily>  $families
             */
            public function __construct(
                private array $families,
            ) {}

            public function families(): array
            {
                return $this->families;
            }
        };

        return new MetricsController(new MetricsExposition([$collector], new PrometheusTextRenderer, $this->createStub(Connection::class), statementTimeoutMs: 0));
    }
}
