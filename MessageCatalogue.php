<?php

declare(strict_types=1);

namespace Storm\Symfony;

use JsonException;
use ReflectionClass;
use Storm\Chronicler\Evolution\CatalogueCompatibility;
use Storm\Message\EventType;

use function json_encode;
use function ksort;

/**
 * Renders the durable message contract of a source tree: every declared alias with the class it
 * resolves to, its current schema version, and the former aliases that still resolve to it.
 *
 * A CLASS rather than a script body, so the rendering is callable twice: `bin/dump-message-catalogue.php`
 * writes it and a test renders the same document in memory and compares against the tracked file.
 * Without that second call the document is a file that used to be true.
 *
 * The catalogue is DERIVED and never written by hand. Its whole value is being the same declarations
 * the runtime reads, so a hand edit would make it a second source of truth that agrees with the code
 * only until someone forgets.
 *
 * Sorted by alias and by nothing else, because the document exists to be DIFFED: a stable order is
 * what makes a diff show the change instead of the walk order of a filesystem.
 *
 * An event carrying no `#[EventType]` falls back to its FQCN and has no durable contract to
 * catalogue, so it is absent; the day such an event goes on a wire it needs an alias first, which is
 * a different conversation.
 *
 * A duplicate alias needs no guard here, and the reason bounds how this may be CALLED. Two classes
 * claiming one alias are refused by `RegisterEventTypesPass` while the container compiles, so a
 * caller that boots the container cannot reach this with a colliding tree. A caller that scans
 * without booting can, and would silently keep one of the two; that is why the surface is a console
 * command and not a bare script.
 *
 * @see \Storm\Chronicler\Evolution\CatalogueCompatibility the verdict this document feeds
 */
final readonly class MessageCatalogue
{
    private const string NOTE = 'Generated from the #[EventType] declarations by storm:message:catalogue. Do not edit; regenerate it.';

    /**
     * The tracked document, pretty-printed so a review reads the change rather than a single line.
     *
     * @param  array<array-key, string>  $paths  the scan roots, the runtime's `storm.event_paths`
     *
     * `events` is cast to an object because an empty PHP map encodes as `[]` and a populated one as
     * `{}`: the document's SHAPE would then depend on whether anything was declared, and a consumer
     * parsing it would work until the day it read an empty one.
     *
     * @throws JsonException when the declarations cannot be encoded, which needs a scanned class to
     *                       carry an alias that is not valid UTF-8
     */
    public static function render(array $paths): string
    {
        $document = [
            'schema_version' => CatalogueCompatibility::SCHEMA_VERSION,
            'note' => self::NOTE,
            'events' => (object) self::declarations($paths),
        ];

        return json_encode($document, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * Every declared alias under `$paths`, keyed by the alias, which is the durable identity; the
     * class rides along as a value because it is the half that may be renamed freely.
     *
     * The paths are the caller's, and a caller that means the runtime hands `storm.event_paths`: the
     * catalogue must describe the same scan the alias map is built from, or it describes a set the
     * application does not load.
     *
     * @param  array<array-key, string>  $paths
     * @return array<string, array{class: class-string, version: int, replaces: list<string>}>
     */
    public static function declarations(array $paths): array
    {
        $events = [];

        foreach (new EventTypeScanner()->scan($paths) as $class) {
            // iterated rather than indexed-then-null-checked: the scanner returns classes BECAUSE
            // they carry the attribute, so a null guard here is a branch nothing can enter, and a
            // branch nothing can enter is code to delete rather than to excuse. `#[EventType]` is not
            // repeatable, so the loop runs once
            foreach (new ReflectionClass($class)->getAttributes(EventType::class) as $attribute) {
                $eventType = $attribute->newInstance();
                $events[$eventType->alias] = [
                    'class' => $class,
                    'version' => $eventType->version,
                    'replaces' => $eventType->replaces,
                ];
            }
        }

        ksort($events);

        return $events;
    }
}
