<?php

declare(strict_types=1);

namespace Storm\Symfony;

use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Saga\Outbox\NullLanePolicy;
use Storm\Saga\Outbox\SagaCommandPublisher;
use Storm\Saga\Outbox\SagaLanePolicy;
use Storm\Saga\Outbox\UnroutableCommand;
use Storm\Story\Stamp\ContextStamps;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * The default `SagaCommandPublisher`: dispatches a saga's outgoing command onto the command bus.
 * Lives in the bundle, bridging the Saga port to Messenger's command bus which the bundle owns; the
 * mirror of Story's `MessengerOutboxPublisher` for events.
 *
 * It dispatches the bare command rather than the `Message` so handlers type-hint the command, but
 * carries the stored context as stamps via `ContextStamps::fromMessage`, the saga's correlation id
 * above all: `AssignMessageMetadata` leaves a present correlation untouched, so the events the command
 * triggers inherit the saga's correlation and a reaction subscriber can route them back to it. The
 * single bus is OFFICIAL: a row whose recorded `$bus` differs from the configured one is refused loud
 * with `UnroutableCommand`, never silently rerouted onto a bus the writer did not name; the refusal is
 * deterministic, so the relay dead-letters it immediately. The outbox column stays as the seam a future
 * multi-bus router would consume.
 *
 * Finish-over-start lane, optional: the {@see \Storm\Saga\Outbox\SagaLanePolicy} decides the priority transport a
 * saga-issued command rides, a `TransportNamesStamp` overriding the class routing, so an in-flight
 * saga's legs never starve behind a flood of fresh commands; null = no lane. The decision is decoupled
 * from the dispatch: this publisher only stamps what the policy returns. The discrimination is by PATH,
 * since all saga commands pass through here, not by class, so a dual-usage command rides the lane only
 * when the saga issues it. The default {@see \Storm\Saga\Outbox\NullLanePolicy} adds no lane; a real deployment wires a
 * configured {@see \Storm\Saga\Priority\PriorityLanePolicy}.
 */
final readonly class MessengerSagaCommandPublisher implements SagaCommandPublisher
{
    public function __construct(
        #[Autowire(service: 'storm.command.bus')]
        private MessageBusInterface $commandBus,
        private SagaLanePolicy $lanePolicy = new NullLanePolicy,
        /** The logical name of the injected bus; the ONLY `$bus` value this publisher honors. */
        private string $busName = 'storm.command.bus',
        /**
         * The bag keys re-stamped across the hop, declared under `storm.context.propagated_keys`; the bundle owns the list.
         *
         * @var list<string>
         */
        private array $propagatedKeys = [],
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws ExceptionInterface any other dispatch failure such as the broker being down; the relay
     *                            treats it as a transient delivery failure and retries with back-off
     */
    public function publish(Message $message, string $bus, string $workflowType): void
    {
        // The port demands delivery to the row's target $bus; this adapter knows exactly ONE. A row
        // naming another bus can never be delivered here; refusing deterministically dead-letters it
        // at once, where silently rerouting would betray both the writer and whoever inspects the row.
        if ($bus !== $this->busName) {
            throw UnroutableCommand::reason(sprintf(
                'this publisher routes "%s" only; the outbox row targets "%s" — multi-bus routing is a future capability, the row cannot be delivered here',
                $this->busName,
                $bus,
            ));
        }

        // SagaIssuedStamp marks this as the saga's OWN command; the dead-letter listener requires it, so
        // a poisoned outcome EVENT, which inherits the correlation, can never settle a saga.
        $messageId = $message->header(Header::MessageId);
        $stamps = [...ContextStamps::fromMessage($message, $this->propagatedKeys), new SagaIssuedStamp(is_string($messageId) ? $messageId : null)];

        // Finish-over-start: the lane policy decides the priority transport this command rides, overriding
        // the class routing, so an in-flight saga's legs never queue behind fresh work; null = no lane.
        $lane = $this->lanePolicy->laneFor($message->message(), $workflowType);
        if ($lane !== null) {
            $stamps[] = new TransportNamesStamp([$lane]);
        }

        try {
            $this->commandBus->dispatch($message->message(), $stamps);
        } catch (NoHandlerForMessageException|ValidationFailedException $e) {
            // Deterministic: no handler wired for this command, or a payload validation will always
            // reject; retrying can't fix it. Mark permanent so the relay dead-letters the row now, and
            // the saga settles/compensates without waiting out the retry budget.
            throw UnroutableCommand::reason($e->getMessage(), $e);
        }
    }
}
