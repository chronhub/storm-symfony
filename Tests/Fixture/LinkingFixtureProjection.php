<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Doctrine\DBAL\Connection;
use Storm\Chronicler\Record\EventRecord;
use Storm\Projector\Definition\LinkProjection;
use Storm\Stream\StreamName;

/**
 * A minimal `LinkProjection` SHAPE, registered by `ReadModelStoreKernel` as the boot-level pin of
 * the SUPPORTED topology: a link projection lives happily under the read-model store split, since
 * per-projection homing places link producers on the events side via `ProjectionHome`. None of
 * this ever runs; the compile accepting it is the proof.
 */
final class LinkingFixtureProjection implements LinkProjection
{
    public function name(): string
    {
        return 'linking_fixture';
    }

    public function generation(): int
    {
        return 1;
    }

    public function categories(): array
    {
        return ['account'];
    }

    public function eventTypes(): array
    {
        return [];
    }

    public function targetStream(): StreamName
    {
        return new StreamName('linking-fixture-target');
    }

    public function initialize(Connection $tx): void {}

    public function clear(Connection $tx): void {}

    public function drop(Connection $tx): void {}

    public function apply(EventRecord $event, Connection $tx): bool
    {
        return true;
    }
}
