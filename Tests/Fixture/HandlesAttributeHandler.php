<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Story\Attribute\DispatchesUnderInboxTransaction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The explicit shape: `#[AsMessageHandler(handles: ...)]` names the message, bypassing param
 * reflection.
 */
#[DispatchesUnderInboxTransaction]
#[AsMessageHandler(handles: GrantFixtureCommand::class)]
final readonly class HandlesAttributeHandler
{
    public function __invoke(mixed $message): void {}
}
