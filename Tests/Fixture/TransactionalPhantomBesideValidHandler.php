<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\TransactionalHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The dangerous shape: one valid declaration keeps the grant list non-empty, so the typo'd
 * handles: on the second reaction would slip past the empty-grant guard, silently un-granted;
 * its dead-letters would escalate where the author expected a settle.
 */
#[TransactionalHandler]
#[AsMessageHandler]
final readonly class TransactionalPhantomBesideValidHandler
{
    public function __invoke(GrantFixtureCommand $command): void {}

    #[AsMessageHandler(handles: 'Storm\Symfony\Tests\Fixture\MissingByTypo')]
    public function onTypo(mixed $message): void {}
}
