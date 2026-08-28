<?php

declare(strict_types=1);

namespace Storm\Symfony;

use Storm\Telemetry\History\SagaHistoryEntry;
use Storm\Telemetry\History\SagaHistorySink;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The publish side of the async saga-history path: dispatches the entry onto a message bus instead of
 * writing it inline, so the recording leaves the saga's worker and, with the bus routed to an async
 * transport, the operational database. Lives in the bundle, like {@see MessengerSagaCommandPublisher}:
 * it bridges the Telemetry `SagaHistorySink` port to Messenger, which the bundle owns. The receiving
 * side {@see SagaHistoryConsumer} persists it, typically through a `TableSagaHistorySink` bound to a
 * separate connection.
 *
 * The bus is injected, not hard-wired to one of the storm buses: saga history is neither a command nor a
 * domain event, so the app picks the transport, a dedicated telemetry bus or queue. Routed to a
 * transport, the dispatch enqueues off the worker; left unrouted, the handler runs in-process, correct
 * but without the async benefit. Routing the `SagaHistoryEntry` message is the app's wiring.
 */
final readonly class PublishingSagaHistorySink implements SagaHistorySink
{
    public function __construct(private MessageBusInterface $bus) {}

    /**
     * {@inheritDoc}
     *
     * @throws ExceptionInterface on a dispatch failure such as the broker being down or no handler or
     *                            route; the caller keeps it off the committed saga via the subscriber's
     *                            backstop, or a wrapping `BestEffortSagaHistorySink` in a fan-out
     */
    public function record(SagaHistoryEntry $entry): void
    {
        $this->bus->dispatch($entry);
    }
}
