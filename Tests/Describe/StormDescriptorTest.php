<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Describe;

use ArrayIterator;
use ArrayObject;
use Doctrine\DBAL\Connection;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Chronicler\Evolution\MappedEventTypeMapper;
use Storm\Chronicler\Record\EventRecord;
use Storm\Projector\Definition\DerivedStreamProjection;
use Storm\Projector\Definition\FanOutLinkProjection;
use Storm\Projector\Definition\FilteredProjection;
use Storm\Projector\Definition\GroupedReadModel;
use Storm\Projector\Definition\LinkProjection;
use Storm\Projector\Definition\Projection;
use Storm\Projector\Definition\QueryProjection;
use Storm\Projector\Definition\ReadModel;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\Cadence\CronCadence;
use Storm\Saga\Workflow\Cadence\IntervalCadence;
use Storm\Saga\Workflow\Cadence\SpreadCadence;
use Storm\Saga\Workflow\CatchUp;
use Storm\Saga\Workflow\CatchUpPolicy;
use Storm\Saga\Workflow\CircuitBreakerPolicy;
use Storm\Saga\Workflow\CorrelationReuse;
use Storm\Saga\Workflow\Fallback\FallbackCandidate;
use Storm\Saga\Workflow\Fallback\FallbackPolicy;
use Storm\Saga\Workflow\Fallback\StaticFallback;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\RetryPolicy;
use Storm\Saga\Workflow\ScheduleState;
use Storm\Saga\Workflow\SignalResult;
use Storm\Saga\Workflow\SpawnSlot;
use Storm\Saga\Workflow\State;
use Storm\Saga\Workflow\Timeout;
use Storm\Saga\Workflow\Transition;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;
use Storm\Stream\StreamName;
use Storm\Symfony\Describe\StormDescriptor;
use Storm\Symfony\Tests\Describe\Fixture\RenamedEvent;
use Storm\Symfony\Tests\Fixture\ScannerFixtureEvent;
use Storm\Telemetry\Health\HealthCheck;
use Storm\Telemetry\Health\HealthCheckResult;

/**
 * The assembler in isolation: kind derivation from the projection interfaces, the two-list
 * split of declared classes versus expanded types, the declarative-only workflow rendering
 * where closures reduce to presence flags, and the stable-shape absence of the opt-in Saga
 * registry. The no-DB and byte-stability proofs on a real kernel live in DescribeCommandTest.
 */
final class StormDescriptorTest extends TestCase
{
    #[Test]
    public function the_meta_section_carries_the_format_version_and_the_kernel_environment(): void
    {
        $meta = self::descriptor()->describe()['meta'];

        self::assertSame(
            ['schema_version' => 1, 'php_version' => PHP_VERSION, 'environment' => 'test'],
            $meta,
        );
    }

    #[Test]
    public function event_types_render_the_alias_map_sorted_by_alias(): void
    {
        // stdClass carries no #[EventType]: alias falls back to the FQCN, version to 1, the
        // gradual-adoption contract of the mapper, visible as such in the description
        $descriptor = self::descriptor(eventClasses: [stdClass::class, ScannerFixtureEvent::class]);

        self::assertSame([
            [
                'alias' => 'scanner.fixture.real-event',
                'class' => ScannerFixtureEvent::class,
                'version' => 1,
                'replaces' => [],
                'personal' => null,
                'consumed_by' => ['projections' => [], 'workflows' => []],
            ],
            [
                'alias' => stdClass::class,
                'class' => stdClass::class,
                'version' => 1,
                'replaces' => [],
                'personal' => null,
                'consumed_by' => ['projections' => [], 'workflows' => []],
            ],
        ], $descriptor->describe()['event_types']);
    }

    #[Test]
    public function a_marked_class_renders_its_personal_declaration_in_the_catalog(): void
    {
        // the auditor's read: the catalog says who is GDPR-bearing, subject key, ciphered keys,
        // declared fallbacks, straight from the compiled #[Personal] map, no second scan
        $descriptor = self::descriptor(eventClasses: [ScannerFixtureEvent::class], personalData: [
            ScannerFixtureEvent::class => [
                'subject' => 'customer_id',
                'keys' => ['full_name'],
                'fallbacks' => ['full_name' => '⌫'],
            ],
        ]);

        self::assertSame(
            ['subject' => 'customer_id', 'keys' => ['full_name'], 'fallbacks' => ['full_name' => '⌫']],
            $descriptor->describe()['event_types'][0]['personal'],
        );
    }

    #[Test]
    public function a_personal_class_without_an_event_type_still_enters_the_catalog(): void
    {
        // #[EventType] is optional. A #[Personal] class rides the FQCN-fallback mapping, and
        // omitting it from the very catalog an auditor reads to know who is GDPR-bearing would be
        // a lie; the catalog walks the UNION of both scan products
        $descriptor = self::descriptor(eventClasses: [], personalData: [
            ScannerFixtureEvent::class => [
                'subject' => 'customer_id',
                'keys' => ['full_name'],
                'fallbacks' => [],
            ],
        ]);

        $entry = $descriptor->describe()['event_types'][0];

        self::assertSame(ScannerFixtureEvent::class, $entry['class']);
        self::assertSame(['subject' => 'customer_id', 'keys' => ['full_name'], 'fallbacks' => []], $entry['personal']);
    }

