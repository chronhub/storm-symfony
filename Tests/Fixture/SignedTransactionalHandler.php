<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\TransactionalHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A handler that signs the saga settle contract: it commits or rolls back as one.
 */
#[TransactionalHandler]
#[AsMessageHandler]
final readonly class SignedTransactionalHandler
{
    public function __invoke(GrantFixtureCommand $command): void {}
}
