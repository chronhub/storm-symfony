<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\RetiredAlias;

use Storm\Message\EventType;

/**
 * The same event after a rename: `capture.done` is a FORMER spelling it still reads from the store,
 * and `capture.settled` is what it is delivered as. A `#[WaitFor]` naming the former one resolves in
 * the mapper and never matches a delivered event, so the routing pass must refuse it rather than
 * subscribe to a spelling nothing arrives under.
 *
 * Its own directory, like the collision fixture: `capture.done` is claimed here as a FORMER spelling
 * and by `CaptureDone` as its current one, which is a refusal the scan owes, so the two cannot share a
 * scanned path.
 */
#[EventType('capture.settled', replaces: ['capture.done'])]
final readonly class RenamedCaptureDone {}
