<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Symfony\StormBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;

/**
 * Every bound the configuration tree declares, exercised on both sides: the value it must refuse and
 * the value at the bound it must accept.
 *
 * A knob is honest when changing it either changes behavior or is refused; one that quietly swallows
 * a degenerate value is worse than no knob, since the operator reads the number back and believes
 * it. The refusals here are the only thing standing between a typo and a daemon that boots, looks
 * healthy, and retries a broker outage away in three seconds.
 *
 * The tree alone is processed, not a booted kernel: it is the same object the kernel compiles
 * against, and a bound lives in the declaration, so a variant per case costs a millisecond instead
 * of a container. What the tree cannot answer, whether a processed value reaches the service that
 * reads it, belongs to the boot tests.
 *
 * @see StormBundleConfigTest the refusals and opt-ins that need a compiled container
 * @see StormBundleBootTest the nominal boot
 */
final class StormConfigurationTreeTest extends TestCase
{
    #[Test]
    public function the_empty_configuration_processes_into_the_declared_defaults(): void
    {
        // The guard-rail of every case below. A broken baseline makes each refusal ambiguous: the
        // grid would report the tree refusing everything, which is indistinguishable from a tree
        // that guards everything, and the run would read as a clean bill of health.
        $config = $this->process([]);

        $this->assertSame(5, $config['outbox']['max_attempts']);
        $this->assertSame(60, $config['outbox']['backoff_max_seconds']);
        $this->assertSame(300, $config['outbox']['stalled_cooldown_seconds']);
        $this->assertSame('delete', $config['outbox']['disposal']);
        $this->assertSame(5.0, $config['safe_head']['grace_seconds']);
        $this->assertSame(30, $config['stream_existence_cache']['negative_ttl_seconds']);
        $this->assertSame(1, $config['inbox']['batch_size']);
        $this->assertSame(50, $config['inbox']['batch_idle_ms']);
        $this->assertSame(300, $config['saga']['timer_lease_seconds']);
        $this->assertSame(50, $config['occ']['retry_delay_ms']);
        $this->assertSame(1000, $config['occ']['retry_max_delay_ms']);

        // A default is a decision the deployment inherits without writing it, so it is read here
        // rather than trusted: the back-off base the relay floors, the scan root every #[EventType]
        // is discovered from, and the whole command outbox, whose four knobs mirror the event one
        // and were the half of the pair this baseline never looked at.
        $this->assertSame(1, $config['outbox']['backoff_base_seconds']);
        $this->assertSame(['%kernel.project_dir%/src'], $config['event_paths']);
        $this->assertSame(5, $config['saga']['command_outbox']['max_attempts']);
        $this->assertSame(1, $config['saga']['command_outbox']['backoff_base_seconds']);
        $this->assertSame(60, $config['saga']['command_outbox']['backoff_max_seconds']);
        $this->assertSame('delete', $config['saga']['command_outbox']['disposal']);
    }

    #[Test]
    public function the_enabled_calendar_declares_a_five_day_week_and_office_hours(): void
    {
        // The calendar is opt-in, so its defaults live behind the switch and the empty-config
        // baseline cannot see them. They describe a market: Monday to Friday, 09:00 to 17:00. A
        // deployment that enables the calendar without declaring one inherits exactly this.
        $config = $this->process(self::calendar([]));

        $this->assertSame([1, 2, 3, 4, 5], $config['saga']['calendar']['business_days']);
        $this->assertSame(9, $config['saga']['calendar']['open_hour']);
        $this->assertSame(17, $config['saga']['calendar']['close_hour']);
    }

    #[Test]
    public function the_snapshot_sweep_counts_a_hundred_events_by_default(): void
    {
        // Declared without tuning, the sweep still needs a count trigger; the two null knobs beside
        // it are the off positions of optional guards, and say so.
        $config = $this->process(self::aggregate(['snapshot' => []]));
        $snapshot = $config['aggregates']['App\\Account']['snapshot'];

        $this->assertSame(100, $snapshot['threshold']);
        $this->assertNull($snapshot['max_age_seconds']);
        $this->assertNull($snapshot['min_interval_seconds']);
    }

