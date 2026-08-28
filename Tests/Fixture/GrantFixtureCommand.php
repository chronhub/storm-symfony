<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

/**
 * A message a granted handler handles; the inbox-dispatch grant is keyed by THIS class. Implements
 * the fixture contract so an interface-granted handler resolves onto it, the hierarchy the runtime
 * guard must honor.
 */
final readonly class GrantFixtureCommand implements GrantFixtureContract {}
