<?php

declare(strict_types=1);

namespace Storm\Symfony;

use Storm\Saga\Child\FamilyPoker;
use Storm\Saga\Child\PokeParentFamily;
use Storm\Saga\Exception\InvalidChildIdentity;
use Storm\Saga\Exception\SagaFenceBusy;
use Storm\Saga\Exception\SagaStorageFailure;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The bus side of the family poke: a `PokeParentFamily` relayed off a settling member's outbox lands
 * here and is handed to the poker. Thin on purpose, the cascade handler's twin: the identity proof
 * and the engine call are the poker's, and this class only exists so the pure Saga package never
 * depends on Messenger.
 *
 * A held fence bubbles as retryable and the transport redelivers, which matters more here than for
 * the cascade: a poke dropped on a busy fence is a heal not taken, and the member that could send it
 * again has already settled. Everything else returns silently, a poke finding nothing to spend being
 * the common answer rather than a fault.
 */
#[AsMessageHandler]
final readonly class PokeParentFamilyHandler
{
    public function __construct(private FamilyPoker $poker) {}

    /**
     * @throws InvalidChildIdentity when the command's child is not a family member of the parent it names
     * @throws SagaFenceBusy when a concurrent step holds the parent's fence; the transport redelivers
     * @throws SagaStorageFailure when the saga storage fails
     */
    public function __invoke(PokeParentFamily $command): void
    {
        $this->poker->poke($command);
    }
}