    #[Test]
    public function a_dashed_key_survives_on_every_node_whose_key_is_the_wiring(): void
    {
        // On these three nodes the KEY is the wiring, so Symfony's default of folding dashes into
        // underscores would rewrite it: a neutral transport names the service
        // `storm.neutral_transport.<name>` an app points its messenger config at, and both lane maps
        // name a Messenger transport. Renamed here, the app references a service nobody registered,
        // and the compile fails somewhere else than where the typo is.
        $config = $this->process([
            'neutral_transports' => ['edge-public' => ['bus' => 'command.bus']],
            'outbox' => [
                'priority_events_transport' => 'storm_events_priority',
                'signal_lanes' => [40 => 'storm-critical'],
            ],
            'saga' => ['priority' => ['lanes' => [10 => 'storm-fast']]],
        ]);

        $this->assertArrayHasKey('edge-public', $config['neutral_transports']);
        $this->assertSame(['storm-critical'], $config['outbox']['signal_lanes'][40]);
        $this->assertSame('storm-fast', $config['saga']['priority']['lanes'][10]);
    }

    #[Test]
    public function a_propagated_key_outside_the_framework_space_is_accepted(): void
    {
        // The acceptance half of the reserved-prefix guard. Its refusal is proven above; without
        // this, a guard rewritten to refuse EVERY key would read exactly like a working one, since
        // a tree that refuses everything still refuses what the grid asks it to refuse.
        $config = $this->process(['context' => ['propagated_keys' => ['tenant', 'locale']]]);

        $this->assertSame(['tenant', 'locale'], $config['context']['propagated_keys']);
    }

