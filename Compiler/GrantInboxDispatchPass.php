<?php

declare(strict_types=1);

namespace Storm\Symfony\Compiler;

use LogicException;
use Override;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use Storm\Story\Attribute\DispatchesUnderInboxTransaction;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Compiles the `#[DispatchesUnderInboxTransaction]` grants: the handled message classes whose handlers
 * signed the dual-write contract, published as the `storm.story.inbox_dispatch_grants` parameter the
 * guarded senders locator reads.
 *
 * Compile-time, not runtime reflection: at the send site the guard only knows the HANDLED message class,
 * the ambient inbox-transaction entry, never which handler object is executing; the grant is keyed by
 * message type, derived here from each declared handler's `#[AsMessageHandler]` shape: an explicit
 * `handles:`, else the first parameter type of the attributed method, `__invoke` for a class-level
 * attribute. An interface or parent class is a legal entry, the shapes Messenger itself resolves onto
 * concrete messages, and the locator honors it by `is_a`, never by string equality, so both halves
 * agree on hierarchy. A declared handler whose message cannot be derived fails the BUILD, loud: a
 * silently empty grant would resurface at runtime as a refused send on a handler that did sign the
 * contract.
 *
 * Scans only services tagged `messenger.message_handler`, the same registry `MessengerPass` consumes, and
 * reflects shallowly, mirroring the saga routing passes.
 *
 * @see \Storm\Story\Attribute\DispatchesUnderInboxTransaction the grant and its contract
 * @see \Storm\Story\Transport\InboxGuardedSendersLocator the reader
 */
final class GrantInboxDispatchPass implements CompilerPassInterface
{
    /**
     * {@inheritDoc}
     *
     * @throws LogicException when a signed handler derives no handled message at all, or names one
     *                        that resolves to neither a class nor an interface; either way the
     *                        grant would be silently empty and the signed send refused at runtime
     */
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        $granted = [];

        foreach (array_keys($container->findTaggedServiceIds('messenger.message_handler')) as $id) {
            $class = $container->getDefinition($id)->getClass();
            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->getAttributes(DispatchesUnderInboxTransaction::class) === []) {
                continue;
            }

            $messages = $this->handledMessages($reflection);

            if ($messages === []) {
                throw new LogicException(sprintf(
                    'Handler "%s" declares #[DispatchesUnderInboxTransaction] but no handled message class'
                    .' could be derived from its #[AsMessageHandler] shape (explicit handles:, or a typed'
                    .' first parameter on the attributed method / __invoke). The grant would be silently'
                    .' empty and the guard would refuse this handler\'s sends at runtime.',
                    $class,
                ));
            }

            $granted = [...$granted, ...$messages];
        }

        $granted = array_unique($granted);
        sort($granted); // deterministic parameter means a stable compiled container

        $container->setParameter('storm.story.inbox_dispatch_grants', $granted);
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @return list<class-string>
     */
    private function handledMessages(ReflectionClass $reflection): array
    {
        $messages = [];

        foreach ($reflection->getAttributes(AsMessageHandler::class) as $attribute) {
            $handler = $attribute->newInstance();

            $messages = [...$messages, ...$this->messagesOf($reflection, $handler, $handler->method ?? '__invoke')];
        }

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(AsMessageHandler::class) as $attribute) {
                $messages = [...$messages, ...$this->messagesOf($reflection, $attribute->newInstance(), $method->getName())];
            }
        }

        return $messages;
    }

    /**
     * The handled class of one `#[AsMessageHandler]`: its explicit `handles:` when given, else the
     * first parameter type of the attributed method.
     *
     * The same collection rule as the transactional grant's: builtins in the parameter type are
     * skipped at collection, since a legal `string|SomeCommand` signature is noise, not a
     * candidate; what remains MUST resolve to a class or an interface. A typo'd `handles:` silently
     * filtered would leave that message un-granted beside a valid sibling declaration: the
     * handler's signed broker send would be refused by the dual-write guard at runtime, far from
     * the wiring that caused it. It fails the build instead, naming both.
     *
     * @param  ReflectionClass<object>  $reflection
     * @return list<class-string>
     *
     * @throws LogicException when a declaration names an unresolvable message
     */
    private function messagesOf(ReflectionClass $reflection, AsMessageHandler $handler, string $method): array
    {
        $candidates = [];

        if ($handler->handles !== null) {
            $candidates[] = $handler->handles;
        } elseif ($reflection->hasMethod($method)) {
            $parameters = $reflection->getMethod($method)->getParameters();
            $type = $parameters === [] ? null : $parameters[0]->getType();

            $named = match (true) {
                $type instanceof ReflectionNamedType => [$type],
                $type instanceof ReflectionUnionType => array_filter($type->getTypes(), static fn ($t): bool => $t instanceof ReflectionNamedType),
                default => [],
            };

            foreach ($named as $namedType) {
                if ($namedType->isBuiltin()) {
                    // @infection-ignore-all; equivalent, continue to break: PHP normalizes a union
                    // with its class types first and its builtins behind them, so no candidate class
                    // can sit past a builtin and both exits collect the same names
                    continue;
                }

                $candidates[] = $namedType->getName();
            }
        }

        $messages = [];
        foreach ($candidates as $name) {
            if (! class_exists($name) && ! interface_exists($name)) {
                throw new LogicException(sprintf(
                    'Handler "%s" declares #[DispatchesUnderInboxTransaction] but its #[AsMessageHandler]'
                    .' names "%s", which resolves to neither a class nor an interface. A typo here would'
                    .' leave the message silently un-granted and the handler\'s signed broker send refused'
                    .' by the dual-write guard at runtime, far from this wiring.',
                    $reflection->getName(),
                    $name,
                ));
            }

            $messages[] = $name;
        }

        return $messages;
    }
}
