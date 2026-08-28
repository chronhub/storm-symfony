<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An UNSIGNED co-handler of `GrantFixtureCommand` whose union names an unresolvable class FIRST.
 * The lenient roster derivation skips the unresolvable candidate; the valid one behind it still has
 * to reach the roster, or the completeness gate would never learn this handler shares the message.
 */
#[AsMessageHandler]
final readonly class UnsignedPhantomUnionCoHandler
{
    // @phpstan-ignore class.notFound (the unresolvable name IS the fixture: a renamed message left standing in a signature)
    public function __invoke(MissingByTypo|GrantFixtureCommand $message): void {}
}
