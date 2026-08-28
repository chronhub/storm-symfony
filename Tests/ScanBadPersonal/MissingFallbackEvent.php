<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\ScanBadPersonal;

use Storm\Contracts\Message\DomainEvent;
use Storm\Message\Attribute\Personal;

/**
 * The degenerate declaration the build guard must catch: `email` is declared personal with NO
 * fallback. The class itself loads fine, attributes being lazy, so only the compile-time scan
 * instantiating the attribute can catch it; its own directory keeps it out of every happy-path
 * scan.
 */
#[Personal(subject: 'customer_id', keys: ['full_name', 'email'], fallback: ['full_name' => '⌫'])]
final class MissingFallbackEvent implements DomainEvent
{
    public function __construct(
        public string $customerId,
        public string $fullName,
        public string $email,
    ) {}

    public function aggregateId(): string
    {
        return $this->customerId;
    }

    public function toPayload(): array
    {
        return ['customer_id' => $this->customerId, 'full_name' => $this->fullName, 'email' => $this->email];
    }

    public static function fromPayload(array $payload): static
    {
        return new self((string) $payload['customer_id'], (string) $payload['full_name'], (string) $payload['email']);
    }
}
