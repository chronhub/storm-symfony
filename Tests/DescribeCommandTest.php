<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use JsonException;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Storm\Symfony\Describe\StormDescriptor;
use Storm\Symfony\Tests\Boot\AlwaysUpCheck;
use Storm\Symfony\Tests\Boot\CountingProjection;
use Storm\Symfony\Tests\Boot\Domain\PingHappened;
use Storm\Symfony\Tests\Boot\KernelWorkspace;
use Storm\Symfony\Tests\Boot\NoopActivity;
use Storm\Symfony\Tests\Boot\PingReadModel;
use Storm\Symfony\Tests\Boot\PongReceived;
use Storm\Symfony\Tests\Boot\StormTestKernel;
use Storm\Telemetry\Health\DatabaseHealthCheck;
use Storm\Telemetry\Health\OutboxLivenessHealthCheck;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * `storm:describe` against the real minimal kernel, two proofs. The adversarial one is the central
 * contract: the description is STATIC, so it must answer on a kernel whose database is unreachable
 * by construction; `StormTestKernel`'s DSN points at 127.0.0.1:1, a closed port, so ANY connection
 * attempt anywhere in the assembly would throw and fail the run. The second is stability: the same
 * compiled container renders byte-identical JSON run after run, the property that makes the
 * document diffable and pinnable in a CI.
 *
 * @see StormTestKernel the unconnectable-DSN kernel, StormBundle + real deps and nothing more
 * @see StormDescriptor the assembly under test
 */
final class DescribeCommandTest extends KernelTestCase
{
    #[Override]
    public static function setUpBeforeClass(): void
    {
        // a fresh compile every run; a stale cached container would describe yesterday's wiring
        // scoped to THIS kernel's variant: the bare root holds every variant of the process, and
        // sweeping it recompiles, or pulls the ground from under, whatever booted next door
        new Filesystem()->remove(KernelWorkspace::dir('storm-test-kernel'));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new StormTestKernel('test', false);
    }

    #[Test]
    #[Group('adversarial')]
    public function describe_answers_on_a_kernel_whose_database_is_unreachable(): void
    {
        // not an empty database but NO database: the DSN's port is closed, so success proves the
        // whole document assembles without a single connection attempt. Every section is here,
        // health included: enumerating the checks constructs their lazy DBAL deps and calls
        // name() only, so even that section answers with the wire down.
        $tester = self::execute();

        $tester->assertCommandIsSuccessful();
        // read off the constant, never re-typed: the constant is what `--section` validates against,
        // and a section added to the document alone is one the filter refuses while the document
        // carries it. Three hand-written copies of this list made that drift a matter of memory.
        self::assertSame(StormDescriptor::SECTIONS, array_keys(self::decode($tester->getDisplay())));
    }

    #[Test]
    public function two_runs_render_byte_identical_json(): void
    {
        // each execute() boots its own kernel, so this is two full boot-and-render cycles
        $first = self::execute()->getDisplay();
        $second = self::execute()->getDisplay();

        self::assertJson($first);
        self::assertSame($first, $second);
    }

    #[Test]
    public function the_document_renders_the_test_kernel_wiring_precisely(): void
    {
        $document = self::decode(self::execute()->getDisplay());

        self::assertSame(
            ['schema_version' => 1, 'php_version' => PHP_VERSION, 'environment' => 'test'],
            $document['meta'],
        );

        // the #[EventType] scan of the kernel's storm.event_paths, read back from the container,
        // cross-referenced against its real consumers: the filtered read model's declared class
        // and AwaitingWorkflow's #[Signal] handler
        self::assertSame([
            [
                'alias' => 'storm_bundle_test.ping_happened',
                'class' => PingHappened::class,
                'version' => 1,
                'replaces' => [],
                'personal' => null,
                'consumed_by' => [
                    'projections' => ['storm_bundle_ping_rm'],
                    'workflows' => ['storm_bundle_awaiting_test'],
                ],
            ],
        ], $document['event_types']);

        // the two #[AsProjection] fixtures, sorted by name: the filtered read model with its
        // declared-class/expanded-type split, then the bare QueryProjection
        self::assertSame([
            [
                'name' => 'storm_bundle_ping_rm',
                'class' => PingReadModel::class,
                'kind' => 'read-model',
                'categories' => ['article'],
                'event_classes' => [PingHappened::class],
                'event_types' => [PingHappened::class, 'storm_bundle_test.ping_happened'],
                'source_stream' => null,
                'target_stream' => null,
                'target_prefix' => null,
                'generation' => 1,
            ],
            [
                'name' => 'storm_bundle_test_counter',
                'class' => CountingProjection::class,
                'kind' => 'query',
                'categories' => [],
                'event_classes' => [],
                'event_types' => [],
                'source_stream' => null,
                'target_stream' => null,
                'target_prefix' => null,
                'generation' => null,
            ],
        ], $document['projections']);
    }

