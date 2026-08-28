<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

/**
 * The interface shape of a granted handler's message: what lands in the compiled grant set when
 * the `#[AsMessageHandler]` declares an interface, while the runtime context reports the concrete
 * implementing class.
 */
interface GrantFixtureContract {}
