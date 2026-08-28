<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Story\Attribute\DispatchesUnderInboxTransaction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A handler wearing the attribute TWICE at class level, each naming its own method, which is the
 * shape that proves the grant walk accumulates across attributes rather than keeping the last, and
 * that a declared method is read rather than assumed to be `__invoke`.
 */
#[DispatchesUnderInboxTransaction]
#[AsMessageHandler(method: 'onCommand')]
#[AsMessageHandler(method: 'onEvent')]
final class TwiceAttributedHandler
{
    public function onCommand(GrantFixtureCommand $command): void {}

    public function onEvent(GrantFixtureEvent $event): void {}
}
