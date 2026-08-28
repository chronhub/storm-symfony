<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Storm\Contracts\Message\MessageContext;
use Storm\Saga\Engine\SagaEngine;
use Storm\Symfony\SagaOutcomeRouter;
use Storm\Symfony\Tests\Fixture\CorrelatedEvent;

/**
 * The generic saga event router: on a routing event it resolves the correlation key, a class in the
 * correlate-by map routing by its declared payload field, declared-wins, and everything else by the
 * propagated correlation id, then delegates to the SagaEngine port. It skips the event entirely when
 * no key resolves, i.e. published outside any saga. A pure unit over the two collaborators, the
 * engine port and the message context.
 */
final class SagaOutcomeRouterTest extends TestCase
{
    #[Test]
    public function skips_an_event_that_carries_no_correlation_id(): void
    {
        $context = $this->createStub(MessageContext::class);
        $context->method('correlationId')->willReturn(null); // published outside any saga

        $engine = $this->createMock(SagaEngine::class);
        $engine->expects($this->never())->method('routeOutcome'); // never touched

        new SagaOutcomeRouter($engine, $context)(new stdClass);
    }

    #[Test]
    public function routes_an_event_with_a_correlation_id_to_the_engine(): void
    {
        $event = new stdClass;

        $context = $this->createStub(MessageContext::class);
        $context->method('correlationId')->willReturn('corr-1');

        $engine = $this->createMock(SagaEngine::class);
        $engine->expects($this->once())->method('routeOutcome')->with('corr-1', $event)->willReturn(true);

        new SagaOutcomeRouter($engine, $context)($event);
    }

    #[Test]
    public function propagates_an_engine_failure_rather_than_swallowing_it(): void
    {
        // the fence seam: a held fence, SagaFenceBusy, or store error is deliberately retryable; it must
        // surface to Messenger, never be silently dropped.
        $context = $this->createStub(MessageContext::class);
        $context->method('correlationId')->willReturn('corr-1');

        $engine = $this->createStub(SagaEngine::class);
        $engine->method('routeOutcome')->willThrowException(new RuntimeException('fence held'));

        $this->expectException(RuntimeException::class);

        new SagaOutcomeRouter($engine, $context)(new stdClass);
    }

    #[Test]
    public function routes_a_declared_class_by_its_payload_field_ignoring_a_present_ambient(): void
    {
        // declared-wins, totally: an externally caused fact may carry the trace of whichever actor
        // triggered it, another saga issuing the command; honoring it would misroute the outcome.
        $event = CorrelatedEvent::fromPayload(['order_id' => 'order-7']);

        $context = $this->createStub(MessageContext::class);
        $context->method('correlationId')->willReturn('foreign-trace');

        $engine = $this->createMock(SagaEngine::class);
        $engine->expects($this->once())->method('routeOutcome')->with('order-7', $event)->willReturn(true);

        new SagaOutcomeRouter($engine, $context, [CorrelatedEvent::class => 'order_id'])($event);
    }

    #[Test]
    public function routes_a_declared_class_when_no_ambient_correlation_exists(): void
    {
        // the external-event case proper: no trace was ever propagated to the merchant's counter.
        $event = CorrelatedEvent::fromPayload(['order_id' => 'order-7']);

        $context = $this->createStub(MessageContext::class);
        $context->method('correlationId')->willReturn(null);

        $engine = $this->createMock(SagaEngine::class);
        $engine->expects($this->once())->method('routeOutcome')->with('order-7', $event)->willReturn(true);

        new SagaOutcomeRouter($engine, $context, [CorrelatedEvent::class => 'order_id'])($event);
    }

    #[Test]
    public function keeps_the_ambient_path_for_an_undeclared_class(): void
    {
        // the map only claims its own classes: everything else routes by the propagated correlation.
        $event = new stdClass;

        $context = $this->createStub(MessageContext::class);
        $context->method('correlationId')->willReturn('corr-1');

        $engine = $this->createMock(SagaEngine::class);
        $engine->expects($this->once())->method('routeOutcome')->with('corr-1', $event)->willReturn(true);

        new SagaOutcomeRouter($engine, $context, [CorrelatedEvent::class => 'order_id'])($event);
    }

    #[Test]
    public function throws_loud_when_the_declared_field_is_missing_from_the_payload(): void
    {
        // a declaration bug hits every delivery of the class: loud on the failure transport, never a
        // silent skip that would drop outcomes.
        $event = CorrelatedEvent::fromPayload(['shipment_id' => 'ship-1']);

        $engine = $this->createMock(SagaEngine::class);
        $engine->expects($this->never())->method('routeOutcome');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('order_id');

        new SagaOutcomeRouter($engine, $this->createStub(MessageContext::class), [CorrelatedEvent::class => 'order_id'])($event);
    }

    #[Test]
    public function throws_loud_when_the_declared_field_is_empty(): void
    {
        // an empty correlation key routes nowhere: same loud refusal as a missing field.
        $event = CorrelatedEvent::fromPayload(['order_id' => '']);

        $engine = $this->createMock(SagaEngine::class);
        $engine->expects($this->never())->method('routeOutcome');

        $this->expectException(LogicException::class);

        new SagaOutcomeRouter($engine, $this->createStub(MessageContext::class), [CorrelatedEvent::class => 'order_id'])($event);
    }

    #[Test]
    public function throws_loud_when_a_declared_class_hides_its_payload(): void
    {
        // the compile guard refuses this topology; a hand-built map bypassing it fails here instead
        // of dropping the outcome.
        $engine = $this->createMock(SagaEngine::class);
        $engine->expects($this->never())->method('routeOutcome');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('SerializablePayload');

        new SagaOutcomeRouter($engine, $this->createStub(MessageContext::class), [stdClass::class => 'order_id'])(new stdClass);
    }
}
