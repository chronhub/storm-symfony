<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Story\Attribute\DispatchesUnderInboxTransaction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Signed the contract but no message class is derivable; the build must fail loud.
 */
#[DispatchesUnderInboxTransaction]
#[AsMessageHandler]
final readonly class BrokenGrantedHandler
{
    public function __invoke(mixed $untyped): void {}
}
