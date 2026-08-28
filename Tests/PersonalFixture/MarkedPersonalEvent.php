<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\PersonalFixture;

use Storm\Contracts\Message\DomainEvent;
use Storm\Message\Attribute\Personal;

/**
 * The one marked class of its scan directory: pointing `storm.event_paths` here turns the
 * ciphering feature ON for a boot variant, without marking anything in the nominal domain.
 */
#[Personal(subject: 'customer_id', keys: ['full_name'], fallback: ['full_name' => '⌫'])]
final class MarkedPersonalEvent implements DomainEvent
{
    public function __construct(
        public string $customerId,
        public string $fullName,
    ) {}

    public function aggregateId(): string
    {
        return $this->customerId;
    }

    public function toPayload(): array
    {
        return ['customer_id' => $this->customerId, 'full_name' => $this->fullName];
    }

    public static function fromPayload(array $payload): static
    {
        return new self((string) $payload['customer_id'], (string) $payload['full_name']);
    }
}
