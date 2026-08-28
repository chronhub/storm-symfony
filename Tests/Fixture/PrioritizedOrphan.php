<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Saga\Attributes\Prioritized;

/**
 * A class-level `#[Prioritized]` with NO `#[Workflow]` beside it: tagged as a workflow anyway,
 * only possible by hand since autoconfiguration tags `#[Workflow]` classes, the declaration would
 * be silently inert, so the pass refuses it at compile.
 */
#[Prioritized(10)]
final class PrioritizedOrphan {}
