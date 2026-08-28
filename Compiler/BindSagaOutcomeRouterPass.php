<?php

declare(strict_types=1);

namespace Storm\Symfony\Compiler;

use Override;
use Storm\Symfony\SagaOutcomeRouter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tags `SagaOutcomeRouter` as a `messenger.message_handler` for every event-class in the union
 * extracted by `ExtractSagaRoutingEventsPass`, on the `storm.event.bus`. Result: any saga-relevant
 * event republished on the event bus reaches the router, which routes it by correlationId.
 *
 * Runs after `ExtractSagaRoutingEventsPass`, which sets the parameter and has already resolved every
 * stable string alias to its class. No-op when the parameter is empty: no saga is registered, or no
 * registered one declares a `#[WaitFor]` at all.
 *
 * @see ExtractSagaRoutingEventsPass
 */
final class BindSagaOutcomeRouterPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        // ExtractSagaRoutingEventsPass runs first at priority 50, after autoconfigure's 100 which applies the
        // `storm.saga.workflow` tag, and always sets the parameter; SagaOutcomeRouter is autowired by the
        // bundle. So both are guaranteed present here.
        /** @var list<class-string> $events */
        $events = $container->getParameter('storm.saga.routing_events');
        if ($events === []) {
            return; // no saga, or none with a class-string WaitFor, so nothing to bind
        }

        $router = $container->getDefinition(SagaOutcomeRouter::class);
        foreach ($events as $eventClass) {
            $router->addTag('messenger.message_handler', [
                'handles' => $eventClass,
                'bus' => 'storm.event.bus',
            ]);
        }
    }
}
