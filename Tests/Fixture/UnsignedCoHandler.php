<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles the SAME message as `SignedTransactionalHandler` without signing `#[TransactionalHandler]`
 * The silent co-handler the completeness gate must refuse to let a signer sign for.
 */
#[AsMessageHandler]
final readonly class UnsignedCoHandler
{
    public function __invoke(GrantFixtureCommand $command): void {}
}
