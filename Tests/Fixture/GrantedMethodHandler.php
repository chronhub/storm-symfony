<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Story\Attribute\DispatchesUnderInboxTransaction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** The method-level shape: each public method carries its own #[AsMessageHandler]. */
#[DispatchesUnderInboxTransaction]
final readonly class GrantedMethodHandler
{
    #[AsMessageHandler(bus: 'storm.event.bus')]
    public function onEvent(GrantFixtureEvent $event): void {}
}
