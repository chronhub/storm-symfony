<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Storm\Symfony\Tests\Boot\KernelWorkspace;
use Storm\Symfony\Tests\Boot\OpsRoutedKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Loads the bundle's opt-in routes files the way a consuming app does. The promise of
 * `config/routes.php` is small and brittle: named imports of the per-surface files under
 * `routes/`, exposing `/_storm/health` and `/_storm/metrics` and NOTHING else. Nothing else loads
 * those files, so a path typo in an import, a moved controller, or a renamed `#[Route]` would 404
 * every consumer while every other storm test stayed green; and the collection is asserted EXACTLY,
 * so a new controller silently riding an existing import would fail here, exposure being a
 * decision each routes file takes by name.
 *
 * @see OpsRoutedKernel the app-side imports, verbatim
 */
final class OpsRoutesTest extends KernelTestCase
{
    #[Override]
    public static function setUpBeforeClass(): void
    {
        // a fresh compile every run; a stale cached container would keep serving yesterday's routes
        $filesystem = new Filesystem;
        $filesystem->remove(KernelWorkspace::dir('ops-routes'));
        $filesystem->remove(KernelWorkspace::dir('ops-routes-health'));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new OpsRoutedKernel('test', false, healthOnly: $options['health_only'] ?? false);
    }

    #[Test]
    public function importing_the_bundle_routes_exposes_exactly_the_two_ops_endpoints(): void
    {
        self::bootKernel();

        // suffix-matched on purpose: an app may mount the file under its own prefix, so the
        // portable promise is the trailing path; the names and the COUNT are exact, the net that
        // catches a third controller leaking into the opt-in
        $paths = self::routePathsByName();

        self::assertSame(['storm_ops_health', 'storm_ops_metrics'], array_keys($paths));
        self::assertTrue(str_ends_with($paths['storm_ops_health'], '/_storm/health'));
        self::assertTrue(str_ends_with($paths['storm_ops_metrics'], '/_storm/metrics'));
    }

    #[Test]
    #[Group('adversarial')]
    public function the_health_surface_imports_alone_without_the_metrics_one(): void
    {
        // the split's whole point: a kubelet probe and a metrics scraper are not the same trust
        // level, so the health file must not drag the metrics body along
        self::bootKernel(['health_only' => true]);

        self::assertSame(['storm_ops_health'], array_keys(self::routePathsByName()));
    }

    /**
     * @return array<string, string> route path by route name, in collection order
     */
    private static function routePathsByName(): array
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        return array_map(
            static fn ($route): string => $route->getPath(),
            iterator_to_array($router->getRouteCollection()),
        );
    }
}
