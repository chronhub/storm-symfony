<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Override;
use Storm\Symfony\StormBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The sibling of `StormTestKernel` for cases that need to say something ELSE under `storm:`, a
 * misconfiguration the bundle must refuse at compile, or an opt-in the default kernel does not take.
 * Same minimal bundle set and same unconnectable DSN; only the storm extension config varies.
 *
 * Each variant compiles into its OWN cache dir: a shared one would hand back the previously compiled
 * container and the misconfiguration would never be re-compiled, so the refusal test would pass
 * without ever running the guard.
 *
 * @see StormTestKernel the nominal boot; this one exists for the branches it cannot express
 */
final class ConfigurableStormKernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * @param  array<string, mixed>  $stormConfig  the whole `storm:` node, verbatim
     * @param  array<string, class-string>  $appServices  service id to class, for a config knob that names a
     *                                                    service the APP is expected to own, such as the
     *                                                    connection a Redis-backed adapter runs on
     */
    public function __construct(
        private readonly array $stormConfig,
        private readonly string $variant,
        private readonly array $appServices = [],
    ) {
        parent::__construct('test', false);
    }

    #[Override]
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle;
        yield new DoctrineBundle;
        yield new StormBundle;
    }

    #[Override]
    public function getProjectDir(): string
    {
        return dirname(__DIR__, 4);
    }

    #[Override]
    public function getCacheDir(): string
    {
        return KernelWorkspace::dir($this->variant).'/cache';
    }

    #[Override]
    public function getLogDir(): string
    {
        return KernelWorkspace::dir($this->variant).'/log';
    }

    // @phpstan-ignore method.unused (MicroKernelTrait::registerContainerConfiguration invokes it by reflection)
    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'storm-bundle-under-test',
            'test' => true,
            'messenger' => [
                'transports' => [
                    'storm_events' => 'in-memory://',
                    'storm_events_priority' => 'in-memory://',
                    'storm_events_critical' => 'in-memory://',
                ],
            ],
        ]);

        $container->extension('doctrine', [
            'dbal' => ['url' => 'postgresql://storm:storm@127.0.0.1:1/storm_bundle_test?serverVersion=18&charset=utf8'],
        ]);

        $container->extension('storm', $this->stormConfig);

        $services = $container->services();
        foreach ($this->appServices as $id => $class) {
            $services->set($id, $class)->public();
        }
    }

    // @phpstan-ignore method.unused (the trait's route loader invokes it by reflection)
    private function configureRoutes(RoutingConfigurator $routes): void
    {
        // no routes: the bundle's Ops routes are an explicit app opt-in, not a boot requirement
    }
}
