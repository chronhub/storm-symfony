<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Evolution\CatalogueCompatibility;
use Storm\Symfony\MessageCatalogue;

use function dirname;
use function json_decode;

/**
 * The rendered document, held on the two properties a consumer actually depends on: that it is
 * DERIVED, so two renderings of one tree agree, and that its SHAPE does not depend on what it found.
 *
 * The empty case is not a curiosity. An empty PHP map encodes as `[]` and a populated one as `{}`,
 * so a document rendered over a tree that declares nothing would have a different type for `events`
 * than every other one; a consumer would parse it happily until the first empty tree, which is
 * exactly the tree nobody tests against.
 *
 * The fixtures are scanned by pointing the root INSIDE them: the scanner skips a `tests/` segment on
 * purpose, so a fixture only reaches this when the root is below the segment. That is the same
 * liberty the scanner's own tests take, and it is what keeps production discovery free of fixtures.
 */
final class MessageCatalogueTest extends TestCase
{
    #[Test]
    public function a_declaration_is_rendered_with_its_version_and_its_former_names(): void
    {
        $events = MessageCatalogue::declarations([self::fixture('RetiredAlias')]);

        self::assertArrayHasKey('capture.settled', $events);
        self::assertSame(['capture.done'], $events['capture.settled']['replaces'], 'the former name is the half a store still carries');
        self::assertSame(1, $events['capture.settled']['version']);
    }

    #[Test]
    public function the_document_is_sorted_by_alias_so_a_diff_shows_the_change(): void
    {
        // the walk order of a filesystem is not a contract; the document exists to be diffed, and an
        // unsorted one would show a move as a change
        $events = MessageCatalogue::declarations([self::fixture('RetiredAlias'), self::fixture('Boot/Domain')]);

        self::assertSame(['capture.settled', 'storm_bundle_test.ping_happened'], array_keys($events));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_tree_that_declares_nothing_still_renders_a_map(): void
    {
        $document = json_decode(MessageCatalogue::render([dirname(__DIR__, 3).'/src/Clock']), true);

        self::assertIsArray($document);
        self::assertSame([], $document['events'], 'decoded, an empty map and an empty list are indistinguishable here');
        self::assertStringContainsString('"events": {}', MessageCatalogue::render([dirname(__DIR__, 3).'/src/Clock']), 'encoded, they are not: a list would change the document type');
    }

    #[Test]
    public function the_document_stamps_the_format_version_the_reader_refuses_on(): void
    {
        $document = json_decode(MessageCatalogue::render([self::fixture('Boot/Domain')]), true);

        self::assertIsArray($document);
        self::assertSame(CatalogueCompatibility::SCHEMA_VERSION, $document['schema_version']);
    }

    #[Test]
    public function two_renderings_of_one_tree_are_the_same_bytes(): void
    {
        // the property that makes the tracked copy checkable at all: a document that moved on its own
        // would fail its drift test on a run that changed nothing
        $paths = [self::fixture('RetiredAlias')];

        self::assertSame(MessageCatalogue::render($paths), MessageCatalogue::render($paths));
    }

    private static function fixture(string $under): string
    {
        return dirname(__DIR__, 3).'/src/Symfony/Tests/'.$under;
    }
}
