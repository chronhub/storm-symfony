<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Message\EventType;
use Storm\Symfony\EventTypeScanner;

/**
 * A genuine `#[EventType]` class, the one thing EventTypeScanner must pick up
 * from this fixture dir, proving it does so while skipping the `scanner_noise` sibling.
 *
 * @see EventTypeScanner
 */
#[EventType('scanner.fixture.real-event')]
final class ScannerFixtureEvent {}
