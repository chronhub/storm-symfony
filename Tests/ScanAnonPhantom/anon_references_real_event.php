<?php

declare(strict_types=1);

// Deliberately declares the namespace where the real AliasedEvent lives, not this file's PSR-4 dir;
// the scanner reads the `namespace` token from the text, not from autoloading. The file declares NO
// class of its own; it only holds an anonymous class whose body MENTIONS AliasedEvent.
//
// Regression guard for \Storm\Symfony\EventTypeScanner::nameAfter: a walk that wanders forward
// from the `class` keyword and grabs the first identifier it finds, here `AliasedEvent`
// inside the body, returns Storm\Symfony\Tests\ScanFixture\AliasedEvent, which exists and
// carries #[EventType], so scanning THIS directory phantom-registers AliasedEvent even though its
// real declaration lives elsewhere. nameAfter instead stops at the `{` and returns '', so nothing.

namespace Storm\Symfony\Tests\ScanFixture;

function anon_phantom(): object
{
    return new class()
    {
        public ?AliasedEvent $ref = null;
    };
}
