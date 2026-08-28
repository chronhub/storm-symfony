<?php

declare(strict_types=1);

namespace Storm\Symfony;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Marks a message as a saga-issued command: stamped by `MessengerSagaCommandPublisher` at dispatch,
 * required by `SagaCommandFailureListener` before it settles a saga on a dead-letter.
 *
 * Why a dedicated stamp: the generic `CorrelationStamp` rides on outcome events too, since they inherit
 * the saga's correlation by design, which is how routing works. A poisoned event handler would
 * dead-letter an event that still carries the correlation; settling on it would violate the "dead-letter
 * means no effect committed" invariant, because the effect SUCCEEDED and only its notification is
 * poisoned, and the rollback would refund a confirmed earlier step while the effect stands: money created.
 * Only the message the saga itself issued may carry this mark.
 *
 * It also carries the sealed message id, the outbox row's identity minted once by the `HopProtocol`: on a
 * consumer-side dead-letter the failure listener uses it to durably mark THE row `failed`, from which the
 * input `strandedByFailedEffect` re-derives, and to pair the settle with the exact command that died. The
 * id is nullable; a stamp without one falls back to the unmarked, unpaired behavior.
 *
 * @see MessengerSagaCommandPublisher
 * @see SagaCommandFailureListener
 */
final readonly class SagaIssuedStamp implements StampInterface
{
    public function __construct(
        public ?string $messageId = null,
    ) {}
}
