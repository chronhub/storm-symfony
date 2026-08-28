<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An UNSIGNED handler whose declaration names a class that does not exist, the typo'd handles:
 * on a handler that never signed #[TransactionalHandler]. The grant pass must leave it to
 * Messenger's own build error instead of failing with a message that blames an attribute the
 * handler does not carry.
 */
#[AsMessageHandler(handles: 'Storm\Symfony\Tests\Fixture\MissingByTypo')]
final readonly class UnsignedPhantomHandlesHandler
{
    public function __invoke(mixed $message): void {}
}
