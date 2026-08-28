<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\ScanFixture\Nested\Tests;

use Storm\Message\EventType;

/**
 * Lives under a `Tests/` segment INSIDE a scanned path: the scanner must skip it, or a test
 * fixture would enter the production alias map.
 */
#[EventType('scan.tests-hidden-upper')]
final class UppercaseTestsEvent {}
