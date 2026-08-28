<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Story\Attribute\DispatchesUnderInboxTransaction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A handler declaring one message at class level and another on a method, the two surfaces the
 * grant walk reads in turn; what it grants is the union, never whichever surface it read last.
 */
#[DispatchesUnderInboxTransaction]
#[AsMessageHandler(method: 'onCommand')]
final class ClassAndMethodHandler
{
    public function onCommand(GrantFixtureCommand $command): void {}

    #[AsMessageHandler]
    public function onEvent(GrantFixtureEvent $event): void {}
}
