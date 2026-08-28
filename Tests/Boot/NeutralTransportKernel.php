<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Override;
use Storm\Symfony\StormBundle;
use Storm\Symfony\Tests\NeutralTransportBootTest;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * {@see StormTestKernel}'s sibling with two `storm.neutral_transports` entries: one same-trust with
 * a bus, wired as the serializer of a real messenger transport so the compile itself proves the
 * reference resolves; one integration-edge shaped, allowlisted and busless. Boots the real bundle
 * wiring; the companion test then pins the carried bus and trust posture per instance.
 *
 * @see NeutralTransportBootTest
 */
final class NeutralTransportKernel extends BaseKernel
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
        return KernelWorkspace::dir('storm-neutral-transport-kernel').'/cache';
    }

    #[Override]
    public function getLogDir(): string
    {
        return KernelWorkspace::dir('storm-neutral-transport-kernel').'/log';
    }

    // @phpstan-ignore method.unused (MicroKernelTrait::registerContainerConfiguration invokes it by reflection)
    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'storm-neutral-transport-under-test',
            'test' => true,
            'messenger' => [
                'transports' => [
                    // the serializer reference resolving here IS part of the proof: a typo'd
                    // storm.neutral_transport.<name> fails this compile, not a production boot
                    'storm_events' => [
                        'dsn' => 'in-memory://',
                        'serializer' => 'storm.neutral_transport.storm_events',
                    ],
                ],
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
            'neutral_transports' => [
                // the same-trust posture: a bus, no allowlist (omitted = every resolvable type)
                'storm_events' => ['bus' => 'storm.event.bus'],
                // the integration-edge posture: an explicit allowlist, no bus override
                'partner_feed' => ['allowed_types' => ['bank.account_opened']],
            ],
        ]);

        // public test handles: the private per-transport services would otherwise be inlined or
        // removed and the boot test could not fetch them, the same motif as the sibling kernels
        $container->services()
            ->alias('test.neutral_storm_events', 'storm.neutral_transport.storm_events')->public()
            ->alias('test.neutral_partner_feed', 'storm.neutral_transport.partner_feed')->public();
    }

    // @phpstan-ignore method.unused (the trait's route loader invokes it by reflection)
    private function configureRoutes(RoutingConfigurator $routes): void
    {
        // no routes: the bundle's Ops routes are an explicit app opt-in, not a boot requirement
    }
}
