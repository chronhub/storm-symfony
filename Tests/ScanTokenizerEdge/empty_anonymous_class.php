<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\ScanTokenizerEdge;

/*
 * Tokenizer edge for \Storm\Symfony\EventTypeScanner: an anonymous class with an EMPTY body as the
 * file's final construct. `nameAfter` scans forward from the `class` keyword and reaches EOF without
 * ever hitting a T_STRING, so it returns '' and `classInFile` takes its anonymous-class guard,
 * `return null; // anonymous class`.
 *
 * Contrast scanner_noise.php, whose anon body `public bool $noise` leaks the property type `bool`
 * as a bogus class name, so that fixture is filtered later by class_exists(), NOT by this guard.
 * Snake-case name + no named class means never autoloaded; it exists only to be read by the file scan.
 */
function scan_tokenizer_edge_anonymous(): object
{
    return new class() {};
}
