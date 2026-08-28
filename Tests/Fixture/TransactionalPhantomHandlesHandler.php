<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\TransactionalHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A signed handler whose ONLY declaration names a class that does not exist, the typo'd handles:;
 * the build must fail naming the unresolvable candidate, not fall through to the empty-grant guard.
 */
#[TransactionalHandler]
#[AsMessageHandler(handles: 'Storm\Symfony\Tests\Fixture\MissingByTypo')]
final readonly class TransactionalPhantomHandlesHandler
{
    public function __invoke(mixed $message): void {}
}
