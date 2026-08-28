<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles `GrantFixtureEvent`, unrelated by hierarchy to `GrantFixtureCommand`: neither implements
 * nor extends the other. The completeness gate must never flag this handler as a co-handler of a
 * granted `GrantFixtureCommand`; only `is_a()` on the declared type decides that, not its mere
 * presence, unsigned, somewhere in the container.
 */
#[AsMessageHandler]
final readonly class UnsignedUnrelatedEventHandler
{
    public function __invoke(GrantFixtureEvent $event): void {}
}
