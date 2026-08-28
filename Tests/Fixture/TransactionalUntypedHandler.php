<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\TransactionalHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Signed the settle contract but no message class is derivable, the first parameter carries no
 * type at all; the build must fail loud instead of compiling a silently empty grant.
 */
#[TransactionalHandler]
#[AsMessageHandler]
final readonly class TransactionalUntypedHandler
{
    public function __invoke($message): void {} // @phpstan-ignore missingType.parameter (deliberate: exercises the no-type match arm)
}
