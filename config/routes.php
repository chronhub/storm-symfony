<?php

declare(strict_types=1);

namespace Symfony\Component\Routing\Loader\Configurator;

/*
 * Storm framework HTTP routes, opt-in: the AGGREGATE of the per-surface files under `routes/`.
 * App imports this file explicitly in its config/routes.yaml to expose Storm's framework-side
 * endpoints under `/_storm/...`. Nothing is auto-registered, and the imports below name FILES,
 * never a directory wildcard: a controller added under `Http/Ops/` exposes nothing until a routes
 * file names it, so exposure stays a decision, not a side effect of the file landing there.
 *
 * Example app-side import, everything at once:
 *
 *   # config/routes.yaml
 *   storm_ops:
 *       resource: '@StormBundle/config/routes.php'
 *       prefix: '/_internal'      # optional: /_internal/_storm/health
 *
 * A kubelet probe and a Prometheus scraper are not the same trust level; when they differ, import
 * one surface at a time instead, `routes/health.php` or `routes/metrics.php`, each documenting its
 * own posture.
 *
 * Every route here ships with NO authentication. Whichever import is chosen, close the surface
 * app-side:
 *
 *   # config/packages/security.yaml
 *   access_control:
 *       - { path: ^/_storm/health$, roles: PUBLIC_ACCESS, ips: [10.0.0.0/8] }
 *       - { path: ^/_storm, roles: ROLE_OPS }
 *
 * Scope: the framework's own `Ops/` controllers, health above all. The API bridge packages, rich
 * introspection and mutation endpoints, declare their surface through API Platform resource
 * metadata instead, so nothing of theirs is routed from here.
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import('routes/health.php');
    $routes->import('routes/metrics.php');
};
