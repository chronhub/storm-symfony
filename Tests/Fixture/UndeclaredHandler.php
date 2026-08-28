<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Dispatches too, but never signed the contract; must NOT appear in the grants.
 */
#[AsMessageHandler]
final readonly class UndeclaredHandler
{
    public function __invoke(GrantFixtureCommand $command): void {}
}
