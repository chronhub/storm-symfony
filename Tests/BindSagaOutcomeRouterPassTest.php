<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Symfony\Compiler\BindSagaOutcomeRouterPass;
use Storm\Symfony\SagaOutcomeRouter;
use Storm\Symfony\Tests\Fixture\RoutedEvent;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class BindSagaOutcomeRouterPassTest extends TestCase
{
    #[Test]
    public function tags_the_router_as_a_messenger_handler_for_each_event_class(): void
    {
        $container = new ContainerBuilder;
        $container->setDefinition(SagaOutcomeRouter::class, new Definition(SagaOutcomeRouter::class));
        $container->setParameter('storm.saga.routing_events', [RoutedEvent::class, stdClass::class]);

        new BindSagaOutcomeRouterPass()->process($container);

        $tags = $container->getDefinition(SagaOutcomeRouter::class)->getTag('messenger.message_handler');
        $this->assertCount(2, $tags);
        $this->assertSame(['handles' => RoutedEvent::class, 'bus' => 'storm.event.bus'], $tags[0]);
        $this->assertSame(['handles' => stdClass::class, 'bus' => 'storm.event.bus'], $tags[1]);
    }

    #[Test]
    public function is_a_noop_when_the_event_union_is_empty(): void
    {
        $container = new ContainerBuilder;
        $container->setDefinition(SagaOutcomeRouter::class, new Definition(SagaOutcomeRouter::class));
        $container->setParameter('storm.saga.routing_events', []);

        new BindSagaOutcomeRouterPass()->process($container);

        $this->assertSame([], $container->getDefinition(SagaOutcomeRouter::class)->getTag('messenger.message_handler'));
    }
}
