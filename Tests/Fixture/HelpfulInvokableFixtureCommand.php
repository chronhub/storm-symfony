<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The same shape done right: the attribute carries the help itself, so an operator reaches the
 * invocations that the pass has no way to install.
 *
 * Examples:
 *
 * ```bash
 * bin/console probe:helpful
 * ```
 */
#[AsCommand(
    name: 'probe:helpful',
    description: 'An invokable command carrying its own help',
    help: "Examples:\n\n  bin/console probe:helpful",
)]
final class HelpfulInvokableFixtureCommand
{
    public function __invoke(): int
    {
        return 0;
    }
}
