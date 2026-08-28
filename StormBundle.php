<?php

declare(strict_types=1);

namespace Storm\Symfony;

use Composer\InstalledVersions;
use LogicException;
use Override;
use Storm\AggregateRepository\AggregateRepositoryManager;
use Storm\AggregateRepository\Snapshot\PersonalDataSnapshotGuard;
use Storm\AggregateRepository\Snapshot\SnapshotDeletingStreamEraser;
use Storm\AggregateRepository\Snapshot\SnapshotStore;
use Storm\Chronicler\Directory\CategoryCachedStreamExistence;
use Storm\Chronicler\Directory\StreamExistence;
use Storm\Chronicler\Erasure\CacheEvictingStreamEraser;
use Storm\Chronicler\Erasure\StreamEraser;
use Storm\Chronicler\Evolution\AsUpcaster;
use Storm\Chronicler\Evolution\UpcasterChain;
use Storm\Chronicler\Outbox\OutboxRelay;
use Storm\Chronicler\Record\PersonalDataVeil;
use Storm\Chronicler\SafeHead\SafeHeadAdvancer;
use Storm\Chronicler\SafeHead\SafeHeadPrecondition;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Contracts\Clock\Clock;
use Storm\Contracts\Projector\ProjectionCommitListener;
use Storm\LiveQuery\Recipe\LiveQueryRecipe;
use Storm\Message\MessageEnricher;
use Storm\Projector\Definition\AsProjection;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Projector\Run\CompositeProjectionCommitListener;
use Storm\Saga\Attributes\Workflow;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Calendar\BusinessCalendar;
use Storm\Saga\Calendar\ConfiguredBusinessCalendar;
use Storm\Saga\CircuitBreaker\CircuitBreakerStorage;
use Storm\Saga\CircuitBreaker\InMemory\InMemoryCircuitBreakerStorage;
use Storm\Saga\CircuitBreaker\Redis\RedisCircuitBreakerStorage;
use Storm\Saga\Engine\Engine;
use Storm\Saga\Engine\EventResolver;
use Storm\Saga\Outbox\Dbal\DbalWorkflowOutboxWriter;
use Storm\Saga\Outbox\SagaCommandPublisher;
use Storm\Saga\Outbox\SagaLanePolicy;
use Storm\Saga\Outbox\SagaOutboxRelay;
use Storm\Saga\Priority\AttributePriorityResolver;
use Storm\Saga\Priority\PriorityLanePolicy;
use Storm\Saga\Schedule\TimerRunner;
use Storm\Saga\Workflow\Activity;
use Storm\Serializer\MessageSerializer;
use Storm\Story\Console\ConsumeBatchedCommand;
use Storm\Story\Consume\BatchConsumer;
use Storm\Story\Consume\InboxTransactionContext;
use Storm\Story\Middleware\AssignMessageMetadata;
use Storm\Story\Middleware\BindMessageContext;
use Storm\Story\Middleware\BindStoredHeader;
use Storm\Story\Middleware\DeduplicateConsumer;
use Storm\Story\Middleware\RecoverConcurrencyConflict;
use Storm\Story\Middleware\ValidateUnlessReceived;
use Storm\Story\Outbox\MessengerOutboxPublisher;
use Storm\Story\Outbox\ShardedSender;
use Storm\Story\Transport\InboxGuardedSendersLocator;
use Storm\Story\Transport\NeutralMessageSerializer;
use Storm\Support\OutboxDisposal;
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
use Storm\Symfony\Console\DescribeCommand;
use Storm\Symfony\Console\MessageCatalogueCommand;
use Storm\Symfony\Describe\StormDescriptor;
use Storm\Symfony\Http\Ops\HealthController;
use Storm\Symfony\Http\Ops\MetricsController;
use Storm\Telemetry\Health\HealthCheck;
use Storm\Telemetry\Metrics\MetricsCollector;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Parameter;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Thin Symfony integration for Storm.
 *
 * Aggregator first: it imports each Storm package's own `config/services.php` so the packages
 * stay self-describing for DI, resolving each package's location through `packageDir()`; the
 * composer runtime API with a monorepo sibling fallback. Required packages import
 * unconditionally and fail loud; the opt-in packages Saga and Telemetry are `suggest`, never
 * `require`, so their wiring is guarded and their absence yields a no-op container, not a
 * compile failure. See `wireSaga()` for the atomic saga guard.
 *
 * What it wires itself stays limited to what no package may own: the cross-package port bridges
 * binding `EventResolver` to the event-type mapper and `SagaCommandPublisher` to the command
 * bus, the config-driven scalar args tuning outbox, saga, occ and inbox, and the cross-package
 * compiler passes in `build()`. How each service works lives in its package, via attributes and
 * its own config; that boundary is what keeps this from becoming a god-object.
 */
class StormBundle extends AbstractBundle
{
    /**
     * The three bus ids the bundle ships, single-sourced: `prependExtension` writes them into the
     * messenger config and `StormDescriptor` renders them, so the description and the actual
     * prepend cannot drift.
     */
    public const string COMMAND_BUS = 'storm.command.bus';

    public const string QUERY_BUS = 'storm.query.bus';

    public const string EVENT_BUS = 'storm.event.bus';

    /**
     * The packages the bundle REQUIRES, single-sourced: `loadExtension` imports each one's
     * `config/services.php`, and the manifest guard reads this list to check that each is also a
     * composer `require` of the bundle, so a standalone install cannot boot on a missing one.
     *
     * A constant rather than a literal in the loop, because the guard used to derive the list by
     * matching the loop's own source and matched nothing at all: an inventory read from the code is
     * only as good as the shape it expects, and an empty result is indistinguishable from agreement.
     *
     * @var list<string>
     */
    public const array WIRED_PACKAGES = [
        'Clock', 'Message', 'Serializer', 'Chronicler', 'EventLinks', 'Story', 'AggregateRepository',
        'Projector', 'LiveQuery', 'Ledger',
    ];

    /**
     * {@inheritDoc}
     *
     * Anchors the bundle's resource path on `src/Symfony/`, where this class lives, instead of
     * Symfony's default that walks up to the package root at `src/`. Without this override
     * `@StormBundle/config/routes.php` would resolve to the wrong directory, `src/config/`. Letting
     * the app import `@StormBundle/config/routes.php` to opt in the framework `Ops/` routes is the
     * whole point.
     */
    #[Override]
    public function getPath(): string
    {
        return __DIR__;
    }

    /**
     * {@inheritDoc}
     *
     * Ships the three CQRS buses so a consuming app gets them ready-wired. Transports and routing stay
     * app-side deployment choices; only the bus and middleware shape is the framework's. The command and
     * query buses require exactly one handler; the event bus allows zero.
     *
     * The middleware, in stack order:
     *
     * - `AssignMessageMetadata`, on every bus, runs first so the id and correlation stamps exist before
     *   anything can fail; a failure stays traceable.
     *
     * - Validation, on the command and query buses only, is Symfony's built-in fail-fast check: those buses
     *   carry external input and are validated, whereas events are internal facts and are not. On the command
     *   bus it is wrapped by `ValidateUnlessReceived`; validation runs before the send middleware, so a
     *   command is validated BEFORE it is queued, and re-validating the same payload when it comes back off
     *   the transport with a `ReceivedStamp` is redundant, so the wrapper skips it on the consume path. The
     *   query bus keeps the raw `validation`, since queries are synchronous, never received, so the wrapper
     *   would be inert.
     *
     * - `BindMessageContext`, on every bus, binds the ambient context for the handler.
     *
     * - `BindStoredHeader`, on the event bus, exposes to handlers via `CurrentStoredHeader` a republished
     *   event's stored header: its `occurred_at`, aggregate type, and version.
     *
     * - `RecoverConcurrencyConflict`, on the command AND event buses, sits just before dedup and outside
     *   the inbox transaction. It maps the Chronicler's optimistic-concurrency split onto Messenger's retry
     *   markers: a transient version race is retried-forward, reloaded, and re-decided on redelivery, instead
     *   of being dead-lettered by the infra `max_retries`, while a duplicate version dead-letters at once. On
     *   the event bus the same progress argument holds for a REACTION that appends under contention: the
     *   competitor that beat it committed, so the redelivered event re-folds against the advanced stream. The
     *   batched consume path earns the SAME translation via its `BatchModeStamp`: the batch consumer
     *   redelivers a recoverable to its own transport with the middleware's delay and captures an
     *   unrecoverable at once; a version race keeps its progress semantics on both paths.
     *
     * - `DeduplicateConsumer`, on the command and event buses, wraps the handlers in the inbox transaction
     *   and runs them at most once per `(transport, message-id)`. It matters once a message comes off a
     *   transport, where delivery is at-least-once: an async command the saga's outbox relay re-drains, or
     *   a republished event the broker redelivers. A saga-issued command carries a stable id for exactly
     *   this, minted by `WorkflowOutboxWriter`. In-process dispatch has no transport and passes straight through.
     *   The handlers it runs are bracketed by the ambient `InboxTransactionContext`, and the decorated
     *   senders locator refuses an undeclared broker send while that transaction is open, the dual-write
     *   guard. A handler that owns the at-least-once consequence signs it with
     *   `#[DispatchesUnderInboxTransaction]`.
     */
    #[Override]
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $assign = AssignMessageMetadata::class;
        $validate = ValidateUnlessReceived::class;
        $bind = BindMessageContext::class;
        $bindStored = BindStoredHeader::class;
        $recover = RecoverConcurrencyConflict::class;
        $dedup = DeduplicateConsumer::class;

