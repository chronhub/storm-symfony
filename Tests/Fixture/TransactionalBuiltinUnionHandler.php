<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\TransactionalHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A legal union mixing a builtin with a message class: the builtin is signature noise, skipped at
 * collection, never mistaken for an unresolvable message candidate.
 */
#[TransactionalHandler]
#[AsMessageHandler]
final readonly class TransactionalBuiltinUnionHandler
{
    public function __invoke(string|GrantFixtureCommand $message): void {}
}
