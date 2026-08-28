<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Saga\Engine\SagaEngine;
use Storm\Saga\Semaphore\Command\GrantSlot;
use Storm\Saga\Semaphore\Event\SemaphoreSlotGranted;
use Storm\Symfony\GrantSlotHandler;

/**
 * The bus side of a semaphore promotion: a live `GrantSlot` is delivered to the waiter as its
 * wait-state event, and one whose `expiresAt` is reached or passed is dropped before the engine
 * ever sees it.
 */
final class GrantSlotHandlerTest extends TestCase
{
    #[Test]
    public function a_live_grant_is_delivered_to_the_waiter_as_its_wait_event(): void
    {
        $engine = $this->createMock(SagaEngine::class);
        $engine->expects($this->once())->method('routeOutcome')
            ->with('w-1', self::callback(
                static fn (object $event): bool => $event instanceof SemaphoreSlotGranted && $event->resource === 'rail:visa',
            ))
            ->willReturn(true);

        $expiresAt = new PointInTime()->addSeconds(60)->toString();
        (new GrantSlotHandler($engine, $this->clock()))(new GrantSlot('rail:visa', 'payment', 'w-1', $expiresAt));
    }

    #[Test]
    #[Group('adversarial')]
    public function an_expired_grant_is_dropped_before_the_engine_ever_sees_it(): void
    {
        // the TTL owns the slot once spent: waking the waiter on a dead grant would exceed the cap
        // against the real resource while the ledger stays formally within bounds
        $engine = $this->createMock(SagaEngine::class);
        $engine->expects($this->never())->method('routeOutcome');

        $expiresAt = new PointInTime()->subSeconds(2)->toString();
        (new GrantSlotHandler($engine, $this->clock()))(new GrantSlot('rail:visa', 'payment', 'w-1', $expiresAt));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_grant_expiring_exactly_now_is_dropped_like_a_past_one(): void
    {
        // the ledger reaps a lease AT its exact expiry instant: a sweep firing on that instant may
        // already have promoted the next waiter, so delivering on the boundary would exceed the cap
        $engine = $this->createMock(SagaEngine::class);
        $engine->expects($this->never())->method('routeOutcome');

        $now = new PointInTime;
        (new GrantSlotHandler($engine, $this->frozenClock($now)))(new GrantSlot('rail:visa', 'payment', 'w-1', $now->toString()));
    }

    /**
     * @return Clock<PointInTime>
     */
    private function frozenClock(PointInTime $now): Clock
    {
        return new
            /** @implements Clock<PointInTime> */
            readonly class($now) implements Clock
            {
                public function __construct(private PointInTime $now) {}

                public function now(): PointInTime
                {
                    return $this->now;
                }
            };
    }

    /**
     * @return Clock<PointInTime>
     */
    private function clock(): Clock
    {
        return new
            /** @implements Clock<PointInTime> */
            class() implements Clock
            {
                public function now(): PointInTime
                {
                    return new PointInTime;
                }
            };
    }
}
