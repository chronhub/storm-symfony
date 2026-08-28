<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The ordinary shape the harvest pass exists for: a class extending `Command`, carrying its
 * invocations in a docblock where no terminal can reach them.
 *
 * One example spends a literal percent sign, the character the container reads as the opening of a
 * parameter reference. No real command uses one today, so the doubling is guarded here or nowhere.
 *
 * Examples:
 *
 * ```bash
 * bin/console probe:harvestable --since 2026-01-01
 * bin/console probe:harvestable --sample 10%
 * ```
 */
#[AsCommand(name: 'probe:harvestable', description: 'A command whose help is harvested from its docblock')]
final class HarvestableFixtureCommand extends Command
{
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return Command::SUCCESS;
    }
}
