<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

/*
 * Tokenizer-robustness fixture for \Storm\Symfony\EventTypeScanner: a classless file that
 * still contains `class` tokens the hand-rolled parser must NOT mistake for a declaration:
 *   (1) a `Foo::class` constant, where the `class` token is part of `::class`, and
 *   (2) an anonymous class, `new class {}` with no name.
 * The scanner must extract no class from this file and not choke. Snake-case name + no named
 * class means never autoloaded; it exists only to be read by the file scan.
 */

use Storm\Symfony\EventTypeScanner;

const SCANNER_NOISE_TYPE = EventTypeScanner::class;

function scanner_noise_anonymous(): object
{
    return new class()
    {
        public bool $noise = true;
    };
}