    #[Test]
    public function kind_is_derived_from_the_projection_interfaces(): void
    {
        $registry = new ProjectionRegistry([
            self::readModel('rm_orders'),
            self::groupedReadModel('rm_customers_360'),
            self::linkProjection('lk_payments', 'payment_links'),
            self::fanOutLinkProjection('lk_by_type', 'et-'),
            self::queryProjection('live_counter'),
            self::derivedReadModel('rm_from_links', 'payment_links'),
        ]);

        $kinds = [];
        foreach (self::descriptor(projections: $registry)->describe()['projections'] as $entry) {
            $kinds[$entry['name']] = $entry['kind'];
        }

        self::assertSame([
            'live_counter' => 'query',
            'lk_by_type' => 'fan-out-link',
            'lk_payments' => 'link',
            'rm_customers_360' => 'grouped-read-model',
            'rm_from_links' => 'read-model',
            'rm_orders' => 'read-model',
        ], $kinds);
    }

    #[Test]
    public function projection_entries_are_sorted_by_name_with_the_full_declarative_topology(): void
    {
        $registry = new ProjectionRegistry([
            self::linkProjection('lk_payments', 'payment_links'),
            self::derivedReadModel('rm_from_links', 'payment_links'),
        ]);

        $entries = self::descriptor(projections: $registry)->describe()['projections'];

        self::assertSame([
            [
                'name' => 'lk_payments',
                'class' => $entries[0]['class'],
                'kind' => 'link',
                'categories' => ['account', 'payment'],
                'event_classes' => [ScannerFixtureEvent::class],
                'event_types' => [ScannerFixtureEvent::class, 'scanner.fixture.real-event'],
                'source_stream' => null,
                'target_stream' => 'payment_links',
                'target_prefix' => null,
                'generation' => 2,
            ],
            [
                'name' => 'rm_from_links',
                'class' => $entries[1]['class'],
                'kind' => 'read-model',
                'categories' => [],
                'event_classes' => [],
                'event_types' => [],
                'source_stream' => 'payment_links',
                'target_stream' => null,
                'target_prefix' => null,
                'generation' => 1,
            ],
        ], $entries);
    }

    #[Test]
    public function declared_classes_and_expanded_types_render_as_two_separate_lists(): void
    {
        // the topology-gate split: the class list is the dev's intent; the type list is the
        // alias expansion the read SQL filters on, which an added #[EventType] legitimately moves
        $registry = new ProjectionRegistry([self::readModel('rm_orders')]);

        $entry = self::descriptor(projections: $registry)->describe()['projections'][0];

        self::assertSame([ScannerFixtureEvent::class], $entry['event_classes']);
        self::assertSame([ScannerFixtureEvent::class, 'scanner.fixture.real-event'], $entry['event_types']);
    }

    #[Test]
    public function a_signal_handling_workflow_does_not_hide_the_consumers_declared_behind_it(): void
    {
        // The consumer walk skips to the next definition once a workflow handles the class as a
        // SIGNAL, and it must keep walking: ending there would drop every workflow declared behind
        // the first signal handler, and two operators diffing `storm:describe` would read one
        // consumer where the topology has two.
        $signals = new WorkflowDefinition(
            name: 'signals_first',
            states: ['done' => new FinalState('done')],
            start: 'done',
            signalHandlers: [
                stdClass::class => static fn (object $signal, array $vars): SignalResult => SignalResult::stay($vars),
            ],
        );
        $waiter = new WorkflowDefinition(
            name: 'waits_behind',
            states: [
                'await' => new WaitState('await', eventClasses: [stdClass::class]),
                'done' => new FinalState('done'),
            ],
            start: 'await',
        );

        $descriptor = self::descriptor(
            workflows: new WorkflowRegistry([$signals, $waiter]),
            eventClasses: [stdClass::class],
        );

        self::assertSame(
            ['signals_first', 'waits_behind'],
            $descriptor->describe()['event_types'][0]['consumed_by']['workflows'],
        );
    }

    #[Test]
    public function the_workflows_section_reports_the_missing_saga_package_with_a_reason(): void
    {
        // shape stability over a vanishing key: a consumer diffing two deployments must read
        // "saga not installed", never wonder where the section went
        $section = self::descriptor(workflows: null)->describe()['workflows'];

        self::assertSame([
            'available' => false,
            'reason' => 'the opt-in Saga package is not installed, so no workflow registry is wired',
            'definitions' => [],
        ], $section);
    }

