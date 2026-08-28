<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Contracts\Message\SerializablePayload;

/**
 * An external-outcome event for the correlate-by compiler-pass and router tests: the correlation
 * key lives in a top-level payload field, the durable wire contract the declaration names.
 */
final readonly class CorrelatedEvent implements SerializablePayload
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private array $payload,
    ) {}

    public function toPayload(): array
    {
        return $this->payload;
    }

    public static function fromPayload(array $payload): static
    {
        return new self($payload);
    }
}
