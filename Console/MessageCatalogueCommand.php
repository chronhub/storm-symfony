<?php

declare(strict_types=1);

namespace Storm\Symfony\Console;

use JsonException;
use Override;
use Storm\Symfony\MessageCatalogue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function dirname;
use function file_put_contents;
use function is_dir;
use function is_writable;

/**
 * Renders this application's durable message contract: every `#[EventType]` alias with its class,
 * its current schema version, and the former aliases it still answers to.
 *
 * It scans `storm.event_paths`, the same parameter `RegisterEventTypesPass` builds the alias map
 * from. That is not a convenience: a catalogue derived from a different set would describe events
 * the application does not load, and the mismatch would surface as a gate approving a contract
 * nobody runs.
 *
 * A COMMAND and not a script, deliberately. It cannot run without a compiled container, and
 * compiling one is where `RegisterEventTypesPass` refuses two classes claiming one alias. A bare
 * script scanning the same tree would meet a collision this can never meet, and would silently keep
 * one of the two; the surface is what makes the guard unavoidable rather than remembered.
 *
 * Commit the written document. Its value is having a PREVIOUS revision: that is what
 * `storm:message:check` compares against to say whether a change would break a store already
 * holding the old shapes.
 *
 * Examples:
 *
 * ```bash
 * # read it
 * bin/console storm:message:catalogue
 * ```
 *
 * ```bash
 * # write the tracked document, then commit it with the change it describes
 * bin/console storm:message:catalogue --write=docs/message-catalogue.json
 * ```
 *
 * @see MessageCatalogue the rendering
 * @see \Storm\Chronicler\Console\MessageCheckCommand the verdict this document feeds
 */
#[AsCommand(name: 'storm:message:catalogue', description: 'Render the durable message contract: every #[EventType] alias, its version and its former names.')]
final class MessageCatalogueCommand extends Command
{
    /**
     * @param  array<array-key, string>  $eventPaths  the runtime's `storm.event_paths`
     */
    public function __construct(
        private readonly array $eventPaths,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('write', null, InputOption::VALUE_REQUIRED, 'Write the document to this path instead of printing it');
    }

    /**
     * {@inheritDoc}
     *
     * @throws JsonException when a declared alias is not valid UTF-8, so the document cannot encode
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $document = MessageCatalogue::render($this->eventPaths);
        $target = $input->getOption('write');

        if (! is_string($target) || $target === '') {
            $output->write($document);

            return Command::SUCCESS;
        }

        $directory = dirname($target);

        if (! is_dir($directory) || ! is_writable($directory)) {
            // named before the write rather than after it: "there is no directory there" is a
            // different thing to fix from "the write failed", and a command that says the second
            // when it means the first sends someone looking at permissions on a path that is a typo
            $output->writeln(sprintf('<error>Cannot write into "%s": no such directory, or it is not writable.</error>', $directory));

            return Command::FAILURE;
        }

        // @codeCoverageIgnoreStart
        // What is left once the directory is known good is a write that fails anyway: a full disk, a
        // revoked permission between the check and the write. No unit test reproduces those
        // faithfully, and the guard above is the half that a typo actually hits.
        if (file_put_contents($target, $document) === false) {
            $output->writeln(sprintf('<error>Could not write %s.</error>', $target));

            return Command::FAILURE;
        }
        // @codeCoverageIgnoreEnd

        $output->writeln(sprintf('<info>Wrote %s (%d aliases).</info>', $target, count(MessageCatalogue::declarations($this->eventPaths))));

        return Command::SUCCESS;
    }
}
