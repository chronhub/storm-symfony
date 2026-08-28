<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot;

use Doctrine\DBAL\Connection;
use Storm\Chronicler\Record\EventRecord;
use Storm\Projector\Definition\AsProjection;
use Storm\Projector\Definition\QueryProjection;

/**
 * A no-dependency `#[AsProjection]` carrier so the bundle's attribute autoconfiguration has
 * something real to tag; the registry indexes it by name at boot.
 *
 * @implements QueryProjection<int>
 */
#[AsProjection]
final class CountingProjection implements QueryProjection
{
    private int $events = 0;

    public function name(): string
    {
        return 'storm_bundle_test_counter';
    }

    public function apply(EventRecord $event, Connection $tx): bool
    {
        $this->events++;

        return true;
    }

    public function getState(): int
    {
        return $this->events;
    }

    public function reset(): void
    {
        $this->events = 0;
    }
}
