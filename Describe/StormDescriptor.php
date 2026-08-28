<?php

declare(strict_types=1);

namespace Storm\Symfony\Describe;

use LogicException;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Ledger\SchemaConformance;
use Storm\Projector\Definition\DerivedStreamProjection;
use Storm\Projector\Definition\FanOutLinkProjection;
use Storm\Projector\Definition\FilteredProjection;
use Storm\Projector\Definition\LinkProjection;
use Storm\Projector\Definition\PersistentProjection;
use Storm\Projector\Definition\ProjectionKind;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Projector\Run\FilterTypes;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Schema\SagaSchemaCatalog;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\Cadence\CronCadence;
use Storm\Saga\Workflow\Cadence\IntervalCadence;
use Storm\Saga\Workflow\Fallback\FallbackCandidate;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\RetryPolicy;
use Storm\Saga\Workflow\ScheduleState;
use Storm\Saga\Workflow\State;
use Storm\Saga\Workflow\Timeout;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;
use Storm\Symfony\Compiler\RegisterEventTypesPass;
use Storm\Symfony\Console\DescribeCommand;
use Storm\Symfony\StormBundle;
use Storm\Telemetry\Health\HealthCheck;
use Storm\Telemetry\Schema\TelemetrySchemaCatalog;

/**
 * Assembles the machine-readable self-description of the COMPILED Storm wiring: what is declared
 * and built into the container, never what is happening at runtime. The contract that makes it
 * usable in a CI, a build step, or a tool: zero SQL, zero connection, zero broker, since every
 * source here is an in-memory registry or a compiled container parameter, so the description
 * answers on a kernel whose database is unreachable. Runtime state, checkpoints, lags and
 * instances stay with the inspection commands such as `storm:projection:status` and
 * `storm:saga:list`.
 *
 * Rendering rules, so the document is diffable:
 *
 * - Every collection is a LIST of records sorted by its natural name: alias, workflow name then
 *   version, projection name, state key, spawn slot;
 *
 * - Declaration order wins wherever order IS semantics, since a state's transitions and a
 *   fallback chain are tried in order;
 *
 * - Record fields keep a fixed authored order, and an absent opt-in module renders
 *   `available: false` with its reason rather than a vanishing key;
 *
 * - Closures and handler bodies never serialize: the document carries their class names or a
 *   bare presence flag such as `matcher` or `guarded`, never their content.
 *
 * Section sources:
 *
 * - `meta`: the FORMAT's `schema_version`, the PHP version, the kernel environment.
 *
 * - `event_types`: the compiled `storm.event_classes` parameter, rendered through the
 *   `EventTypeMapper` port, each entry carrying its compiled `#[Personal]` declaration and its
 *   CONSUMERS, `consumed_by`. Upcaster claims are deliberately absent, `Upcaster::supports()`
 *   being dynamic rather than enumerable; producers are absent because storm compiles no
 *   per-class producer declaration, so any list here would be an invention.
 *
 * - `workflows`: `WorkflowRegistry::all()`, attribute-derived at boot.
 *
 * - `projections`: `ProjectionRegistry::all()`, read off the same declarative path the runner
 *   uses, with the declared class list and its alias-expanded stored types exposed as TWO lists,
 *   exactly like the topology gate keeps them apart.
 *
 * - `buses`: the three bus ids the bundle prepends and which one is the default, single-sourced
 *   from the `StormBundle` constants, plus the saga command-priority policy storm itself
 *   compiles. Transports, DSNs, routing and per-bus middleware are app-side Messenger DEPLOYMENT
 *   and are deliberately not rendered.
 *
 * - `grants`: the two compiled grant parameters, `storm.saga.transactional_handlers` and
 *   `storm.story.inbox_dispatch_grants`.
 *
 * - `schemas`: table => owning module, from the verification catalogs, table NAMES only.
 *
 * - `health`: the `storm.health_check`-tagged services, name and class; `check()` is never
 *   called, a probe being runtime rather than wiring.
 *
 * @see DescribeCommand the console rendering
 * @see RegisterEventTypesPass the compiled event-class enumeration
 * @see \Storm\Projector\Run\RunProfile the runner's declarative selection path this mirrors
 * @see \Storm\Projector\Run\TopologyDrift why declared classes and expanded types stay separate
 */
