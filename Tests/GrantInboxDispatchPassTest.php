<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Story\Consume\InboxTransactionContext;
use Storm\Story\Transport\InboxGuardedSendersLocator;
use Storm\Symfony\Compiler\GrantInboxDispatchPass;
use Storm\Symfony\Tests\Fixture\BrokenGrantedHandler;
use Storm\Symfony\Tests\Fixture\ClassAndMethodHandler;
use Storm\Symfony\Tests\Fixture\GrantedInvokableHandler;
use Storm\Symfony\Tests\Fixture\GrantedMethodHandler;
use Storm\Symfony\Tests\Fixture\GrantFixtureCommand;
use Storm\Symfony\Tests\Fixture\GrantFixtureContract;
use Storm\Symfony\Tests\Fixture\GrantFixtureEvent;
use Storm\Symfony\Tests\Fixture\HandlesAttributeHandler;
use Storm\Symfony\Tests\Fixture\InboxPhantomBesideValidHandler;
use Storm\Symfony\Tests\Fixture\InterfaceTypedGrantedHandler;
use Storm\Symfony\Tests\Fixture\TwiceAttributedHandler;
use Storm\Symfony\Tests\Fixture\UndeclaredHandler;
use Storm\Symfony\Tests\Fixture\UnionTypedHandler;
use Storm\Symfony\Tests\Fixture\UntypedParamHandler;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

final class GrantInboxDispatchPassTest extends TestCase
{
    #[Test]
    public function compiles_the_grants_from_class_and_method_level_handler_shapes(): void
    {
        $container = new ContainerBuilder;
        $container->register(GrantedInvokableHandler::class, GrantedInvokableHandler::class)->addTag('messenger.message_handler');
        $container->register(GrantedMethodHandler::class, GrantedMethodHandler::class)->addTag('messenger.message_handler');

        new GrantInboxDispatchPass()->process($container);

        // sorted for a deterministic compiled container
        $this->assertSame(
            [GrantFixtureCommand::class, GrantFixtureEvent::class],
            $container->getParameter('storm.story.inbox_dispatch_grants'),
        );
    }

    #[Test]
    public function the_grant_set_is_deduplicated_and_sorted_whatever_the_registration_order(): void
    {
        // This parameter is baked into the compiled container, and two builds of the same code must
        // produce the same bytes: the registration order is Symfony's, not ours, so it is the sort
        // that makes the artifact reproducible. Registered here in the order that renders REVERSED
        // without it, with the command claimed twice so the dedupe has something to remove.
        $container = new ContainerBuilder;
        $container->register(GrantedMethodHandler::class, GrantedMethodHandler::class)->addTag('messenger.message_handler');
        $container->register(GrantedInvokableHandler::class, GrantedInvokableHandler::class)->addTag('messenger.message_handler');
        $container->register(HandlesAttributeHandler::class, HandlesAttributeHandler::class)->addTag('messenger.message_handler');

        new GrantInboxDispatchPass()->process($container);

        $this->assertSame(
            [GrantFixtureCommand::class, GrantFixtureEvent::class],
            $container->getParameter('storm.story.inbox_dispatch_grants'),
        );
    }

    #[Test]
    public function two_class_level_attributes_grant_both_of_their_declared_methods(): void
    {
        // The attribute is repeatable and each occurrence names its own method. Two things ride on
        // this: the walk ACCUMULATES across occurrences rather than keeping the last, and it reads
        // the declared method instead of assuming `__invoke`, which this handler does not have.
        $container = new ContainerBuilder;
        $container->register(TwiceAttributedHandler::class, TwiceAttributedHandler::class)->addTag('messenger.message_handler');

        new GrantInboxDispatchPass()->process($container);

        $this->assertSame(
            [GrantFixtureCommand::class, GrantFixtureEvent::class],
            $container->getParameter('storm.story.inbox_dispatch_grants'),
        );
    }

    #[Test]
    public function a_class_level_grant_and_a_method_level_one_are_both_kept(): void
    {
        // The two surfaces are read by separate walks, and what a handler grants is their union: a
        // grant lost between them leaves the dual-write guard refusing a send the signature allows.
        $container = new ContainerBuilder;
        $container->register(ClassAndMethodHandler::class, ClassAndMethodHandler::class)->addTag('messenger.message_handler');

        new GrantInboxDispatchPass()->process($container);

        $this->assertSame(
            [GrantFixtureCommand::class, GrantFixtureEvent::class],
            $container->getParameter('storm.story.inbox_dispatch_grants'),
        );
    }

    #[Test]
    public function a_handler_definition_carrying_no_class_is_skipped_rather_than_resolved(): void
    {
        // A tagged definition may name no class at all, an alias or a factory-built service among
        // them. Reaching class_exists() with null is a TypeError at COMPILE time, which takes down
        // the whole build over a service this pass has no business reading.
        $container = new ContainerBuilder;
        $container->register('handler.classless')->addTag('messenger.message_handler');
        $container->register(HandlesAttributeHandler::class, HandlesAttributeHandler::class)->addTag('messenger.message_handler');

        new GrantInboxDispatchPass()->process($container);

        $this->assertSame([GrantFixtureCommand::class], $container->getParameter('storm.story.inbox_dispatch_grants'));
    }

