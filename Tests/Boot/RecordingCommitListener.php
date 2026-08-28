<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot;

use Storm\Contracts\Projector\ProjectionCommitListener;

/**
 * The commit-hook carrier of the boot proof: implementing the interface alone, no tag and no
 * attribute, must land this listener in the composite fan-out the port alias resolves to.
 */
final class RecordingCommitListener implements ProjectionCommitListener
{
    /** @var list<string> */
    public array $committed = [];

    public function committed(string $projection): void
    {
        $this->committed[] = $projection;
    }
}