final readonly class StormDescriptor
{
    /** The FORMAT version of the description document, not any package version. */
    public const int SCHEMA_VERSION = 1;

    /** The section names, in document order; the `--section` filter validates against this. */
    public const array SECTIONS = ['meta', 'event_types', 'workflows', 'projections', 'buses', 'grants', 'schemas', 'health'];

    /**
     * @param  ProjectionRegistry  $projections  the `#[AsProjection]`-tagged services, indexed at boot
     * @param  EventTypeMapper  $eventTypeMapper  the compiled alias mapper, for alias/version/expansion
     * @param  WorkflowRegistry|null  $workflows  null when the opt-in Saga package is not installed
     * @param  list<class-string>  $eventClasses  the compiled `storm.event_classes` scan product
     * @param  string  $environment  the kernel environment the container was compiled for
     * @param  list<class-string>|null  $transactionalHandlers  the compiled `storm.saga.transactional_handlers`
     *                                                          grants; null when the grant passes did not run
     * @param  list<class-string>|null  $inboxDispatchGrants  the compiled `storm.story.inbox_dispatch_grants`;
     *                                                        null when the grant passes did not run
     * @param  int|null  $priorityDefaultLevel  the published `storm.saga.priority_default` parameter, the
     *                                          global default level; null also when Saga is absent
     * @param  array<int, string>  $priorityLanes  the published `storm.saga.priority_lanes` parameter:
     *                                             level => transport NAME, the lane realization
     * @param  array<string, int>  $workflowPriorityLevels  the compiled `storm.saga.workflow_priorities`:
     *                                                      workflow name => class-level `#[Prioritized]` level
     * @param  iterable<HealthCheck>|null  $healthChecks  the `storm.health_check`-tagged services; null when
     *                                                    the opt-in Telemetry package is not installed
     * @param  array<class-string, array{subject: string, keys: list<string>, fallbacks: array<string, scalar|null>}>  $personalData
     *                                                                                                                                the compiled `storm.personal_data` map, the `#[Personal]` scan product: which payload
     *                                                                                                                                keys of which event class are ciphered per subject; the catalog is where an auditor
     *                                                                                                                                reads who is GDPR-bearing
     */
    public function __construct(
        private ProjectionRegistry $projections,
        private EventTypeMapper $eventTypeMapper,
        private ?WorkflowRegistry $workflows,
        private array $eventClasses,
        private string $environment,
        private ?array $transactionalHandlers = null,
        private ?array $inboxDispatchGrants = null,
        private ?int $priorityDefaultLevel = null,
        private array $priorityLanes = [],
        private array $workflowPriorityLevels = [],
        private ?iterable $healthChecks = null,
        private array $personalData = [],
    ) {}

    /**
     * The full description document, every section in `SECTIONS` order. The shape is part of the
     * seam: the HTTP twin's provider reads the sections off this exact map, so it is declared
     * key by key, not as an opaque array.
     *
     * @return array{
     *     meta: array<string, mixed>,
     *     event_types: list<array<string, mixed>>,
     *     workflows: array<string, mixed>,
     *     projections: list<array<string, mixed>>,
     *     buses: array<string, mixed>,
     *     grants: array<string, mixed>,
     *     schemas: list<array<string, mixed>>,
     *     health: array<string, mixed>
     * }
     */
    public function describe(): array
    {
        return [
            'meta' => $this->meta(),
            'event_types' => $this->eventTypes(),
            'workflows' => $this->workflowSection(),
            'projections' => $this->projectionEntries(),
            'buses' => $this->buses(),
            'grants' => $this->grants(),
            'schemas' => $this->schemas(),
            'health' => $this->health(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'php_version' => PHP_VERSION,
            'environment' => $this->environment,
        ];
    }

    /**
     * The alias and class pairing, one record per scanned `#[EventType]` class, sorted by alias.
     * The mapper port answers everything: canonical alias via `toType`, declared revision via
     * `versionOf`, and the replaced aliases as `storedTypesOf` minus the canonical alias and the
     * FQCN; no reflection duplicated here.
     *
     * `consumed_by` is the catalog's cross-reference, read off the SAME registries the sibling
     * sections render so the two can never diverge. Producers are deliberately absent: nothing in
     * storm declares "this aggregate emits that class" at compile, so a `produced_by` would be an
     * invention this document refuses to make.
     *
     * @return list<array<string, mixed>>
     */
    private function eventTypes(): array
    {
        $entries = [];

        // the UNION of both scan products: #[EventType] is optional, so a #[Personal] class
        // without it rides the FQCN-fallback mapping and would be ABSENT from this catalog, the
        // very surface an auditor reads to know who is GDPR-bearing; an omission here is a lie
        $classes = array_unique([...$this->eventClasses, ...array_keys($this->personalData)]);

        foreach ($classes as $class) {
            $alias = $this->eventTypeMapper->toType($class);
            $replaces = array_diff($this->eventTypeMapper->storedTypesOf($class), [$alias, $class]);
            sort($replaces);

            $declared = $this->personalData[$class] ?? null;

            $entries[] = [
                'alias' => $alias,
                'class' => $class,
                'version' => $this->eventTypeMapper->versionOf($class),
                'replaces' => $replaces,
                // the GDPR-bearing marker of the catalog: the #[Personal] declaration as compiled,
                // subject key, ciphered keys, declared fallbacks; null for an unmarked class, a
                // fixed key over a vanishing one
                'personal' => $declared === null ? null : [
                    'subject' => $declared['subject'],
                    'keys' => $declared['keys'],
                    'fallbacks' => $declared['fallbacks'],
                ],
                'consumed_by' => [
                    'projections' => $this->projectionConsumers($class),
                    'workflows' => $this->workflowConsumers($class, $alias),
                ],
            ];
        }

        usort($entries, static fn (array $a, array $b): int => $a['alias'] <=> $b['alias']);

        return $entries;
    }

    /**
     * The projections whose DECLARED class filter contains the event class: the same
     * `FilteredProjection::eventTypes()` list the projections section renders as `event_classes`,
     * the dev's intent rather than the alias expansion.
     *
     * @return list<string> projection names, sorted
     */
    private function projectionConsumers(string $class): array
    {
        $names = [];

        foreach ($this->projections->all() as $projection) {
            if ($projection instanceof FilteredProjection && in_array($class, $projection->eventTypes(), true)) {
                $names[] = $projection->name();
            }
        }

        return self::sorted($names);
    }

    /**
     * The workflows that consume the event class: a wait awaiting it by class, a wait awaiting it
     * by its canonical ALIAS when the `#[WaitFor]` was declared with the stable string, or a
     * signal handler keyed by the exact class. One name per workflow, co-registered versions
     * collapse.
     *
     * @return list<string> workflow names, sorted and deduplicated
     */
    private function workflowConsumers(string $class, string $alias): array
    {
        if ($this->workflows === null) {
            return [];
        }

        $names = [];

        foreach ($this->workflows->all() as $definition) {
            if (array_key_exists($class, $definition->signalHandlers)) {
                $names[] = $definition->name;

                continue;
            }

            foreach ($definition->states() as $state) {
                if (! $state instanceof WaitState) {
                    continue;
                }

                if (in_array($class, $state->eventClasses, true) || in_array($alias, $state->eventTypes, true)) {
                    $names[] = $definition->name;

                    // @infection-ignore-all; equivalent, break to continue: `sorted()` deduplicates
                    // before returning, so the repeated appends a full walk would add leave the
                    // answer unchanged
                    break;
                }
            }
        }

        return self::sorted($names);
    }

    /**
     * The workflows section keeps a STABLE shape whether the opt-in Saga package is
     * wired: `available` says which world this kernel is in, and an absent registry renders the
     * reason instead of silently dropping the key; a consumer diffing two deployments must see
     * "saga not installed", not a vanished section.
     *
     * @return array<string, mixed>
     */
    private function workflowSection(): array
    {
        if ($this->workflows === null) {
            return [
                'available' => false,
                'reason' => 'the opt-in Saga package is not installed, so no workflow registry is wired',
                'definitions' => [],
            ];
        }

        $definitions = $this->workflows->all();
        usort(
            $definitions,
            static fn (WorkflowDefinition $a, WorkflowDefinition $b): int => [$a->name, $a->version] <=> [$b->name, $b->version],
        );

        return [
            'available' => true,
            'reason' => null,
            'definitions' => array_map($this->workflow(...), $definitions),
        ];
    }

    /**
     * One built `WorkflowDefinition`, declarative fields only: identity and policies verbatim,
     * signal handlers reduced to their signal classes, spawn slots as records, and the state
     * graph via `state()`.
     *
     * @return array<string, mixed>
     */
    private function workflow(WorkflowDefinition $definition): array
    {
        $signals = array_keys($definition->signalHandlers);
        sort($signals);

        $spawns = [];
        foreach ($definition->spawns as $slot) {
            // `indexed` is not decoration: a family and a static slot behave differently at the
            // awaited wait, one gating on member counts and the other crossing on arrival, and the
            // declaration is the only place that difference is written
            $spawns[] = ['slot' => $slot->slot, 'workflow' => $slot->workflow, 'awaited_by' => $slot->awaitedBy, 'indexed' => $slot->indexed];
        }
        usort($spawns, static fn (array $a, array $b): int => $a['slot'] <=> $b['slot']);

        $states = array_map($this->state(...), $definition->states());
        usort($states, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        return [
            'name' => $definition->name,
            'version' => $definition->version,
            'label' => $definition->label,
            'start' => $definition->start,
            'global_timeout_seconds' => $definition->globalTimeout,
            'on_global_timeout' => $definition->onGlobalTimeout,
            'compensation' => $definition->compensation->value,
            'retry_budget' => $definition->retryBudget,
            'reuse' => $definition->reuse->value,
            'signals' => $signals,
            'spawns' => $spawns,
            'states' => $states,
        ];
    }

    /**
     * One state of the sealed four-kind hierarchy. Kind-specific fields expose the declarative
     * surface; anything that is a closure, whether matcher, extract, guard or per-instance
     * cadence resolver, renders as a presence flag or a type marker, never as content.
     *
     * @return array<string, mixed>
     *
     * @throws LogicException when a fifth State kind appears; the hierarchy is sealed by package
     *                        convention and every instanceof dispatch, this one included, must be revisited
     */
    private function state(State $state): array
    {
        $transitions = [];
        foreach ($state->transitions as $transition) {
            // declaration order is kept: the selector walks the declared edges in order
            $transitions[] = [
                'trigger' => $transition->trigger->value,
                'to' => $transition->to,
                'guarded' => $transition->guard !== null,
                'on_event' => $transition->onEvent,
            ];
        }

        $specific = match (true) {
            $state instanceof ActivityState => [
                'kind' => 'activity',
                'activity' => $state->activity::class,
                'retry' => self::retry($state->retry),
                'timeout' => self::timeout($state->timeout),
                'compensation' => $state->compensation === null ? null : $state->compensation::class,
                'compensation_confirmed_by' => $state->compensationConfirmedBy,
                'circuit_breaker' => $state->circuitBreaker === null ? null : [
                    'key' => $state->circuitBreaker->key,
                    'failure_threshold' => $state->circuitBreaker->failureThreshold,
                    'cooldown_seconds' => $state->circuitBreaker->cooldownSeconds,
                    'resource_key' => $state->circuitBreaker->resourceKey,
                ],
                // the chain is tried in declaration order, so it renders as declared
                'fallbacks' => $state->fallback === null ? [] : array_map(
                    static fn (FallbackCandidate $candidate): array => [
                        'strategy' => $candidate->strategy::class,
                        'guarded' => $candidate->guard !== null,
                    ],
                    $state->fallback->candidates,
                ),
            ],
            $state instanceof WaitState => [
                'kind' => 'wait',
                'event_classes' => self::sorted($state->eventClasses),
                'event_types' => self::sorted($state->eventTypes),
                'matcher' => $state->matcher !== null,
                'extract' => $state->extract !== null,
                'extract_map' => self::extractMap($state->extractMap),
                'retriable' => $state->retriable,
                'timeout' => self::timeout($state->timeout),
            ],
            $state instanceof ScheduleState => [
                'kind' => 'schedule',
                'cadence' => self::cadence($state),
                'catch_up' => [
                    'mode' => $state->catchUp->mode->value,
                    'limit' => $state->catchUp->limit,
                    'window_seconds' => $state->catchUp->windowSeconds,
                ],
                'spread_seconds' => $state->spreadSeconds,
            ],
            $state instanceof FinalState => ['kind' => 'final'],
            default => throw new LogicException(sprintf(
                'Unknown state kind %s — the State hierarchy is sealed to activity/wait/schedule/final.',
                $state::class,
            )),
        };

        return ['key' => $state->key, ...$specific, 'transitions' => $transitions];
    }

    /**
     * The declared cadence as a fixed five-field record, `type`, `seconds`, `expression`, `var`
     * and `method`, with nulls where a field does not apply, so the key set never changes with
     * the cadence kind.
     *
     * - `seconds` is only ever set for a fixed interval;
     *
     * - `expression` for a fixed cron, the declared string kept readable back, without which a
     *   cron schedule renders unreadable;
     *
     * - `var` and `method` for a per-instance cadence, the declared SOURCE the resolver was built
     *   from; the closure itself stays opaque, its origin does not.
     *
     * @return array<string, mixed>
     */
    private static function cadence(ScheduleState $state): array
    {
        // array_replace, not `+`: the template's key ORDER is part of the canonical rendering,
        // and a union would let each kind's own keys jump ahead of it
        $record = ['type' => null, 'seconds' => null, 'expression' => null, 'var' => null, 'method' => null];

        return array_replace($record, match (true) {
            $state->cadence instanceof IntervalCadence => ['type' => 'interval', 'seconds' => $state->cadence->seconds],
            $state->cadence instanceof CronCadence => ['type' => 'cron', 'expression' => $state->cadence->expression()],
            $state->cadence !== null => ['type' => $state->cadence::class],
            $state->intervalResolver !== null => ['type' => 'per_instance_interval', 'var' => $state->cadenceVar, 'method' => $state->cadenceMethod],
            default => ['type' => 'per_instance_cron', 'var' => $state->cadenceVar, 'method' => $state->cadenceMethod],
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function retry(?RetryPolicy $retry): ?array
    {
        if ($retry === null) {
            return null;
        }

        return [
            'max_attempts' => $retry->maxAttempts,
            'strategy' => $retry->strategy->value,
            'base_ms' => $retry->baseMs,
            'jitter' => $retry->jitter,
            'retry_on' => self::sorted($retry->retryOn),
            'do_not_retry_on' => self::sorted($retry->doNotRetryOn),
        ];
    }

    /**
     * Fixed three-field shape whether wall-clock or business-time, so a timeout's presence never
     * changes the record's key set.
     *
     * @return array<string, mixed>|null
     */
    private static function timeout(?Timeout $timeout): ?array
    {
        if ($timeout === null) {
            return null;
        }

        return [
            'seconds' => $timeout->seconds,
            'business_days' => $timeout->businessDays,
            'business_hours' => $timeout->businessHours,
        ];
    }

    /**
     * The raw `varName => payloadField` map as a sorted list of records: a PHP map would encode
     * as `{}` when filled but `[]` when empty, and a canonical format cannot change type on
     * cardinality.
     *
     * @param  array<string, string>  $map
     * @return list<array<string, string>>
     */
    private static function extractMap(array $map): array
    {
        $entries = [];
        foreach ($map as $var => $field) {
            $entries[] = ['var' => $var, 'field' => $field];
        }
        usort($entries, static fn (array $a, array $b): int => $a['var'] <=> $b['var']);

        return $entries;
    }

    /**
     * One record per registered projection, sorted by name. The selection is the same
     * declarative path the runner reads, never a `RunProfile`, which demands run options:
     * `categories()`/`eventTypes()` off `FilteredProjection`, the source stream off
     * `DerivedStreamProjection`. The declared class list and its `FilterTypes` expansion render
     * as two separate lists, the topology gate's split: the classes are the dev's intent, the
     * types the derived SQL filter an added alias legitimately moves.
     *
     * @return list<array<string, mixed>>
     */
    private function projectionEntries(): array
    {
        $entries = [];

        foreach ($this->projections->all() as $projection) {
            $categories = [];
            $eventClasses = [];
            $eventTypes = [];

            if ($projection instanceof FilteredProjection) {
                $categories = self::sorted($projection->categories());
                $eventClasses = self::sorted($projection->eventTypes());
                $eventTypes = self::sorted(FilterTypes::resolve($this->eventTypeMapper, $projection->eventTypes()));
            }

            $entries[] = [
                'name' => $projection->name(),
                'class' => $projection::class,
                'kind' => ProjectionKind::of($projection)->value,
                'categories' => $categories,
                'event_classes' => $eventClasses,
                'event_types' => $eventTypes,
                'source_stream' => $projection instanceof DerivedStreamProjection ? $projection->sourceStream()->toString() : null,
                'target_stream' => $projection instanceof LinkProjection ? $projection->targetStream()->toString() : null,
                'target_prefix' => $projection instanceof FanOutLinkProjection ? $projection->targetPrefix() : null,
                'generation' => $projection instanceof PersistentProjection ? $projection->generation() : null,
            ];
        }

        usort($entries, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $entries;
    }

    /**
     * The bus ids the bundle prepends and the compiled saga command-priority policy. The ids are
     * single-sourced from the `StormBundle` constants its `prependExtension` writes, so this
     * section and the actual messenger prepend cannot drift. Everything app-side, whether
     * transports, DSNs, routing or per-bus middleware, is deployment rather than wiring and stays
     * out; the lane table's values are the transport NAMES `storm.saga.priority.lanes` declares,
     * the lane's realization label, never a DSN.
     *
     * @return array<string, mixed>
     */
    private function buses(): array
    {
        return [
            'default_bus' => StormBundle::COMMAND_BUS,
            // written sorted; the natural order happens to be alphabetical
            'ids' => [StormBundle::COMMAND_BUS, StormBundle::EVENT_BUS, StormBundle::QUERY_BUS],
            'saga_priority' => $this->sagaPriority(),
        ];
    }

    /**
     * The saga command-priority policy as compiled: the global default level, the per-workflow
     * class-level `#[Prioritized]` map, and the level-to-lane table; both maps render as sorted
     * record lists, since their keys, workflow names and levels, are data. Same stable
     * opt-in shape as the workflows section: Saga absent renders the reason, not a vanished key.
     *
     * @return array<string, mixed>
     */
    private function sagaPriority(): array
    {
        if ($this->workflows === null) {
            return [
                'available' => false,
                'reason' => 'the opt-in Saga package is not installed, so no priority policy is wired',
                'default_level' => null,
                'workflow_levels' => [],
                'lanes' => [],
            ];
        }

        $levels = [];
        foreach ($this->workflowPriorityLevels as $workflow => $level) {
            $levels[] = ['workflow' => $workflow, 'level' => $level];
        }
        usort($levels, static fn (array $a, array $b): int => $a['workflow'] <=> $b['workflow']);

        $lanes = [];
        foreach ($this->priorityLanes as $level => $transport) {
            $lanes[] = ['level' => $level, 'transport' => $transport];
        }
        usort($lanes, static fn (array $a, array $b): int => $a['level'] <=> $b['level']);

        return [
            'available' => true,
            'reason' => null,
            'default_level' => $this->priorityDefaultLevel,
            'workflow_levels' => $levels,
            'lanes' => $lanes,
        ];
    }

    /**
     * The two compiled grant parameters, each a sorted list of handled MESSAGE classes; the grant
     * is keyed by message, never by handler, because both readers only ever hold an envelope.
     * Under this bundle both grant passes run unconditionally, Saga installed or not, and without
     * Saga the transactional list compiles empty since no class can carry the absent attribute.
     * The `available: false` branch covers a container this bundle did not compile.
     *
     * @return array<string, mixed>
     */
    private function grants(): array
    {
        if ($this->transactionalHandlers === null || $this->inboxDispatchGrants === null) {
            return [
                'available' => false,
                'reason' => 'the grant compiler passes did not run against this container, so no grant parameters are compiled',
                'transactional_handlers' => [],
                'inbox_dispatch' => [],
            ];
        }

        return [
            'available' => true,
            'reason' => null,
            'transactional_handlers' => self::sorted($this->transactionalHandlers),
            'inbox_dispatch' => self::sorted($this->inboxDispatchGrants),
        ];
    }

    /**
     * Table => owning module, one record per module sorted by module name, tables sorted within.
     * The owner is the module whose installer PROVES the table, through its verification catalog:
     * core via `SchemaConformance` under `storm:install`, saga and telemetry via their own
     * catalogs. Deliberately table names only, no columns and no constraints, since the
     * per-column truth stays with the catalogs the installers verify against. The opt-in modules
     * keep the stable absent shape; their catalog constants
     * are only touched behind the gate, since the classes may not exist.
     *
     * @return list<array<string, mixed>>
     */
    private function schemas(): array
    {
        $saga = ['module' => 'saga', 'available' => false, 'reason' => 'the opt-in Saga package is not installed, so it owns no tables', 'tables' => []];
        if ($this->workflows !== null) {
            $saga = ['module' => 'saga', 'available' => true, 'reason' => null, 'tables' => self::sorted(array_keys(SagaSchemaCatalog::COLUMNS))];
        }

        $telemetry = ['module' => 'telemetry', 'available' => false, 'reason' => 'the opt-in Telemetry package is not installed, so it owns no tables', 'tables' => []];
        if ($this->healthChecks !== null) {
            $telemetry = ['module' => 'telemetry', 'available' => true, 'reason' => null, 'tables' => self::sorted(array_keys(TelemetrySchemaCatalog::COLUMNS))];
        }

        return [
            ['module' => 'core', 'available' => true, 'reason' => null, 'tables' => self::sorted(array_keys(SchemaConformance::COLUMNS))],
            $saga,
            $telemetry,
        ];
    }

    /**
     * The registered health checks, name and class, sorted by name then class. Enumerating
     * CONSTRUCTS the tagged services, safe under the no-DB contract since their DBAL dependencies
     * are lazy, and calls `name()` ONLY, never `check()`: a probe is runtime, this document is
     * wiring. `name()` returning a constant is the interface's own contract, a stable identifier,
     * verified on every in-tree implementation.
     *
     * @return array<string, mixed>
     */
    private function health(): array
    {
        if ($this->healthChecks === null) {
            return [
                'available' => false,
                'reason' => 'the opt-in Telemetry package is not installed, so no health checks are wired',
                'checks' => [],
            ];
        }

        $checks = [];
        foreach ($this->healthChecks as $check) {
            $checks[] = ['name' => $check->name(), 'class' => $check::class];
        }
        usort($checks, static fn (array $a, array $b): int => [$a['name'], $a['class']] <=> [$b['name'], $b['class']]);

        return ['available' => true, 'reason' => null, 'checks' => $checks];
    }

    /**
     * A set-semantics list, declaration order not being topology per the drift gate, sorted for
     * the canonical rendering.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        $values = array_unique($values);
        sort($values);

        return $values;
    }
}
