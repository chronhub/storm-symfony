<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot;

use Storm\Contracts\Message\SerializablePayload;

/**
 * The awaited-event carrier for `ExternalWaitWorkflow`: an external outcome whose correlation key
 * lives in a top-level payload field, proving the correlate-by `WaitFor` chain wires at boot.
 */
final readonly class ExternalOutcomeArrived implements SerializablePayload
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
