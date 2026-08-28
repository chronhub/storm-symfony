<?php

declare(strict_types=1);

namespace Storm\Symfony\Http\Ops;

use Storm\Telemetry\Health\HealthCheck;
use Storm\Telemetry\Health\HealthCheckResult;
use Storm\Telemetry\Health\HealthStatus;
use Storm\Telemetry\HealthChecker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Invokable controller for `GET /_storm/health`: runs every registered `HealthCheck` via
 * `HealthChecker` and serializes the aggregate to JSON.
 *
 * Response shape:
 * ```json
 * {
 *   "status": "ok|degraded|down",
 *   "checks": {
 *     "database": {"status": "ok", "message": null},
 *     "...":      {"status": "down", "message": "connection failed: ..."}
 *   }
 * }
 * ```
 *
 * HTTP status codes, Kubernetes-friendly:
 *
 * - `200 OK` on `ok` or `degraded`: the service is still serving traffic, even if a non-critical
 *   subcomponent is slow.
 *
 * - `503 Service Unavailable` on `down`: any check returned `Down`, so the pod is pulled from rotation.
 *
 * Auth and network policy are the app's call, the "Ops vs Api" framing: typically exposed internally
 * to monitoring infra such as a kubelet or Prometheus scraper, behind mTLS or network policies. Not
 * for public exposure.
 *
 * Check messages cross this boundary VERBATIM. The framework's own checks and the checker's backstop
 * emit stable, class-only details, never raw driver text, so what an APPLICATION check puts in its
 * `message` is what its operators will read over HTTP; a check that quotes an exception message owns
 * that exposure.
 *
 * @see \Storm\Telemetry\Health\HealthCheck
 */
#[Route(path: '/_storm/health', name: 'storm_ops_health', methods: ['GET'])]
final readonly class HealthController
{
    public function __construct(
        private HealthChecker $checker,
    ) {}

    public function __invoke(): JsonResponse
    {
        $aggregate = $this->checker->runAll();
        $status = $aggregate['status'];
        $httpStatus = $status === HealthStatus::Down
            ? Response::HTTP_SERVICE_UNAVAILABLE
            : Response::HTTP_OK;

        return new JsonResponse(
            [
                'status' => $status->value,
                'checks' => $this->serializeChecks($aggregate['checks']),
            ],
            $httpStatus,
        );
    }

    /**
     * @param  array<string, HealthCheckResult>  $checks
     * @return array<string, array{status: string, message: string|null}>
     */
    private function serializeChecks(array $checks): array
    {
        return array_map(
            static fn (HealthCheckResult $r): array => [
                'status' => $r->status->value,
                'message' => $r->message,
            ],
            $checks,
        );
    }
}
