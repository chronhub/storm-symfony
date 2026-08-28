<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Override;
use Storm\Symfony\StormBundle;
use Storm\Symfony\Tests\Fixture\Article;
use Storm\Symfony\Tests\Fixture\ArticleId;
use Storm\Symfony\Tests\OpsRoutesTest;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The sibling of `StormTestKernel` that DOES take the Ops routes opt-in: it imports
 * `@StormBundle/config/routes.php` verbatim, the resource a consuming app's routes.yaml names. The
 * nominal kernel deliberately boots routeless, routes being an opt-in rather than a boot
 * requirement, so nothing else loads the routes file: a path typo or a renamed route attribute
 * would break every consumer silently, storm-side green. Same minimal bundle set and unconnectable
 * DSN; its own cache dir, since a shared one would hand back whichever container compiled first,
 * routed or not.
 *
 * @see StormTestKernel the nominal, routeless boot
 * @see OpsRoutesTest
 */
final class OpsRoutedKernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * @param  bool  $healthOnly  import the split `routes/health.php` alone instead of the
     *                            aggregate, the per-surface opt-in a consuming app takes when its
     *                            probe and its scraper live at different trust levels
     */
    public function __construct(string $environment, bool $debug, private bool $healthOnly = false)
    {
        parent::__construct($environment, $debug);
    }

    #[Override]
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle;
        yield new DoctrineBundle;
        yield new StormBundle;
    }

    /**
     * {@inheritDoc}
     *
     * The monorepo root, not `src/Symfony`, where the package manifest would anchor it.
     */
    #[Override]
    public function getProjectDir(): string
    {
        return dirname(__DIR__, 4);
    }

    #[Override]
    public function getCacheDir(): string
    {
        return $this->workspace().'/cache';
    }

    #[Override]
    public function getLogDir(): string
    {
        return $this->workspace().'/log';
    }

    // each import shape compiles its own container; a shared workspace would hand back whichever
    // route collection compiled first
    private function workspace(): string
    {
        return KernelWorkspace::dir($this->healthOnly ? 'ops-routes-health' : 'ops-routes');
    }

    // @phpstan-ignore method.unused (MicroKernelTrait::registerContainerConfiguration invokes it by reflection)
    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'storm-bundle-under-test',
            'test' => true,
            'messenger' => [
                // the bundle's documented app-side requirement: the outbox handoff transport
                'transports' => ['storm_events' => 'in-memory://'],
            ],
        ]);

        $container->extension('doctrine', [
            // port 1 + serverVersion: shaped like production, connectable never
            'dbal' => ['url' => 'postgresql://storm:storm@127.0.0.1:1/storm_bundle_test?serverVersion=18&charset=utf8'],
        ]);

        $container->extension('storm', [
            'aggregates' => [
                Article::class => ['id' => ArticleId::class, 'category' => 'article'],
            ],
            'event_paths' => [__DIR__.'/Domain'],
        ]);
    }

    // @phpstan-ignore method.unused (the trait's route loader invokes it by reflection)
    private function configureRoutes(RoutingConfigurator $routes): void
    {
        // the app-side opt-in, verbatim; @StormBundle resolves through the bundle's getPath()
        $routes->import($this->healthOnly ? '@StormBundle/config/routes/health.php' : '@StormBundle/config/routes.php');
    }
}
