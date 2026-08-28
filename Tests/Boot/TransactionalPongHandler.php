<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot;

use Storm\Saga\Attributes\TransactionalHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The `#[TransactionalHandler]` carrier: the grant pass must compile `PongReceived` into
 * `%storm.saga.transactional_handlers%`, which `storm:describe` renders under `grants`. Only the
 * compiled signature matters here; the body is a no-op.
 */
#[AsMessageHandler]
#[TransactionalHandler]
final class TransactionalPongHandler
{
    public function __invoke(PongReceived $event): void {}
}
