<?php

declare(strict_types=1);

namespace Symfony\Component\Routing\Loader\Configurator;

/*
 * The metrics surface alone: `/_storm/metrics`, the scraper trust level, NOT the probe one; the
 * body enumerates workflow names and backlog shapes, which a liveness probe never needs to see.
 *
 *   # config/routes.yaml
 *   storm_metrics:
 *       resource: '@StormBundle/config/routes/metrics.php'
 *
 * The route ships with NO authentication; close it app-side, behind the monitoring network or a
 * scrape credential:
 *
 *   # config/packages/security.yaml
 *   access_control:
 *       - { path: ^/_storm/metrics$, roles: PUBLIC_ACCESS, ips: [10.1.0.0/16] }
 *       - { path: ^/_storm/metrics$, roles: ROLE_NO_ACCESS }
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import('../../Http/Ops/MetricsController.php', 'attribute');
};
