<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function bin2hex;
use function dirname;
use function getmypid;
use function is_dir;
use function random_bytes;
use function register_shutdown_function;
use function rmdir;
use function unlink;

/**
 * The scratch space the test kernels compile into, one per process, swept when the process exits.
 *
 * A compiled container is a copy of the tree it was built from, and the resources Symfony tracks
 * follow class signatures, never method bodies. One shared directory therefore makes concurrent
 * processes fight over a single container file: whoever writes last decides what the others boot,
 * a half-written one takes an unrelated test down with it, and the tests that wipe the space in
 * their setUp pull it out from under a kernel booting next door. Each outcome reads as a verdict
 * on the code while being a verdict on the race, which is what a mutation run turns into noise.
 *
 * A variant keeps its own subdirectory inside the process space, so a kernel built from one
 * configuration never boots another's container.
 */
final class KernelWorkspace
{
    /**
     * The directory a kernel compiles into, optionally under a named variant.
     */
    public static function dir(string $variant = ''): string
    {
        $root = dirname(__DIR__, 4).'/tmp/storm-test-kernel/'.self::processId();

        return $variant === '' ? $root : $root.'/'.$variant;
    }

    /**
     * Remove a directory and everything under it, best effort: a leftover costs disk, while a throw
     * from a shutdown handler would fail a suite that has already passed.
     */
    public static function sweep(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }

    /**
     * The identity of this process's space, minted once and registered for sweeping.
     *
     * The pid alone would not do: a process that exits frees its number for the next one, which
     * would then inherit a container built from another tree.
     */
    private static function processId(): string
    {
        static $id = null;

        if ($id === null) {
            $id = getmypid().'-'.bin2hex(random_bytes(4));
            $dir = dirname(__DIR__, 4).'/tmp/storm-test-kernel/'.$id;

            register_shutdown_function(static fn () => self::sweep($dir));
        }

        return $id;
    }
}
