<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Story\Attribute\DispatchesUnderInboxTransaction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A valid `__invoke` declaration BESIDE a typo'd `handles:`, the dangerous shape: the valid one
 * keeps the candidate list non-empty, so a silent filter would grant partially and leave the
 * typo'd message's signed broker send refused at runtime, far from this wiring.
 */
#[DispatchesUnderInboxTransaction]
#[AsMessageHandler]
#[AsMessageHandler(handles: 'Storm\Symfony\Tests\Fixture\MissingByTypo')]
final readonly class InboxPhantomBesideValidHandler
{
    public function __invoke(GrantFixtureCommand $command): void {}
}