    #[Test]
    public function a_workflow_definition_renders_its_declarative_surface_without_closure_content(): void
    {
        $registry = new WorkflowRegistry([self::paymentDefinition()]);

        $section = self::descriptor(workflows: $registry)->describe()['workflows'];

        self::assertTrue($section['available']);
        self::assertNull($section['reason']);
        self::assertSame([
            'name' => 'payment',
            'version' => 2,
            'label' => 'v2',
            'start' => 'charge',
            'global_timeout_seconds' => 3600,
            'on_global_timeout' => 'failed',
            'compensation' => 'best_effort',
            'retry_budget' => 5,
            'reuse' => 'allow',
            'signals' => [stdClass::class],
            'spawns' => [
                ['slot' => 'refund', 'workflow' => 'refund_flow', 'awaited_by' => 'await_confirm', 'indexed' => false],
            ],
            'states' => [
                [
                    'key' => 'await_confirm',
                    'kind' => 'wait',
                    'event_classes' => [ScannerFixtureEvent::class],
                    'event_types' => ['payment.confirmed'],
                    'matcher' => true,
                    'extract' => false,
                    'extract_map' => [
                        ['var' => 'amount', 'field' => 'total'],
                        ['var' => 'currency', 'field' => 'ccy'],
                    ],
                    'retriable' => false,
                    'timeout' => ['seconds' => 900, 'business_days' => null, 'business_hours' => null],
                    'transitions' => [
                        ['trigger' => 'event', 'to' => 'done', 'guarded' => false, 'on_event' => null],
                        ['trigger' => 'timeout', 'to' => 'failed', 'guarded' => false, 'on_event' => null],
                    ],
                ],
                [
                    'key' => 'charge',
                    'kind' => 'activity',
                    'activity' => NoopDescribeActivity::class,
                    'retry' => [
                        'max_attempts' => 3,
                        'strategy' => 'exponential',
                        'base_ms' => 500,
                        'jitter' => true,
                        'retry_on' => [],
                        'do_not_retry_on' => ['InvalidCard', 'ValidationError'],
                    ],
                    'timeout' => ['seconds' => 60, 'business_days' => null, 'business_hours' => null],
                    'compensation' => null,
                    'compensation_confirmed_by' => null,
                    'circuit_breaker' => null,
                    'fallbacks' => [],
                    // declaration order kept: the selector walks the declared edges in order
                    'transitions' => [
                        ['trigger' => 'success', 'to' => 'await_confirm', 'guarded' => false, 'on_event' => null],
                        ['trigger' => 'failure', 'to' => 'failed', 'guarded' => true, 'on_event' => null],
                    ],
                ],
                ['key' => 'done', 'kind' => 'final', 'transitions' => []],
                ['key' => 'failed', 'kind' => 'final', 'transitions' => []],
            ],
        ], $section['definitions'][0]);
    }

    #[Test]
    public function a_renamed_event_lists_its_former_aliases_sorted_and_as_a_list(): void
    {
        // What an operator reads to know which stored rows still answer to this class. The aliases
        // are declared out of order on purpose; and the diff that drops the current alias leaves
        // gaps in the keys, so a rendering that does not reindex turns this list into a JSON object
        // the moment the class is renamed once.
        $descriptor = new StormDescriptor(
            new ProjectionRegistry([]),
            new MappedEventTypeMapper([RenamedEvent::class]),
            null,
            [RenamedEvent::class],
            'test',
        );

        self::assertSame(
            ['describe.fixture.ancient-name', 'describe.fixture.old-name'],
            $descriptor->describe()['event_types'][0]['replaces'],
        );
    }

    #[Test]
    public function a_class_carried_by_both_scans_enters_the_catalog_once(): void
    {
        // The catalog walks the UNION of the #[EventType] scan and the #[Personal] map, and a class
        // marked both ways sits in both. Rendered twice it would double every count an auditor
        // takes from this section, and read as two classes sharing one alias.
        $descriptor = self::descriptor(eventClasses: [ScannerFixtureEvent::class], personalData: [
            ScannerFixtureEvent::class => ['subject' => 'customer_id', 'keys' => ['full_name'], 'fallbacks' => []],
        ]);

        self::assertCount(1, $descriptor->describe()['event_types']);
    }

    #[Test]
    public function health_checks_render_sorted_rather_than_in_registration_order(): void
    {
        $descriptor = self::descriptor(healthChecks: [
            self::healthCheck('queue'),
            self::healthCheck('database'),
        ]);

        self::assertSame(
            ['database', 'queue'],
            array_column($descriptor->describe()['health']['checks'], 'name'),
        );
    }

    #[Test]
    public function one_missing_grant_parameter_is_enough_to_report_the_section_unavailable(): void
    {
        // Both parameters come from compiler passes that run together, so the pair is the unit: with
        // one absent the section describes a container it did not read, and an empty grant list
        // reads as "nothing is granted" rather than "nobody looked".
        $section = self::descriptor(transactionalHandlers: null, inboxDispatchGrants: [])->describe()['grants'];

        self::assertFalse($section['available']);
        self::assertNotNull($section['reason']);
    }

    #[Test]
    public function the_ordered_lists_of_a_definition_are_sorted_rather_than_declared(): void
    {
        // The nominal fixture carries ONE signal and ONE spawn, and a list of one is sorted whatever
        // the code does. Two operators comparing `storm:describe` across environments diff these
        // documents, so an order that follows declaration makes an identical topology read as a
        // difference. Three of each, declared out of order, is what proves the sort exists.
        $definition = new WorkflowDefinition(
            name: 'orders',
            states: ['done' => new FinalState('done')],
            start: 'done',
            signalHandlers: [
                stdClass::class => static fn (object $signal, array $vars): SignalResult => SignalResult::stay($vars),
                ArrayObject::class => static fn (object $signal, array $vars): SignalResult => SignalResult::stay($vars),
                ArrayIterator::class => static fn (object $signal, array $vars): SignalResult => SignalResult::stay($vars),
            ],
            spawns: [
                'refund' => new SpawnSlot('refund', 'refund_flow', 'await_confirm'),
                'audit' => new SpawnSlot('audit', 'audit_flow', 'await_audit'),
                'ship' => new SpawnSlot('ship', 'ship_flow', 'await_ship'),
            ],
        );

        $rendered = self::descriptor(workflows: new WorkflowRegistry([$definition]))->describe()['workflows']['definitions'][0];

        self::assertSame([ArrayIterator::class, ArrayObject::class, stdClass::class], $rendered['signals']);
        self::assertSame(['audit', 'refund', 'ship'], array_column($rendered['spawns'], 'slot'));
    }

