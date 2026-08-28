<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot\SagaAbsent;

use Override;
use Storm\Symfony\StormBundle as StormBundleBase;

/**
 * The real bundle with Saga made ABSENT: the split-world state the monorepo tree cannot produce,
 * since every sibling package directory exists there. Named `StormBundle` on purpose, so the
 * derived extension alias stays `storm` and a kernel configures it identically to the real one.
 */
final class StormBundle extends StormBundleBase
{
    /**
     * {@inheritDoc}
     *
     * Saga alone answers null; every other package resolves through the real lookup.
     */
    #[Override]
    protected function packageDir(string $module): ?string
    {
        return $module === 'Saga' ? null : parent::packageDir($module);
    }
}
