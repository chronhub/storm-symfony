<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Story\Attribute\DispatchesUnderInboxTransaction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A union-typed first parameter: the handler grants EVERY named class in the union.
 */
#[DispatchesUnderInboxTransaction]
#[AsMessageHandler]
final readonly class UnionTypedHandler
{
    public function __invoke(GrantFixtureCommand|GrantFixtureEvent $message): void {}
}
