<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Symfony\PublishingSagaHistorySink;
use Storm\Telemetry\History\SagaHistoryEntry;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class PublishingSagaHistorySinkTest extends TestCase
{
    #[Test]
    public function dispatches_the_entry_onto_the_bus(): void
    {
        $bus = new class() implements MessageBusInterface
        {
            public ?object $dispatched = null;

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->dispatched = $message;

                return new Envelope($message);
            }
        };
        $entry = new SagaHistoryEntry('transfer', 'corr-1', 'SagaStarted', []);

        new PublishingSagaHistorySink($bus)->record($entry);

        self::assertSame($entry, $bus->dispatched);
    }
}
