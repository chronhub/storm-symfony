<?php

declare(strict_types=1);

namespace Storm\Symfony;

use JsonException;
use Storm\Saga\Child\ChildSpawner;
use Storm\Saga\Child\StartChildWorkflow;
use Storm\Saga\Exception\ChildSpawnRefused;
use Storm\Saga\Exception\CorrelationAlreadyOwned;
use Storm\Saga\Exception\InvalidChildIdentity;
use Storm\Saga\Exception\ParentNotBornYet;
use Storm\Saga\Exception\SagaFenceBusy;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Exception\WorkflowNotFound;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The bus side of a spawn: a `StartChildWorkflow` relayed off the parent's command outbox lands
 * here and is handed to the spawner. Thin on purpose: the guards, the lineage, and the birth are
 * the spawner's; this class only exists so the pure Saga package never depends on Messenger.
 *
 * The spawner's throws bubble by design: a tripped ceiling, an unknown child type, or a foreign
 * owner of the minted correlation dead-letters the command, and the dead-letter settles the
 * PARENT's leg through the saga command failure path, like any poisoned issued command. A skipped
 * spawn, parent gone or terminal in the cancel-versus-spawn race, returns quietly and is announced
 * by the spawner itself.
 *
 * @see SagaCommandFailureListener
 */
#[AsMessageHandler]
final readonly class StartChildWorkflowHandler
{
    public function __construct(private ChildSpawner $spawner) {}

    /**
     * @throws ParentNotBornYet when the parent row is not committed yet; the transport redelivers the spawn
     * @throws ChildSpawnRefused when a depth, children or vars ceiling trips, or the parent's definition never declared this slot for this child type
     * @throws CorrelationAlreadyOwned when the minted correlation is owned by another workflow type
     * @throws InvalidChildIdentity when the slot is not a valid static identifier, or the parent row carries a malformed parent declaration
     * @throws JsonException when the spawn vars cannot be measured as JSON
     * @throws SagaFenceBusy when a concurrent step holds the child's fence; the transport redelivers
     * @throws SagaStorageFailure when the saga storage fails
     * @throws WorkflowNotFound when no workflow is registered under the child type
     */
    public function __invoke(StartChildWorkflow $command): void
    {
        $this->spawner->spawn($command);
    }
}