        $builder->prependExtensionConfig('framework', [
            'messenger' => [
                'default_bus' => self::COMMAND_BUS,
                'buses' => [
                    self::COMMAND_BUS => ['default_middleware' => true, 'middleware' => [$assign, $validate, $bind, $recover, $dedup]],
                    self::QUERY_BUS => ['default_middleware' => true, 'middleware' => [$assign, 'validation', $bind]],
                    self::EVENT_BUS => ['default_middleware' => 'allow_no_handlers', 'middleware' => [$assign, $bind, $bindStored, $recover, $dedup]],
                ],
            ],
        ]);
    }

    /**
     * {@inheritDoc}
     *
     * Declares the whole `storm:` app config tree. Each node carries its own `info()`; what the
     * roots cover, in the order they are declared:
     *
     * - `aggregates`, the map that drives the `AggregateRepositoryManager`, binding an aggregate
     *   class to its identity class, its stream category, and optional snapshot tuning;
     *
     * - `context`, the application header keys declared to propagate along the causal chain;
     *
     * - `event_paths` and `privacy`, the build-time scans: where `#[EventType]` events are looked
     *   for, and the master key protecting `#[Personal]` payload keys at rest;
     *
     * - `event_store_partition_list`, `autodiscover_partition_per_category` and `connections`, the
     *   storage layout: which stream categories get their own `event_store` partition, and which
     *   named DBAL connection the read models live on;
     *
     * - `stream_existence_cache` and `safe_head`, the read posture: the opt-in category liveness
     *   cache, and the grace below which a sequence gap is still presumed in-flight;
     *
     * - `outbox`, `saga`, `occ` and `inbox`, the runtime tuning of the event relay and its priority
     *   lanes, the opt-in saga engine, optimistic-concurrency recovery, and batched consume;
     *
     * - `neutral_transports`, one neutral wire serializer per declared Messenger transport.
     *
     * Per-workflow and per-projection behavior is never config: it rides attributes on the code.
     *
     * @see \Storm\AggregateRepository\AggregateRepositoryManager
     */
    #[Override]
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->arrayNode('aggregates')
            ->info('Map of aggregate class => { id: identity class, category: stream category, snapshot?: { threshold, max_age_seconds?, min_interval_seconds? } }.')
            ->useAttributeAsKey('class')
            ->arrayPrototype()
            ->children()
            ->scalarNode('id')->isRequired()->cannotBeEmpty()->end()
            ->scalarNode('category')->isRequired()->cannotBeEmpty()->end()
            ->arrayNode('snapshot')
            ->info('Enable + tune the snapshot sweep for this aggregate (it must implement SnapshotableAggregateRoot).')
            ->children()
            ->integerNode('threshold')->min(1)->defaultValue(100)
            ->info('Events accumulated since the last snapshot before the sweep takes a new one (the count trigger).')
            ->end()
            ->integerNode('max_age_seconds')->min(1)->defaultNull()
            ->info('Staleness ceiling: also snapshot a changed (>= 1 event) aggregate whose last snapshot is older than this — catches the cold-but-large aggregate the count trigger leaves with a long tail. Null = off.')
            ->end()
            ->integerNode('min_interval_seconds')->min(1)->defaultNull()
            ->info('Frequency floor: do not re-snapshot within this wall-clock interval (throttles the count trigger on a hot aggregate). Must be <= max_age_seconds. Null = off.')
            ->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->arrayNode('context')
            ->info('The cross-cutting message context beyond the fixed identifiers (correlation, causation, actor, tenant).')
            ->addDefaultsIfNotSet()
            ->children()
            ->arrayNode('propagated_keys')
            ->info('Application header keys DECLARED to propagate along the causal chain like the fixed identifiers do: bound into the ambient context at dispatch, stamped onto recorded events, re-stamped by the outbox/saga publishers and the neutral wire. An undeclared header stays a one-message annotation. The `__` prefix is refused (framework space), and personal data is forbidden here: headers are not covered by crypto-shredding. Domain data never belongs in the bag — a downstream fact or decision that branches on a value makes it payload.')
            ->scalarPrototype()->cannotBeEmpty()->end()
            ->defaultValue([])
            ->validate()
            ->ifTrue(static fn (array $keys): bool => array_any($keys, static fn ($key): bool => str_starts_with((string) $key, '__')))
            ->thenInvalid('storm.context.propagated_keys refuses "__"-prefixed keys: that prefix is the reserved framework header space.')
            ->end()
            ->end()
            ->end()
            ->end()
            ->arrayNode('event_paths')
            ->info('Directories scanned for #[EventType] domain events (build the alias/class map). Defaults to %kernel.project_dir%/src; narrow it for a large app.')
            ->scalarPrototype()->end()
            ->defaultValue(['%kernel.project_dir%/src'])
            ->end()
            ->arrayNode('privacy')
            ->info('Crypto-shredding (#[Personal] events): per-subject payload encryption, forgetting = key destruction (storm:privacy:forget). The feature turns ON by marking a class; this section only carries the master key.')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('master_key')->cannotBeEmpty()->defaultValue('%env(STORM_PRIVACY_MASTER_KEY)%')
            ->info('Base64 of 32 random bytes, encrypting the per-subject key material at rest so a crypto_keys dump alone yields no key. Generate: php -r "echo base64_encode(random_bytes(32));". Resolved LAZILY — an app with no #[Personal] class never reads it. Losing it makes every encrypted field unreadable: treat it as a secret with the same care as the database itself.')->end()
            ->end()
            ->end()
            ->arrayNode('event_store_partition_list')
            ->info('Stream categories provisioned as their own event_store LIST partition (split out of event_store_default). The DDD-known set the storm:event-store:partition command reads. Empty = everything stays in the DEFAULT.')
            ->scalarPrototype()->end()
            ->defaultValue([])
            ->end()
            ->booleanNode('autodiscover_partition_per_category')
            ->info('When true, the partition command ALSO provisions a partition per registered aggregate category — a convenience, NOT best practice (a partition is not necessarily an aggregate). Default false: use event_store_partition_list.')
            ->defaultFalse()
            ->end()
            ->arrayNode('connections')
            ->info('Named doctrine DBAL connections storm consumes — storm never builds a connection: the app declares them (doctrine.dbal.connections), storm binds by NAME. Family node: read_model_store today; a read_query lane may join it (parked replica plan).')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('read_model_store')->defaultNull()
            ->info("Doctrine connection NAME the persistent projections live on — a dedicated read-model database: the rm_* writes (apply() receives this connection), the projections checkpoints, the applied markers, claim/lease. They move TOGETHER: the checkpoint+read-model same-tx invariant is the exactly-once mechanism and must hold on the WRITE connection. Events keep being read from the default connection (the safe-head watermark is computed where the events live — a lagging store never skips events). Null = the default connection: single database, byte-identical wiring. Reads on the store db carry projection lag only; anything needing the primary's truth must not point there.")->end()
            ->end()
            ->end()
            ->arrayNode('stream_existence_cache')
            ->info('Category-level cache over the Chronicler StreamExistence port (opt-in). Only CATEGORY liveness is remembered — the exact hasStream(name) probe stays live on every call: per-stream answers are never cached (unbounded key space, erasure revokes positives). Enabled, the bundle decorates StreamExistence with the cache and StreamEraser with the eviction hook; disabled = the raw DBAL probe, zero extra services. Per-process state: lives with a worker (boot-once), dies with a one-shot CLI run.')
            ->canBeEnabled()
            ->children()
            ->integerNode('negative_ttl_seconds')->min(1)->defaultValue(30)
            ->info('How long a confirmed-absent category is remembered before re-probing — the upper bound on the "category just born, not visible yet" window. Positives never expire: liveness is stable, only erasure evicts.')->end()
            ->end()
            ->end()
            ->arrayNode('safe_head')
            ->info('The catch-up watermark a filtered projection may read up to. It stops below the lowest gap whose upper neighbour is still young: a gap is an in-flight insert (wait) or an aborted one (step over), and the grace below is what tells the two apart.')
            ->addDefaultsIfNotSet()
            ->children()
            ->floatNode('grace_seconds')->min(0.001)->defaultValue(5.0)
            ->info('How long an unfilled sequence_no gap is treated as possibly in-flight before it is presumed aborted and stepped over. THE knob of the safe head, and the one number the deployment must make true: a writer transaction that outlives it, then commits, is skipped by every catch-up already past it — permanent read-model incompleteness, not lag. Storm cannot enforce that from here (transaction_timeout is a session/role setting, and it is connection-wide), so storm:install reports what this connection can see about it. Raise it if writers are legitimately slow; lower it only against a writer-side timeout set under it.')->end()
            ->end()
            ->end()
            ->arrayNode('outbox')
            ->info('Outbox relay retry policy — how a failed publish is retried before it is dead-lettered.')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('max_attempts')->min(1)->defaultValue(5)
            ->info('Publish attempts before a transient failure is dead-lettered.')->end()
            ->integerNode('backoff_base_seconds')->min(0)->defaultValue(1)
            ->info('Base back-off between retries; the Nth retry waits base·2^(N-1), capped, and never less than a second: the relay floors it so a zero cannot spend the whole max_attempts budget in one daemon loop.')->end()
            ->integerNode('backoff_max_seconds')->min(1)->defaultValue(60)
            ->info('Upper bound on the back-off delay.')->end()
            ->integerNode('stalled_cooldown_seconds')->min(1)->defaultValue(300)
            ->info('How long a row this runtime cannot decode (missing upcaster, or written by a newer binary) is left alone before the relay retries it. Its own knob, not the publish back-off: such a row is neither poison nor an outage — it keeps its place and burns no attempt, and its verdict cannot change until a deployment does, so the useful scale is a deploy (minutes), not a broker hiccup (seconds).')->end()
            ->scalarNode('events_transport')->cannotBeEmpty()->defaultValue('storm_events')
            ->info('Messenger transport the relay publishes events TO (the outbox handoff is a transport, not a routing opinion — the app defines this transport\'s DSN, deployment stays app-side). The publisher\'s wiring fails at boot when it is missing.')->end()
            ->arrayNode('priority_events_transport')
            ->info('Finish-over-start EVENT lane (optional): the Messenger transport the saga-AWAITED events (the #[WaitFor] union, %storm.saga.routing_events%) publish TO, drained ahead of the bulk event flow — so an in-flight saga\'s awaited signal (the capture\'s FundsCaptured above all) never queues behind a flood of bulk events. A LIST of transports shards the lane by correlation hash: one saga always rides one shard (per-saga order by construction), distinct sagas spread for scale-out; re-sharding = renumbering (poll queues, drain first). null / unset = no lane (every event to events_transport). Every transport must exist in the app messenger config.')
            ->beforeNormalization()->ifString()->then(static fn (string $v): array => [$v])->end()
            ->treatNullLike([])
            ->scalarPrototype()->cannotBeEmpty()->end()
            ->defaultValue([])
            ->end()
            ->arrayNode('signal_lanes')
            ->info('Splits the priority event lane by criticality (requires priority_events_transport): level => Messenger transport, or a LIST of transports to shard that level by correlation hash (lanes and shards compose). An awaited event whose #[WaitFor(lane:)] level is listed rides that transport; no level, or a level absent here, stays on priority_events_transport — the floor, an awaited event never falls back to the bulk flow. Storm names no levels; higher = more urgent, and the realization must drain a higher lane no slower than a lower one. Each transport must exist in the app messenger config.')
            ->normalizeKeys(false)
            ->arrayPrototype()
            ->beforeNormalization()->ifString()->then(static fn (string $v): array => [$v])->end()
            ->requiresAtLeastOneElement()
            ->scalarPrototype()->cannotBeEmpty()->end()
            ->end()
            ->defaultValue([])
            ->end()
            ->enumNode('disposal')->values(['delete', 'archive'])->defaultValue('delete')
            ->info('What becomes of a published es_outbox row: delete (default — a queue is not a ledger; the event lives in event_store, consumption proof in es_inbox) or archive (atomic move to the append-only es_outbox_archive: the delivery audit, out of the hot path). Failed rows always stay in the hot table, whatever the mode.')->end()
            ->end()
            ->end()
            ->arrayNode('saga')
            ->info('Saga engine operational tuning (the opt-in workflow module). Per-workflow knobs — a state\'s timeout, its retry policy — are attributes, not config.')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('timer_lease_seconds')->min(1)->defaultValue(300)
            ->info('How long a claimed-but-unprocessed timer waits before another runner re-claims it (the recovery lease).')->end()
            ->arrayNode('calendar')
            ->info('The BusinessCalendar behind business-time deadlines (#[WaitFor(deadlineBusinessDays:|Hours:)]). OPT-IN on purpose: disabled, the first business arm fails loud (BusinessCalendarMissing) instead of silently computing on a fictitious default market. Enable it and DECLARE your market — or register your own BusinessCalendar implementation.')
            ->canBeEnabled()
            ->children()
            ->arrayNode('business_days')
            ->info('ISO day numbers that are business days (1=Mon … 7=Sun).')
            ->integerPrototype()->end()
            ->defaultValue([1, 2, 3, 4, 5])
            ->end()
            ->integerNode('open_hour')->min(0)->max(23)->defaultValue(9)
            ->info('Daily window start, LOCAL to the calendar timezone ([open, close)).')->end()
            ->integerNode('close_hour')->min(1)->max(24)->defaultValue(17)
            ->info('Daily window end, LOCAL to the calendar timezone ([open, close)).')->end()
            ->arrayNode('holidays')
            ->info("Non-business dates, 'Y-m-d', in the calendar timezone.")
            ->scalarPrototype()->end()
            ->defaultValue([])
            ->end()
            ->scalarNode('timezone')->defaultValue('UTC')
            ->info('The market timezone the hours and holidays are local to.')->end()
            ->end()
            ->validate()
            ->ifTrue(static fn (array $calendar): bool => $calendar['open_hour'] >= $calendar['close_hour'])
            ->thenInvalid('storm.saga.calendar refuses an inverted or empty daily window: open_hour must be strictly below close_hour, the window being [open, close). Each bound alone is in range here, and the calendar refuses the PAIR when it is built, which is inside a saga step under its fence; refusing at build is what keeps that failure out of the runtime.')
            ->end()
            ->end()
            ->arrayNode('circuit_breaker')
            ->info('Where a #[CircuitBreaker] keeps its counters. The breaker is SHARED by every caller of a resource key, so the backend decides how far that sharing reaches: postgres reaches every worker and survives a restart, redis reaches every worker and does not, memory reaches nothing beyond the process. The thresholds and the cooldown stay in the attribute — this section only says where the count lives.')
            ->addDefaultsIfNotSet()
            ->children()
            ->enumNode('storage')->values(['postgres', 'redis', 'memory'])->defaultValue('postgres')
            ->info('postgres: the default, on-brand with the outbox and timers, one row per key, no extra infrastructure. redis: an opt-in swap when the guarded resource is called often enough that a row per outcome is the cost that hurts. memory: process-local, so each worker trips on its OWN count — a single-process CLI run, a test, or a resource whose failures are local to the caller, never a shared remote.')->end()
            ->scalarNode('redis_service')->cannotBeEmpty()->defaultValue('redis')
            ->info("Service id of the app's phpredis \\Redis connection, read only when storage is redis. Storm opens no connection of its own: the app owns its Redis, its DSN and its pooling.")->end()
            ->scalarNode('redis_prefix')->cannotBeEmpty()->defaultValue('storm:circuit_breaker:')
            ->info('Key namespace on that connection, so a Redis shared with cache, sessions or locks cannot collide with a resource key the app chose freely.')->end()
            ->end()
            ->end()
            ->arrayNode('priority')
            ->info('Priority lanes for saga-issued commands (finish-over-start). Storm carries an opaque ORDINAL level (higher = more urgent), declared in code — per-leg via #[Prioritized] on the command class, per-workflow via #[Prioritized] on the #[Workflow] class (compiled at build) — or defaulted globally here; this section maps a level to a Messenger transport (the lane). Storm names no levels and caps no cardinality — the app brings its own vocabulary. Unset / empty lanes = no lane (commands keep class routing).')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('default')->defaultNull()
            ->info('Global default LEVEL for every saga-issued command (null = no priority, so no lane). Higher = more urgent. The per-workflow default is NOT config: it is #[Prioritized] on the #[Workflow] class, the code being the single source of a workflow\'s posture.')->end()
            ->arrayNode('lanes')
            ->info('Realization: level => Messenger transport. A resolved level absent here = no lane (class routing). Each transport must exist in the app messenger config.')
            ->normalizeKeys(false)
            ->scalarPrototype()->cannotBeEmpty()->end()
            ->defaultValue([])
            ->end()
            ->end()
            ->end()
            ->arrayNode('command_outbox')
            ->info('Retry policy for dispatching a saga\'s outgoing commands to the command bus, and the disposal of published rows.')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('max_attempts')->min(1)->defaultValue(5)
            ->info('Dispatch attempts before a transient failure is dead-lettered.')->end()
            ->integerNode('backoff_base_seconds')->min(0)->defaultValue(1)
            ->info('Base back-off between retries; the Nth retry waits base·2^(N-1), capped, and never less than a second: the relay floors it so a zero cannot spend the whole max_attempts budget in one daemon loop.')->end()
            ->integerNode('backoff_max_seconds')->min(1)->defaultValue(60)
            ->info('Upper bound on the back-off delay.')->end()
            ->enumNode('disposal')->values(['delete', 'archive'])->defaultValue('delete')
            ->info('What becomes of a published workflow_outbox row: delete (default — the saga\'s audit is its history sink) or archive (atomic move to the append-only workflow_outbox_archive: the issued-command trail, which unlike events exists nowhere else). Failed rows always stay in the hot table, whatever the mode.')->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->arrayNode('occ')
            ->info('Optimistic-concurrency recovery (RecoverConcurrencyConflict). The exception split is the poison backstop — a DuplicateVersion is unrecoverable, dead-lettered at once — so this is only the retry-forward knob for a transient version race (StaleVersion).')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('retry_delay_ms')->min(0)->defaultValue(50)
            ->info('Delay (ms) before a recoverable version race is redelivered. 0 = immediate (the broker already spaces by queue depth); a small value damps the churn on a very hot stream — the bench saw a loser reach retry #9 at 0 ms. Kept low: a larger delay taxes the common single-conflict case.')->end()
            ->integerNode('retry_max_delay_ms')->min(0)->defaultValue(1000)
            ->info('Caps the jittered exponential backoff: each redelivery waits a random slice of a window that doubles per attempt (base retry_delay_ms) up to this cap, so simultaneous losers of one hot stream spread out instead of re-colliding on the same tick. 0 = no backoff, the fixed retry_delay_ms alone, no jitter.')->end()
            ->end()
            ->end()
            ->arrayNode('inbox')
            ->info('Batched consume (storm:bus:consume-batched): N messages per inbox transaction / COMMIT, amortising the per-event commit. batch_size 1 = per-message behavior (no-op).')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('batch_size')->min(1)->defaultValue(1)
            ->info('Messages per batch transaction. 1 = no batching. Larger amortises the commit but widens the OCC blast radius (one conflict rolls the whole batch back) — keep conservative (8-16) on hot streams.')->end()
            ->integerNode('batch_idle_ms')->min(1)->defaultValue(50)
            ->info('Flush a partial batch after this idle window (ms) so a quiet queue does not stall waiting for batch_size. Floor 1: zero would turn the idle poll into a busy loop pinning a core on an empty queue.')->end()
            ->scalarNode('failure_transport')->defaultNull()
            ->info("Transport name a rejected message is CAPTURED to (the Worker's failure-transport semantic, reimbursed — this loop replaces the Worker so its machinery never runs). Null = broker reject: a dead-letter hand-off only if the queue declares x-dead-letter-exchange, a DESTRUCTION otherwise (warned once per run). Point it at the same transport as framework.messenger.failure_transport so messenger:failed:show/:retry cover both paths.")->end()
            ->end()
            ->end()
            ->arrayNode('neutral_transports')
            ->info('Neutral wire serializers, one per declared transport: each entry registers a NeutralMessageSerializer as storm.neutral_transport.<name>, referenced from that transport\'s serializer key in the app messenger config. The entry IS the wiring: the bus a decoded message routes to, and the trust posture of the channel. Never point a FAILURE transport at one of these: the failure-capture stamps are not carried on the neutral wire (deliberately, they are PHP-internal ops data), so messenger:failed:* would go blind; a failure transport stays on the default PhpSerializer.')
            ->normalizeKeys(false)
            ->arrayPrototype()
            ->children()
            ->scalarNode('bus')->defaultNull()
            ->info('Bus a decoded message is stamped with — transport config, never producer input (the wire X-Message-Bus is advisory, ignored on decode). Null = Messenger\'s default bus, correct only when the transport carries that bus\'s traffic.')->end()
            ->arrayNode('allowed_types')
            ->info('Wire type aliases this channel accepts, refused loud before any class is resolved. Empty/omitted = same-trust: every resolvable type is accepted — correct for an in-process or same-trust-domain transport, never for an integration edge, which must declare its list. There is no deny-all: a transport that decodes nothing is a config mistake, not a posture.')
            ->scalarPrototype()->cannotBeEmpty()->end()
            ->defaultValue([])
            ->end()
            ->end()
            ->end()
            ->defaultValue([])
            ->end()
            ->end()
            ->end();
    }

    /**
     * {@inheritDoc}
     *
     * @param  mixed[]  $config
     *
     * @throws LogicException when a required Storm package cannot be located; a broken install, so
     *                        the import fails loud rather than compiling a half-wired container
     * @throws InvalidConfigurationException when `storm.outbox.signal_lanes` splits a priority event
     *                                       lane that `storm.outbox.priority_events_transport` never
     *                                       declared
     */
    #[Override]
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // The packages the bundle REQUIRES: a missing one is a broken install, so the import
        // stays unconditional and fails loud; packageDir answers null rather than guessing, and
        // the call site turns that null into the refusal.
        foreach (self::WIRED_PACKAGES as $module) {
            $dir = $this->packageDir($module) ?? throw new LogicException(sprintf('Storm package "%s" is required by the bundle but cannot be located — broken install?', $module));
            $container->import($dir.'/config/services.php');
        }

        // Saga is OPT-IN.
        // One guard covers the import AND the wiring block below, wireSaga; skipping only the import would leave a
        // half-wired saga, getDefinition on absent services, that fails the compilation instead of
        // degrading to a no-op. The `storm.saga` config tree stays declared; inert without the package.
        $sagaDir = $this->packageDir('Saga');
        if ($sagaDir !== null) {
            $container->import($sagaDir.'/config/services.php');
        }

        // Telemetry is OPT-IN and OVERRIDES the Null observability aliases shipped by Chronicler /
        // Projector; import it LAST so its explicit alias declarations win. Skip the import, or
        // remove the package, to fall back to the Null defaults; the framework bodies stay
        // untouched either way. Saga has its own surface, the existing Saga* events.
        $telemetryDir = $this->packageDir('Telemetry');
        if ($telemetryDir !== null) {
            $container->import($telemetryDir.'/config/services.php');
        }

        // The read-model store is opt-in: Projector's services bind their DBAL connection to
        // this alias; declared package-side targeting the DEFAULT connection, standalone-safe,
        // single database, byte-identical; the app's choice re-points it here. A wrong name
        // fails the compilation on the missing doctrine.dbal.<name>_connection service; loud, at
        // boot. Null means the split is off and every projection lane collapses onto the default.
        $store = $config['connections']['read_model_store'];
        $builder->setParameter('storm.connections.read_model_store', $store);
        if ($store !== null) {
            $builder->setAlias('storm.read_model_store_connection', sprintf('doctrine.dbal.%s_connection', $store));
        }

        // The category existence cache is opt-in: the two cache classes are excluded from the
        // module's auto-load on purpose; enabling re-routes the StreamExistence alias through
        // the decorator and decorates the eraser so an erased stream evicts its category's
        // liveness. Registered without autowiring: the scalar TTL and the deliberate references
        // ARE the wiring, and per-stream answers must never gain a cache by accident.
        if ($config['stream_existence_cache']['enabled']) {
            $builder->register(CategoryCachedStreamExistence::class, CategoryCachedStreamExistence::class)
                ->setDecoratedService(StreamExistence::class)
                ->setArguments([
                    new Reference(CategoryCachedStreamExistence::class.'.inner'),
                    new Reference(Clock::class),
                    $config['stream_existence_cache']['negative_ttl_seconds'],
                ]);

            $builder->register(CacheEvictingStreamEraser::class, CacheEvictingStreamEraser::class)
                ->setDecoratedService(StreamEraser::class)
                ->setArguments([
                    new Reference(CacheEvictingStreamEraser::class.'.inner'),
                    new Reference(CategoryCachedStreamExistence::class),
                ]);
        }

        // Any autoconfigured service marked #[AsProjection] is tagged so the ProjectionRegistry
        // collects it; the projections are the source of truth, no config-array discovery.
        $builder->registerAttributeForAutoconfiguration(
            AsProjection::class,
            static function (ChildDefinition $definition): void {
                $definition->addTag('storm.projection');
            },
        );

        // Any #[AsUpcaster] service is tagged so the UpcasterChain collects it.
        $builder->registerAttributeForAutoconfiguration(
            AsUpcaster::class,
            static function (ChildDefinition $definition): void {
                $definition->addTag('storm.upcaster');
            },
        );

        // Saga wiring; everything saga lives behind the one guard set at the import above.
        if ($sagaDir !== null) {
            $this->wireSaga($config['saga'], $builder);
        }

        $builder->setParameter('storm.aggregates', $config['aggregates'] ?? []);
        // event_paths has a config default of %kernel.project_dir%/src, so it is always present
        $builder->setParameter('storm.event_paths', $config['event_paths']);

        // The declared transverse bag: which application headers propagate along the causal chain.
        // A parameter, not a per-service value: every re-stamping site (outbox publisher, saga
        // command publisher, neutral serializers) must agree on the SAME list, or a hop silently
        // drops what another carries.
        $builder->setParameter('storm.context.propagated_keys', $config['context']['propagated_keys']);
        // seeded empty and overwritten by RegisterEventTypesPass with the #[EventType] scan product:
        // the seed keeps %storm.event_classes% resolvable even when the pass no-ops
        $builder->setParameter('storm.event_classes', []);
        // same seed-then-overwrite for the #[Personal] scan product of RegisterPersonalDataPass; the
        // master key rides a parameter so Ledger's key store resolves it lazily, on first actual use
        $builder->setParameter('storm.personal_data', []);
        $builder->setParameter('storm.privacy.master_key', $config['privacy']['master_key']);
        // the no-decrypt rule of the inspection surfaces: the veil overlays personal keys with what
        // the store holds; %storm.personal_data% resolves after the scan pass ran
        $builder->getDefinition(PersonalDataVeil::class)->setArgument('$map', '%storm.personal_data%');
        // the snapshot exclusion: a stream folding a marked event refuses its snapshot loud; state
        // would persist decrypted personal values no forget can reach
        $builder->getDefinition(PersonalDataSnapshotGuard::class)->setArgument('$map', '%storm.personal_data%');
        // partition provisioning for storm:event-store:partition; both keys have config defaults, [] and false
        $builder->setParameter('storm.event_store_partition_list', $config['event_store_partition_list']);
        $builder->setParameter('storm.autodiscover_partition_per_category', $config['autodiscover_partition_per_category']);

        // The safe-head grace, on BOTH the scan that uses it and the probe that reports whether the
        // deployment makes it true: they must never drift apart, since a check against a different
        // number than the scan reads is worse than no check.
        $builder->getDefinition(SafeHeadAdvancer::class)
            ->setArgument('$graceSeconds', $config['safe_head']['grace_seconds']);
        $builder->getDefinition(SafeHeadPrecondition::class)
            ->setArgument('$graceSeconds', $config['safe_head']['grace_seconds']);

        // Outbox retry policy: the package ships sane defaults, so Chronicler works standalone, without
        // this bundle; here we override the OutboxRelay's scalar args from config. `outbox` has
        // addDefaultsIfNotSet() so the keys are always present.
        $outbox = $config['outbox'];
        $builder->getDefinition(OutboxRelay::class)
            ->setArgument('$maxAttempts', $outbox['max_attempts'])
            ->setArgument('$backoffBaseSeconds', $outbox['backoff_base_seconds'])
            ->setArgument('$backoffMaxSeconds', $outbox['backoff_max_seconds'])
            ->setArgument('$stalledCooldownSeconds', $outbox['stalled_cooldown_seconds'])
            ->setArgument('$disposal', OutboxDisposal::from($outbox['disposal']));

        // The outbox handoff is a TRANSPORT, not a routing opinion: the publisher sends straight to
        // this transport; BusNameStamp routes the consumption back onto storm.event.bus, so the
        // Messenger routing, whose silent default would run handlers in-process, inside the relay's
        // drain transaction, is never consulted on the relay path. A missing transport fails the
        // publisher's wiring at boot: loud by construction, no compiler pass needed.
        $builder->getDefinition(MessengerOutboxPublisher::class)
            ->setArgument('$transport', new Reference('messenger.transport.'.$outbox['events_transport']))
            ->setArgument('$propagatedKeys', '%storm.context.propagated_keys%');

        // Neutral wire serializers, one per storm.neutral_transports entry, referenced from the app
        // messenger config (serializer: storm.neutral_transport.<name>). Registered without autowiring
        // on purpose, and excluded from the Story auto-load: the bus and the allowlist ARE the wiring,
        // and a busless global instance would route decoded events to the default bus. An empty
        // allowed_types list is the same-trust posture, carried as null: every resolvable type.
        foreach ($config['neutral_transports'] as $name => $neutral) {
            $builder->register('storm.neutral_transport.'.$name, NeutralMessageSerializer::class)
                ->setArguments([
                    new Reference(MessageSerializer::class),
                    new Reference(EventTypeMapper::class),
                    new Reference(UpcasterChain::class),
                    $neutral['bus'],
                    $neutral['allowed_types'] === [] ? null : $neutral['allowed_types'],
                    '%storm.context.propagated_keys%',
                ]);
        }

        // Finish-over-start EVENT lane is optional and mirrors the saga COMMAND lane. When set, the saga-awaited
        // events %storm.saga.routing_events%, the #[WaitFor] union extracted at compile by
        // ExtractSagaRoutingEventsPass, publish to a dedicated transport drained ahead of the bulk flow. The
        // publisher stays saga-AGNOSTIC, routing "priority event types"; HERE is where the saga-derived
        // set meets the outbox wiring. The %param% resolves at compile; the extract pass runs first.
        // A lane given as a LIST of transports shards by correlation hash through ShardedSender: one saga one
        // shard, per-saga order by construction; the lane seam takes one sender either way.
        if ($outbox['priority_events_transport'] !== []) {
            $builder->getDefinition(MessengerOutboxPublisher::class)
                ->setArgument('$priorityTransport', $this->laneSender($outbox['priority_events_transport']))
                ->setArgument('$priorityEventTypes', '%storm.saga.routing_events%');

            // The optional split of that lane by criticality: #[WaitFor(lane:)] levels compiled into
            // %storm.saga.signal_lanes%, highest wins and the guard pass refused any split wait, realized
            // through the level-to-transport table. A missing transport fails the wiring at boot.
            if ($outbox['signal_lanes'] !== []) {
                $builder->getDefinition(MessengerOutboxPublisher::class)
                    ->setArgument('$eventLanes', '%storm.saga.signal_lanes%')
                    ->setArgument('$laneTransports', array_map($this->laneSender(...), $outbox['signal_lanes']));
            }
        } elseif ($outbox['signal_lanes'] !== []) {
            throw new InvalidConfigurationException(
                'storm.outbox.signal_lanes splits the priority event lane, so it requires'
                .' storm.outbox.priority_events_transport — the default lane every unlaned awaited event'
                .' rides, and the floor a level with no mapped transport falls back to.',
            );
        }

        // OCC recovery: RecoverConcurrencyConflict ships a sane default; bind the retry delay from
        // config so a hot stream can be damped without taxing the common case. `occ` has addDefaultsIfNotSet().
        $builder->getDefinition(RecoverConcurrencyConflict::class)
            ->setArgument('$retryDelayMs', $config['occ']['retry_delay_ms'])
            ->setArgument('$retryMaxDelayMs', $config['occ']['retry_max_delay_ms']);

        // Snapshot coherence at the erase: deleting a stream must delete its snapshot, or the leftover
        // row resurrects the orphan / pollutes a re-created id; snapshot first, then erase. Same
        // composition as the existence-cache eviction: the port stays snapshot-blind,
        // the bundle sees both.
        $builder->register(SnapshotDeletingStreamEraser::class, SnapshotDeletingStreamEraser::class)
            ->setDecoratedService(StreamEraser::class, priority: 1)
            ->setArguments([
                new Reference(SnapshotDeletingStreamEraser::class.'.inner'),
                new Reference(SnapshotStore::class),
            ]);

        // The dual-write guard: every sender the locator hands out refuses an undeclared broker send
        // while an inbox transaction is open; the grants parameter is compiled by GrantInboxDispatchPass
        // from #[DispatchesUnderInboxTransaction] handlers.
        $builder->register(InboxGuardedSendersLocator::class, InboxGuardedSendersLocator::class)
            ->setDecoratedService('messenger.senders_locator')
            ->setArgument('$senders', new Reference('.inner'))
            ->setArgument('$context', new Reference(InboxTransactionContext::class))
            ->setArgument('$granted', '%storm.story.inbox_dispatch_grants%');

        // Batched consume for storm:bus:consume-batched: route each message to its origin bus via the routable
        // bus, like messenger:consume, so the reaction handlers are found, then register the command with the
        // transport receiver locator and the configured batch knobs. `inbox` has addDefaultsIfNotSet().
        // Auto-loaded BatchConsumer autowires the default bus by default; override to the routable one.
        $builder->getDefinition(BatchConsumer::class)
            ->setArgument('$bus', new Reference('messenger.routable_message_bus'));

        $builder->register(ConsumeBatchedCommand::class, ConsumeBatchedCommand::class)
            ->setArgument('$receivers', new Reference('messenger.receiver_locator'))
            ->setArgument('$consumer', new Reference(BatchConsumer::class))
            ->setArgument('$defaultBatchSize', $config['inbox']['batch_size'])
            ->setArgument('$defaultIdleMs', $config['inbox']['batch_idle_ms'])
            // The reject-capture destination; resolved at build so a mistyped name fails the boot, not the
            // first poison. Null keeps the bare broker reject, warned at runtime, once per run.
            ->setArgument('$failureSender', $config['inbox']['failure_transport'] !== null
                ? new Reference('messenger.transport.'.$config['inbox']['failure_transport'])
                : null)
            // The Worker's terminal contract, reimbursed: each capture emits a never-retried
            // WorkerMessageFailedEvent, so SagaCommandFailureListener and telemetry see the same
            // terminal failure on the batched path as on messenger:consume.
            ->setArgument('$dispatcher', new Reference('event_dispatcher'))
            ->setAutoconfigured(true); // pick up #[AsCommand] for the console.command tag

        // storm:describe: the compiled wiring rendered as canonical JSON. Static by contract:
        // the descriptor is fed registries and compiled parameters ONLY, no connection, no store,
        // no broker, so it answers on a kernel whose database is unreachable. The workflow
        // registry is opt-in with the Saga package; absent, null makes the sections gated on it
        // render themselves unavailable with the reason instead of failing the compile; same for
        // the health-check iterator, which doubles as the Telemetry-presence witness. The grant
        // parameters are always compiled here; both passes are added unconditionally in build().
        // the saga priority parameters exist only when wireSaga published them.
        $builder->register(StormDescriptor::class, StormDescriptor::class)
            ->setArguments([
                new Reference(ProjectionRegistry::class),
                new Reference(EventTypeMapper::class),
                $sagaDir !== null ? new Reference(WorkflowRegistry::class) : null,
                '%storm.event_classes%',
                '%kernel.environment%',
                '%storm.saga.transactional_handlers%',
                '%storm.story.inbox_dispatch_grants%',
                $sagaDir !== null ? '%storm.saga.priority_default%' : null,
                $sagaDir !== null ? '%storm.saga.priority_lanes%' : [],
                $sagaDir !== null ? '%storm.saga.workflow_priorities%' : [],
                $telemetryDir !== null ? new TaggedIteratorArgument('storm.health_check') : null,
                '%storm.personal_data%',
            ]);

        $builder->register(DescribeCommand::class, DescribeCommand::class)
            ->setArgument('$descriptor', new Reference(StormDescriptor::class))
            ->setAutoconfigured(true); // pick up #[AsCommand] for the console.command tag

        // Registered here for the same reason as DescribeCommand: Storm\Symfony\* has no per-package
        // services.php loading by namespace. It takes the SAME parameter RegisterEventTypesPass scans,
        // so the catalogue can only ever describe the set the alias map is built from.
        $builder->register(MessageCatalogueCommand::class, MessageCatalogueCommand::class)
            ->setArgument('$eventPaths', '%storm.event_paths%')
            ->setAutoconfigured(true);

        // HTTP Ops/ controllers: lightweight observability endpoints under /_storm.
        // Autowired here because Storm\Symfony\* has no per-package services.php loading by namespace.
        // setAutoconfigured(true) makes Symfony pick up the #[Route] attribute and tag it as a controller.
        // Routes are NOT auto-registered: the app imports `@StormBundle/config/routes.php` explicitly.
        // Telemetry-gated: the controller autowires Telemetry's HealthChecker; with the opt-in
        // package absent this must be a no-op, not a compile failure on a missing service.
        if ($telemetryDir !== null) {
            $builder->autowire(HealthController::class)->setAutoconfigured(true);
            $builder->autowire(MetricsController::class)->setAutoconfigured(true);

            // Any class implementing `HealthCheck` gets the `storm.health_check` tag so the HealthChecker's
            // AutowireIterator collects it. Same convention as Saga's Activity / Workflow auto-tagging:
            // the attribute on the Contracts interface alone doesn't propagate to consumers reliably, the
            // bundle's explicit registration does.
            $builder->registerForAutoconfiguration(HealthCheck::class)->addTag('storm.health_check');

            // Same convention for the metrics block: implementing MetricsCollector is enough, the
            // MetricsExposition's AutowireIterator collects every tagged block at scrape time.
            $builder->registerForAutoconfiguration(MetricsCollector::class)->addTag('storm.metrics_collector');
        }

        // Same convention for the message-enrichment extension point: implementing MessageEnricher is
        // enough; the tag arrives by autoconfiguration, no magic string to know. The four built-ins
        // keep their explicit attribute, which carries their priority; the resulting double tag is
        // harmless; the tagged iterator collects one entry per service id.
        $builder->registerForAutoconfiguration(MessageEnricher::class)->addTag('storm.message_enricher');

        // Same convention for LiveQuery recipes: the README's "implement the interface, done"
        // promise. The package's own `_instanceof` rule is scoped to ITS services file; an app
        // recipe loaded by the app's own definitions never met it, and the feature silently
        // vanished, no recipe listed, no compile error. The app-side workaround, an explicit
        // #[AutoconfigureTag] on the recipe, documented the trap; this registration removes it.
        $builder->registerForAutoconfiguration(LiveQueryRecipe::class)->addTag('storm.live_query_recipe');

        // The post-commit hook is COLLECTED, never a single slot: the port's use case is plural,
        // the Api bundle's purger beside an app's metrics tick, so every implementation is tagged
        // and the port alias resolves to the composite fan-out; installing one listener cannot
        // silently evict another. The composite is registered without autoconfiguration, or it
        // would collect itself.
        $builder->registerForAutoconfiguration(ProjectionCommitListener::class)->addTag('storm.projection_commit_listener');
        $builder->register(CompositeProjectionCommitListener::class, CompositeProjectionCommitListener::class)
            ->setArguments([new TaggedIteratorArgument('storm.projection_commit_listener')]);
        $builder->setAlias(ProjectionCommitListener::class, CompositeProjectionCommitListener::class);
    }

    #[Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Build-time scan of #[EventType] events, which are not services and so cannot be tagged, into the alias mapper.
        // priority 60: the scan product `storm.event_classes` must exist BEFORE the saga routing
        // pass at 50 resolves `#[WaitFor]` alias tokens against it; at the default 0 the map arrives
        // too late and an alias-only wait is silently dropped from the subscription union
        $container->addCompilerPass(new RegisterEventTypesPass, PassConfig::TYPE_BEFORE_OPTIMIZATION, 60);

        // Sibling scan of #[Personal] classes into the compiled personal-data map; a non-empty map
        // decorates the MessageSerializer with the ciphering codec; an empty map means zero decoration.
        $container->addCompilerPass(new RegisterPersonalDataPass);

        // Fail the build with a clear message on a malformed storm.aggregates entry.
        $container->addCompilerPass(new ValidateAggregatesPass);

        // The Examples: block of every command docblock becomes its --help, harvested so the two
        // cannot drift and baked into the compiled container so --help costs nothing at run time.
        $container->addCompilerPass(new HarvestCommandHelpPass);

        // Compiles the #[DispatchesUnderInboxTransaction] grants for the dual-write guard; priority 0,
        // alongside MessengerPass, so the attribute-applied messenger.message_handler tags are materialized.
        $container->addCompilerPass(new GrantInboxDispatchPass, PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);

        // Compiles the #[TransactionalHandler] signatures the failure listener reads to decide whether a
        // consumer-side dead-letter may settle a saga or must escalate. Same priority and same reason as
        // the grants above: the messenger.message_handler tags must already be materialized.
        $container->addCompilerPass(new GrantTransactionalHandlerPass, PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);

        // The saga outcome routing chain, mirror of loadExtension's saga guard; build() has no
        // package path at hand, so package presence reads as class_exists. Priorities pin the exact order:
        //   100 Symfony's RegisterAutoconfigureAttributesPass: applies `storm.saga.workflow` to #[Workflow] services
        //    50 ExtractSagaRoutingEventsPass: reads tagged workflows, sets the routing-events + signal-lanes + correlate-by + workflow-priorities parameters
        //    40 GuardSagaSignalLanesPass: refuses a lane merge that splits one wait's alternatives
        //    40 GuardSagaCorrelateByPass: refuses an external-event declaration the router could not honor
        //    30 BindSagaOutcomeRouterPass: tags SagaOutcomeRouter as messenger.message_handler per class
        //     0 Symfony's MessengerPass: consumes the handler tags
        if (class_exists(Workflow::class)) {
            $container->addCompilerPass(new ExtractSagaRoutingEventsPass, PassConfig::TYPE_BEFORE_OPTIMIZATION, 50);
            $container->addCompilerPass(new GuardSagaSignalLanesPass, PassConfig::TYPE_BEFORE_OPTIMIZATION, 40);
            $container->addCompilerPass(new GuardSagaCorrelateByPass, PassConfig::TYPE_BEFORE_OPTIMIZATION, 40);
            $container->addCompilerPass(new BindSagaOutcomeRouterPass, PassConfig::TYPE_BEFORE_OPTIMIZATION, 30);
        }
    }

    /**
     * Locate a Storm package's directory in BOTH install layouts. Composer's runtime API answers
     * when the package is installed as its own dependency, the subtree-split world; the sibling
     * fallback covers the monorepo layouts, the storm dev tree itself, or an app consuming the whole
     * `chronhub/storm`, where the packages are directories next to this bundle, not composer
     * installs. A bare `dirname(__DIR__)` alone lies in the split world: a path-repo or subtree
     * install would resolve into whatever tree HOLDS the bundle and "find" packages the consumer
     * never required. Null = genuinely absent, a supported state only for the opt-in packages Saga
     * and Telemetry, whose wiring is guarded on it. Resolvable yet unusable is absent too: a
     * located path without a `config/services.php`, a metapackage, a `replace`d entry or a
     * partially extracted install, answers null and folds into the caller's named refusal instead
     * of a raw file-not-found from the import. Protected, not private: the one seam a test
     * overrides to compile the container with a package genuinely absent, a state the monorepo
     * tree, where every sibling directory exists, cannot produce.
     */
    protected function packageDir(string $module): ?string
    {
        $package = 'chronhub/storm-'.strtolower((string) preg_replace('/(?<!^)(?=[A-Z])/', '-', $module));
        if (InstalledVersions::isInstalled($package)) {
            $path = InstalledVersions::getInstallPath($package);
            if ($path !== null && is_file($path.'/config/services.php')) {
                return $path;
            }
        }

        $sibling = dirname(__DIR__).'/'.$module;

        return is_file($sibling.'/config/services.php') ? $sibling : null;
    }

    /**
     * The sender one lane resolves to: a single transport is its bare Reference, several transports
     * become an inline `ShardedSender` routing by correlation hash, one saga one shard, per-saga
     * order by construction. A missing transport fails the wiring at boot either way.
     *
     * @param  non-empty-list<string>  $transports
     */
    private function laneSender(array $transports): Reference|Definition
    {
        if (count($transports) === 1) {
            return new Reference('messenger.transport.'.$transports[0]);
        }

        return new Definition(ShardedSender::class, [array_map(
            static fn (string $transport): Reference => new Reference('messenger.transport.'.$transport),
            $transports,
        )]);
    }

    /**
     * Everything the bundle wires FOR the opt-in Saga package, kept in one method so the saga guard
     * in `loadExtension()` covers the module atomically: package absent = none of this runs, and the
     * container compiles saga-free.
     *
     * Note the one deliberate leak-with-teeth: `outbox.priority_events_transport` references
     * `%storm.saga.routing_events%`, set by `ExtractSagaRoutingEventsPass`. Configuring that lane
     * WITHOUT the Saga package fails the compile on the unresolved parameter; correct, since the lane
     * exists solely for saga-awaited events.
     *
     * @param  array<mixed>  $saga  the resolved `storm.saga` config section
     */
    private function wireSaga(array $saga, ContainerBuilder $builder): void
    {
        // Saga discovery: #[Workflow] classes are tagged so the WorkflowRegistry collects them,
        // and Activity implementations are tagged into the locator the WorkflowBuilder resolves from.
        $builder->registerAttributeForAutoconfiguration(
            Workflow::class,
            static function (ChildDefinition $definition): void {
                $definition->addTag('storm.saga.workflow');
            },
        );
        $builder->registerForAutoconfiguration(Activity::class)->addTag('storm.saga.activity');

        // The saga engine's EventResolver port has no in-package impl; it must not couple Saga to
        // Chronicler. The bundle bridges it to the event-type mapper here, where depending on both is
        // fine, and aliases the port so the WaitRunner autowires it.
        $builder->autowire(MappedEventResolver::class);
        $builder->setAlias(EventResolver::class, MappedEventResolver::class);

        // Likewise the SagaCommandPublisher port to the command bus, a bundle concern. Mirror of Story's
        // MessengerOutboxPublisher for the event bus.
        $builder->autowire(MessengerSagaCommandPublisher::class)
            ->setArgument('$propagatedKeys', '%storm.context.propagated_keys%');
        $builder->setAlias(SagaCommandPublisher::class, MessengerSagaCommandPublisher::class);

        // The framework's generic saga outcome routing. The router is tagged as a
        // messenger.message_handler for each event-class any #[WaitFor] declares, computed by the
        // compiler passes registered in build(). Saga routing is framework-intrinsic: an app only
        // declares its #[WaitFor] events and writes no per-event reaction naming the workflow type.
        // The correlate-by map routes the external-event waits by a declared payload field.
        $builder->autowire(SagaOutcomeRouter::class)
            ->setArgument('$correlateBy', '%storm.saga.correlate_by%');

        // The child-workflow spawn path: the pure spawner is package-wired in Saga's services.php;
        // only the thin handler lives here, keeping Messenger out of the Saga package. A skipped
        // spawn is announced, a refused one dead-letters and settles the parent's leg through the
        // failure listener below.
        $builder->autowire(StartChildWorkflowHandler::class)->setAutoconfigured(true);
        $builder->autowire(CancelChildWorkflowHandler::class)->setAutoconfigured(true);
        $builder->autowire(PokeParentFamilyHandler::class)->setAutoconfigured(true);

        // The shipped semaphore's promotion wake-up: the workflow, sweep activity, and client are
        // package-wired in Saga's services.php; only this thin GrantSlot handler lives here, the same
        // split as the child-workflow pair above.
        $builder->autowire(GrantSlotHandler::class)->setAutoconfigured(true);

        // The consumer-side dead-letter hook. A poisoned saga command, retries exhausted, fires
        // WorkerMessageFailedEvent, and the listener signals Engine::failIssuedEffect. Whether that
        // SETTLES depends on the evidence: the handler ran, so only its author's #[TransactionalHandler]
        // signature says the throw took its writes with it; otherwise the saga escalates rather than
        // compensating around an effect that may have landed. The relay-side dead-letter is handled
        // inside SagaOutboxRelay itself, post-commit, with Engine wired as an optional argument here.
        $builder->autowire(SagaCommandFailureListener::class)
            ->setAutoconfigured(true)
            ->setArgument('$transactionalHandlers', new Parameter('storm.saga.transactional_handlers'));
        $builder->getDefinition(SagaOutboxRelay::class)->setArgument('$engine', new Reference(Engine::class));

        // Saga operational tuning: package defaults keep the services working standalone; override the
        // lease, timer recovery, + the command-outbox retry from config. `saga` has addDefaultsIfNotSet().
        $builder->getDefinition(TimerRunner::class)->setArgument('$leaseSeconds', $saga['timer_lease_seconds']);

        // The circuit-breaker backend is a re-alias, never a second binding: Saga's services.php already
        // points the port at Postgres, so `postgres` is the branch that does nothing. Redis is registered
        // HERE rather than in the package because only the app can name the connection; Storm opens none.
        $breaker = $saga['circuit_breaker'];
        if ($breaker['storage'] === 'redis') {
            $builder->register(RedisCircuitBreakerStorage::class, RedisCircuitBreakerStorage::class)
                ->setArguments([new Reference($breaker['redis_service']), $breaker['redis_prefix']]);
        }
        if ($breaker['storage'] !== 'postgres') {
            $builder->setAlias(CircuitBreakerStorage::class, $breaker['storage'] === 'redis'
                ? RedisCircuitBreakerStorage::class
                : InMemoryCircuitBreakerStorage::class);
        }
        // The business calendar is OPT-IN: enabled, the declared market binds the port; disabled, the
        // port stays unbound and the first business-time arm fails loud with BusinessCalendarMissing;
        // never a deadline silently computed on a fictitious default market.
        $calendar = $saga['calendar'];
        if ($calendar['enabled'] === true) {
            $builder->register(ConfiguredBusinessCalendar::class, ConfiguredBusinessCalendar::class)
                ->setArgument('$businessDays', $calendar['business_days'])
                ->setArgument('$openHour', $calendar['open_hour'])
                ->setArgument('$closeHour', $calendar['close_hour'])
                ->setArgument('$holidays', $calendar['holidays'])
                ->setArgument('$timezone', $calendar['timezone']);
            $builder->setAlias(BusinessCalendar::class, ConfiguredBusinessCalendar::class);
        }
        // The lane decision is priority-driven. AttributePriorityResolver computes a command's opaque
        // LEVEL from the per-leg #[Prioritized] on the command class, then the workflow's own default,
        // #[Prioritized] on the #[Workflow] class, compiled into %storm.saga.workflow_priorities% by
        // ExtractSagaRoutingEventsPass, then the global default. PriorityLanePolicy maps that level
        // to a lane, a transport. Storm names no levels; the app supplies the defaults and the
        // level-to-transport map. The publisher only sees the SagaLanePolicy port.
        $priority = $saga['priority'];
        // Published as parameters for storm:describe: without this, the default level and the
        // level-to-transport table live only as private constructor args of the resolver and the
        // policy, invisible to the compiled container the descriptor reads.
        $builder->setParameter('storm.saga.priority_default', $priority['default']);
        $builder->setParameter('storm.saga.priority_lanes', $priority['lanes']);
        $builder->register(AttributePriorityResolver::class, AttributePriorityResolver::class)
            ->setArgument('$default', $priority['default'])
            ->setArgument('$perWorkflow', '%storm.saga.workflow_priorities%');
        $builder->register(PriorityLanePolicy::class, PriorityLanePolicy::class)
            ->setArgument('$resolver', new Reference(AttributePriorityResolver::class))
            ->setArgument('$lanes', $priority['lanes']);
        $builder->setAlias(SagaLanePolicy::class, PriorityLanePolicy::class);
        $builder->getDefinition(MessengerSagaCommandPublisher::class)
            ->setArgument('$lanePolicy', new Reference(PriorityLanePolicy::class));
        $sagaOutbox = $saga['command_outbox'];
        $builder->getDefinition(SagaOutboxRelay::class)
            ->setArgument('$maxAttempts', $sagaOutbox['max_attempts'])
            ->setArgument('$backoffBaseSeconds', $sagaOutbox['backoff_base_seconds'])
            ->setArgument('$backoffMaxSeconds', $sagaOutbox['backoff_max_seconds']);
        // Disposal belongs to retention, not to the relay: the relay only ever marks `published`, and
        // the cleanup prune deletes or archives the aged-out row. The bus name rides the same override:
        // the publisher honors only the framework command bus, so the bundle's constant is the single
        // source and the writer's standalone default can never drift from it.
        $builder->getDefinition(DbalWorkflowOutboxWriter::class)
            ->setArgument('$bus', self::COMMAND_BUS)
            ->setArgument('$disposal', OutboxDisposal::from($sagaOutbox['disposal']));
    }
}
