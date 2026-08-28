<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Outbox\OutboxRelay;
use Storm\Chronicler\SafeHead\SafeHeadAdvancer;
use Storm\Chronicler\SafeHead\SafeHeadPrecondition;
use Storm\Symfony\CancelChildWorkflowHandler;
use Storm\Symfony\GrantSlotHandler;
use Storm\Symfony\PokeParentFamilyHandler;
use Storm\Symfony\SagaCommandFailureListener;
use Storm\Symfony\StartChildWorkflowHandler;
use Storm\Symfony\StormBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * What the extension WIRES, read off the definitions it leaves behind.
 *
 * A boot test proves the container compiles; it says nothing about the value that reached a
 * constructor. Drop a `setArgument()` here and the service silently falls back to its own default:
 * the container still compiles, every boot test stays green, and the deployment runs a grace
 * window, a retry budget or a lease it never configured. Each row below is a promise the bundle's
 * configuration reference makes to an application.
 *
 * The extension is loaded on its own rather than through a kernel, so a row fails on the wiring
 * alone and names the argument it lost. That scope is also its limit: services a package declares
 * in its own `config/services.php` are imported by the configurator, not by `load()`, so the
 * arguments the bundle sets on THEM are held by the boot tests instead.
 *
 * @see StormBundleBootTest the nominal boot
 * @see StormConfigurationTreeTest the values this wiring carries
 */
final class StormWiringContractTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $config
     */
    #[Test]
    #[DataProvider('wired_arguments')]
    public function the_extension_wires_the_argument(string $service, string $argument, mixed $expected, array $config = []): void
    {
        $builder = self::load($config);

        $this->assertTrue($builder->hasDefinition($service), sprintf('%s is not registered at all', $service));
        $this->assertSame($expected, self::plain($builder->getDefinition($service)->getArgument($argument)));
    }

    /**
     * @return iterable<string, array{string, string, mixed, 3?: array<string, mixed>}>
     */
    public static function wired_arguments(): iterable
    {
        // Both safe-head collaborators must agree on the grace window; wired from one config key,
        // they cannot drift, and a lost argument leaves one of them on a default the other ignores.
        yield 'the advancer takes the configured grace' => [SafeHeadAdvancer::class, '$graceSeconds', 2.5, ['safe_head' => ['grace_seconds' => 2.5]]];
        yield 'the precondition takes the same grace' => [SafeHeadPrecondition::class, '$graceSeconds', 2.5, ['safe_head' => ['grace_seconds' => 2.5]]];

        // The relay's budget: attempts, base and cap. Silently defaulted, a deployment that widened
        // its retry window keeps the shipped one and dead-letters earlier than it asked to.
        yield 'the relay takes the configured attempts' => [OutboxRelay::class, '$maxAttempts', 9, ['outbox' => ['max_attempts' => 9]]];
        yield 'the relay takes the configured back-off base' => [OutboxRelay::class, '$backoffBaseSeconds', 7, ['outbox' => ['backoff_base_seconds' => 7]]];
    }

    #[Test]
    #[DataProvider('autoconfigured_services')]
    public function the_extension_leaves_the_service_autoconfigured(string $service): void
    {
        $builder = self::load([]);

        $this->assertTrue($builder->hasDefinition($service), sprintf('%s is not registered at all', $service));
        // autoconfiguration is how the attribute on the class becomes a tag; without it these are
        // ordinary services, the handler tag never lands, and the message they exist for reaches a
        // bus that has no handler for it
        $this->assertTrue($builder->getDefinition($service)->isAutoconfigured(), sprintf('%s would never receive its tags', $service));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function autoconfigured_services(): iterable
    {
        yield 'the child-spawn handler' => [StartChildWorkflowHandler::class];
        yield 'the child-cancel handler' => [CancelChildWorkflowHandler::class];
        yield 'the family-poke handler' => [PokeParentFamilyHandler::class];
        yield 'the slot-grant handler' => [GrantSlotHandler::class];
        yield 'the saga command failure listener' => [SagaCommandFailureListener::class];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[Test]
    #[DataProvider('seeded_parameters')]
    public function the_extension_seeds_the_parameter(string $parameter, mixed $expected, array $config = []): void
    {
        $builder = self::load($config);

        $this->assertTrue($builder->hasParameter($parameter), sprintf('%s is never seeded', $parameter));
        $this->assertSame($expected, $builder->getParameter($parameter));
    }

    /**
     * @return iterable<string, array{string, mixed, 2?: array<string, mixed>}>
     */
    public static function seeded_parameters(): iterable
    {
        // Two of these are seeded EMPTY and overwritten by a compiler pass. The seed is what makes
        // the pass optional: unseeded, every consumer of the parameter fails to resolve on an app
        // that never triggers the scan, and the failure names a parameter rather than a feature.
        yield 'the event-class map is seeded' => ['storm.event_classes', []];
        yield 'the personal-data map is seeded' => ['storm.personal_data', []];

        yield 'the propagated context keys are published' => [
            'storm.context.propagated_keys',
            ['tenant'],
            ['context' => ['propagated_keys' => ['tenant']]],
        ];

        // Null is a value here, not an absence: it says "no dedicated read-model connection", which
        // is what the projector reads to decide it rides the default one.
        yield 'the read-model connection is published even when unset' => ['storm.connections.read_model_store', null];
    }

    /**
     * A Reference renders as the service id it points at; anything else is already comparable.
     */
    private static function plain(mixed $argument): mixed
    {
        return $argument instanceof Reference ? (string) $argument : $argument;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function load(array $config): ContainerBuilder
    {
        $builder = new ContainerBuilder;
        $extension = new StormBundle()->getContainerExtension();
        self::assertInstanceOf(ExtensionInterface::class, $extension);

        $extension->load([$config], $builder);

        return $builder;
    }
}