    #[Test]
    public function a_single_transport_named_as_a_string_becomes_a_one_element_list(): void
    {
        // The string form is the documented shorthand of a node whose long form is a list; dropping
        // the normalization leaves a string where every consumer indexes a list.
        $config = $this->process(['outbox' => ['priority_events_transport' => 'storm_events_priority']]);

        $this->assertSame(['storm_events_priority'], $config['outbox']['priority_events_transport']);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[Test]
    #[DataProvider('degenerate_values')]
    public function refuses_a_degenerate_value(string $path, array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);
        // the path, not just the type: a tree refusing at the wrong node reads exactly like one
        // refusing at the right one
        $this->expectExceptionMessageIsOrContains($path);

        $this->process($config);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function degenerate_values(): iterable
    {
        yield 'a snapshot count trigger of zero' => ['storm.aggregates', self::aggregate(['snapshot' => ['threshold' => 0]])];
        yield 'a snapshot staleness ceiling of zero' => ['storm.aggregates', self::aggregate(['snapshot' => ['max_age_seconds' => 0]])];
        yield 'a snapshot frequency floor of zero' => ['storm.aggregates', self::aggregate(['snapshot' => ['min_interval_seconds' => 0]])];
        yield 'a blank identity class' => ['storm.aggregates', self::aggregate(['id' => ''])];
        yield 'a blank stream category' => ['storm.aggregates', self::aggregate(['category' => ''])];
        yield 'an aggregate with no identity class' => ['storm.aggregates', ['aggregates' => ['App\\Account' => ['category' => 'account']]]];
        yield 'an aggregate with no stream category' => ['storm.aggregates', ['aggregates' => ['App\\Account' => ['id' => 'App\\AccountId']]]];

        yield 'a blank propagated header key' => ['storm.context.propagated_keys', ['context' => ['propagated_keys' => ['']]]];
        yield 'a propagated key in the framework space' => ['storm.context.propagated_keys', ['context' => ['propagated_keys' => ['__tenant']]]];

        yield 'a blank privacy master key' => ['storm.privacy.master_key', ['privacy' => ['master_key' => '']]];

        yield 'a negative-liveness cache that never expires' => ['storm.stream_existence_cache.negative_ttl_seconds', ['stream_existence_cache' => ['enabled' => true, 'negative_ttl_seconds' => 0]]];

        yield 'a safe-head grace of zero' => ['storm.safe_head.grace_seconds', ['safe_head' => ['grace_seconds' => 0]]];

        yield 'an outbox that never attempts a publish' => ['storm.outbox.max_attempts', ['outbox' => ['max_attempts' => 0]]];
        yield 'a negative outbox back-off base' => ['storm.outbox.backoff_base_seconds', ['outbox' => ['backoff_base_seconds' => -1]]];
        yield 'an outbox back-off capped at zero' => ['storm.outbox.backoff_max_seconds', ['outbox' => ['backoff_max_seconds' => 0]]];
        yield 'a stalled row re-read on every tick' => ['storm.outbox.stalled_cooldown_seconds', ['outbox' => ['stalled_cooldown_seconds' => 0]]];
        yield 'a blank events transport' => ['storm.outbox.events_transport', ['outbox' => ['events_transport' => '']]];
        yield 'a signal lane mapped to no transport' => ['storm.outbox.signal_lanes', ['outbox' => ['priority_events_transport' => 'storm_events_priority', 'signal_lanes' => [40 => []]]]];
        yield 'an outbox disposal that is neither delete nor archive' => ['storm.outbox.disposal', ['outbox' => ['disposal' => 'truncate']]];

        yield 'a timer lease of zero' => ['storm.saga.timer_lease_seconds', ['saga' => ['timer_lease_seconds' => 0]]];
        yield 'a market opening before the day starts' => ['storm.saga.calendar.open_hour', self::calendar(['open_hour' => -1])];
        yield 'a market opening after the day ends' => ['storm.saga.calendar.open_hour', self::calendar(['open_hour' => 24])];
        yield 'a market closing at hour zero' => ['storm.saga.calendar.close_hour', self::calendar(['close_hour' => 0])];
        yield 'a market closing past midnight' => ['storm.saga.calendar.close_hour', self::calendar(['close_hour' => 25])];
        // the PAIR, each half of it in range: the bounds are per-field and the window is not, so
        // nothing below the node itself can see an inverted one
        yield 'a market closing before it opens' => ['storm.saga.calendar', self::calendar(['open_hour' => 17, 'close_hour' => 9])];
        yield 'a market whose window is a single instant' => ['storm.saga.calendar', self::calendar(['open_hour' => 9, 'close_hour' => 9])];

        yield 'a command outbox that never attempts a dispatch' => ['storm.saga.command_outbox.max_attempts', ['saga' => ['command_outbox' => ['max_attempts' => 0]]]];
        yield 'a negative command-outbox back-off base' => ['storm.saga.command_outbox.backoff_base_seconds', ['saga' => ['command_outbox' => ['backoff_base_seconds' => -1]]]];
        yield 'a command-outbox back-off capped at zero' => ['storm.saga.command_outbox.backoff_max_seconds', ['saga' => ['command_outbox' => ['backoff_max_seconds' => 0]]]];
        yield 'a command-outbox disposal that is neither delete nor archive' => ['storm.saga.command_outbox.disposal', ['saga' => ['command_outbox' => ['disposal' => 'truncate']]]];

        yield 'a negative occ retry delay' => ['storm.occ.retry_delay_ms', ['occ' => ['retry_delay_ms' => -1]]];
        yield 'a negative occ retry cap' => ['storm.occ.retry_max_delay_ms', ['occ' => ['retry_max_delay_ms' => -1]]];

        yield 'a batch of no messages' => ['storm.inbox.batch_size', ['inbox' => ['batch_size' => 0]]];
        yield 'an idle flush window of zero' => ['storm.inbox.batch_idle_ms', ['inbox' => ['batch_idle_ms' => 0]]];

        yield 'a blank wire type on a neutral transport' => ['storm.neutral_transports', ['neutral_transports' => ['edge' => ['allowed_types' => ['']]]]];

        // Every node that names a Messenger transport carries the same net, so a typo is refused
        // where it is written; unguarded, a blank name reaches the container as the unresolvable
        // service `messenger.transport.` and fails the compile without saying which key wrote it.
        yield 'a blank priority lane transport' => ['storm.outbox.priority_events_transport', ['outbox' => ['priority_events_transport' => ['']]]];
        yield 'a blank signal lane transport' => ['storm.outbox.signal_lanes', ['outbox' => ['priority_events_transport' => 'storm_events_priority', 'signal_lanes' => [40 => ['']]]]];
        yield 'a blank command lane transport' => ['storm.saga.priority.lanes', ['saga' => ['priority' => ['lanes' => [40 => '']]]]];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  callable(self, array<string, mixed>): void  $assert
     */
    #[Test]
    #[DataProvider('values_at_the_bound')]
    public function accepts_the_value_at_the_bound(array $config, callable $assert): void
    {
        $assert($this, $this->process($config));
    }

    /**
     * The other half of every bound. Each refusal above proves what is out of range; without these,
     * a floor written one off, refusing the very value the deployment is meant to set, reads exactly
     * like a correct one.
     *
     * @return iterable<string, array{array<string, mixed>, callable(self, array<string, mixed>): void}>
     */
    public static function values_at_the_bound(): iterable
    {
        yield 'a snapshot on every single event' => [
            self::aggregate(['snapshot' => ['threshold' => 1, 'max_age_seconds' => 1, 'min_interval_seconds' => 1]]),
            static fn (self $t, array $c) => $t->assertSame(
                ['threshold' => 1, 'max_age_seconds' => 1, 'min_interval_seconds' => 1],
                $c['aggregates']['App\\Account']['snapshot'],
            ),
        ];

        yield 'a single publish attempt, no retry at all' => [
            ['outbox' => ['max_attempts' => 1, 'backoff_max_seconds' => 1]],
            static fn (self $t, array $c) => $t->assertSame([1, 1], [$c['outbox']['max_attempts'], $c['outbox']['backoff_max_seconds']]),
        ];

        yield 'a millisecond of safe-head grace' => [
            ['safe_head' => ['grace_seconds' => 0.001]],
            static fn (self $t, array $c) => $t->assertSame(0.001, $c['safe_head']['grace_seconds']),
        ];

        yield 'a one-second negative-liveness memory' => [
            ['stream_existence_cache' => ['enabled' => true, 'negative_ttl_seconds' => 1]],
            static fn (self $t, array $c) => $t->assertSame(1, $c['stream_existence_cache']['negative_ttl_seconds']),
        ];

        yield 'a market open around the clock' => [
            self::calendar(['open_hour' => 0, 'close_hour' => 24]),
            static fn (self $t, array $c) => $t->assertSame([0, 24], [$c['saga']['calendar']['open_hour'], $c['saga']['calendar']['close_hour']]),
        ];

        yield 'a back-off base of zero, on both outboxes' => [
            // The control case of this grid: a bound that reads like a typo and is not one. Zero is
            // accepted here and means the relay's own floor, one second, which is why neither relay
            // may compute a zero delay from it; the pair of adversarial relay tests holds that end.
            ['outbox' => ['backoff_base_seconds' => 0], 'saga' => ['command_outbox' => ['backoff_base_seconds' => 0]]],
            static fn (self $t, array $c) => $t->assertSame(
                [0, 0],
                [$c['outbox']['backoff_base_seconds'], $c['saga']['command_outbox']['backoff_base_seconds']],
            ),
        ];

        yield 'an occ redelivery with no delay and no backoff' => [
            ['occ' => ['retry_delay_ms' => 0, 'retry_max_delay_ms' => 0]],
            static fn (self $t, array $c) => $t->assertSame([0, 0], [$c['occ']['retry_delay_ms'], $c['occ']['retry_max_delay_ms']]),
        ];

        yield 'a batch of one, the no-batching posture' => [
            ['inbox' => ['batch_size' => 1, 'batch_idle_ms' => 1]],
            static fn (self $t, array $c) => $t->assertSame([1, 1], [$c['inbox']['batch_size'], $c['inbox']['batch_idle_ms']]),
        ];

        yield 'a one-second timer lease' => [
            ['saga' => ['timer_lease_seconds' => 1]],
            static fn (self $t, array $c) => $t->assertSame(1, $c['saga']['timer_lease_seconds']),
        ];

        yield 'the archive disposal on both outboxes' => [
            ['outbox' => ['disposal' => 'archive'], 'saga' => ['command_outbox' => ['disposal' => 'archive']]],
            static fn (self $t, array $c) => $t->assertSame(
                ['archive', 'archive'],
                [$c['outbox']['disposal'], $c['saga']['command_outbox']['disposal']],
            ),
        ];

        yield 'the delete disposal, written out rather than inherited' => [
            // A default is not validated against its own enum, so `delete` stays the default value
            // of a list it no longer belongs to; only a deployment writing it out proves it is still
            // a legal choice. The pair with the case above covers both members of both lists.
            ['outbox' => ['disposal' => 'delete'], 'saga' => ['command_outbox' => ['disposal' => 'delete']]],
            static fn (self $t, array $c) => $t->assertSame(
                ['delete', 'delete'],
                [$c['outbox']['disposal'], $c['saga']['command_outbox']['disposal']],
            ),
        ];

        yield 'a stalled row cooling for a single second' => [
            ['outbox' => ['stalled_cooldown_seconds' => 1]],
            static fn (self $t, array $c) => $t->assertSame(1, $c['outbox']['stalled_cooldown_seconds']),
        ];

        yield 'a single dispatch attempt, and a cap of one second, on the command outbox' => [
            ['saga' => ['command_outbox' => ['max_attempts' => 1, 'backoff_max_seconds' => 1]]],
            static fn (self $t, array $c) => $t->assertSame(
                [1, 1],
                [$c['saga']['command_outbox']['max_attempts'], $c['saga']['command_outbox']['backoff_max_seconds']],
            ),
        ];

        yield 'a market open for the last hour of the day' => [
            // One of the two ceilings the round-the-clock case above leaves untouched: 23 is the
            // highest legal start, and the window rule then forces the only close above it.
            self::calendar(['open_hour' => 23, 'close_hour' => 24]),
            static fn (self $t, array $c) => $t->assertSame(
                [23, 24],
                [$c['saga']['calendar']['open_hour'], $c['saga']['calendar']['close_hour']],
            ),
        ];

        yield 'a market open for the first hour of the day' => [
            // and the other: 1 is the lowest legal end, which forces the only open below it
            self::calendar(['open_hour' => 0, 'close_hour' => 1]),
            static fn (self $t, array $c) => $t->assertSame(
                [0, 1],
                [$c['saga']['calendar']['open_hour'], $c['saga']['calendar']['close_hour']],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function aggregate(array $overrides): array
    {
        return ['aggregates' => ['App\\Account' => ['id' => 'App\\AccountId', 'category' => 'account', ...$overrides]]];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function calendar(array $overrides): array
    {
        return ['saga' => ['calendar' => ['enabled' => true, ...$overrides]]];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     *
     * @throws InvalidConfigurationException when the tree refuses the value
     */
    private function process(array $config): array
    {
        $extension = new StormBundle()->getContainerExtension();
        $this->assertInstanceOf(ConfigurationExtensionInterface::class, $extension);

        $configuration = $extension->getConfiguration([], new ContainerBuilder);
        $this->assertNotNull($configuration);

        return new Processor()->processConfiguration($configuration, [$config]);
    }
}
