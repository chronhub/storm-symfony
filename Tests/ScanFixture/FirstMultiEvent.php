<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\ScanFixture;

use Storm\Message\EventType;

/*
 * One FILE, three declarations; the scan must see them ALL: a first-class-only walk lets a
 * plain helper ahead of the events hide every #[EventType] that follows, discovered only at the
 * first deserialize, as an UnknownEventType.
 */

final class PlainHelperBeforeEvents {}

#[EventType(alias: 'scan_fixture.multi_first')]
final class FirstMultiEvent {}

#[EventType(alias: 'scan_fixture.multi_second')]
final class SecondMultiEvent {}
