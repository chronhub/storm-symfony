<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use Doctrine\DBAL\Connection;
use Override;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Storm\Chronicler\Store\PostgresEventStore;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Projector\Run\ProjectionLanes;
use Storm\Projector\Store\DbalProjectionStore;
use Storm\Projector\Store\HomedProjectionStore;
use Storm\Projector\Store\ProjectionCatalog;
use Storm\Projector\Store\ProjectionLifecycleStore;
use Storm\Symfony\Tests\Boot\KernelWorkspace;
use Storm\Symfony\Tests\Boot\ReadModelStoreKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The read-model store opt-in, proven at the WIRING level: with
 * `storm.connections.read_model_store` set, everything the projector owns rides the named
 * connection while the event store keeps the default one. A real, uncached container compile
 * through {@see ReadModelStoreKernel}; no database is ever connected, since DBAL is lazy. What is
 * pinned here is which Connection INSTANCE each side carries, i.e. the exact seam the
 * two-database integration test `ReadModelStoreSplitTest` exercises against real PostgreSQL.
 *
 * The opt-OUT wiring, the alias pointing to the default connection with byte-identical
 * single-database behavior, is proven by StormBundleBootTest and the whole integration suite
 * riding it unchanged.
 */
final class ReadModelStoreBootTest extends KernelTestCase
{
    #[Override]
    public static function setUpBeforeClass(): void
    {
        // a fresh compile every run; a stale cached container would skip the bundle entirely
        new Filesystem()->remove(KernelWorkspace::dir('storm-rms-kernel'));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new ReadModelStoreKernel('test', true);
    }

    #[Test]
    public function the_projector_rides_the_named_store_connection_and_the_event_store_does_not(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $default = $container->get('doctrine.dbal.default_connection');
        // @phpstan-ignore symfonyContainer.serviceNotFound (phpstan-symfony checks the MONOREPO dev container; this connection exists in ReadModelStoreKernel's own container, booted above)
        $store = $container->get('doctrine.dbal.read_models_connection');
        self::assertInstanceOf(Connection::class, $default);
        self::assertInstanceOf(Connection::class, $store);
        self::assertNotSame($default, $store);

        // the operator and catalog facets resolve to the ONE router under a declared store...
        $catalog = $container->get(ProjectionCatalog::class);
        self::assertInstanceOf(HomedProjectionStore::class, $catalog);
        self::assertSame($catalog, $container->get(ProjectionLifecycleStore::class));

        // ...and the lanes pin each home: read models on the named store, links on the default
        $lanes = $container->get('test.lanes'); // @phpstan-ignore symfonyContainer.serviceNotFound (the fixture kernel's own test handle)
        self::assertInstanceOf(ProjectionLanes::class, $lanes);
        self::assertSame($store, $lanes->readModels->connection);
        self::assertSame($default, $lanes->events->connection);
        self::assertNotSame($lanes->events->store, $lanes->readModels->store);
        self::assertSame($store, new ReflectionProperty(DbalProjectionStore::class, 'connection')->getValue($lanes->readModels->store));

        // the link/derived refusal, pinned in REVERSE: a LinkProjection registers and
        // boots under the split; per-projection homing owns the topology, and
        // this assertion keeps anyone from reintroducing a compile-time rejection by mistake
        $registry = $container->get('test.registry'); // @phpstan-ignore symfonyContainer.serviceNotFound (the fixture kernel's own test handle)
        self::assertInstanceOf(ProjectionRegistry::class, $registry);
        self::assertTrue($registry->has('linking_fixture'), 'a link projection is SUPPORTED under the split, at bundle level');

        // ...while the event store, which reads AND appends, keeps the default one: the safe-head
        // watermark is computed where the events live, the store split never touches it
        $eventStore = $container->get(PostgresEventStore::class);
        self::assertInstanceOf(PostgresEventStore::class, $eventStore);
        self::assertSame($default, new ReflectionProperty(PostgresEventStore::class, 'connection')->getValue($eventStore));
    }
}
