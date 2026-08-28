<?php

declare(strict_types=1);

namespace Storm\Symfony\Http\Ops;

use Storm\Telemetry\Metrics\MetricsExposition;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Invokable controller for `GET /_storm/metrics`: serves the Prometheus text exposition the
 * console twin `storm:telemetry:metrics` prints, byte for byte, always under the legacy
 * `text/plain; version=0.0.4` content type. No content negotiation: an `Accept` asking for the
 * OpenMetrics format still gets this one, a single-format surface every Prometheus-compatible
 * scraper ingests. Always `200`: a collector failure is already a sample in the body,
 * `storm_telemetry_collector_errors`, and a scrape that got a body has succeeded.
 *
 * Auth and network policy are the app's call, the health endpoint's framing: typically exposed to
 * the monitoring network only, behind the ops firewall or a scrape credential. Not for public
 * exposure; the body enumerates workflow names and backlog shapes.
 */
#[Route(path: '/_storm/metrics', name: 'storm_ops_metrics', methods: ['GET'])]
final readonly class MetricsController
{
    public function __construct(
        private MetricsExposition $exposition,
    ) {}

    public function __invoke(): Response
    {
        return new Response($this->exposition->render(), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }
}
