<?php

declare(strict_types=1);

namespace Storm\Symfony;

use Storm\Chronicler\Exception\UnknownEventType;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Contracts\Message\SerializablePayload;
use Storm\Saga\Engine\EventResolver;

/**
 * Bridges the saga engine's `EventResolver` port to the framework's `EventTypeMapper`.
 *
 * It lives in the bundle, the integration layer, on purpose: Saga must stay free of a Chronicler
 * dependency and ships only the port, so the adapter that knows both the saga port and the event-type
 * mapper belongs here, where the bundle may depend on both. Same pattern as Story's
 * `MessengerOutboxPublisher` supplying Chronicler's `OutboxPublisher` port.
 */
final readonly class MappedEventResolver implements EventResolver
{
    public function __construct(
        private EventTypeMapper $mapper,
    ) {}

    public function aliasFor(object $event): ?string
    {
        try {
            return $this->mapper->toType($event::class);
        } catch (UnknownEventType) {
            return null; // not a registered #[EventType], so it has no stable alias
        }
    }

    public function payloadFor(object $event): array
    {
        return $event instanceof SerializablePayload ? $event->toPayload() : [];
    }
}
