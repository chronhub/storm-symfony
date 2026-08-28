<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\AliasCollision;

use Storm\Message\EventType;

/** A second claimant of `capture.done`, the alias collision the resolution must refuse. */
#[EventType('capture.done')]
final readonly class CaptureDoneRival {}
