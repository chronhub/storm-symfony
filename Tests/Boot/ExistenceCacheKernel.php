<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Override;
use Storm\Chronicler\Erasure\StreamEraser;
use Storm\Symfony\StormBundle;
use Storm\Symfony\Tests\ExistenceCacheBootTest;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * {@see StormTestKernel}'s sibling with the category existence cache OPTED IN via
 * `storm.stream_existence_cache`, a non-default TTL so the knob's carriage is provable. Boots
 * the real bundle wiring so the compile itself proves the decoration; the companion test then
 * pins WHICH concrete each port resolves to and that the TTL reached the decorator.
 *
 * @see ExistenceCacheBootTest
 */
final class ExistenceCacheKernel extends BaseKernel
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
        return KernelWorkspace::dir('storm-existence-cache-kernel').'/cache';
    }

    #[Override]
    public function getLogDir(): string
    {
        return KernelWorkspace::dir('storm-existence-cache-kernel').'/log';
    }

    // @phpstan-ignore method.unused (MicroKernelTrait::registerContainerConfiguration invokes it by reflection)
    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'storm-existence-cache-under-test',
            'test' => true,
            'messenger' => [
                'transports' => ['storm_events' => 'in-memory://'],
            ],
        ]);

        $container->extension('doctrine', [
            'dbal' => [
                // port 1 + serverVersion: shaped like production, connectable never
                'url' => 'postgresql://storm:storm@127.0.0.1:1/storm_db?serverVersion=18&charset=utf8',
            ],
        ]);

        $container->extension('storm', [
            'event_paths' => [__DIR__.'/Domain'],
            'stream_existence_cache' => [
                'enabled' => true,
                'negative_ttl_seconds' => 45, // non-default on purpose: proves the knob is carried
            ],
        ]);

        // public test handle: the private eraser alias would otherwise be inlined into its single
        // consumer and the boot test could not fetch the decorated chain, the same motif as the
        // monorepo services_test.yaml test.* aliases
        $container->services()
            ->alias('test.stream_eraser', StreamEraser::class)->public();
    }

    // @phpstan-ignore method.unused (the trait's route loader invokes it by reflection)
    private function configureRoutes(RoutingConfigurator $routes): void
    {
        // no routes: the bundle's Ops routes are an explicit app opt-in, not a boot requirement
    }
}
