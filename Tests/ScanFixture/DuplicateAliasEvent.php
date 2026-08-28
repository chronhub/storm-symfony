<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\ScanFixture;

use Storm\Message\EventType;

#[EventType('test.aliased')] // deliberately collides with AliasedEvent
final class DuplicateAliasEvent {}
