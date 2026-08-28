<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Symfony\Compiler\BindSagaOutcomeRouterPass;
use Storm\Symfony\Compiler\ExtractSagaRoutingEventsPass;
use Storm\Symfony\Compiler\GrantInboxDispatchPass;
use Storm\Symfony\Compiler\GrantTransactionalHandlerPass;
use Storm\Symfony\Compiler\GuardSagaCorrelateByPass;
use Storm\Symfony\Compiler\GuardSagaSignalLanesPass;
use Storm\Symfony\Compiler\HarvestCommandHelpPass;
use Storm\Symfony\Compiler\RegisterEventTypesPass;
use Storm\Symfony\Compiler\RegisterPersonalDataPass;
use Storm\Symfony\Compiler\ValidateAggregatesPass;
use Storm\Symfony\StormBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_filter;
use function array_map;
use function array_values;
use function str_starts_with;

/**
 * The order the passes run in, which is a contract rather than a detail.
 *
 * Each priority here answers to a consumer: the event-class scan must publish its parameter before
 * the saga routing pass resolves alias tokens against it, or an alias-only wait leaves the
 * subscription union without a word; the two grant passes sit where Messenger has already
 * materialized its handler tags; the routing chain refuses, then binds. Nothing in a compiled
 * container says which pass ran first, so a shifted priority produces a container that is merely
 * wrong, and a dropped pass produces one that is missing a parameter half the framework reads.
 *
 * @see StormBundleBootTest the boot this ordering makes possible
 */
final class StormCompilerPassOrderTest extends TestCase
{
    #[Test]
    public function the_bundle_registers_its_passes_in_the_documented_order(): void
    {
        $container = new ContainerBuilder;
        new StormBundle()->build($container);

        $storm = array_values(array_filter(
            array_map(
                static fn (object $pass): string => $pass::class,
                $container->getCompilerPassConfig()->getBeforeOptimizationPasses(),
            ),
            static fn (string $class): bool => str_starts_with($class, 'Storm\\'),
        ));

        // Read top to bottom, this IS the priority ladder: 60, then 50, the two guards at 40, then
        // 30, and the three that take the default 0 last. The pair at 40 is the one an off-by-one
        // reorders without changing anything else, and the two on either side of it are what a
        // dropped pass removes from the middle.
        self::assertSame([
            RegisterEventTypesPass::class,
            ExtractSagaRoutingEventsPass::class,
            GuardSagaSignalLanesPass::class,
            GuardSagaCorrelateByPass::class,
            BindSagaOutcomeRouterPass::class,
            RegisterPersonalDataPass::class,
            ValidateAggregatesPass::class,
            // no consumer, and nothing consumes it: it only writes help strings onto command
            // definitions, so it sits where it was declared rather than earning a priority
            HarvestCommandHelpPass::class,
            GrantInboxDispatchPass::class,
            GrantTransactionalHandlerPass::class,
        ], $storm);
    }
}
