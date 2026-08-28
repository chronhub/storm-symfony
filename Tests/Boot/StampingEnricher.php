<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot;

use Storm\Message\Message;
use Storm\Message\MessageEnricher;

/**
 * Deliberately UNTAGGED: implementing the interface must be enough; the bundle's
 * registerForAutoconfiguration(MessageEnricher) provides the tag. The boot test asserts this
 * header came through the registry, proving the extension point works on the bundle's wiring
 * alone, with no app configuration.
 */
final class StampingEnricher implements MessageEnricher
{
    public function enrich(Message $message): Message
    {
        return $message->withHeader('storm_bundle_test', 'on');
    }
}
