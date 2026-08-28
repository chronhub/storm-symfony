<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\TransactionalHandler;
use Storm\Story\Attribute\DispatchesUnderInboxTransaction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The pair that cannot both be true: one signs an at-least-once broker send that survives a rollback,
 * the other promises nothing survives one.
 */
#[TransactionalHandler]
#[DispatchesUnderInboxTransaction]
#[AsMessageHandler]
final readonly class ContradictorySignatureHandler
{
    public function __invoke(GrantFixtureEvent $event): void {}
}
