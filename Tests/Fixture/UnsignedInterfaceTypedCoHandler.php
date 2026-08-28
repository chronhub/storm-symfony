<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles the SAME message as `SignedTransactionalHandler`, `GrantFixtureCommand`, but typed on the
 * INTERFACE it implements rather than the concrete class, the shape Messenger itself dispatches
 * onto and the completeness gate must resolve by hierarchy, not by a literal name match, to see
 * this as `GrantFixtureCommand`'s silent co-handler.
 */
#[AsMessageHandler]
final readonly class UnsignedInterfaceTypedCoHandler
{
    public function __invoke(GrantFixtureContract $message): void {}
}
