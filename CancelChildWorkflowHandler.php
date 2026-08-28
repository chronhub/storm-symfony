<?php

declare(strict_types=1);

namespace Storm\Symfony;

use Storm\Saga\Child\CancelChildWorkflow;
use Storm\Saga\Child\ChildCanceller;
use Storm\Saga\Exception\InvalidChildIdentity;
use Storm\Saga\Exception\SagaFenceBusy;
use Storm\Saga\Exception\SagaStorageFailure;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The bus side of the cascade: a `CancelChildWorkflow` relayed off the settling parent's outbox
 * lands here and is handed to the canceller. Thin on purpose: the proof of parenthood and the
 * cancel are the canceller's; this class only exists so the pure Saga package never depends on
 * Messenger.
 *
 * A held fence bubbles as retryable and the transport redelivers; the quiet no-ops and the
 * announced skips return silently, since a cascade landing after the race resolved must never
 * dead-letter, there is nothing left to settle on either side.
 */
#[AsMessageHandler]
final readonly class CancelChildWorkflowHandler
{
    public function __construct(private ChildCanceller $canceller) {}

    /**
     * @throws InvalidChildIdentity when the child row carries a malformed parent declaration
     * @throws SagaFenceBusy when a concurrent step holds the child's fence; the transport redelivers
     * @throws SagaStorageFailure when the saga storage fails
     */
    public function __invoke(CancelChildWorkflow $command): void
    {
        $this->canceller->cancel($command);
    }
}
