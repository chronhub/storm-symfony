<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot;

use Storm\Story\Attribute\DispatchesUnderInboxTransaction;
use Storm\Symfony\Tests\Boot\Domain\PingHappened;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The `#[DispatchesUnderInboxTransaction]` carrier: the grant pass must compile `PingHappened`
 * into `%storm.story.inbox_dispatch_grants%`, which `storm:describe` renders under `grants`. Only
 * the compiled signature matters here; the body is a no-op.
 */
#[AsMessageHandler]
#[DispatchesUnderInboxTransaction]
final class InboxDispatchingPingHandler
{
    public function __invoke(PingHappened $event): void {}
}
