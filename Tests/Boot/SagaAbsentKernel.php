<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Override;
use Storm\Symfony\Tests\Fixture\Article;
use Storm\Symfony\Tests\Fixture\ArticleId;
use Storm\Symfony\Tests\SagaAbsentBootTest;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The sibling of `StormTestKernel` that boots WITHOUT the Saga package: the `SagaAbsent` bundle
 * subclass makes `packageDir('Saga')` answer null, so this kernel compiles the degraded-to-no-op
 * container the atomic saga guard promises. No saga config, no priority event lane: the lane
 * exists solely for saga-awaited events and configuring it saga-free fails the compile by design,
 * the documented leak-with-teeth.
 *
 * @see SagaAbsentBootTest
 */
final class SagaAbsentKernel extends BaseKernel
{
    use MicroKernelTrait;

    #[Override]
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle;
        yield new DoctrineBundle;
        yield new SagaAbsent\StormBundle;
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
        return KernelWorkspace::dir('saga-absent').'/cache';
    }

    #[Override]
    public function getLogDir(): string
    {
        return KernelWorkspace::dir('saga-absent').'/log';
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
        // no routes: the bundle's Ops routes are an explicit app opt-in, not a boot requirement
    }
}
