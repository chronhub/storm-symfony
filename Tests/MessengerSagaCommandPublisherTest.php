<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Storm\Message\Message;
use Storm\Saga\Outbox\SagaLanePolicy;
use Storm\Saga\Outbox\UnrecoverableCommandDispatch;
use Storm\Symfony\MessengerSagaCommandPublisher;
use Storm\Symfony\SagaIssuedStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Validator\ConstraintViolationList;
use Throwable;

/**
 * The command-bus side of the saga outbox. The command bus has no `allow_no_handlers`, so a missing
 * handler, or a payload the validation middleware rejects, throws synchronously at dispatch, both
 * deterministic. The publisher maps them to UnrecoverableCommandDispatch so SagaOutboxRelay dead-letters
 * the row immediately; every other failure, such as a broker outage, propagates so the relay retries.
 */
final class MessengerSagaCommandPublisherTest extends TestCase
{
    #[Test]
    public function maps_a_no_handler_failure_to_an_unrecoverable_dispatch(): void
    {
        $cause = new NoHandlerForMessageException('no handler is wired for this command');
        $publisher = new MessengerSagaCommandPublisher($this->busThrowing($cause));

        try {
            $publisher->publish(new Message(new stdClass), 'storm.command.bus', 'wf');
            $this->fail('expected an UnrecoverableCommandDispatch');
        } catch (UnrecoverableCommandDispatch $e) {
            $this->assertSame($cause, $e->getPrevious()); // the cause is chained so the relay logs it in last_error
        }
    }

    #[Test]
    public function maps_a_validation_failure_to_an_unrecoverable_dispatch(): void
    {
        $cause = new ValidationFailedException(new stdClass, new ConstraintViolationList);
        $publisher = new MessengerSagaCommandPublisher($this->busThrowing($cause));

        $this->expectException(UnrecoverableCommandDispatch::class);
        $publisher->publish(new Message(new stdClass), 'storm.command.bus', 'wf');
    }

    #[Test]
    public function every_dispatch_carries_the_saga_issued_mark(): void
    {
        // The contract: ONLY the saga's own dispatches wear SagaIssuedStamp; the dead-letter
        // listener requires it, so a poisoned outcome event can never settle a saga.
        $seen = [];
        $bus = new class($seen) implements MessageBusInterface
        {
            /** @param  array<StampInterface>  $seen */
            public function __construct(public array $seen) {}

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->seen = $stamps;

                return new Envelope($message, $stamps);
            }
        };

        new MessengerSagaCommandPublisher($bus)->publish(new Message(new stdClass), 'storm.command.bus', 'wf');

        $this->assertCount(1, array_filter($bus->seen, static fn (object $s): bool => $s instanceof SagaIssuedStamp));
    }

    #[Test]
    public function routes_to_the_priority_transport_when_configured(): void
    {
        // Finish-over-start: a configured priority transport rides as a TransportNamesStamp, overriding the
        // class routing so the saga's command takes the lane ahead of fresh work.
        $seen = [];
        $bus = new class($seen) implements MessageBusInterface
        {
            /** @param  array<StampInterface>  $seen */
            public function __construct(public array $seen) {}

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->seen = $stamps;

                return new Envelope($message, $stamps);
            }
        };

        $lane = new class() implements SagaLanePolicy
        {
            public function laneFor(object $command, string $workflowType): string
            {
                return 'async_priority';
            }
        };

        new MessengerSagaCommandPublisher($bus, $lane)->publish(new Message(new stdClass), 'storm.command.bus', 'wf');

        $names = [];
        foreach ($bus->seen as $stamp) {
            if ($stamp instanceof TransportNamesStamp) {
                $names = $stamp->getTransportNames();
            }
        }
        $this->assertSame(['async_priority'], $names);
    }

    #[Test]
    public function omits_the_transport_override_when_no_priority_lane(): void
    {
        // Default null: no lane; the command keeps its normal class routing.
        $seen = [];
        $bus = new class($seen) implements MessageBusInterface
        {
            /** @param  array<StampInterface>  $seen */
            public function __construct(public array $seen) {}

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->seen = $stamps;

                return new Envelope($message, $stamps);
            }
        };

        new MessengerSagaCommandPublisher($bus)->publish(new Message(new stdClass), 'storm.command.bus', 'wf');

        $this->assertCount(0, array_filter($bus->seen, static fn (object $s): bool => $s instanceof TransportNamesStamp));
    }

    #[Test]
    public function propagates_a_transient_dispatch_failure_unchanged(): void
    {
        // a broker outage etc. is NOT permanent; it must propagate untouched so the relay retries with back-off.
        $cause = new RuntimeException('broker down');
        $publisher = new MessengerSagaCommandPublisher($this->busThrowing($cause));

        try {
            $publisher->publish(new Message(new stdClass), 'storm.command.bus', 'wf');
            $this->fail('expected the transient failure to propagate');
        } catch (Throwable $e) {
            $this->assertSame($cause, $e); // unchanged, not wrapped in an UnrecoverableCommandDispatch
        }
    }

    private function busThrowing(Throwable $error): MessageBusInterface
    {
        return new readonly class($error) implements MessageBusInterface
        {
            public function __construct(private Throwable $error) {}

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw $this->error;
            }
        };
    }

    #[Test]
    public function refuses_a_foreign_bus_instead_of_silently_rerouting(): void
    {
        // the port demands delivery to the row's target $bus; this adapter knows exactly one; a row
        // naming another must dead-letter, a deterministic refusal, never ride a bus the writer did
        // not name while the row's inspection column says otherwise
        $dispatched = 0;
        $bus = new class($dispatched) implements MessageBusInterface
        {
            public function __construct(private int &$count) {}

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->count++;

                return new Envelope($message);
            }
        };

        try {
            new MessengerSagaCommandPublisher($bus)->publish(new Message(new stdClass), 'some.other.bus', 'wf');
            self::fail('a foreign bus must be refused, not rerouted');
        } catch (UnrecoverableCommandDispatch $e) {
            $this->assertStringContainsString('some.other.bus', $e->getMessage(), 'the refusal names the target');
        }

        $this->assertSame(0, $dispatched, 'NOTHING was dispatched on the wrong bus');
    }
}
