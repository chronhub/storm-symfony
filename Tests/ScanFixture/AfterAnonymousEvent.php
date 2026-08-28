<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\ScanFixture;

use Storm\Message\EventType;

/*
 * An anonymous class AHEAD of a named event; a walk that returns null for the whole file on the
 * first anonymous class makes the event below vanish from the alias map.
 */

$ignored = new class() {};

#[EventType(alias: 'scan_fixture.after_anonymous')]
final class AfterAnonymousEvent {}
