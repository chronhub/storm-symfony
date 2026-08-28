<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Storm\Story\Middleware\AssignMessageMetadata;
use Storm\Story\Middleware\BindMessageContext;
use Storm\Story\Middleware\BindStoredHeader;
use Storm\Story\Middleware\DeduplicateConsumer;
use Storm\Story\Middleware\RecoverConcurrencyConflict;
use Storm\Story\Middleware\ValidateUnlessReceived;
use Storm\Symfony\StormBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class StormBundleTest extends TestCase
{
    /**
     * The per-bus middleware order carries silent correctness invariants: AssignMessageMetadata must
     * precede BindMessageContext, else causation binds null, and DeduplicateConsumer must be last, so
     * handlers run inside the inbox transaction. On the command bus RecoverConcurrencyConflict sits just
     * before dedup, outside that transaction, so a translated OCC conflict is the rolled-back attempt's.
     * A reorder would corrupt metadata, not error, so the effective order is pinned here to make a drift
     * fail in CI rather than in production data.
     */
    #[Test]
    public function ships_each_cqrs_bus_with_its_invariant_middleware_order(): void
    {
        $builder = new ContainerBuilder;
        // prependExtension only touches $builder; the ContainerConfigurator is unused, so skip its plumbing.
        $configurator = new ReflectionClass(ContainerConfigurator::class)->newInstanceWithoutConstructor();

        new StormBundle()->prependExtension($configurator, $builder);

        $messenger = $builder->getExtensionConfig('framework')[0]['messenger'];

        $assign = AssignMessageMetadata::class;
        $validate = ValidateUnlessReceived::class;
        $bind = BindMessageContext::class;
        $bindStored = BindStoredHeader::class;
        $recover = RecoverConcurrencyConflict::class;
        $dedup = DeduplicateConsumer::class;

        $this->assertSame('storm.command.bus', $messenger['default_bus']);
        // Command bus wraps validation in ValidateUnlessReceived, skipped on the consume path; query bus keeps raw.
        $this->assertSame([$assign, $validate, $bind, $recover, $dedup], $messenger['buses']['storm.command.bus']['middleware']);
        $this->assertSame([$assign, 'validation', $bind], $messenger['buses']['storm.query.bus']['middleware']);
        // The event bus carries recover too: a REACTION appending under contention loses a version race
        // the same way a command handler does; same progress argument, same retry-forward translation.
        $this->assertSame([$assign, $bind, $bindStored, $recover, $dedup], $messenger['buses']['storm.event.bus']['middleware']);
    }

    /**
     * The handler-resolution mode per bus is load-bearing too. The event bus tolerates a message with no
     * handler via `allow_no_handlers`: the transactional outbox republishes EVERY recorded event, and
     * whether one is observed is the app's choice, for instance a final-reaction logger, so requiring a
     * handler would dead-letter normal events. The command and query buses require a handler: an unrouted
     * command/query is a bug that must surface, not be dropped. Pinned so a flip of either mode fails in
     * CI, not in production.
     */
    #[Test]
    public function pins_each_bus_handler_resolution_mode(): void
    {
        $builder = new ContainerBuilder;
        $configurator = new ReflectionClass(ContainerConfigurator::class)->newInstanceWithoutConstructor();

        new StormBundle()->prependExtension($configurator, $builder);

        $buses = $builder->getExtensionConfig('framework')[0]['messenger']['buses'];

        $this->assertTrue($buses['storm.command.bus']['default_middleware']);
        $this->assertTrue($buses['storm.query.bus']['default_middleware']);
        $this->assertSame('allow_no_handlers', $buses['storm.event.bus']['default_middleware']);
    }
}