    #[Test]
    public function a_fifth_state_kind_refuses_the_document_rather_than_describing_a_hole(): void
    {
        // the hierarchy is sealed by package CONVENTION, not by the language: a fifth kind added to
        // Saga compiles here without a word. A default arm answering an empty shape would render a
        // state with no kind, and an operator diffing this document across environments would read
        // a workflow that is not the one running, with nothing saying so. The refusal is the
        // reminder that every instanceof dispatch, this one included, has to be revisited
        $definition = new WorkflowDefinition(
            name: 'sealed',
            states: ['rogue' => new class('rogue') extends State {}],
            start: 'rogue',
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('#sealed to activity/wait/schedule/final#');

        self::descriptor(workflows: new WorkflowRegistry([$definition]))->describe();
    }

    #[Test]
    public function an_activity_renders_its_breaker_and_its_fallback_chain(): void
    {
        // Both blocks render as null and empty in the nominal fixture, so every field inside them is
        // unproven: the breaker's four values, and the two the chain reports per candidate. An
        // operator reads this section to know what protects a step and what degrades it; a field
        // that silently renders null describes a workflow that is not the one running.
        $definition = new WorkflowDefinition(
            name: 'charging',
            states: [
                'charge' => new ActivityState(
                    'charge',
                    new NoopDescribeActivity,
                    circuitBreaker: new CircuitBreakerPolicy('psp', 5, 30, 'merchant_id'),
                    fallback: new FallbackPolicy([
                        new FallbackCandidate(new StaticFallback(['degraded' => true]), static fn (array $vars): bool => true),
                        new FallbackCandidate(new StaticFallback(['degraded' => false])),
                    ]),
                    transitions: [new Transition(OnTrigger::Success, 'done')],
                ),
                'done' => new FinalState('done'),
            ],
            start: 'charge',
        );

        $charge = self::descriptor(workflows: new WorkflowRegistry([$definition]))->describe()['workflows']['definitions'][0]['states'][0];

        self::assertSame([
            'key' => 'psp',
            'failure_threshold' => 5,
            'cooldown_seconds' => 30,
            'resource_key' => 'merchant_id',
        ], $charge['circuit_breaker']);

        // declaration order, and the guarded flag distinguishing a conditional candidate from the
        // unconditional tail the chain ends on
        self::assertSame([
            ['strategy' => StaticFallback::class, 'guarded' => true],
            ['strategy' => StaticFallback::class, 'guarded' => false],
        ], $charge['fallbacks']);
    }

    #[Test]
    public function definitions_are_sorted_by_name_then_version(): void
    {
        $registry = new WorkflowRegistry([
            self::minimalDefinition('payment', 2),
            self::minimalDefinition('billing', 1),
            self::minimalDefinition('payment', 1),
        ]);

        $rendered = self::descriptor(workflows: $registry)->describe()['workflows']['definitions'];

        self::assertSame(
            [['billing', 1], ['payment', 1], ['payment', 2]],
            array_map(static fn (array $definition): array => [$definition['name'], $definition['version']], $rendered),
        );
    }

    #[Test]
    public function a_schedule_state_renders_its_cadence_and_catch_up_policy(): void
    {
        $definition = new WorkflowDefinition(
            name: 'fee_sweep',
            states: [
                'tick' => new ScheduleState(
                    'tick',
                    new IntervalCadence(86400),
                    new CatchUpPolicy(CatchUp::ReplayBounded, limit: 3),
                    transitions: [new Transition(OnTrigger::Schedule, 'tick')],
                    spreadSeconds: 300,
                ),
            ],
            start: 'tick',
        );

        $states = self::descriptor(workflows: new WorkflowRegistry([$definition]))
            ->describe()['workflows']['definitions'][0]['states'];

        self::assertSame([
            'key' => 'tick',
            'kind' => 'schedule',
            'cadence' => ['type' => 'interval', 'seconds' => 86400, 'expression' => null, 'var' => null, 'method' => null],
            'catch_up' => ['mode' => 'bounded', 'limit' => 3, 'window_seconds' => null],
            'spread_seconds' => 300,
            'transitions' => [
                ['trigger' => 'schedule', 'to' => 'tick', 'guarded' => false, 'on_event' => null],
            ],
        ], $states[0]);
    }

    #[Test]
    public function a_fixed_cron_cadence_renders_its_declared_expression(): void
    {
        // without the declared string, a cron schedule reads as
        // an opaque "cron, null"; the parsed form stays private, the declaration does not
        $definition = new WorkflowDefinition(
            name: 'nightly_sweep',
            states: [
                'tick' => new ScheduleState(
                    'tick',
                    new CronCadence('30 2 * * *'),
                    new CatchUpPolicy(CatchUp::Skip),
                    transitions: [new Transition(OnTrigger::Schedule, 'tick')],
                ),
            ],
            start: 'tick',
        );

        $states = self::descriptor(workflows: new WorkflowRegistry([$definition]))
            ->describe()['workflows']['definitions'][0]['states'];

        self::assertSame(
            ['type' => 'cron', 'seconds' => null, 'expression' => '30 2 * * *', 'var' => null, 'method' => null],
            $states[0]['cadence'],
        );
    }

    #[Test]
    public function a_per_instance_cadence_renders_its_declared_source_never_its_resolver(): void
    {
        // the resolver closure is opaque at description time; the var/method NAME it was built
        // from is the declarative truth, carried as state metadata by the builder
        $definition = new WorkflowDefinition(
            name: 'per_tier_refresh',
            states: [
                'tick' => new ScheduleState(
                    'tick',
                    null,
                    new CatchUpPolicy(CatchUp::Skip),
                    transitions: [new Transition(OnTrigger::Schedule, 'tick')],
                    intervalResolver: static fn (array $vars): int => 900,
                    cadenceMethod: 'refreshInterval',
                ),
            ],
            start: 'tick',
        );

        $states = self::descriptor(workflows: new WorkflowRegistry([$definition]))
            ->describe()['workflows']['definitions'][0]['states'];

        self::assertSame(
            ['type' => 'per_instance_interval', 'seconds' => null, 'expression' => null, 'var' => null, 'method' => 'refreshInterval'],
            $states[0]['cadence'],
        );
    }

    #[Test]
    public function a_cadence_the_descriptor_does_not_name_renders_as_its_own_class(): void
    {
        // The two named kinds are interval and cron; a `Cadence` is an interface, so a third
        // implementation is legal and the shipped `SpreadCadence` is one. There is nothing honest to
        // say about its grid from here, so the description names the CLASS and leaves every declared
        // field null rather than borrowing the shape of whichever kind it happens to wrap.
        $definition = new WorkflowDefinition(
            name: 'spread_refresh',
            states: [
                'tick' => new ScheduleState(
                    'tick',
                    new SpreadCadence(new IntervalCadence(3600), 120),
                    new CatchUpPolicy(CatchUp::Skip),
                    transitions: [new Transition(OnTrigger::Schedule, 'tick')],
                ),
            ],
            start: 'tick',
        );

        $states = self::descriptor(workflows: new WorkflowRegistry([$definition]))
            ->describe()['workflows']['definitions'][0]['states'];

        self::assertSame(
            ['type' => SpreadCadence::class, 'seconds' => null, 'expression' => null, 'var' => null, 'method' => null],
            $states[0]['cadence'],
        );
    }

    #[Test]
    public function a_per_instance_cron_renders_the_var_it_reads_and_no_expression(): void
    {
        // The last arm, and the one an expression would be a lie about: a cron resolved per instance
        // has no expression until an instance is in hand, so the description carries the VAR it will
        // be read from. Its sibling arm answers `per_instance_interval` from a different resolver,
        // and the two are told apart by which resolver the state was built with, never by the names.
        $definition = new WorkflowDefinition(
            name: 'per_tenant_billing',
            states: [
                'tick' => new ScheduleState(
                    'tick',
                    null,
                    new CatchUpPolicy(CatchUp::Skip),
                    transitions: [new Transition(OnTrigger::Schedule, 'tick')],
                    cronResolver: static fn (array $vars): string => '0 3 * * *',
                    cadenceVar: 'billing_cron',
                ),
            ],
            start: 'tick',
        );

        $states = self::descriptor(workflows: new WorkflowRegistry([$definition]))
            ->describe()['workflows']['definitions'][0]['states'];

        self::assertSame(
            ['type' => 'per_instance_cron', 'seconds' => null, 'expression' => null, 'var' => 'billing_cron', 'method' => null],
            $states[0]['cadence'],
        );
    }

    #[Test]
    public function two_describes_render_the_identical_document(): void
    {
        $descriptor = self::descriptor(
            projections: new ProjectionRegistry([self::readModel('rm_orders'), self::queryProjection('live_counter')]),
            workflows: new WorkflowRegistry([self::paymentDefinition()]),
            eventClasses: [ScannerFixtureEvent::class],
        );

        self::assertSame($descriptor->describe(), $descriptor->describe());
    }

    #[Test]
    public function an_event_class_consumed_by_two_projections_and_a_workflow_lists_both_sorted(): void
    {
        // both projections DECLARE the class; the workflow's await_confirm waits on it, and the
        // cross-reference reads the same registries the sibling sections render
        $descriptor = self::descriptor(
            projections: new ProjectionRegistry([self::readModel('rm_orders'), self::linkProjection('lk_payments', 'payment_links')]),
            workflows: new WorkflowRegistry([self::paymentDefinition()]),
            eventClasses: [ScannerFixtureEvent::class],
        );

        self::assertSame(
            ['projections' => ['lk_payments', 'rm_orders'], 'workflows' => ['payment']],
            $descriptor->describe()['event_types'][0]['consumed_by'],
        );
    }

    #[Test]
    public function co_registered_versions_of_a_workflow_count_once_as_a_consumer(): void
    {
        $registry = new WorkflowRegistry([
            self::waitingDefinition('payment', 1),
            self::waitingDefinition('payment', 2),
        ]);

        $entry = self::descriptor(workflows: $registry, eventClasses: [ScannerFixtureEvent::class])
            ->describe()['event_types'][0];

        self::assertSame(['payment'], $entry['consumed_by']['workflows']);
    }

    #[Test]
    public function a_signal_handler_makes_its_workflow_a_consumer_of_the_signal_class(): void
    {
        // paymentDefinition takes stdClass as a SIGNAL, not a wait: the instruction-side twin
        // still consumes the class, so the catalog must list the workflow under it
        $entry = self::descriptor(
            workflows: new WorkflowRegistry([self::paymentDefinition()]),
            eventClasses: [stdClass::class],
        )->describe()['event_types'][0];

        self::assertSame(['projections' => [], 'workflows' => ['payment']], $entry['consumed_by']);
    }

    #[Test]
    public function a_wait_declared_by_alias_makes_its_workflow_a_consumer_of_the_aliased_class(): void
    {
        // the wait names the stable string alias, no class at all; the entry's canonical alias
        // is what joins them; a class-only match would silently drop this real consumer
        $registry = new WorkflowRegistry([
            self::waitingDefinition('alias_waiter', 1, eventClasses: [], eventTypes: ['scanner.fixture.real-event']),
        ]);

        $entry = self::descriptor(workflows: $registry, eventClasses: [ScannerFixtureEvent::class])
            ->describe()['event_types'][0];

        self::assertSame(['alias_waiter'], $entry['consumed_by']['workflows']);
    }

    #[Test]
    public function the_buses_section_carries_the_bus_ids_and_the_default_bus(): void
    {
        $buses = self::descriptor()->describe()['buses'];

        self::assertSame('storm.command.bus', $buses['default_bus']);
        self::assertSame(['storm.command.bus', 'storm.event.bus', 'storm.query.bus'], $buses['ids']);
    }

    #[Test]
    public function the_saga_priority_policy_reports_the_missing_saga_package_with_a_reason(): void
    {
        self::assertSame([
            'available' => false,
            'reason' => 'the opt-in Saga package is not installed, so no priority policy is wired',
            'default_level' => null,
            'workflow_levels' => [],
            'lanes' => [],
        ], self::descriptor(workflows: null)->describe()['buses']['saga_priority']);
    }

    #[Test]
    public function the_saga_priority_policy_renders_levels_and_lanes_as_sorted_records(): void
    {
        // both maps arrive keyed by DATA, workflow name and level; the canonical form is a record
        // list sorted by that key, never a JSON object whose keys are data
        $descriptor = self::descriptor(
            workflows: new WorkflowRegistry([self::minimalDefinition('billing', 1)]),
            priorityDefault: 20,
            priorityLanes: [40 => 'critical', 10 => 'bulk'],
            workflowPriorities: ['payment' => 30, 'billing' => 10],
        );

        self::assertSame([
            'available' => true,
            'reason' => null,
            'default_level' => 20,
            'workflow_levels' => [
                ['workflow' => 'billing', 'level' => 10],
                ['workflow' => 'payment', 'level' => 30],
            ],
            'lanes' => [
                ['level' => 10, 'transport' => 'bulk'],
                ['level' => 40, 'transport' => 'critical'],
            ],
        ], $descriptor->describe()['buses']['saga_priority']);
    }

    #[Test]
    public function the_grants_section_reports_missing_compiled_parameters_with_a_reason(): void
    {
        self::assertSame([
            'available' => false,
            'reason' => 'the grant compiler passes did not run against this container, so no grant parameters are compiled',
            'transactional_handlers' => [],
            'inbox_dispatch' => [],
        ], self::descriptor()->describe()['grants']);
    }

    #[Test]
    public function the_grants_section_renders_the_compiled_grant_lists_sorted(): void
    {
        $descriptor = self::descriptor(
            transactionalHandlers: [stdClass::class, ScannerFixtureEvent::class],
            inboxDispatchGrants: [stdClass::class, LogicException::class],
        );

        self::assertSame([
            'available' => true,
            'reason' => null,
            'transactional_handlers' => [ScannerFixtureEvent::class, stdClass::class],
            'inbox_dispatch' => [LogicException::class, stdClass::class],
        ], $descriptor->describe()['grants']);
    }

    #[Test]
    public function the_schemas_section_reports_the_opt_in_modules_absent_with_a_reason(): void
    {
        // core is required and always owns its tables; the two opt-in modules keep the stable
        // absent shape instead of vanishing from the record list
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
                'available' => false,
                'reason' => 'the opt-in Saga package is not installed, so it owns no tables',
                'tables' => [],
            ],
            [
                'module' => 'telemetry',
                'available' => false,
                'reason' => 'the opt-in Telemetry package is not installed, so it owns no tables',
                'tables' => [],
            ],
        ], self::descriptor()->describe()['schemas']);
    }

    #[Test]
    public function the_schemas_section_lists_the_opt_in_tables_when_the_packages_are_wired(): void
    {
        // the workflow registry witnesses Saga, the check iterable witnesses Telemetry: the same
        // two witnesses the bundle wires, one per opt-in package
        $modules = self::descriptor(
            workflows: new WorkflowRegistry([self::minimalDefinition('billing', 1)]),
            healthChecks: [],
        )->describe()['schemas'];

        self::assertSame([
            'module' => 'saga',
            'available' => true,
            'reason' => null,
            'tables' => [
                'circuit_breaker', 'workflow_correlations', 'workflow_instances', 'workflow_outbox',
                'workflow_outbox_archive', 'workflow_pauses', 'workflow_timers',
            ],
        ], $modules[1]);

        self::assertSame([
            'module' => 'telemetry',
            'available' => true,
            'reason' => null,
            'tables' => ['workflow_history'],
        ], $modules[2]);
    }

    #[Test]
    public function the_health_section_reports_the_missing_telemetry_package_with_a_reason(): void
    {
        self::assertSame([
            'available' => false,
            'reason' => 'the opt-in Telemetry package is not installed, so no health checks are wired',
            'checks' => [],
        ], self::descriptor()->describe()['health']);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_health_section_enumerates_names_without_running_any_probe(): void
    {
        // check() throws on purpose: a describe that probed would blow up here. Only name() may
        // be called: the document is wiring, a probe is runtime.
        $exploding = new class() implements HealthCheck
        {
            public function name(): string
            {
                return 'zz_probe';
            }

            public function check(): HealthCheckResult
            {
                throw new LogicException('check() must never run during describe');
            }
        };

        $quiet = new class() implements HealthCheck
        {
            public function name(): string
            {
                return 'aa_probe';
            }

            public function check(): HealthCheckResult
            {
                return HealthCheckResult::ok();
            }
        };

        self::assertSame([
            'available' => true,
            'reason' => null,
            'checks' => [
                ['name' => 'aa_probe', 'class' => $quiet::class],
                ['name' => 'zz_probe', 'class' => $exploding::class],
            ],
        ], self::descriptor(healthChecks: [$exploding, $quiet])->describe()['health']);
    }

    /**
     * A named probe, so a rendering can be checked against an order the registration does not have.
     */
    private static function healthCheck(string $name): HealthCheck
    {
        return new readonly class($name) implements HealthCheck
        {
            public function __construct(private string $name) {}

            public function name(): string
            {
                return $this->name;
            }

            public function check(): HealthCheckResult
            {
                return HealthCheckResult::ok();
            }
        };
    }

    /**
     * @param  list<class-string>  $eventClasses
     * @param  list<class-string>|null  $transactionalHandlers
     * @param  list<class-string>|null  $inboxDispatchGrants
     * @param  array<int, string>  $priorityLanes
     * @param  array<string, int>  $workflowPriorities
     * @param  iterable<HealthCheck>|null  $healthChecks
     * @param  array<class-string, array{subject: string, keys: list<string>, fallbacks: array<string, scalar|null>}>  $personalData
     */
    private static function descriptor(
        ?ProjectionRegistry $projections = null,
        ?WorkflowRegistry $workflows = null,
        array $eventClasses = [],
        ?array $transactionalHandlers = null,
        ?array $inboxDispatchGrants = null,
        ?int $priorityDefault = null,
        array $priorityLanes = [],
        array $workflowPriorities = [],
        ?iterable $healthChecks = null,
        array $personalData = [],
    ): StormDescriptor {
        return new StormDescriptor(
            $projections ?? new ProjectionRegistry([]),
            new MappedEventTypeMapper([ScannerFixtureEvent::class]),
            $workflows,
            $eventClasses,
            'test',
            $transactionalHandlers,
            $inboxDispatchGrants,
            $priorityDefault,
            $priorityLanes,
            $workflowPriorities,
            $healthChecks,
            $personalData,
        );
    }

    /**
     * The smallest definition whose single wait subscribes by class and/or by stable alias.
     *
     * @param  list<class-string>  $eventClasses
     * @param  list<string>  $eventTypes
     */
    private static function waitingDefinition(string $name, int $version, array $eventClasses = [ScannerFixtureEvent::class], array $eventTypes = []): WorkflowDefinition
    {
        return new WorkflowDefinition(
            name: $name,
            states: [
                'await' => new WaitState(
                    'await',
                    eventClasses: $eventClasses,
                    eventTypes: $eventTypes,
                    transitions: [new Transition(OnTrigger::Event, 'done')],
                ),
                'done' => new FinalState('done'),
            ],
            start: 'await',
            version: $version,
        );
    }

    private static function paymentDefinition(): WorkflowDefinition
    {
        return new WorkflowDefinition(
            name: 'payment',
            states: [
                'charge' => new ActivityState(
                    'charge',
                    new NoopDescribeActivity,
                    retry: new RetryPolicy(3, doNotRetryOn: ['ValidationError', 'InvalidCard']),
                    timeout: new Timeout(60),
                    transitions: [
                        new Transition(OnTrigger::Success, 'await_confirm'),
                        new Transition(OnTrigger::Failure, 'failed', guard: static fn (array $vars): bool => true),
                    ],
                ),
                'await_confirm' => new WaitState(
                    'await_confirm',
                    eventClasses: [ScannerFixtureEvent::class],
                    eventTypes: ['payment.confirmed'],
                    matcher: static fn (object $event, array $vars): bool => true,
                    timeout: new Timeout(900),
                    extractMap: ['currency' => 'ccy', 'amount' => 'total'],
                    transitions: [
                        new Transition(OnTrigger::Event, 'done'),
                        new Transition(OnTrigger::Timeout, 'failed'),
                    ],
                ),
                'done' => new FinalState('done'),
                'failed' => new FinalState('failed'),
            ],
            start: 'charge',
            globalTimeout: 3600,
            onGlobalTimeout: 'failed',
            version: 2,
            label: 'v2',
            retryBudget: 5,
            signalHandlers: [
                stdClass::class => static fn (object $signal, array $vars): SignalResult => SignalResult::stay($vars),
            ],
            spawns: ['refund' => new SpawnSlot('refund', 'refund_flow', 'await_confirm')],
            reuse: CorrelationReuse::Allow,
        );
    }

    private static function minimalDefinition(string $name, int $version): WorkflowDefinition
    {
        return new WorkflowDefinition(
            name: $name,
            states: ['done' => new FinalState('done')],
            start: 'done',
            version: $version,
        );
    }

    private static function readModel(string $name): Projection
    {
        return new readonly class($name) implements FilteredProjection, ReadModel
        {
            public function __construct(private string $name) {}

            public function name(): string
            {
                return $this->name;
            }

            public function apply(EventRecord $event, Connection $tx): bool
            {
                return true;
            }

            public function initialize(Connection $tx): void {}

            public function clear(Connection $tx): void {}

            public function drop(Connection $tx): void {}

            public function generation(): int
            {
                return 3;
            }

            public function categories(): array
            {
                return ['order', 'account'];
            }

            public function eventTypes(): array
            {
                return [ScannerFixtureEvent::class];
            }
        };
    }

    private static function groupedReadModel(string $name): Projection
    {
        return new readonly class($name) implements GroupedReadModel
        {
            public function __construct(private string $name) {}

            public function name(): string
            {
                return $this->name;
            }

            public function apply(EventRecord $event, Connection $tx): bool
            {
                return true;
            }

            public function groupKeys(EventRecord $event): array
            {
                return [];
            }

            public function applyTo(string $key, EventRecord $event, Connection $tx): bool
            {
                return true;
            }

            public function initialize(Connection $tx): void {}

            public function clear(Connection $tx): void {}

            public function drop(Connection $tx): void {}

            public function generation(): int
            {
                return 1;
            }

            public function categories(): array
            {
                return ['customer'];
            }

            public function eventTypes(): array
            {
                return [];
            }
        };
    }

    private static function linkProjection(string $name, string $target): Projection
    {
        return new readonly class($name, $target) implements LinkProjection
        {
            public function __construct(private string $name, private string $target) {}

            public function name(): string
            {
                return $this->name;
            }

            public function apply(EventRecord $event, Connection $tx): bool
            {
                return true;
            }

            public function initialize(Connection $tx): void {}

            public function clear(Connection $tx): void {}

            public function drop(Connection $tx): void {}

            public function generation(): int
            {
                return 2;
            }

            public function categories(): array
            {
                return ['payment', 'account'];
            }

            public function eventTypes(): array
            {
                return [ScannerFixtureEvent::class];
            }

            public function targetStream(): StreamName
            {
                return new StreamName($this->target);
            }
        };
    }

    private static function fanOutLinkProjection(string $name, string $prefix): Projection
    {
        return new readonly class($name, $prefix) implements FanOutLinkProjection
        {
            public function __construct(private string $name, private string $prefix) {}

            public function name(): string
            {
                return $this->name;
            }

            public function apply(EventRecord $event, Connection $tx): bool
            {
                return true;
            }

            public function initialize(Connection $tx): void {}

            public function clear(Connection $tx): void {}

            public function drop(Connection $tx): void {}

            public function generation(): int
            {
                return 1;
            }

            public function categories(): array
            {
                return [];
            }

            public function eventTypes(): array
            {
                return [];
            }

            public function targetFor(EventRecord $event): ?StreamName
            {
                return null;
            }

            public function targetPrefix(): string
            {
                return $this->prefix;
            }
        };
    }

    private static function derivedReadModel(string $name, string $source): Projection
    {
        return new readonly class($name, $source) implements DerivedStreamProjection, ReadModel
        {
            public function __construct(private string $name, private string $source) {}

            public function name(): string
            {
                return $this->name;
            }

            public function apply(EventRecord $event, Connection $tx): bool
            {
                return true;
            }

            public function initialize(Connection $tx): void {}

            public function clear(Connection $tx): void {}

            public function drop(Connection $tx): void {}

            public function generation(): int
            {
                return 1;
            }

            public function sourceStream(): StreamName
            {
                return new StreamName($this->source);
            }
        };
    }

    private static function queryProjection(string $name): Projection
    {
        /** @implements QueryProjection<null> */
        return new readonly class($name) implements QueryProjection
        {
            public function __construct(private string $name) {}

            public function name(): string
            {
                return $this->name;
            }

            public function apply(EventRecord $event, Connection $tx): bool
            {
                return true;
            }

            public function getState(): null
            {
                return null;
            }

            public function reset(): void {}
        };
    }
}
