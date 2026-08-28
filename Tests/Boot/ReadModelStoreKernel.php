<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Override;
use Storm\Symfony\StormBundle;
use Storm\Symfony\Tests\ReadModelStoreBootTest;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * {@see StormTestKernel}'s sibling with the read-model store OPTED IN: two doctrine
 * connections, never connected on port 1, `storm.connections.read_model_store` naming the
 * second. Boots the real bundle wiring so the compile itself proves the alias flip; the
 * companion test then pins WHICH connection each side of the split actually carries.
 *
 * @see ReadModelStoreBootTest
 */
final class ReadModelStoreKernel extends BaseKernel
{
    use MicroKernelTrait;

    #[Override]
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle;
        yield new DoctrineBundle;
        yield new StormBundle;
    }

    /** The monorepo root (not src/Symfony, where the package manifest would anchor it). */
    #[Override]
    public function getProjectDir(): string
    {
        return dirname(__DIR__, 4);
    }

    #[Override]
    public function getCacheDir(): string
    {
        return KernelWorkspace::dir('storm-rms-kernel').'/cache';
    }

    #[Override]
    public function getLogDir(): string
    {
        return KernelWorkspace::dir('storm-rms-kernel').'/log';
    }

    // @phpstan-ignore method.unused (MicroKernelTrait::registerContainerConfiguration invokes it by reflection)
    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'storm-read-model-store-under-test',
            'test' => true,
            'messenger' => [
                'transports' => ['storm_events' => 'in-memory://'],
            ],
        ]);

        $container->extension('doctrine', [
            'dbal' => [
                'default_connection' => 'default',
                'connections' => [
                    // port 1 + serverVersion: shaped like production, connectable never
                    'default' => ['url' => 'postgresql://storm:storm@127.0.0.1:1/storm_events_db?serverVersion=18&charset=utf8'],
                    'read_models' => ['url' => 'postgresql://storm:storm@127.0.0.1:1/storm_read_models_db?serverVersion=18&charset=utf8'],
                ],
            ],
        ]);

        $container->extension('storm', [
            'event_paths' => [__DIR__.'/Domain'],
            'connections' => ['read_model_store' => 'read_models'],
        ]);

        // public test handle: the boot proof pins the lanes' connections
        $container->services()->alias('test.lanes', \Storm\Projector\Run\ProjectionLanes::class)->public();

        // the link/derived refusal, pinned in REVERSE at bundle level: a LinkProjection
        // must compile and register under the split; per-projection homing owns the topology
        $container->services()->set(\Storm\Symfony\Tests\Fixture\LinkingFixtureProjection::class)->tag('storm.projection');
        $container->services()->alias('test.registry', \Storm\Projector\Registry\ProjectionRegistry::class)->public();
    }

    // @phpstan-ignore method.unused (the trait's route loader invokes it by reflection)
    private function configureRoutes(RoutingConfigurator $routes): void
    {
        // no routes: the bundle's Ops routes are an explicit app opt-in, not a boot requirement
    }
}
