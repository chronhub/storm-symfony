<?php

declare(strict_types=1);

namespace Storm\Symfony\Console;

use JsonException;
use Override;
use Storm\Symfony\Describe\StormDescriptor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders the compiled Storm wiring as canonical JSON; it answers "what is wired?", never
 * "where is it at?". The assembly lives in `StormDescriptor`; this command is only the JSON
 * rendering and the `--section` filter, so the HTTP twin can share the exact same document. By
 * the descriptor's contract it answers on a kernel with no reachable database: everything it
 * renders is an in-memory registry or a compiled parameter.
 *
 * The output is stable across runs, sorted lists and fixed record shapes, so it is diffable in a
 * CI and consumable by a build tool; `meta.schema_version` versions the FORMAT.
 *
 * Examples:
 *
 * ```bash
 * # the full description document
 * bin/console storm:describe
 * ```
 *
 * ```bash
 * # one section only, e.g. the projection topology
 * bin/console storm:describe --section=projections
 * ```
 *
 * @see StormDescriptor the document and its sources
 */
#[AsCommand(name: 'storm:describe', description: 'Describe the compiled Storm wiring as canonical JSON — static, never live.')]
final class DescribeCommand extends Command
{
    public function __construct(private readonly StormDescriptor $descriptor)
    {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        implode(', ', StormDescriptor::SECTIONS)
            |> (static fn ($x) => sprintf('Render one section only (%s)', $x))
            |> (fn ($x) => $this->addOption('section', null, InputOption::VALUE_REQUIRED, $x));
    }

    /**
     * {@inheritDoc}
     *
     * @throws JsonException never in practice: the document is scalars and arrays end to end
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $section */
        $section = $input->getOption('section');

        if ($section !== null && ! in_array($section, StormDescriptor::SECTIONS, true)) {
            implode(', ', StormDescriptor::SECTIONS)
                |> (static fn ($x) => sprintf('Unknown section "%s". Valid sections: %s.', $section, $x))
                |> new SymfonyStyle($input, $output)->error(...);

            return Command::INVALID;
        }

        $document = $this->descriptor->describe();

        if ($section !== null) {
            // keep the section under its key: the fragment stays self-describing
            $document = [$section => $document[$section]];
        }

        $output->writeln(json_encode(
            $document,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return Command::SUCCESS;
    }
}
