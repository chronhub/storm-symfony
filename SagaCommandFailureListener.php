<?php

declare(strict_types=1);

namespace Storm\Symfony;

use Storm\Saga\Engine\EffectEvidence;
use Storm\Saga\Engine\Engine;
use Storm\Saga\Engine\ExecutionReport;
use Storm\Saga\Outbox\FailedWorkflowCommands;
use Storm\Story\Stamp\CorrelationStamp;
use Storm\Support\Error\AuditDigest;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Throwable;

/**
 * The framework's dead-letter hook on the consumer side: it settles a saga whose own issued command was
 * permanently rejected by its handler, so a poisoned command can never strand the saga at its gating wait.
 *
 * On a `WorkerMessageFailedEvent` it acts only when all three hold, and returns otherwise:
 * - The failure is terminal, `WorkerMessageFailedEvent::willRetry() === false`, else Messenger still retries;
 * - The message carries a `SagaIssuedStamp`, the saga's OWN command, not an inherited outcome event;
 * - The message carries a correlation id.
 *
 * It then calls `Engine::failIssuedEffect`, which settles ONLY when something proved the effect never
 * was committed, so the engine halts at the effect-gating wait and runs any earlier confirmed compensation.
 * The engine no-ops on a stray match, when no saga matches or it is past the gating wait. The command-side
 * counterpart lives in `SagaOutboxRelay`, which dead-letters an undeliverable dispatch post-commit.
 *
 * Why the dedicated `SagaIssuedStamp` and not the generic `CorrelationStamp`: outcome EVENTS inherit the
 * saga's correlation by design, so a poisoned event handler dead-letters an envelope that still carries it.
 * Settling on that would refund a confirmed step while the effect stands, since the effect SUCCEEDED and
 * only its notification is poisoned: money created. An event dead-letter's correct reaction is
 * observability, the saga escalates its gating wait visibly and ops replays the failure transport, never a
 * settle.
 */
#[AsEventListener]
final readonly class SagaCommandFailureListener
{
    /** Local retries against a momentarily held fence; the event is one-shot, a collapsed miss would lose it. */
    private const int FENCE_ATTEMPTS = 3;

    private const int FENCE_RETRY_MICROSECONDS = 100_000;

    /**
     * @param  list<class-string>  $transactionalHandlers  the message classes whose handlers signed
     *                                                     `#[TransactionalHandler]`, compiled by
     *                                                     `GrantTransactionalHandlerPass`
     */
    public function __construct(
        private Engine $engine,
        private FailedWorkflowCommands $outbox,
        private array $transactionalHandlers = [],
    ) {}

    /**
     * Two halves, durable first. First, flip the outbox row `failed` by its sealed message id, the
     * consumer-side twin of the relay's own dead-letter mark: whatever happens next, the cleanup's
     * `strandedByFailedEffect` re-derives the settle from that row. Second, settle in-process, retrying a
     * bounded number of times when a concurrent step holds the fence; this event fires ONCE with no
     * Messenger redelivery, so a collapsed `false` would silently lose the signal, and when the retries
     * exhaust the durable half guarantees the cleanup finishes the job.
     *
     * @throws Throwable propagated while durably marking the row failed or settling the dead-lettered
     *                   effect, such as a store or serialization error or an OCC conflict; surfaced to the
     *                   Messenger worker
     */
    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return; // not terminal; Messenger will retry
        }

        $issued = $event->getEnvelope()->last(SagaIssuedStamp::class);
        if ($issued === null) {
            return; // not a saga-issued command; a dead-lettered EVENT must never settle a saga
        }

        $stamp = $event->getEnvelope()->last(CorrelationStamp::class);
        if ($stamp === null) {
            return; // no saga correlation on the failed message
        }

        // durable FIRST: the failed row survives any crash below. A stamp carrying no message id
        // leaves the relay-side mark or nothing at all; such envelopes drain out of the fleet quickly.
        // What is known about the effect, and it is not much: the handler RAN. Unless its author signed
        // the contract, nothing here says the throw took its writes with it; an HTTP call that landed
        // before the exception is exactly the effect a rollback does not undo. The engine escalates on
        // `Unknown` rather than compensating around something that may be out there.
        $evidence = $this->evidenceFor($event->getEnvelope()->getMessage());

        if ($issued->messageId !== null) {
            $this->outbox->markFailed($stamp->id, $issued->messageId, AuditDigest::digest($event->getThrowable()), $evidence);
        }

        for ($attempt = 1; $attempt <= self::FENCE_ATTEMPTS; $attempt++) {
            $report = $this->engine->failIssuedEffect($stamp->id, failedMessageId: $issued->messageId);
            if ($report !== ExecutionReport::FenceBusy) {
                return; // applied, or a benign no-op, already settled or past the wait; done either way
            }
            usleep(self::FENCE_RETRY_MICROSECONDS);
        }
        // still busy: give up loud-lessly; the durable `failed` row is the backstop the cleanup replays
    }

    /**
     * The message's own class must be granted, not a parent's: the grant is derived from what each
     * handler declares it handles, and a subclass may well be routed elsewhere.
     */
    private function evidenceFor(object $message): EffectEvidence
    {
        return in_array($message::class, $this->transactionalHandlers, true)
            ? EffectEvidence::Uncommitted
            : EffectEvidence::Unknown;
    }
}
