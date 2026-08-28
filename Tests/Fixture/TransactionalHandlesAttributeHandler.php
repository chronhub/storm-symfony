<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\TransactionalHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A signed handler naming its message through the explicit `handles:`, bypassing param reflection.
 */
#[TransactionalHandler]
#[AsMessageHandler(handles: GrantFixtureCommand::class)]
final readonly class TransactionalHandlesAttributeHandler
{
    public function __invoke(mixed $message): void {}
}