    #[Test]
    public function the_workflows_section_renders_the_attribute_derived_definitions(): void
    {
        $workflows = self::decode(self::execute()->getDisplay())['workflows'];

        self::assertTrue($workflows['available']);
        self::assertNull($workflows['reason']);

        $byName = array_column($workflows['definitions'], null, 'name');
        self::assertSame(
            // storm_semaphore is the SHIPPED workflow, package-wired: its presence here proves the
            // bundle boots it into the registry beside the app's own definitions
            ['storm_bundle_awaiting_test', 'storm_bundle_external_wait_test', 'storm_bundle_test', 'storm_semaphore'],
            array_keys($byName),
        );

        // PingWorkflow: one activity hop to a final state, rendered field for field
        self::assertSame([
            'name' => 'storm_bundle_test',
            'version' => 1,
            'label' => null,
            'start' => 'ping',
            'global_timeout_seconds' => null,
            'on_global_timeout' => null,
            'compensation' => 'best_effort',
            'retry_budget' => null,
            'reuse' => 'reject',
            'signals' => [],
            'spawns' => [],
            'states' => [
                ['key' => 'done', 'kind' => 'final', 'transitions' => []],
                [
                    'key' => 'ping',
                    'kind' => 'activity',
                    'activity' => NoopActivity::class,
                    'retry' => null,
                    'timeout' => null,
                    'compensation' => null,
                    'compensation_confirmed_by' => null,
                    'circuit_breaker' => null,
                    'fallbacks' => [],
                    'transitions' => [
                        ['trigger' => 'success', 'to' => 'done', 'guarded' => false, 'on_event' => null],
                    ],
                ],
            ],
        ], $byName['storm_bundle_test']);

        // AwaitingWorkflow's wait declares its event CLASS; the lane realization is transport
        // wiring, not workflow declaration, so it does not appear here
        $await = array_column($byName['storm_bundle_awaiting_test']['states'], null, 'key')['await_pong'];
        self::assertSame('wait', $await['kind']);
        self::assertSame([PongReceived::class], $await['event_classes']);
        self::assertSame(
            [['trigger' => 'event', 'to' => 'done', 'guarded' => false, 'on_event' => null]],
            $await['transitions'],
        );

        // its #[Signal] declaration reduces to the signal class; the handler body never renders
        self::assertSame([PingHappened::class], $byName['storm_bundle_awaiting_test']['signals']);
    }

    #[Test]
    public function the_buses_section_renders_the_bus_ids_and_the_compiled_saga_priority(): void
    {
        // ids and default from the bundle's own prepend; the policy from the published parameters:
        // PingWorkflow's class-level #[Prioritized(10)] and the kernel's one configured lane.
        // Transport DSNs and per-bus middleware are app deployment and never appear.
        self::assertSame([
            'default_bus' => 'storm.command.bus',
            'ids' => ['storm.command.bus', 'storm.event.bus', 'storm.query.bus'],
            'saga_priority' => [
                'available' => true,
                'reason' => null,
                'default_level' => null,
                'workflow_levels' => [['workflow' => 'storm_bundle_test', 'level' => 10]],
                'lanes' => [['level' => 10, 'transport' => 'storm_events_priority']],
            ],
        ], self::decode(self::execute()->getDisplay())['buses']);
    }

    #[Test]
    public function the_grants_section_renders_the_compiled_grant_parameters(): void
    {
        // one signed carrier per grant pass, each rendered as its handled MESSAGE class
        self::assertSame([
            'available' => true,
            'reason' => null,
            'transactional_handlers' => [PongReceived::class],
            'inbox_dispatch' => [PingHappened::class],
        ], self::decode(self::execute()->getDisplay())['grants']);
    }

    #[Test]
    public function the_schemas_section_renders_the_owned_tables_per_module(): void
    {
        // table names only, from the verification catalogs; the DDL of record stays docs/schema.sql
        self::assertSame([
            [
                'module' => 'core',
                'available' => true,
                'reason' => null,
                'tables' => [
                    'crypto_keys', 'es_inbox', 'es_outbox', 'es_outbox_archive', 'event_link_streams',
                    'event_links', 'event_store', 'event_store_default', 'event_store_high_water',
                    'projections', 'snapshots', 'stream_heads',
                ],
            ],
            [
                'module' => 'saga',
                'available' => true,
                'reason' => null,
                'tables' => [
                    'circuit_breaker', 'workflow_correlations', 'workflow_instances', 'workflow_outbox',
                    'workflow_outbox_archive', 'workflow_pauses', 'workflow_timers',
                ],
            ],
            [
                'module' => 'telemetry',
                'available' => true,
                'reason' => null,
                'tables' => ['workflow_history'],
            ],
        ], self::decode(self::execute()->getDisplay())['schemas']);
    }

    #[Test]
    public function the_health_section_renders_the_tagged_check_names(): void
    {
        // the two Telemetry built-ins plus the harness fixture, sorted by name; name() is the
        // only method the assembly ever called on them
        self::assertSame([
            'available' => true,
            'reason' => null,
            'checks' => [
                ['name' => 'database', 'class' => DatabaseHealthCheck::class],
                ['name' => 'outbox_liveness', 'class' => OutboxLivenessHealthCheck::class],
                ['name' => 'storm_bundle_test', 'class' => AlwaysUpCheck::class],
            ],
        ], self::decode(self::execute()->getDisplay())['health']);
    }

    #[Test]
    public function a_single_section_renders_alone_under_its_key(): void
    {
        $tester = self::execute(['--section' => 'projections']);

        $tester->assertCommandIsSuccessful();
        self::assertSame(['projections'], array_keys(self::decode($tester->getDisplay())));
    }

    #[Test]
    #[Group('adversarial')]
    public function an_unknown_section_fails_loud_listing_the_valid_ones(): void
    {
        $tester = self::execute(['--section' => 'checkpoints']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());

        // SymfonyStyle wraps its error box, so collapse the whitespace before asserting
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Unknown section "checkpoints"', $display);
        self::assertStringContainsString(
            'Valid sections: meta, event_types, workflows, projections, buses, grants, schemas, health.',
            $display,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function execute(array $input = []): CommandTester
    {
        // bootKernel shuts any previous kernel down first: every call is a fresh boot
        $tester = new CommandTester(new Application(self::bootKernel())->find('storm:describe'));
        $tester->execute($input);

        return $tester;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException when the rendered output is not valid JSON, itself a failure
     */
    private static function decode(string $display): array
    {
        /** @var array<string, mixed> */
        return json_decode($display, true, 512, JSON_THROW_ON_ERROR);
    }
}
