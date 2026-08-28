<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\TransactionalHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A signed handler with a union-typed first parameter: EVERY named class in the union is granted.
 */
#[TransactionalHandler]
#[AsMessageHandler]
final readonly class TransactionalUnionHandler
{
    public function __invoke(GrantFixtureCommand|GrantFixtureEvent $message): void {}
}
