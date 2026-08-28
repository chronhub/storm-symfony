<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use Override;
use PHPUnit\Framework\Attributes\Test;
use ReflectionObject;
use ReflectionProperty;
use stdClass;
use Storm\Chronicler\Evolution\MappedEventTypeMapper;
use Storm\Chronicler\Evolution\UpcasterChain;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\LiveQuery\Recipe\RecipeRegistry;
use Storm\Message\EnricherRegistry;
use Storm\Message\Message;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Projector\Run\CompositeProjectionCommitListener;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Engine\Engine;
use Storm\Saga\Locking\Dbal\PgAdvisoryFence;
use Storm\Saga\Locking\SagaStepUnitOfWork;
use Storm\Saga\Outbox\WorkflowOutbox;
use Storm\Saga\Priority\AttributePriorityResolver;
use Storm\Saga\Priority\PriorityLanePolicy;
use Storm\Saga\Store\Dbal\DbalWorkflowInstanceStore;
use Storm\Saga\Store\WorkflowInstanceStore;
use Storm\Story\Outbox\MessengerOutboxPublisher;
use Storm\Story\Outbox\ShardedSender;
use Storm\Symfony\Http\Ops\HealthController;
use Storm\Symfony\MessengerSagaCommandPublisher;
use Storm\Symfony\SagaOutcomeRouter;
use Storm\Symfony\StormBundle;
use Storm\Symfony\Tests\Boot\Domain\PingHappened;
use Storm\Symfony\Tests\Boot\ExternalOutcomeArrived;
use Storm\Symfony\Tests\Boot\KernelWorkspace;
use Storm\Symfony\Tests\Boot\PongReceived;
use Storm\Symfony\Tests\Boot\RecordingCommitListener;
use Storm\Symfony\Tests\Boot\StormTestKernel;
use Storm\Symfony\Tests\Fixture\Article;
use Storm\Symfony\Tests\Fixture\ArticleId;
use Storm\Telemetry\HealthChecker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Boots the bundle through the MINIMAL StormTestKernel, a real, uncached container compile with
 * the cache wiped before the class. All five bundle methods run in-process, under test: prepend,
 * configure, load, build, and the compiler passes they register. This is the self-sufficiency
 * net: a service registered nowhere, a dead alias, a leaked autoconfiguration fail HERE,
 * storm-side, not at a consumer's boot.
 *
 * No database, no broker: the compile needs neither, since DBAL is lazy and the transport is
 * in-memory. The assembled-app angle, real PG and monorepo config, stays with
 * tests/Integration/Symfony.
 *
 * @see StormTestKernel the rule: StormBundle + its real deps, nothing more
 */
