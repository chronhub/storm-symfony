<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Redis;
use ReflectionProperty;
use Storm\Saga\Calendar\BusinessCalendar;
use Storm\Saga\Calendar\ConfiguredBusinessCalendar;
use Storm\Saga\CircuitBreaker\CircuitBreakerStorage;
use Storm\Saga\CircuitBreaker\Dbal\DbalCircuitBreakerStorage;
use Storm\Saga\CircuitBreaker\InMemory\InMemoryCircuitBreakerStorage;
use Storm\Saga\CircuitBreaker\Redis\RedisCircuitBreakerStorage;
use Storm\Story\Console\ConsumeBatchedCommand;
use Storm\Symfony\Tests\Boot\ConfigurableStormKernel;
use Storm\Symfony\Tests\Boot\KernelWorkspace;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * What the bundle does with a config the nominal `StormTestKernel` cannot express: the misconfigurations
 * it must REFUSE at compile, and the opt-in it does not take. The nominal boot proves the valid
 * combination wires; these prove the invalid one does not ship, and that the opt-in actually binds.
 *
 * A misconfiguration caught at compile is a boot that never happens. The same mistake uncaught is a
 * daemon that runs, routes onto a lane nobody consumes, and looks healthy while doing it.
 *
 * @see StormBundleBootTest the nominal boot
 */
final class StormBundleConfigTest extends TestCase
{
    #[Override]
    public static function setUpBeforeClass(): void
    {
        // every variant compiles fresh; a cached container would skip the bundle entirely
        new Filesystem()->remove(KernelWorkspace::dir('config'));
    }

