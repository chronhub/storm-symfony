<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\ScanTokenizerEdge;

/*
 * Tokenizer edge for \Storm\Symfony\EventTypeScanner: the only `class` token in the file is part of a
 * `Foo::class` constant, isClassConstant() returns true so it is skipped, and there is no real or
 * anonymous class declaration. `classInFile` therefore walks the whole token stream without returning
 * early and falls through to its final `return null`.
 * Snake-case name + no named class means never autoloaded; it exists only to be read by the file scan.
 */

use Storm\Symfony\EventTypeScanner;

const SCAN_TOKENIZER_EDGE_TYPE = EventTypeScanner::class;
