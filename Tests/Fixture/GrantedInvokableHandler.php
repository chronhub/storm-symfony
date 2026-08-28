<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Story\Attribute\DispatchesUnderInboxTransaction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** The class-level shape: #[AsMessageHandler] on the class, the message on __invoke. */
#[DispatchesUnderInboxTransaction]
#[AsMessageHandler]
final readonly class GrantedInvokableHandler
{
    public function __invoke(GrantFixtureCommand $command): void {}
}
