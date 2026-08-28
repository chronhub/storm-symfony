<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\TransactionalHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A signed handler whose `#[AsMessageHandler]` rides a public METHOD, not the class.
 */
#[TransactionalHandler]
final readonly class MethodLevelTransactionalHandler
{
    #[AsMessageHandler(bus: 'storm.event.bus')]
    public function onEvent(GrantFixtureEvent $event): void {}
}
