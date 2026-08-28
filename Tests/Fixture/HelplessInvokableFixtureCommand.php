<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The shape the pass cannot serve: an invokable command declaring the attribute without extending
 * `Command`, so no `setHelp()` call exists to add to its definition. Its examples would stay
 * unreachable from a terminal, and the attribute carries no `help:` to hold them instead.
 *
 * Examples:
 *
 * ```bash
 * bin/console probe:helpless
 * ```
 */
#[AsCommand(name: 'probe:helpless', description: 'An invokable command whose examples can reach nobody')]
final class HelplessInvokableFixtureCommand
{
    public function __invoke(): int
    {
        return 0;
    }
}
