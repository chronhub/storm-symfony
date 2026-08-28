<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A command with nothing to harvest: the pass must leave its definition alone rather than write an
 * empty help onto it. The charter asks every real command for an examples block; a third-party
 * command sharing the tag owes it nothing, and this stands in for one.
 */
#[AsCommand(name: 'probe:silent', description: 'A command carrying no examples block at all')]
final class SilentFixtureCommand extends Command
{
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return Command::SUCCESS;
    }
}
