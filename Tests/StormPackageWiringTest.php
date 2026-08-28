<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Storm\Chronicler\Outbox\OutboxRelay;
use Storm\Saga\Outbox\SagaOutboxRelay;
use Storm\Saga\Priority\PriorityLanePolicy;
use Storm\Saga\Schedule\TimerRunner;
use Storm\Symfony\MessengerSagaCommandPublisher;
use Storm\Symfony\Tests\Boot\ConfigurableStormKernel;
use Storm\Symfony\Tests\Boot\KernelWorkspace;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The arguments the bundle sets on services a PACKAGE declares.
 *
 * Those services ship working defaults so each package stands alone, which is exactly what makes
 * the wiring invisible: drop the `setArgument()` and the service keeps running on its own value
 * while the deployment believes it configured something else. The container compiles, the boot
 * tests pass, and the lease or the retry budget in force is not the one written in the config.
 *
 * Every value below is therefore chosen to DIFFER from the constructor default it would fall back
 * to. `StormWiringContractTest` covers the same promise for services the extension registers
 * itself, where a definition can be read without booting.
 *
 * @see StormWiringContractTest
 */
final class StormPackageWiringTest extends TestCase
{
    #[Override]
    public static function setUpBeforeClass(): void
    {
        new Filesystem()->remove(KernelWorkspace::dir('package-wiring'));
    }

    #[Test]
    public function the_timer_lease_reaches_the_runner(): void
    {
        // 45 against a constructor default of 300: the lease decides how long a claimed timer waits
        // before another runner may take it, so a fleet silently running the shipped value re-claims
        // on a schedule nobody set.
        $runner = $this->serviceFrom(['saga' => ['timer_lease_seconds' => 45]], TimerRunner::class);

        $this->assertSame(45, new ReflectionProperty(TimerRunner::class, 'leaseSeconds')->getValue($runner));
    }

    #[Test]
    public function the_command_outbox_budget_reaches_the_saga_relay(): void
    {
        // 9, 7 and 11 against defaults of 5, 1 and 60: the dispatch budget of a saga's outgoing
        // commands, which decides when a transient failure becomes a dead letter. Each of the three
        // carries the same exemption, "the bundle always sets this argument", and an exemption is a
        // CLAIM about the wiring: the cap was the one making it with nothing behind it.
        $relay = $this->serviceFrom(
            ['saga' => ['command_outbox' => ['max_attempts' => 9, 'backoff_base_seconds' => 7, 'backoff_max_seconds' => 11]]],
            SagaOutboxRelay::class,
        );

        $this->assertSame(9, new ReflectionProperty(SagaOutboxRelay::class, 'maxAttempts')->getValue($relay));
        $this->assertSame(7, new ReflectionProperty(SagaOutboxRelay::class, 'backoffBaseSeconds')->getValue($relay));
        $this->assertSame(11, new ReflectionProperty(SagaOutboxRelay::class, 'backoffMaxSeconds')->getValue($relay));
    }

    #[Test]
    public function every_exempted_knob_of_the_event_relay_reaches_it_too(): void
    {
        // the sibling relay, whose four scalars carry the same exemption for the same reason. The
        // cooldown is the one with its own scale: a row this runtime cannot decode is left alone for
        // a DEPLOY, not a broker hiccup, so a relay silently running the shipped 300 re-reads a
        // deployment-shaped verdict on a broker-shaped rhythm.
        $relay = $this->serviceFrom(
            ['outbox' => [
                'max_attempts' => 8,
                'backoff_base_seconds' => 6,
                'backoff_max_seconds' => 12,
                'stalled_cooldown_seconds' => 900,
            ]],
            OutboxRelay::class,
        );

        $this->assertSame(8, new ReflectionProperty(OutboxRelay::class, 'maxAttempts')->getValue($relay));
        $this->assertSame(6, new ReflectionProperty(OutboxRelay::class, 'backoffBaseSeconds')->getValue($relay));
        $this->assertSame(12, new ReflectionProperty(OutboxRelay::class, 'backoffMaxSeconds')->getValue($relay));
        $this->assertSame(900, new ReflectionProperty(OutboxRelay::class, 'stalledCooldownSeconds')->getValue($relay));
    }

    #[Test]
    public function the_publisher_routes_through_the_priority_lane_policy(): void
    {
        // The package default is a null policy, which routes everything by class: a publisher left
        // on it drops every declared lane in silence, and the operator sees priority commands ride
        // the bulk transport while the configuration says otherwise.
        $publisher = $this->serviceFrom([], MessengerSagaCommandPublisher::class);

        $this->assertInstanceOf(
            PriorityLanePolicy::class,
            new ReflectionProperty(MessengerSagaCommandPublisher::class, 'lanePolicy')->getValue($publisher),
        );
    }

    /**
     * @param  array<string, mixed>  $storm
     * @param  class-string  $service
     */
    private function serviceFrom(array $storm, string $service): object
    {
        $kernel = new ConfigurableStormKernel($storm, 'package-wiring/'.hash('xxh3', $service));
        $kernel->boot();

        // @phpstan-ignore symfonyContainer.serviceNotFound
        $container = $kernel->getContainer()->get('test.service_container');
        $this->assertInstanceOf(ContainerInterface::class, $container);

        $instance = $container->get($service);
        $this->assertInstanceOf($service, $instance);

        return $instance;
    }
}
