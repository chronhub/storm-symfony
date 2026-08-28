<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\ScanFixture;

use Storm\Message\EventType;

/**
 * An event modeled as a backed enum: a legal `#[EventType]` carrier, `fromPayload` and `toPayload`
 * both being declarable on an enum, so the scan must see `enum` declarations as it sees `class`
 * ones.
 */
#[EventType('scan.enum')]
enum ScanEnumEvent: string
{
    case Occurred = 'occurred';
}
