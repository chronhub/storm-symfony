<?php

declare(strict_types=1);

namespace Storm\Symfony;

use LogicException;
use Storm\Contracts\Message\MessageContext;
use Storm\Contracts\Message\SerializablePayload;
use Storm\Saga\Engine\SagaOutcomeDelivery;
use Storm\Symfony\Compiler\BindSagaOutcomeRouterPass;
use Storm\Symfony\Compiler\ExtractSagaRoutingEventsPass;
use Storm\Symfony\Compiler\GuardSagaCorrelateByPass;
use Throwable;

/**
 * The framework's generic saga event router: subscribes to the union of event-classes any `#[WaitFor]`
 * declares, computed at compile by `ExtractSagaRoutingEventsPass` and bound by `BindSagaOutcomeRouterPass`
 * as a `messenger.message_handler` for each class on the `storm.event.bus`. On any such event it resolves
 * the correlation key and calls the delivery role's `routeOutcome()`; the engine resolves the workflow type from
 * the correlation via the instance store, then delivers normally with the fence seam exposed, so a held
 * fence is retryable, not silently dropped.
 *
 * The key comes from one of two places, and the declaration wins TOTALLY:
 *
 * - A class in the compiled correlate-by map, an external-event wait's `#[WaitFor(correlateBy:)]`, routes
 *   by the declared top-level payload field of the delivered event itself. The ambient correlation is
 *   IGNORED for these classes: an externally caused fact may carry the trace of whichever actor triggered
 *   it, a merchant console, another saga issuing the command, never the awaited saga's, so honoring it
 *   would misroute the outcome. A declared field missing or empty at delivery throws, loud and visible on
 *   the failure transport, since a declaration bug hits every delivery of the class and a silent skip
 *   would drop outcomes;
 *
 * - Any other class routes by the propagated `Header::CorrelationId`, already on the message context
 *   since events inherit it from the saga's issued command.
 *
 * Saga routing is framework-intrinsic: an app only declares its `#[WaitFor]` events, writing no per-event
 * reaction and never naming the workflow type, which the engine resolves from the correlation.
 *
 * Events on the bus with no correlationId and no declared field, published outside any saga, are trivially
 * skipped. Events whose key matches no saga are no-ops at the engine level, which returns false.
 *
 * @see ExtractSagaRoutingEventsPass
 * @see BindSagaOutcomeRouterPass
 * @see GuardSagaCorrelateByPass
 * @see \Storm\Saga\Engine\SagaEngine::routeOutcome()
 */
final readonly class SagaOutcomeRouter
{
    /**
     * @param  array<class-string, string>  $correlateBy  the compiled class-to-payload-field map of the external-event waits
     */
    public function __construct(
        private SagaOutcomeDelivery $engine,
        private MessageContext $context,
        private array $correlateBy = [],
    ) {}

    /**
     * @throws LogicException when the event's declared correlate-by field is missing or empty at delivery,
     *                        a declaration bug surfaced loud instead of dropping the outcome
     * @throws Throwable propagated from the saga engine while delivering the event, such as a store or
     *                   serialization error or an OCC conflict, and `SagaFenceBusy` on a held fence or
     *                   `SagaOutcomeNotYetApplicable` on an early arrival, both deliberately retryable and
     *                   never swallowed; surfaced to Messenger
     */
    public function __invoke(object $event): void
    {
        $field = $this->correlateBy[$event::class] ?? null;
        if ($field !== null) {
            $this->engine->routeOutcome($this->declaredKey($event, $field), $event);

            return;
        }

        $correlationId = $this->context->correlationId();
        if ($correlationId === null) {
            return; // not part of a saga, e.g. a plain command not issued by a saga
        }

        $this->engine->routeOutcome($correlationId, $event);
    }

    /**
     * The external-event key: the declared top-level payload field, read through the durable wire
     * contract. The guard pass proved the class implements `SerializablePayload` at compile; the
     * field itself is schemaless, so it is enforced here, loud.
     *
     * @throws LogicException when the event hides its payload or the declared field is missing or empty
     */
    private function declaredKey(object $event, string $field): string
    {
        if (! $event instanceof SerializablePayload) {
            throw new LogicException(sprintf(
                'correlateBy routing failed: %s does not implement %s, so the declared field "%s" cannot be'
                .' read. The compile guard refuses this topology; a hand-built router map bypassed it.',
                $event::class,
                SerializablePayload::class,
                $field,
            ));
        }

        $value = $event->toPayload()[$field] ?? null;
        if (! is_scalar($value) || (string) $value === '') {
            throw new LogicException(sprintf(
                'correlateBy routing failed: field "%s" is missing or empty on the delivered %s payload.'
                .' The declaration and the wire contract disagree; align the #[WaitFor(correlateBy:)]'
                .' field with the event payload.',
                $field,
                $event::class,
            ));
        }

        return (string) $value;
    }
}
