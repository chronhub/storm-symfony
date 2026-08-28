<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Fixture;

/**
 * A plain event object for the saga-routing compiler-pass tests; the routing keys off the
 * message CLASS, so no framework event type is required.
 */
final class RoutedEvent {}
