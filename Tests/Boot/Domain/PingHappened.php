<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot\Domain;

use Storm\Message\EventType;

/**
 * Lives in its own directory on purpose: the test kernel points `storm.event_paths` HERE, so the
 * boot test proves the #[EventType] scan runs on the configured path, not on the kernel default.
 */
#[EventType('storm_bundle_test.ping_happened')]
final class PingHappened {}
