<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

use Storm\Contracts\Message\SerializablePayload;

/**
 * A payload-bearing event INTERFACE for the correlate-by guard: it satisfies the payload check,
 * yet the router resolves the declared field by the delivered event's exact concrete class, so
 * declaring it on a `correlateBy:` wait must refuse the compile.
 */
interface CorrelatedContract extends SerializablePayload {}