    #[Test]
    public function the_explicit_handles_attribute_grants_the_named_message(): void
    {
        // the AsMessageHandler(handles: ...) form names the message directly, bypassing param reflection
        $container = new ContainerBuilder;
        $container->register(HandlesAttributeHandler::class, HandlesAttributeHandler::class)->addTag('messenger.message_handler');

        new GrantInboxDispatchPass()->process($container);

        $this->assertSame([GrantFixtureCommand::class], $container->getParameter('storm.story.inbox_dispatch_grants'));
    }

    #[Test]
    public function an_interface_typed_handler_grants_the_interface(): void
    {
        // Messenger resolves interface-typed handlers onto every implementing message, so the
        // interface IS the handler's declared message type and enters the grant set as itself;
        // resolving it to implementors here would freeze at compile a set the app can extend
        $container = new ContainerBuilder;
        $container->register(InterfaceTypedGrantedHandler::class, InterfaceTypedGrantedHandler::class)->addTag('messenger.message_handler');

        new GrantInboxDispatchPass()->process($container);

        $this->assertSame([GrantFixtureContract::class], $container->getParameter('storm.story.inbox_dispatch_grants'));
    }

    #[Test]
    #[Group('adversarial')]
    public function the_compiled_grant_and_the_runtime_guard_agree_on_an_interface_signed_handler(): void
    {
        // The seam the two halves' own suites cannot see: the pass compiles the INTERFACE into the
        // grant while the runtime context holds the CONCRETE handled class. This drives the pass's
        // actual output into the actual locator; a string-equality guard would refuse here, the
        // exact runtime refusal the pass exists to prevent.
        $container = new ContainerBuilder;
        $container->register(InterfaceTypedGrantedHandler::class, InterfaceTypedGrantedHandler::class)->addTag('messenger.message_handler');

        new GrantInboxDispatchPass()->process($container);

        /** @var list<class-string> $granted */
        $granted = $container->getParameter('storm.story.inbox_dispatch_grants');

        $context = new InboxTransactionContext;
        $context->enter(GrantFixtureCommand::class);

        $sender = new class() implements SenderInterface
        {
            public function send(Envelope $envelope): Envelope
            {
                return $envelope;
            }
        };
        $inner = new readonly class($sender) implements SendersLocatorInterface
        {
            public function __construct(private SenderInterface $sender) {}

            public function getSenders(Envelope $envelope): iterable
            {
                yield 'async' => $this->sender;
            }
        };

        $locator = new InboxGuardedSendersLocator($inner, $context, $granted);

        $senders = iterator_to_array($locator->getSenders(new Envelope(new GrantFixtureCommand)));

        $this->assertSame(['async' => $sender], $senders);
    }

    #[Test]
    public function a_union_typed_handler_grants_every_named_class_in_the_union(): void
    {
        $container = new ContainerBuilder;
        $container->register(UnionTypedHandler::class, UnionTypedHandler::class)->addTag('messenger.message_handler');

        new GrantInboxDispatchPass()->process($container);

        $this->assertSame(
            [GrantFixtureCommand::class, GrantFixtureEvent::class],
            $container->getParameter('storm.story.inbox_dispatch_grants'),
        );
    }

    #[Test]
    #[Group('adversarial')]
    public function a_signed_handler_with_an_untyped_parameter_fails_the_build_loud(): void
    {
        // the no-type match arm derives nothing, so, like the `mixed` case, a signed handler with no
        // derivable message must not compile to a silently empty grant
        $container = new ContainerBuilder;
        $container->register(UntypedParamHandler::class, UntypedParamHandler::class)->addTag('messenger.message_handler');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/DispatchesUnderInboxTransaction/');

        new GrantInboxDispatchPass()->process($container);
    }

    #[Test]
    public function an_unsigned_handler_grants_nothing(): void
    {
        $container = new ContainerBuilder;
        $container->register(UndeclaredHandler::class, UndeclaredHandler::class)->addTag('messenger.message_handler');

        new GrantInboxDispatchPass()->process($container);

        $this->assertSame([], $container->getParameter('storm.story.inbox_dispatch_grants'));
    }

    #[Test]
    public function no_handlers_still_publishes_an_empty_grant_set(): void
    {
        // the guarded senders locator references the parameter unconditionally; it must always exist
        $container = new ContainerBuilder;

        new GrantInboxDispatchPass()->process($container);

        $this->assertSame([], $container->getParameter('storm.story.inbox_dispatch_grants'));
    }

    #[Test]
    #[Group('adversarial')]
    public function an_unresolvable_candidate_beside_a_valid_grant_fails_the_build(): void
    {
        // the dangerous shape: the valid __invoke keeps the list non-empty, so a silent filter
        // would grant partially; the typo'd message's signed broker send would then be refused
        // by the dual-write guard at runtime, far from the wiring that caused it
        $container = new ContainerBuilder;
        $container->register(InboxPhantomBesideValidHandler::class, InboxPhantomBesideValidHandler::class)->addTag('messenger.message_handler');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('Storm\\Symfony\\Tests\\Fixture\\MissingByTypo');

        new GrantInboxDispatchPass()->process($container);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_signed_handler_with_no_derivable_message_fails_the_build_loud(): void
    {
        // a silently empty grant would resurface at runtime as a refused send on a handler that DID
        // sign the contract; the build is where the mistake is visible
        $container = new ContainerBuilder;
        $container->register(BrokenGrantedHandler::class, BrokenGrantedHandler::class)->addTag('messenger.message_handler');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/DispatchesUnderInboxTransaction/');

        new GrantInboxDispatchPass()->process($container);
    }
}
