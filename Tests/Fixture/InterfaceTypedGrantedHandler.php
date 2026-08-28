<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Story\Attribute\DispatchesUnderInboxTransaction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The interface-typed shape Messenger supports: the handler declares the CONTRACT, resolved onto
 * every implementing message at dispatch, so the compiled grant must carry the interface and the
 * runtime guard must honor it by hierarchy.
 */
#[DispatchesUnderInboxTransaction]
#[AsMessageHandler]
final readonly class InterfaceTypedGrantedHandler
{
    public function __invoke(GrantFixtureContract $message): void {}
}
