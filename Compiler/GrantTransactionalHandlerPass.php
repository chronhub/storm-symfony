<?php

declare(strict_types=1);

namespace Storm\Symfony\Compiler;

use LogicException;
use Override;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use Storm\Saga\Attributes\TransactionalHandler;
use Storm\Story\Attribute\DispatchesUnderInboxTransaction;
use Storm\Symfony\SagaCommandFailureListener;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Compiles the `#[TransactionalHandler]` signatures: the handled message classes whose handlers promise
 * to commit or roll back as one, published as the `storm.saga.transactional_handlers` parameter the
 * failure listener reads.
 *
 * Compile-time, not runtime reflection, and keyed by MESSAGE rather than handler for the same reason
 * the inbox-dispatch grants are: at the failure site the listener holds an envelope, so it knows which
 * message died, never which handler object was executing when it did.
 *
 * A declared handler whose message cannot be derived fails the BUILD, loud. A silently empty grant
 * would not surface as an error at runtime; it would surface as a saga quietly escalating where its
 * author expected a compensation, which is the kind of wrong that gets noticed a week later.
 *
 * Refuses the contradictory pair at build: a handler signing BOTH this and
 * `#[DispatchesUnderInboxTransaction]` has misread one of them.
 *
 * The completeness gate honors the polymorphism Messenger itself dispatches on: a co-handler typed
 * on an interface or a parent class the granted message implements is checked by `is_a()`, never by
 * a literal name match, so it cannot escape the gate by declaring an ancestor instead of the
 * concrete class its signed sibling names.
 *
 * @see \Storm\Saga\Attributes\TransactionalHandler the contract and what signing it promises
 * @see SagaCommandFailureListener the reader
 */
final class GrantTransactionalHandlerPass implements CompilerPassInterface
{
    /**
     * {@inheritDoc}
     *
     * @throws LogicException when a signed handler also signs the contradictory
     *                        `#[DispatchesUnderInboxTransaction]`, derives no handled message at all,
     *                        names one that resolves to neither a class nor an interface, or a
     *                        granted message has a co-handler that does not sign; one handler must
     *                        never sign for another
     */
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        $granted = [];
        $handledBy = []; // message => list of EVERY derivable handler class, signed or not
        $signed = []; // handler classes carrying the signature, the completeness gate's roster

        foreach (array_keys($container->findTaggedServiceIds('messenger.message_handler')) as $id) {
            $class = $container->getDefinition($id)->getClass();
            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            // every handler's messages are derived, signed or not: the completeness gate below
            // needs the co-handlers of a granted message, not only its signers. Lenient on purpose:
            // an unsigned handler's unresolvable handles: is Messenger's own build error to report
            // with the right blame; throwing here would accuse #[TransactionalHandler] on a handler
            // that never declared it
            foreach ($this->handledMessages($reflection, strict: false) as $message) {
                $handledBy[$message][] = $class;
            }

            if ($reflection->getAttributes(TransactionalHandler::class) === []) {
                continue;
            }

            $signed[$class] = true;

            // The two signatures are contradictory, and a handler that carries both has misread one of
            // them: #[DispatchesUnderInboxTransaction] is a handler declaring it DOES leave an effect a
            // rollback cannot take back, a broker send inside the still-open inbox transaction, signed
            // at-least-once, which is exactly what #[TransactionalHandler] promises never happens. Left
            // to run, the pair would tell the saga to compensate around a message already on the wire.
            if ($reflection->getAttributes(DispatchesUnderInboxTransaction::class) !== []) {
                throw new LogicException(sprintf(
                    'Handler "%s" declares both #[TransactionalHandler] and #[DispatchesUnderInboxTransaction],'
                    .' which contradict each other: the second signs an at-least-once broker send that'
                    .' survives a rollback, the first promises nothing survives one. Drop whichever does not'
                    .' describe this handler — if it really does send under the inbox transaction, it is NOT'
                    .' transactional for the saga settle.',
                    $class,
                ));
            }

            $messages = $this->handledMessages($reflection);

            if ($messages === []) {
                throw new LogicException(sprintf(
                    'Handler "%s" declares #[TransactionalHandler] but no handled message class could be'
                    .' derived from its #[AsMessageHandler] shape (explicit handles:, or a typed first'
                    .' parameter on the attributed method / __invoke). The grant would be silently empty'
                    .' and this handler\'s dead-letters would escalate instead of settling.',
                    $class,
                ));
            }

            $granted = [...$granted, ...$messages];
        }

