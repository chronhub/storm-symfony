<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Contracts\Message\DomainEvent;

/**
 * A domain event with a real payload, used to exercise the resolver's payload extraction.
 */
final class PayloadEvent implements DomainEvent
{
    public function __construct(
        public string $orderId,
        public int $amount,
    ) {}

    public function aggregateId(): string
    {
        return $this->orderId;
    }

    public function toPayload(): array
    {
        return ['orderId' => $this->orderId, 'amount' => $this->amount];
    }

    public static function fromPayload(array $payload): static
    {
        return new self((string) $payload['orderId'], (int) $payload['amount']);
    }
}