    #[Test]
    public function refuses_signal_lanes_declared_without_a_priority_events_transport(): void
    {
        // signal_lanes SPLITS the priority event lane, so it is meaningless without the lane it splits:
        // the priority transport is both the default every unlaned awaited event rides, and the floor a
        // level with no mapped transport falls back to. Configured alone, the lanes would name senders the
        // publisher can never route to; awaited signals would ride bulk while the operator believes they
        // are prioritized. Refused loud at compile.
        $kernel = $this->kernelWith('lanes-without-floor', [
            'outbox' => ['signal_lanes' => [40 => 'storm_events_critical']],
        ]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('priority_events_transport');
        $kernel->boot();
    }

    #[Test]
    public function the_business_calendar_binds_the_port_when_enabled(): void
    {
        // the calendar is OPT-IN: disabled, the port stays unbound and the first business-time arm fails
        // loud with BusinessCalendarMissing rather than compute a deadline on a fictitious default market.
        // Enabled, the declared market must actually BIND; an app that opts into hour-shaped SLAs and
        // silently gets no calendar would compute its deadlines against nothing.
        $kernel = $this->kernelWith('calendar-enabled', [
            'saga' => [
                'calendar' => [
                    'enabled' => true,
                    'business_days' => [1, 2, 3, 4, 5],
                    'open_hour' => 9,
                    'close_hour' => 17,
                    'holidays' => ['2026-12-25'],
                    'timezone' => 'Europe/Paris',
                ],
            ],
        ]);

        $kernel->boot();

        // stan resolves services against the DEV container, where the calendar is off by default, which is
        // exactly the binding this test opts into
        // @phpstan-ignore symfonyContainer.serviceNotFound
        $this->assertInstanceOf(ConfiguredBusinessCalendar::class, $this->containerOf($kernel)->get(BusinessCalendar::class));
    }

    #[Test]
    public function the_business_calendar_port_stays_unbound_by_default(): void
    {
        // the other half of the opt-in: no calendar config, so no binding at all. This is what makes the
        // BusinessCalendarMissing fail-loud reachable instead of a default market silently answering.
        $kernel = $this->kernelWith('calendar-default', []);

        $kernel->boot();

        $this->assertFalse($this->containerOf($kernel)->has(BusinessCalendar::class));
    }

    #[Test]
    public function the_inbox_failure_transport_binds_the_reject_capture_destination(): void
    {
        // the batched loop replaces the Worker, so the failure-transport semantic is reimbursed by
        // hand: a configured name must resolve to THE transport service at build, the destination
        // a rejected message is captured to, proven bound rather than trusted. A mistyped name
        // fails this same resolution at boot instead of destroying the first poison message.
        $kernel = $this->kernelWith('inbox-failure-transport', [
            'inbox' => ['failure_transport' => 'storm_events_critical'],
        ]);

        $kernel->boot();

        $container = $this->containerOf($kernel);
        // private in the compiled container; the test container hands it out at runtime, exactly
        // as the calendar test above reaches its private alias
        // @phpstan-ignore symfonyContainer.privateService
        $command = $container->get(ConsumeBatchedCommand::class);
        $this->assertInstanceOf(ConsumeBatchedCommand::class, $command);

        $sender = new ReflectionProperty(ConsumeBatchedCommand::class, 'failureSender')->getValue($command);
        // @phpstan-ignore symfonyContainer.serviceNotFound
        $this->assertSame($container->get('messenger.transport.storm_events_critical'), $sender);
    }

    #[Test]
    public function the_circuit_breaker_backend_is_postgres_until_config_says_otherwise(): void
    {
        // the default must be the durable, shared one. An app that never thinks about this section gets a
        // breaker whose count survives a restart and reaches every worker; the two alternatives each give
        // up one of those, and giving one up must be a decision somebody wrote down.
        $kernel = $this->kernelWith('breaker-default', []);

        $kernel->boot();

        // @phpstan-ignore symfonyContainer.privateService
        $this->assertInstanceOf(DbalCircuitBreakerStorage::class, $this->containerOf($kernel)->get(CircuitBreakerStorage::class));
    }

    #[Test]
    public function the_memory_circuit_breaker_backend_re_aliases_the_port(): void
    {
        $kernel = $this->kernelWith('breaker-memory', [
            'saga' => ['circuit_breaker' => ['storage' => 'memory']],
        ]);

        $kernel->boot();

        // @phpstan-ignore symfonyContainer.privateService
        $this->assertInstanceOf(InMemoryCircuitBreakerStorage::class, $this->containerOf($kernel)->get(CircuitBreakerStorage::class));
    }

    #[Test]
    public function the_redis_circuit_breaker_backend_runs_on_the_connection_the_app_names(): void
    {
        // Storm opens no Redis of its own, so the knob names an APP service and the wiring must reach
        // exactly it. A bundle that quietly built its own connection would duplicate the app's pool and
        // ignore its DSN, its auth and its database index.
        $kernel = $this->kernelWith('breaker-redis', [
            'saga' => ['circuit_breaker' => ['storage' => 'redis', 'redis_service' => 'app.redis']],
        ], ['app.redis' => Redis::class]);

        $kernel->boot();

        $container = $this->containerOf($kernel);
        // @phpstan-ignore symfonyContainer.privateService
        $this->assertInstanceOf(RedisCircuitBreakerStorage::class, $container->get(CircuitBreakerStorage::class));

        $connection = new ReflectionProperty(RedisCircuitBreakerStorage::class, 'redis')
            // @phpstan-ignore symfonyContainer.privateService
            ->getValue($container->get(CircuitBreakerStorage::class));
        // @phpstan-ignore symfonyContainer.serviceNotFound
        $this->assertSame($container->get('app.redis'), $connection);
    }

    #[Test]
    public function the_redis_backend_refuses_to_boot_without_the_connection_it_was_pointed_at(): void
    {
        // the failure mode worth pinning: a typo in `redis_service` must stop the compile, not leave a
        // breaker that throws on its first guarded call, in production, under the outage it exists for
        $kernel = $this->kernelWith('breaker-redis-missing', [
            'saga' => ['circuit_breaker' => ['storage' => 'redis', 'redis_service' => 'app.redis_typo']],
        ]);

        $this->expectException(ServiceNotFoundException::class);
        $kernel->boot();
    }

    /**
     * @param  array<string, mixed>  $storm
     * @param  array<string, class-string>  $appServices
     */
    private function kernelWith(string $variant, array $storm, array $appServices = []): ConfigurableStormKernel
    {
        return new ConfigurableStormKernel($storm, 'config/'.$variant, $appServices);
    }

    /**
     * The port is a private alias; only the test container exposes it, under framework.test, exactly as
     * KernelTestCase::getContainer() does for the nominal boot test.
     */
    private function containerOf(ConfigurableStormKernel $kernel): ContainerInterface
    {
        // the test container only exists under framework.test; stan reads the dev container's XML, which
        // carries no such service
        // @phpstan-ignore symfonyContainer.serviceNotFound
        $container = $kernel->getContainer()->get('test.service_container');
        $this->assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }
}
