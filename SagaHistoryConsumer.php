<?php

declare(strict_types=1);

namespace Storm\Symfony;

use LogicException;
use Storm\Telemetry\History\SagaHistoryEntry;
use Storm\Telemetry\History\SagaHistorySink;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The consume side of the async saga-history path: persists a published `SagaHistoryEntry`, off the
 * saga's worker. Routed to an async transport it runs on a separate telemetry worker; bind its sink to
 * a `TableSagaHistorySink` on a separate connection to keep the write off the operational database
 * entirely.
 *
 * Unlike the in-process path it does NOT swallow a failure: this runs on the bus, so a transient write
 * error should bubble and let the transport's retry policy redeliver, at-least-once recording rather
 * than the best-effort drop that the post-commit in-process path needs, where a throw would re-enter a
 * committed saga. The injected sink is the persisting one, a `TableSagaHistorySink` wired explicitly by
 * the app, never the `SagaHistorySink` alias which the app may have pointed back at this publishing
 * path. The constructor DEFENDS that rule: an ordinary autowire that reinjects the publisher would
 * republish every consumed entry forever, a loop on a sync bus and amplification on an async one,
 * while persisting nothing, so a `PublishingSagaHistorySink` is refused loud at construction.
 */
#[AsMessageHandler]
final readonly class SagaHistoryConsumer
{
    /**
     * @throws LogicException when the injected sink is the publishing one; consuming into it would
     *                        republish every entry forever while persisting nothing
     */
    public function __construct(private SagaHistorySink $sink)
    {
        if ($sink instanceof PublishingSagaHistorySink) {
            throw new LogicException(
                'SagaHistoryConsumer cannot consume into the publishing sink: every consumed entry would be republished forever, persisting nothing. Bind $sink to a PERSISTING sink (e.g. TableSagaHistorySink), never the SagaHistorySink alias an app points at the publisher.',
            );
        }
    }

    public function __invoke(SagaHistoryEntry $entry): void
    {
        $this->sink->record($entry);
    }
}
