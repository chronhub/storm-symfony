<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot;

use Doctrine\DBAL\Connection;
use Storm\Chronicler\Record\EventRecord;
use Storm\Projector\Definition\AsProjection;
use Storm\Projector\Definition\FilteredProjection;
use Storm\Projector\Definition\ReadModel;
use Storm\Symfony\Tests\Boot\Domain\PingHappened;

/**
 * A `FilteredProjection` carrier with a DECLARED event-class selection, so the describe document
 * has a real projection-side consumer to cross-reference: the `PingHappened` entry must list this
 * name under `consumed_by.projections`. The lifecycle methods stay no-ops and nothing here may
 * touch the connection; the descriptor's no-DB contract is proven on this kernel.
 */
#[AsProjection]
final class PingReadModel implements FilteredProjection, ReadModel
{
    public function name(): string
    {
        return 'storm_bundle_ping_rm';
    }

    public function apply(EventRecord $event, Connection $tx): bool
    {
        return true;
    }

    public function initialize(Connection $tx): void {}

    public function clear(Connection $tx): void {}

    public function drop(Connection $tx): void {}

    public function generation(): int
    {
        return 1;
    }

    public function categories(): array
    {
        return ['article'];
    }

    public function eventTypes(): array
    {
        return [PingHappened::class];
    }
}