        $granted = array_unique($granted);
        sort($granted); // deterministic parameter means a stable compiled container

        // The completeness gate: the SIGNATURE is per handler, the compiled GRANT is
        // per message, so one signer must never sign for its silent co-handlers. A message granted
        // here whose OTHER handler is unsigned would let that co-handler's uncommitted-looking
        // failure trigger a compensation around an effect it may well have committed. Refused at
        // build; the honest limit is that a handler whose message cannot be derived is invisible
        // to this gate, exactly as it is invisible to the grant itself.
        //
        // `$handledBy` is keyed by each handler's OWN declared type, an interface or a parent class
        // being a legal declaration Messenger itself dispatches onto: a co-handler typed on such an
        // ancestor is a real handler of the granted concrete message at runtime, but a lookup keyed
        // by the granted message's own literal name would never find it under the ancestor's key.
        // So every declared type is checked by `is_a()`, never by string equality, the same rule
        // `GrantInboxDispatchPass`'s reader already honors for its own grant.
        foreach ($granted as $message) {
            foreach ($handledBy as $declaredType => $handlerClasses) {
                if (! is_a($message, $declaredType, true)) {
                    continue;
                }

                foreach ($handlerClasses as $handlerClass) {
                    if (! isset($signed[$handlerClass])) {
                        throw new LogicException(sprintf(
                            'Message "%s" is granted #[TransactionalHandler], but its co-handler "%s" does not'
                            .' sign it: the grant is keyed by MESSAGE, so the unsigned handler\'s failures would'
                            .' be settled as "uncommitted" and compensated around an effect it may have'
                            .' committed. Sign the co-handler if it really is transactional, or restructure so'
                            .' the granted message has only transactional handlers.',
                            $message,
                            $handlerClass,
                        ));
                    }
                }
            }
        }

        $container->setParameter('storm.saga.transactional_handlers', $granted);
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @return list<class-string>
     *
     * @throws LogicException when strict and one of the declarations names an unresolvable message
     */
    private function handledMessages(ReflectionClass $reflection, bool $strict = true): array
    {
        $messages = [];

        foreach ($reflection->getAttributes(AsMessageHandler::class) as $attribute) {
            $handler = $attribute->newInstance();
            $messages = [...$messages, ...$this->messagesOf($reflection, $handler, $handler->method ?? '__invoke', $strict)];
        }

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(AsMessageHandler::class) as $attribute) {
                $messages = [...$messages, ...$this->messagesOf($reflection, $attribute->newInstance(), $method->getName(), $strict)];
            }
        }

        return $messages;
    }

    /**
     * The handled class of one `#[AsMessageHandler]`: its explicit `handles:` when given, else the first
     * parameter type of the attributed method.
     *
     * Builtins in the parameter type are skipped at collection: a legal `string|SomeCommand` signature
     * is noise, not a message candidate. What remains MUST resolve to a class or an interface; an
     * unresolvable candidate, a typo'd `handles:` or a renamed message, fails the build naming both
     * the handler and the candidate. Silently filtering it would leave the typo'd message un-granted
     * beside another valid declaration on the same handler, invisible until its first dead-letter
     * escalates a saga its author expected to settle, precisely the defect this pass exists to prevent.
     *
     * @param  ReflectionClass<object>  $reflection
     * @return list<class-string>
     *
     * @throws LogicException when strict and a candidate resolves to neither a class nor an interface
     */
    private function messagesOf(ReflectionClass $reflection, AsMessageHandler $handler, string $method, bool $strict = true): array
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
                if (! $strict) {
                    continue;
                }

                throw new LogicException(sprintf(
                    'Handler "%s" declares #[TransactionalHandler] but its #[AsMessageHandler] names'
                    .' "%s", which resolves to neither a class nor an interface. A typo here would leave'
                    .' the message silently un-granted and its dead-letters would escalate instead of'
                    .' settling.',
                    $reflection->getName(),
                    $name,
                ));
            }

            $messages[] = $name;
        }

        return $messages;
    }
}
