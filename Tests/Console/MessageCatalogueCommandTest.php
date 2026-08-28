<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Symfony\Console\MessageCatalogueCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function dirname;
use function json_decode;
use function sys_get_temp_dir;
use function unlink;

/**
 * The command's own layer: where the document goes, and what it says when it cannot go there.
 *
 * The rendering is pinned in `MessageCatalogueTest` and is not re-asserted here; what this holds is
 * the pair of destinations, and the refusal that names a directory rather than a write. A command
 * answering "could not write" for a path whose directory does not exist sends someone auditing
 * permissions on a typo.
 */
final class MessageCatalogueCommandTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            @unlink($path);
        }
    }

    #[Test]
    public function it_prints_the_document_when_no_destination_is_given(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $document = json_decode($tester->getDisplay(), true);
        self::assertIsArray($document);
        self::assertArrayHasKey('capture.settled', $document['events']);
    }

    #[Test]
    public function it_writes_the_document_and_says_how_many_aliases_it_holds(): void
    {
        $target = sys_get_temp_dir().'/storm-catalogue-written.json';
        $this->written[] = $target;

        $tester = $this->tester();
        $tester->execute(['--write' => $target]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1 aliases', $tester->getDisplay());

        $document = json_decode((string) file_get_contents($target), true);
        self::assertIsArray($document);
        self::assertArrayHasKey('capture.settled', $document['events']);
    }

    #[Test]
    public function a_destination_whose_directory_does_not_exist_is_named_as_such(): void
    {
        $tester = $this->tester();
        $tester->execute(['--write' => sys_get_temp_dir().'/storm-no-such-dir/catalogue.json']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('no such directory', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new MessageCatalogueCommand([dirname(__DIR__, 4).'/src/Symfony/Tests/RetiredAlias']));
    }
}
