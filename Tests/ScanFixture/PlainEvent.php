<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\ScanFixture;

/**
 * Deliberately carries NO #[EventType]: the scanner must skip it.
 */
final class PlainEvent {}
