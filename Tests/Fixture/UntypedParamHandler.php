<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Story\Attribute\DispatchesUnderInboxTransaction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A first parameter with NO type: reflection derives nothing, the default match arm, so a signed
 * handler still fails the build loud. The untyped twin of {@see BrokenGrantedHandler}'s `mixed`.
 */
#[DispatchesUnderInboxTransaction]
#[AsMessageHandler]
final readonly class UntypedParamHandler
{
    public function __invoke($message): void {} // @phpstan-ignore missingType.parameter (deliberate: exercises the no-type match arm)
}
