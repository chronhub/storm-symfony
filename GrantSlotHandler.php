<?php

declare(strict_types=1);

namespace Storm\Symfony;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Saga\Engine\SagaOutcomeDelivery;
use Storm\Saga\Exception\SagaFenceBusy;
use Storm\Saga\Exception\SagaOutcomeNotYetApplicable;
use Storm\Saga\Semaphore\Command\GrantSlot;
use Storm\Saga\Semaphore\Event\SemaphoreSlotGranted;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * The bus side of a semaphore promotion: a `GrantSlot` relayed off the semaphore's command outbox
 * lands here and is delivered to the WAITER as its wait-state event. Thin on purpose, the mirror of
 * {@see StartChildWorkflowHandler}: it exists so the pure Saga package never depends on Messenger.
 *
 * It routes by correlation through `routeOutcome`, the same seam the generic outcome router uses, so
 * the delivery inherits the honest failure grammar: an early arrival or a busy fence redelivers, a
 * waiter already gone consumes quietly, and a waiter resting elsewhere is a discarded routing fact.
 * A waiter that never consumes its grant leaves the slot to the TTL, the semaphore's normal
 * at-least-once weather.
 *
 * One gate is the handler's own: a grant no longer strictly alive at delivery, its `expiresAt`
 * reached or passed, is dropped, acked, never routed. The boundary mirrors the ledger's, where a
 * lease is over AT its exact expiry instant: a sweep firing on that instant may have expropriated
 * the slot and promoted the next waiter, so waking the waiter on the dead grant would put one
 * caller more than the cap on the resource in the real world while the ledger stays formally
 * within bounds. The waiter's own patience deadline remains the liveness that decides what a grant
 * that never came means.
 */
#[AsMessageHandler]
final readonly class GrantSlotHandler
{
    /**
     * @param  Clock<PointInTime>  $clock
     */
    public function __construct(
        private SagaOutcomeDelivery $engine,
        private Clock $clock,
    ) {}

    /**
     * @throws SagaFenceBusy when a concurrent step holds the waiter's fence; the transport redelivers
     * @throws SagaOutcomeNotYetApplicable when the grant arrives ahead of the waiter's wait; the transport redelivers
     * @throws Throwable propagated from the routing, which declares its own tail; the two clauses
     *                   above are the ones a transport acts on, never the whole set
     */
    public function __invoke(GrantSlot $command): void
    {
        if (! PointInTime::fromStorage($command->expiresAt)->isAfter($this->clock->now())) {
            return; // dead on arrival: the TTL owns the slot now, redelivering could not help
        }

        $this->engine->routeOutcome(
            $command->waiterCorrelation,
            new SemaphoreSlotGranted($command->resource, $command->expiresAt),
        );
    }
}
