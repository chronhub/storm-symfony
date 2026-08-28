<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Describe\Fixture;

use Storm\Message\EventType;

/**
 * An event renamed twice, its former aliases declared out of alphabetical order so a catalog
 * rendering them proves it sorts rather than echoes the declaration.
 *
 * It sits here rather than beside the shared fixtures because that directory is the field of the
 * scanner's own tests, which assert the exact set of classes they find in it.
 */
#[EventType('describe.fixture.renamed-event', replaces: ['describe.fixture.old-name', 'describe.fixture.ancient-name'])]
final class RenamedEvent {}
