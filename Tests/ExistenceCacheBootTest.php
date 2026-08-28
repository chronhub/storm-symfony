<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use Override;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Storm\AggregateRepository\Snapshot\SnapshotDeletingStreamEraser;
use Storm\Chronicler\Directory\CategoryCachedStreamExistence;
use Storm\Chronicler\Directory\DbalStreamExistence;
use Storm\Chronicler\Erasure\CacheEvictingStreamEraser;
use Storm\Chronicler\Erasure\DbalStreamEraser;
use Storm\Symfony\Tests\Boot\ExistenceCacheKernel;
use Storm\Symfony\Tests\Boot\KernelWorkspace;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The category existence cache opt-in, proven at the WIRING level: with
 * `storm.stream_existence_cache` enabled, the eraser resolves to the evicting decorator,
 * the decorator holds THE cache that fronts the StreamExistence alias, the cache wraps the raw
 * DBAL probe, and the configured TTL reached it. A real, uncached container compile through
 * {@see ExistenceCacheKernel}; no database is ever connected, since DBAL is lazy.
 *
 * The opt-OUT wiring, the raw DBAL probe with zero cache services since the classes are excluded
 * from the module auto-load, is proven by the whole suite riding the default kernels unchanged.
 */
final class ExistenceCacheBootTest extends KernelTestCase
{
    #[Override]
    public static function setUpBeforeClass(): void
    {
        // a fresh compile every run; a stale cached container would skip the bundle entirely
        new Filesystem()->remove(KernelWorkspace::dir('storm-existence-cache-kernel'));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new ExistenceCacheKernel('test', true);
    }

    #[Test]
    public function the_enabled_cache_decorates_both_ports_and_carries_the_configured_ttl(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // the eraser rides the evicting decorator over the snapshot-deleting one over the raw DBAL
        // eraser; snapshot-deleting sits innermost, decoration priority 1, always on...
        // @phpstan-ignore symfonyContainer.serviceNotFound (the fixture kernel's own public test alias)
        $eraser = $container->get('test.stream_eraser');
        self::assertInstanceOf(CacheEvictingStreamEraser::class, $eraser);
        $snapshotDeleting = new ReflectionProperty(CacheEvictingStreamEraser::class, 'inner')->getValue($eraser);
        self::assertInstanceOf(SnapshotDeletingStreamEraser::class, $snapshotDeleting);
        self::assertInstanceOf(DbalStreamEraser::class, new ReflectionProperty(SnapshotDeletingStreamEraser::class, 'eraser')->getValue($snapshotDeleting));

        // ...and evicts into THE cache that fronts the StreamExistence alias, wrapping the raw probe
        $cache = new ReflectionProperty(CacheEvictingStreamEraser::class, 'cache')->getValue($eraser);
        self::assertInstanceOf(CategoryCachedStreamExistence::class, $cache);
        self::assertInstanceOf(DbalStreamExistence::class, new ReflectionProperty(CategoryCachedStreamExistence::class, 'inner')->getValue($cache));

        // the non-default TTL proves the knob is carried, not defaulted
        self::assertSame(45, new ReflectionProperty(CategoryCachedStreamExistence::class, 'negativeTtlSeconds')->getValue($cache));
    }
}
