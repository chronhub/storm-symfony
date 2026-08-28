<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Message\EventType;

/** The scanned class behind the stable alias `capture.done` a fixture `#[WaitFor]` names. */
#[EventType('capture.done')]
final readonly class CaptureDone {}
