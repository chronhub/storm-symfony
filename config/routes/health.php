<?php

declare(strict_types=1);

namespace Symfony\Component\Routing\Loader\Configurator;

/*
 * The health surface alone: `/_storm/health`, the kubelet-probe trust level. Importable on its own
 * so an app can expose liveness to its orchestrator without also exposing the metrics body, which
 * enumerates workflow names and backlog shapes.
 *
 *   # config/routes.yaml
 *   storm_health:
 *       resource: '@StormBundle/config/routes/health.php'
 *
 * The route ships with NO authentication; close it app-side. A probe network allowlist is the
 * usual shape:
 *
 *   # config/packages/security.yaml
 *   access_control:
 *       - { path: ^/_storm/health$, roles: PUBLIC_ACCESS, ips: [10.0.0.0/8] }
 *       - { path: ^/_storm/health$, roles: ROLE_NO_ACCESS }
 *
 * access_control keeps the FIRST matching rule: place these lines ABOVE any broader `^/_storm`
 * pattern, such as the ops surface's role gate, or the broader rule absorbs the probe path and
 * the orchestrator is refused with a 403 it cannot authenticate its way out of.
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import('../../Http/Ops/HealthController.php', 'attribute');
};
