<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\ScanFixture\tests;

use Storm\Message\EventType;

/**
 * Lives under a lowercase `tests/` segment: the skip is case-insensitive, an app tree spelling
 * the directory `tests/` as often as `Tests/`, so this must vanish from the scan exactly like its
 * uppercase sibling.
 */
#[EventType('scan.tests-hidden-lower')]
final class LowercaseTestsEvent {}
