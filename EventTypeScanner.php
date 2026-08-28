<?php

declare(strict_types=1);

namespace Storm\Symfony;

use ReflectionClass;
use Storm\Chronicler\Evolution\MappedEventTypeMapper;
use Storm\Message\EventType;
use Storm\Symfony\Compiler\RegisterPersonalDataPass;
use Symfony\Component\Finder\Finder;

/**
 * Build-time scan: finds the classes carrying a given class-target attribute. `#[EventType]` by
 * default, so the `MappedEventTypeMapper` can build its alias/class reverse map; the same walk
 * serves `#[Personal]` for the compiled personal-data map.
 *
 * Domain events aren't services, being value objects built by `fromPayload`, so the usual
 * tag-autoconfiguration doesn't reach them; hence a file scan. Run once per compiler pass; the
 * resulting class list is baked into the container.
 *
 * @see \Storm\Chronicler\Evolution\MappedEventTypeMapper
 * @see RegisterPersonalDataPass
 */
final class EventTypeScanner
{
    /**
     * @param  array<array-key, string>  $paths  directories to scan; non-existent ones are ignored
     * @param  class-string  $attribute  the class-target attribute that marks a hit
     * @return list<class-string>
     */
    public function scan(array $paths, string $attribute = EventType::class): array
    {
        $dirs = array_filter($paths, is_dir(...));

        if ($dirs === []) {
            return [];
        }

        $classes = [];

        // Skip test dirs: production event discovery must never ingest test fixtures; a
        // deliberate-collision fixture would poison the alias map. Case-insensitive, since an app
        // tree spells the segment `tests/` as often as `Tests/`. The scan walks the filesystem
        // + PSR-4, so it can't lean on composer's `exclude-from-classmap`; it needs its own skip.
        foreach (Finder::create()->files()->in($dirs)->name('*.php')->notPath('#(^|/)tests/#i') as $file) {
            foreach (self::classesInFile($file->getPathname()) as $class) {
                if (class_exists($class) && new ReflectionClass($class)->getAttributes($attribute) !== []) {
                    $classes[$class] = true;
                }
            }
        }

        return array_keys($classes);
    }

    /**
     * EVERY named class of the file, never just the first: a helper class, a second event, or an
     * anonymous class ahead of an `#[EventType]` declaration must not make it vanish from the alias
     * map; a first-class-only walk would surface that hole only at the first deserialize, as an
     * `UnknownEventType`. Anonymous classes are skipped and the walk continues. Enum declarations
     * count too: a backed enum is a legal attribute carrier and a representable event, and an
     * `enum`-blind walk would drop it from the map with the same silent symptom.
     *
     * @return list<class-string>
     */
    private static function classesInFile(string $path): array
    {
        $code = file_get_contents($path);

        // Defensive read guard. Finder only ever hands us existing files, so this triggers only if
        // one becomes unreadable between listing and reading, a deletion or permission change; an IO
        // race no unit test can reproduce faithfully. We skip the file rather than crash the scan.
        // @codeCoverageIgnoreStart
        if ($code === false) {
            return [];
        }
        // @codeCoverageIgnoreEnd

        $tokens = token_get_all($code);
        $count = count($tokens);
        $namespace = '';
        $found = [];

        foreach ($tokens as $i => $iValue) {
            $token = $iValue;

            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = self::nameAfter($tokens, $i, $count);
            }

            if ($token[0] === T_CLASS || $token[0] === T_ENUM) {
                $name = self::nameAfter($tokens, $i, $count);

                // No name, so nothing is declared here: an anonymous class, or the `class` of a
                // `::class` constant, which the grammar never lets an identifier follow. Skipping
                // on the empty name covers both, and the rest of the file still gets walked.
                if ($name === '') {
                    continue;
                }

                /** @var class-string $fqcn */
                $fqcn = $namespace === '' ? $name : $namespace.'\\'.$name;

                $found[] = $fqcn;
            }
        }

        return $found;
    }

    /**
     * The identifier that immediately follows a `namespace` or `class` keyword, or '' when none does,
     * as in an anonymous class such as `new class {`, `new class(`, or `new class extends`.
     *
     * Only whitespace/comments may sit between the keyword and the name, so we skip those, then the
     * FIRST significant token decides: a name token starts the identifier; anything else means there
     * is no name. It must NOT keep scanning past a non-name token; otherwise an anonymous class would
     * borrow the first identifier in its body, a property type or a parent name, as its own name.
     *
     * @param  array<int, array{int, string, int}|string>  $tokens
     */
    private static function nameAfter(array $tokens, int $from, int $count): string
    {
        $name = '';

        for ($j = $from + 1; $j < $count; $j++) {
            $token = $tokens[$j];

            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                $name .= $token[1];

                continue;
            }

            // Padding, while the name has not started yet.
            if ($name === '' && is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            break; // the name ended at `{`, `(`, `extends`, `;` or similar, or never began, an anonymous class
        }

        return $name;
    }
}
