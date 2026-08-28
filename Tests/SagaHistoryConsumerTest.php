<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Symfony\PublishingSagaHistorySink;
use Storm\Symfony\SagaHistoryConsumer;
use Storm\Telemetry\History\SagaHistoryEntry;
use Storm\Telemetry\History\SagaHistorySink;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class SagaHistoryConsumerTest extends TestCase
{
    #[Test]
    public function persists_the_entry_through_its_sink(): void
    {
        $sink = new class() implements SagaHistorySink
        {
            public ?SagaHistoryEntry $entry = null;

            public function record(SagaHistoryEntry $entry): void
            {
                $this->entry = $entry;
            }
        };
        $entry = new SagaHistoryEntry('transfer', 'corr-1', 'SagaStarted', []);

        new SagaHistoryConsumer($sink)($entry);

        self::assertSame($entry, $sink->entry);
    }

    #[Test]
    public function lets_a_failure_bubble_for_the_transport_to_retry(): void
    {
        // The async path must NOT swallow: a bubbled error lets the transport's retry redeliver
        // at-least-once, unlike the in-process post-commit path that drops best-effort.
        $sink = new class() implements SagaHistorySink
        {
            public function record(SagaHistoryEntry $entry): void
            {
                throw new RuntimeException('db down');
            }
        };

        $this->expectException(RuntimeException::class);

        new SagaHistoryConsumer($sink)(new SagaHistoryEntry('transfer', 'corr-1', 'SagaStarted', []));
    }

    #[Test]
    public function refuses_the_publishing_sink_at_construction(): void
    {
        // the loop the docblock only WARNED about, now impossible by construction: an ordinary
        // autowire that reinjects the publisher would republish every consumed entry forever,
        // persisting nothing; refused loud before a single message flows
        $bus = new class() implements MessageBusInterface
        {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return new Envelope($message);
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('publishing sink');

        new SagaHistoryConsumer(new PublishingSagaHistorySink($bus));
    }
}