final class StormBundleBootTest extends KernelTestCase
{
    #[Override]
    public static function setUpBeforeClass(): void
    {
        // a fresh compile every run; a stale cached container would skip the bundle entirely
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
    public function the_bundle_boots_alone_on_its_real_dependencies(): void
    {
        $kernel = self::bootKernel();

        // the compile IS the assertion: config tree validated, passes ran, every kept
        // definition resolved its class and references
        self::assertSame('test', $kernel->getEnvironment());
    }

    #[Test]
    public function the_prepend_ships_the_three_buses(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        foreach (['storm.command.bus', 'storm.query.bus', 'storm.event.bus'] as $bus) {
            self::assertTrue($container->has($bus), sprintf('bus "%s" is not wired', $bus));
        }
    }

    #[Test]
    public function the_wiring_spine_resolves_without_any_app_config(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // each get() instantiates for real; ctor deps, aliases and references all resolve
        self::assertInstanceOf(DbalWorkflowInstanceStore::class, $container->get(WorkflowInstanceStore::class));
        self::assertInstanceOf(PgAdvisoryFence::class, $container->get(SagaStepUnitOfWork::class));
        self::assertInstanceOf(Engine::class, $container->get(Engine::class));
        self::assertInstanceOf(WorkflowOutbox::class, $container->get(WorkflowOutbox::class));
        self::assertInstanceOf(HealthController::class, $container->get(HealthController::class));
        // the publisher holds the hard reference to the storm_events transport; the bundle's
        // documented "fails at boot when missing" contract, proven from the satisfied side
        self::assertInstanceOf(MessengerOutboxPublisher::class, $container->get(MessengerOutboxPublisher::class));
    }

    #[Test]
    public function an_app_recipe_is_discovered_by_implementing_the_interface_alone(): void
    {
        // the README promise, finally true at bundle level: ProbeRecipe carries NO tag and NO
        // attribute; the package's own `_instanceof` rule never reached app definitions, and an
        // app recipe would vanish silently, no listing and no compile error
        self::bootKernel();
        $registry = self::getContainer()->get('test.live_query_recipes'); // @phpstan-ignore symfonyContainer.serviceNotFound (the fixture kernel's own test handle)

        self::assertInstanceOf(RecipeRegistry::class, $registry);
        self::assertNotNull($registry->get('boot_probe'), 'implement LiveQueryRecipe, done — the bundle autoconfigures the tag');
    }

    #[Test]
    public function the_optional_priority_event_lane_wires_from_config(): void
    {
        // the StormTestKernel sets outbox.priority_events_transport, exercising the optional wiring branch:
        // the publisher resolves ONLY if both the priority transport Reference AND the
        // %storm.saga.routing_events% placeholder, which maps to the array-typed $priorityEventTypes, resolved at
        // compile. The awaited-type ROUTING is unit-covered by MessengerOutboxPublisherTest.
        self::bootKernel();

        $publisher = self::getContainer()->get(MessengerOutboxPublisher::class);
        self::assertInstanceOf(MessengerOutboxPublisher::class, $publisher);

        // the bundle populated BOTH lane args: the priority sender, and the awaited set as the RESOLVED
        // %param% array; an unresolved placeholder would be a string, a TypeError on the array arg above
        $reflected = new ReflectionObject($publisher);
        self::assertNotNull($reflected->getProperty('priorityTransport')->getValue($publisher), 'priority sender not wired');
        self::assertIsArray($reflected->getProperty('priorityEventTypes')->getValue($publisher), 'awaited set not an array');

        // the kernel gives the lane TWO transports: the list form must compose the correlation-hash
        // sharded sender, not a bare transport reference
        self::assertInstanceOf(
            ShardedSender::class,
            $reflected->getProperty('priorityTransport')->getValue($publisher),
            'a two-transport lane must wire as a ShardedSender',
        );
    }

    #[Test]
    public function the_signal_lane_split_wires_from_the_laned_wait_to_its_transport(): void
    {
        // the whole chain on a real boot: AwaitingWorkflow's #[WaitFor(lane: 40)] feeds the extract
        // pass, which sets %storm.saga.signal_lanes%, then the guard pass, a one-class wait cannot split
        // and the refusal branches are unit-covered, then the publisher's level-to-sender table from
        // outbox.signal_lanes.
        self::bootKernel();

        $publisher = self::getContainer()->get(MessengerOutboxPublisher::class);
        self::assertInstanceOf(MessengerOutboxPublisher::class, $publisher);

        $reflected = new ReflectionObject($publisher);
        self::assertSame(
            [PongReceived::class => 40],
            $reflected->getProperty('eventLanes')->getValue($publisher),
            'the laned wait did not compile into the publisher map',
        );

        $laneTransports = $reflected->getProperty('laneTransports')->getValue($publisher);
        self::assertIsArray($laneTransports);
        self::assertArrayHasKey(40, $laneTransports, 'level 40 has no realized sender');
    }

    #[Test]
    public function the_correlate_by_declaration_wires_from_the_external_wait_to_the_router(): void
    {
        // the whole chain on a real boot: ExternalWaitWorkflow's #[WaitFor(correlateBy: 'order_id')]
        // feeds the extract pass, which sets %storm.saga.correlate_by%, then the guard pass, a payload event
        // with one declared field and the refusal branches are unit-covered, then the router's map.
        self::bootKernel();

        $router = self::getContainer()->get(SagaOutcomeRouter::class);
        self::assertInstanceOf(SagaOutcomeRouter::class, $router);

        self::assertSame(
            [ExternalOutcomeArrived::class => 'order_id'],
            new ReflectionObject($router)->getProperty('correlateBy')->getValue($router),
            'the external wait did not compile into the router map',
        );
    }

    #[Test]
    public function the_workflow_priority_declaration_wires_from_the_class_to_the_resolver(): void
    {
        // the whole chain on a real boot: PingWorkflow's class-level #[Prioritized(10)] feeds the
        // extract pass, which sets %storm.saga.workflow_priorities%, and the bundle hands the RESOLVED map to
        // AttributePriorityResolver; the per-workflow default comes from code, not config.
        self::bootKernel();

        $publisher = self::getContainer()->get(MessengerSagaCommandPublisher::class);
        self::assertInstanceOf(MessengerSagaCommandPublisher::class, $publisher);

        $policy = new ReflectionObject($publisher)->getProperty('lanePolicy')->getValue($publisher);
        self::assertInstanceOf(PriorityLanePolicy::class, $policy);
        $resolver = new ReflectionObject($policy)->getProperty('resolver')->getValue($policy);
        self::assertInstanceOf(AttributePriorityResolver::class, $resolver);

        self::assertSame(
            ['storm_bundle_test' => 10],
            new ReflectionObject($resolver)->getProperty('perWorkflow')->getValue($resolver),
            'the class-level #[Prioritized] did not compile into the resolver map',
        );
    }

    #[Test]
    public function the_event_scan_maps_the_alias_on_the_configured_path(): void
    {
        self::bootKernel();

        $mapper = self::getContainer()->get(EventTypeMapper::class);

        self::assertInstanceOf(MappedEventTypeMapper::class, $mapper);
        self::assertSame('storm_bundle_test.ping_happened', $mapper->toType(PingHappened::class));
        self::assertSame(PingHappened::class, $mapper->toClass('storm_bundle_test.ping_happened'));
    }

    #[Test]
    public function the_aggregate_map_lands_as_the_parameter(): void
    {
        self::bootKernel();

        self::assertSame(
            [Article::class => ['id' => ArticleId::class, 'category' => 'article']],
            self::getContainer()->getParameter('storm.aggregates'),
        );
    }

    #[Test]
    public function an_app_service_joins_by_implementing_the_extension_point(): void
    {
        self::bootKernel();

        $registry = self::getContainer()->get(EnricherRegistry::class);
        self::assertInstanceOf(EnricherRegistry::class, $registry);

        $enriched = $registry->enrich(new Message(new stdClass));

        // the untagged fixture ran; the bundle's registerForAutoconfiguration alone collected it...
        self::assertSame('on', $enriched->header('storm_bundle_test'));
        // ...alongside the built-ins, which keep filling their concerns
        self::assertNotNull($enriched->messageId());
        self::assertNotNull($enriched->occurredAt());
    }

    #[Test]
    public function the_attribute_discovery_collects_every_carrier(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // the three attribute channels, each fed by one Boot fixture: the closures the bundle
        // registers at loadExtension() tagged them at compile, the registries collected them
        $workflows = $container->get(WorkflowRegistry::class);
        self::assertInstanceOf(WorkflowRegistry::class, $workflows);
        self::assertTrue($workflows->has('storm_bundle_test'), 'the #[Workflow] carrier was not discovered');

        $projections = $container->get(ProjectionRegistry::class);
        self::assertInstanceOf(ProjectionRegistry::class, $projections);
        self::assertTrue($projections->has('storm_bundle_test_counter'), 'the #[AsProjection] carrier was not discovered');
    }

    #[Test]
    public function an_upcaster_joins_the_chain_by_implementing(): void
    {
        self::bootKernel();

        $chain = self::getContainer()->get(UpcasterChain::class);
        self::assertInstanceOf(UpcasterChain::class, $chain);

        // the container's chain, not a hand-built one, applies the #[AsUpcaster] fixture:
        // the tag-to-collection leg UpcastOnReadTest leaves out; it news the chain manually
        self::assertSame(
            ['amount' => 100, 'currency' => 'EUR'],
            $chain->upcast('test.upcast', 1, 2, ['amount' => 100]),
        );
    }

    #[Test]
    public function a_health_check_joins_the_checker_by_implementing(): void
    {
        self::bootKernel();

        $checker = self::getContainer()->get(HealthChecker::class);
        self::assertInstanceOf(HealthChecker::class, $checker);

        // implement-and-done, the promise the bundle's registerForAutoconfiguration writes:
        // the checker reports the fixture under its declared name
        self::assertArrayHasKey('storm_bundle_test', $checker->runAll()['checks']);
    }

    #[Test]
    public function the_bundle_anchors_its_resources_where_it_lives(): void
    {
        // the documented getPath() override: without it the Symfony 8 default walks up to src/
        // and `@StormBundle/config/routes.php`, the app's Ops opt-in, resolves to the wrong dir
        self::assertSame(dirname(__DIR__), new StormBundle()->getPath());
    }

    #[Test]
    public function an_app_commit_listener_joins_the_composite_instead_of_claiming_the_slot(): void
    {
        self::bootKernel();

        $composite = self::getContainer()->get('test.commit_listeners'); // @phpstan-ignore symfonyContainer.serviceNotFound (the fixture kernel's own test handle)
        self::assertInstanceOf(CompositeProjectionCommitListener::class, $composite);

        // the fan-out's CONTENT is a fact, not a belief: exactly this kernel's one app listener,
        // collected by implementing the interface alone; a second listener joins it, never evicts
        $listeners = new ReflectionProperty(CompositeProjectionCommitListener::class, 'listeners')->getValue($composite);
        self::assertIsIterable($listeners);
        $listeners = iterator_to_array($listeners, false);
        self::assertCount(1, $listeners);
        self::assertInstanceOf(RecordingCommitListener::class, $listeners[0]);

        $composite->committed('ping_read_model');
        self::assertSame(['ping_read_model'], $listeners[0]->committed);
    }
}
