# Storm Symfony bundle

The **Symfony integration** for Storm (`chronhub/storm-symfony`) — the one bundle a Symfony app
registers (`Storm\Symfony\StormBundle` in `bundles.php`) to get the whole framework wired. An
aggregator plus the COMPOSITION wiring that belongs to no single package: it imports each Storm
package's own `config/services.php`, ships the three CQRS buses ready-wired, auto-discovers your
`#[AsProjection]` / `#[Workflow]` / `#[AsUpcaster]` / `#[EventType]` / `#[Personal]` /
`HealthCheck` / `MessageEnricher` classes, and hosts the cross-package compiler passes. Per-service
wiring stays in the packages (attributes + their own config); what lives HERE is the glue only the
composition can decide — config-driven decorations (cache, crypto, outbox publishers), the batched
consume command, the neutral wire serializers (`storm.neutral_transports`), `storm:describe`'s
descriptor, the framework controllers, and the saga wiring.

> **Opt-in is a TWO-TIER boundary.** The required packages (message, serializer, chronicler, story,
> projector, and friends) are imported **unconditionally** — a missing one is a broken install and
> fails loud, never a silent no-op. Only **Saga** and **Telemetry** are the opt-in tier: absent, the
> import and everything wired behind it are skipped. Telemetry is imported LAST so its explicit
> observability aliases override the Null defaults shipped by Chronicler / Projector.

## The three CQRS buses

`prependExtension` ships them so a consuming app gets them ready-wired; transports and routing stay
app-side deployment choices, only the bus + middleware **shape** is the framework's:

| Bus | Handlers | Middleware (after the default stack) |
|---|---|---|
| `storm.command.bus` (default) | one, **by convention** — Messenger itself only enforces *at least* one and would run co-handlers | `AssignMessageMetadata` · `ValidateUnlessReceived` · `BindMessageContext` · `RecoverConcurrencyConflict` · `DeduplicateConsumer` |
| `storm.query.bus` | one, by the same convention | `AssignMessageMetadata` · `validation` · `BindMessageContext` |
| `storm.event.bus` | **zero or more** (`allow_no_handlers`) | `AssignMessageMetadata` · `BindMessageContext` · `BindStoredHeader` · `RecoverConcurrencyConflict` · `DeduplicateConsumer` |

`AssignMessageMetadata` runs first on every bus so the id + correlation stamps exist before anything can
fail. Commands and queries carry external input and are validated; events are internal facts and are
not. `DeduplicateConsumer` / `RecoverConcurrencyConflict` only bite once a message comes off a transport
(at-least-once delivery); in-process dispatch passes straight through.

## Configuration — zero-config except `aggregates`

Every configuration section has a working default (`event_paths`, `outbox`, `saga`, `occ`, `inbox`,
`neutral_transports`, `event_store_partition_list`, …), so the **only** key you actually write is
`aggregates` — the map that drives the `AggregateRepositoryManager`. Each entry requires an `id` (identity class) and a `category`
(stream category); `snapshot` is optional. Everything else is discoverable, not guessed — dump it with:

```bash
bin/console config:dump-reference storm   # the full tree with every default + an ->info() on each node
bin/console debug:config storm            # the tree as RESOLVED for your app
```

Minimal `config/packages/storm.yaml`:

```yaml
storm:
    aggregates:
        App\Account\Account:
            id: App\Account\AccountId
            category: account
            snapshot: { threshold: 100 }   # optional — omit to disable snapshotting for this aggregate
```

`ValidateAggregatesPass` checks each entry **at container-build time** (a clear failure, not a confusing
runtime error): the aggregate class must exist and implement `AggregateRoot`, the id class must exist and
implement `AggregateIdentity`, stream categories must be unique across aggregates, and a `snapshot`
block's `min_interval_seconds` must be `<= max_age_seconds`.

## Discovery — the two introspection commands

Wiring is attribute-driven and compile-time (no runtime filesystem scan): a service marked
`#[AsProjection]` is auto-tagged into the `ProjectionRegistry`, and a class marked `#[Workflow]` is
auto-tagged into the `WorkflowRegistry`. These two commands surface what was discovered — the check that
your projection / workflow was actually picked up:

- **`bin/console storm:projection:list`** — lists the registered projections and their declared
  `generation` (add `--with-db` to also read the stored generation and flag out-of-date ones). Reads the
  `ProjectionRegistry` (the `#[AsProjection]` discovery).
- **`bin/console storm:saga:versions`** — lists the discovered workflow versions × running-instance
  counts (the purge view); `--check` fails if a running instance is pinned to an unregistered version.
  Reads the `WorkflowRegistry` (the `#[Workflow]` discovery).
- **`bin/console storm:describe`** — the compiled-wiring description: event types (with their
  `#[Personal]` GDPR declarations), projections and their topology, workflows, grants, health checks —
  one static document rendered from the container, never from a live backend. The ops twin lives in
  ApiOps.

## Compiler passes

Nine, ordered by priority (higher runs first); the saga four only register when the Saga package is
installed:

- `RegisterEventTypesPass` (prio 60) — build-time scan of `#[EventType]` events (not services, so they
  cannot be tagged) into the alias/class mapper and the `%storm.event_classes%` enumeration; refuses an
  alias two classes claim.
- `RegisterPersonalDataPass` — the `#[Personal]` scan into the compiled `%storm.personal_data%` map the
  crypto decorator and `storm:describe` read.
- `ValidateAggregatesPass` — the `storm.aggregates` well-formedness check above.
- `GrantInboxDispatchPass` + `GrantTransactionalHandlerPass` — compile the
  `#[DispatchesUnderInboxTransaction]` and `#[TransactionalHandler]` signatures into the per-message
  grant parameters the dual-write guard and the saga failure listener read; the transactional grant
  refuses a message whose co-handler does not sign.
- `ExtractSagaRoutingEventsPass` (prio 50) — reads the tagged workflows, extracts the `#[WaitFor]`
  union into `%storm.saga.routing_events%` (aliases resolved through the scan product, unresolvable
  tokens refused), plus the signal-lane, correlate-by, and workflow-priority maps.
- `GuardSagaSignalLanesPass` + `GuardSagaCorrelateByPass` (prio 40) — refuse a wait whose alternatives
  end up split across lanes, and two waits declaring different correlation fields for one class.
- `BindSagaOutcomeRouterPass` (prio 30) — tags the generic `SagaOutcomeRouter` as a
  `messenger.message_handler` for each routed event class. Pinned to run after Symfony's autoconfigure
  pass and before `MessengerPass`.

## HTTP Ops (opt-in)

The `HealthController` (`/_storm/health`) is autowired but its routes are **not** auto-registered — the
app opts in by importing `@StormBundle/config/routes.php`.

## Resources

This package is developed in the `chronhub/storm` monorepo; a standalone repository for it is a
READ-ONLY subtree split. Report issues and open pull requests on the monorepo, where the tests,
the architecture gates and the full internal documentation live.

---

*Pre-version: this package changes without deprecation cycles — pin a commit if you need
stability, expect resets rather than migrations until the first tagged version.*
