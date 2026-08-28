<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Override;
use Storm\Symfony\StormBundle;
use Storm\Symfony\Tests\Fixture\Article;
use Storm\Symfony\Tests\Fixture\ArticleId;
use Storm\Symfony\Tests\StormBundleBootTest;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The bundle loaded AS AN APP, minimally: StormBundle + its REAL dependencies, FrameworkBundle
 * and DoctrineBundle, and nothing more: no Twig, no Profiler, no monorepo config/. A fatter kernel
 * would test Symfony, not the bundle; the monorepo's own app config could paper over a wiring
 * hole the bundle ships; the assembled-app angle stays with the integration suite.
 *
 * Every convention the MicroKernelTrait reads from disk is overridden inline: bundles, container
 * config, routes, so booting this kernel exercises ONLY what the bundle declares. The doctrine
 * DSN points at a closed port and is never connected, since DBAL is lazy and the container compiles
 * without a database. The `storm_events` transport is the one piece of app-side contract the
 * bundle documents as required, declared in-memory here, proving the documented minimum is the
 * actual minimum.
 *
 * @see StormBundleBootTest
 */
final class StormTestKernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Its own variant, like every other kernel of the suite: compiling into the process ROOT put this
     * container beside every variant's subdirectory, so a class sweeping "its" space took all of them.
     */
    private const string WORKSPACE = 'storm-test-kernel';

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
        return KernelWorkspace::dir(self::WORKSPACE).'/cache';
    }

    #[Override]
    public function getLogDir(): string
    {
        return KernelWorkspace::dir(self::WORKSPACE).'/log';
    }

    // @phpstan-ignore method.unused (MicroKernelTrait::registerContainerConfiguration invokes it by reflection)
    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'storm-bundle-under-test',
            'test' => true,
            'messenger' => [
                // the bundle's documented app-side requirement: the outbox handoff transport, plus the
                // OPTIONAL finish-over-start EVENT lane, declared so the boot test exercises its wiring,
                // and one criticality split of that lane, level 40
                'transports' => [
                    'storm_events' => 'in-memory://',
                    'storm_events_priority' => 'in-memory://',
                    'storm_events_priority_1' => 'in-memory://',
                    'storm_events_critical' => 'in-memory://',
                ],
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
            // opt into the optional priority EVENT lane, so the boot test proves it wires and the publisher
            // gets the saga-awaited set %storm.saga.routing_events% + the lane sender; into its criticality
            // split, AwaitingWorkflow's laned wait compiled into %storm.saga.signal_lanes%; and into the
            // correlation-hash sharding of the default lane, the LIST form giving a ShardedSender
            'outbox' => [
                'priority_events_transport' => ['storm_events_priority', 'storm_events_priority_1'],
                'signal_lanes' => [40 => 'storm_events_critical'],
            ],
            // one saga command-priority lane, so the published %storm.saga.priority_lanes% carries a
            // real record for describe; the policy only ever STAMPS the transport name, nothing
            // resolves it at boot, and the name reuses a transport declared above anyway
            'saga' => [
                'priority' => ['lanes' => [10 => 'storm_events_priority']],
            ],
        ]);

        // app-side services, autoconfigured; the extension-point proofs. One carrier per
        // discovery channel: the interface implementations, enricher, upcaster, health check,
        // and the three attributes #[Workflow] + its activity, #[AsProjection], #[AsUpcaster];
        // the workflow must be VALID, the registry reflects it into a definition eagerly at boot.
        $services = $container->services();
        $services->set(StampingEnricher::class)->autoconfigure();
        $services->set(PingWorkflow::class)->autoconfigure();
        $services->set(AwaitingWorkflow::class)->autoconfigure();
        $services->set(ExternalWaitWorkflow::class)->autoconfigure();
        $services->set(NoopActivity::class)->autoconfigure();
        $services->set(CountingProjection::class)->autoconfigure();
        $services->set(AddCurrencyUpcaster::class)->autoconfigure();
        $services->set(AlwaysUpCheck::class)->autoconfigure();

        // the describe/grant carriers: a filtered projection whose declared class feeds the
        // catalog cross-reference, and one signed handler per grant pass so both compiled grant
        // parameters carry a real message class
        $services->set(PingReadModel::class)->autoconfigure();
        $services->set(TransactionalPongHandler::class)->autoconfigure();
        $services->set(InboxDispatchingPingHandler::class)->autoconfigure();

        // an "app" recipe with NO tag/attribute; the boot proof that implementing the interface
        // alone is discovered, the bundle's global autoconfiguration, not the package's local rule
        $services->set(ProbeRecipe::class)->autoconfigure();
        $services->alias('test.live_query_recipes', \Storm\LiveQuery\Recipe\RecipeRegistry::class)->public();

        // an "app" commit listener with NO tag/attribute; the boot proof that the port alias is
        // the composite fan-out collecting every implementation, never a single claimed slot
        $services->set(RecordingCommitListener::class)->autoconfigure();
        $services->alias('test.commit_listeners', \Storm\Projector\Run\CompositeProjectionCommitListener::class)->public();
    }

    // @phpstan-ignore method.unused (the trait's route loader invokes it by reflection)
    private function configureRoutes(RoutingConfigurator $routes): void
    {
        // no routes: the bundle's Ops routes are an explicit app opt-in, not a boot requirement
    }
}
