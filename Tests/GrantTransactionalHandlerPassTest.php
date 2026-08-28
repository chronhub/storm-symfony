<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Symfony\Compiler\GrantTransactionalHandlerPass;
use Storm\Symfony\Tests\Fixture\ContradictorySignatureHandler;
use Storm\Symfony\Tests\Fixture\GrantFixtureCommand;
use Storm\Symfony\Tests\Fixture\GrantFixtureEvent;
use Storm\Symfony\Tests\Fixture\MethodLevelTransactionalHandler;
use Storm\Symfony\Tests\Fixture\SignedTransactionalHandler;
use Storm\Symfony\Tests\Fixture\TransactionalBuiltinUnionHandler;
use Storm\Symfony\Tests\Fixture\TransactionalHandlesAttributeHandler;
use Storm\Symfony\Tests\Fixture\TransactionalPhantomBesideValidHandler;
use Storm\Symfony\Tests\Fixture\TransactionalPhantomHandlesHandler;
use Storm\Symfony\Tests\Fixture\TransactionalUnionHandler;
use Storm\Symfony\Tests\Fixture\TransactionalUntypedHandler;
use Storm\Symfony\Tests\Fixture\UndeclaredHandler;
use Storm\Symfony\Tests\Fixture\UnsignedCoHandler;
use Storm\Symfony\Tests\Fixture\UnsignedInterfaceTypedCoHandler;
use Storm\Symfony\Tests\Fixture\UnsignedPhantomHandlesHandler;
use Storm\Symfony\Tests\Fixture\UnsignedPhantomUnionCoHandler;
use Storm\Symfony\Tests\Fixture\UnsignedUnrelatedEventHandler;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compiles which MESSAGES have a handler that signed `#[TransactionalHandler]`, the parameter the saga
 * failure listener reads to decide whether a consumer-side dead-letter may settle a saga.
 */
final class GrantTransactionalHandlerPassTest extends TestCase
{
    #[Test]
    public function compiles_the_signed_handlers_message_classes(): void
    {
        $container = new ContainerBuilder;
        $container->register(SignedTransactionalHandler::class, SignedTransactionalHandler::class)->addTag('messenger.message_handler');

        new GrantTransactionalHandlerPass()->process($container);

        $this->assertSame([GrantFixtureCommand::class], $container->getParameter('storm.saga.transactional_handlers'));
    }

    #[Test]
    public function a_signer_cannot_sign_for_a_silent_co_handler_of_the_same_message(): void
    {
        // the signature is per HANDLER, the compiled grant per MESSAGE; granted with an
        // unsigned co-handler, that handler's failures would be settled as "uncommitted" and
        // compensated around an effect it may well have committed; refused at build, loudly
        $container = new ContainerBuilder;
        $container->register(SignedTransactionalHandler::class, SignedTransactionalHandler::class)->addTag('messenger.message_handler');
        $container->register(UnsignedCoHandler::class, UnsignedCoHandler::class)->addTag('messenger.message_handler');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('does not sign it');

        new GrantTransactionalHandlerPass()->process($container);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_co_handler_typed_on_an_ancestor_cannot_escape_the_gate_by_declaring_it_instead(): void
    {
        // Messenger dispatches an #[AsMessageHandler] typed on an interface or a parent class onto
        // every implementing/extending message, the SAME polymorphism GrantInboxDispatchPass's own
        // reader already honors by is_a(). A completeness gate keyed by literal name equality would
        // never find this co-handler under the granted message's own key, since it declared the
        // ancestor instead: exactly the escape SYM-04 named, a co-handler's dead-letter classified
        // "uncommitted" though nothing proved it, because the gate never saw it as a co-handler at all.
        //
        // The unrelated handler is registered FIRST on purpose: its declared type lands in
        // $handledBy ahead of the granted message's own key, in insertion order, so the gate must
        // SKIP a non-matching key and keep scanning, never stop at it, or the polymorphic co-handler
        // further along would never be reached at all.
        $container = new ContainerBuilder;
        $container->register(UnsignedUnrelatedEventHandler::class, UnsignedUnrelatedEventHandler::class)->addTag('messenger.message_handler');
        $container->register(SignedTransactionalHandler::class, SignedTransactionalHandler::class)->addTag('messenger.message_handler');
        $container->register(UnsignedInterfaceTypedCoHandler::class, UnsignedInterfaceTypedCoHandler::class)->addTag('messenger.message_handler');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('does not sign it');

        new GrantTransactionalHandlerPass()->process($container);
    }

    #[Test]
    public function an_unsigned_handler_of_an_unrelated_message_is_not_flagged_as_a_co_handler(): void
    {
        // the hierarchy check must be a real is_a() test, not a stand-in for "any unsigned handler
        // exists somewhere": GrantFixtureEvent shares no ancestor with GrantFixtureCommand, so this
        // unsigned handler must never block the grant, exactly the false positive an over-eager gate
        // would produce if it stopped checking hierarchy and flagged every unsigned handler in reach
        $container = new ContainerBuilder;
        $container->register(SignedTransactionalHandler::class, SignedTransactionalHandler::class)->addTag('messenger.message_handler');
        $container->register(UnsignedUnrelatedEventHandler::class, UnsignedUnrelatedEventHandler::class)->addTag('messenger.message_handler');

        new GrantTransactionalHandlerPass()->process($container);

        $this->assertSame([GrantFixtureCommand::class], $container->getParameter('storm.saga.transactional_handlers'));
    }

    #[Test]
    public function the_grant_set_is_deduplicated_and_sorted_whatever_the_registration_order(): void
    {
        // The parameter is baked into the compiled container, so two builds of one tree must yield
        // the same bytes; registration order belongs to Symfony, the sort is what makes the
        // artifact reproducible. Registered in the order that renders reversed without it, with the
        // command signed twice so the dedupe has something to remove.
        $container = new ContainerBuilder;
        $container->register(MethodLevelTransactionalHandler::class, MethodLevelTransactionalHandler::class)->addTag('messenger.message_handler');
        $container->register(SignedTransactionalHandler::class, SignedTransactionalHandler::class)->addTag('messenger.message_handler');
        $container->register(TransactionalHandlesAttributeHandler::class, TransactionalHandlesAttributeHandler::class)->addTag('messenger.message_handler');

        new GrantTransactionalHandlerPass()->process($container);

        $this->assertSame(
            [GrantFixtureCommand::class, GrantFixtureEvent::class],
            $container->getParameter('storm.saga.transactional_handlers'),
        );
    }

    #[Test]
    public function a_handler_definition_carrying_no_class_is_skipped_rather_than_resolved(): void
    {
        // A tagged definition need not name a class, an alias or a factory-built service among
        // them; reaching class_exists() with null is a TypeError that takes the whole compile down
        // over a service this pass has no business reading.
        $container = new ContainerBuilder;
        $container->register('handler.classless')->addTag('messenger.message_handler');
        $container->register(SignedTransactionalHandler::class, SignedTransactionalHandler::class)->addTag('messenger.message_handler');

        new GrantTransactionalHandlerPass()->process($container);

        $this->assertSame([GrantFixtureCommand::class], $container->getParameter('storm.saga.transactional_handlers'));
    }

    #[Test]
    public function an_unsigned_handler_grants_nothing(): void
    {
        // the DEFAULT, and the safe one: without a signature the settle escalates rather than
        // compensating around an effect nobody accounted for. A real HANDLER, the shape of the
        // signed twin minus its attribute: the message class this once registered has no handling
        // method at all, so the pass skipped it long before reaching the signature it was meant
        // to find absent, and the empty grant said nothing about signatures.
        $container = new ContainerBuilder;
        $container->register(UndeclaredHandler::class, UndeclaredHandler::class)->addTag('messenger.message_handler');

        new GrantTransactionalHandlerPass()->process($container);

        $this->assertSame([], $container->getParameter('storm.saga.transactional_handlers'));
    }

    #[Test]
    public function a_method_level_attributed_reaction_grants_its_message(): void
    {
        // the #[AsMessageHandler] rides a public method, not the class; the method-level
        // derivation branch must grant just the same
        $container = new ContainerBuilder;
        $container->register(MethodLevelTransactionalHandler::class, MethodLevelTransactionalHandler::class)->addTag('messenger.message_handler');

        new GrantTransactionalHandlerPass()->process($container);

        $this->assertSame([GrantFixtureEvent::class], $container->getParameter('storm.saga.transactional_handlers'));
    }

    #[Test]
    public function the_explicit_handles_attribute_grants_the_named_message(): void
    {
        // the AsMessageHandler(handles: ...) form names the message directly, bypassing param reflection
        $container = new ContainerBuilder;
        $container->register(TransactionalHandlesAttributeHandler::class, TransactionalHandlesAttributeHandler::class)->addTag('messenger.message_handler');

        new GrantTransactionalHandlerPass()->process($container);

        $this->assertSame([GrantFixtureCommand::class], $container->getParameter('storm.saga.transactional_handlers'));
    }

    #[Test]
    public function a_union_typed_handler_grants_every_named_class_in_the_union(): void
    {
        // one handler, two possible messages: the settle promise covers BOTH, so both must be granted
        $container = new ContainerBuilder;
        $container->register(TransactionalUnionHandler::class, TransactionalUnionHandler::class)->addTag('messenger.message_handler');

        new GrantTransactionalHandlerPass()->process($container);

        $this->assertSame(
            [GrantFixtureCommand::class, GrantFixtureEvent::class],
            $container->getParameter('storm.saga.transactional_handlers'),
        );
    }

    #[Test]
    public function a_builtin_union_member_is_skipped_not_granted(): void
    {
        // string|Command is a legal signature: the builtin is noise, not a message candidate, so it
        // must neither be granted nor read as an unresolvable typo
        $container = new ContainerBuilder;
        $container->register(TransactionalBuiltinUnionHandler::class, TransactionalBuiltinUnionHandler::class)->addTag('messenger.message_handler');

        new GrantTransactionalHandlerPass()->process($container);

        $this->assertSame([GrantFixtureCommand::class], $container->getParameter('storm.saga.transactional_handlers'));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_signed_handler_with_no_derivable_message_fails_the_build_loud(): void
    {
        // an untyped first parameter derives nothing: a silently empty grant would not error at
        // runtime, it would surface as this handler's dead-letters escalating instead of settling
        $container = new ContainerBuilder;
        $container->register(TransactionalUntypedHandler::class, TransactionalUntypedHandler::class)->addTag('messenger.message_handler');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('silently empty');

        new GrantTransactionalHandlerPass()->process($container);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_unresolvable_candidate_beside_a_valid_grant_fails_the_build(): void
    {
        // the dangerous shape: the valid __invoke keeps the list non-empty, so the typo'd handles:
        // would slip past the empty-grant guard silently un-granted; its dead-letters would
        // escalate where the author expected a settle, the exact defect this pass exists to prevent
        $container = new ContainerBuilder;
        $container->register(TransactionalPhantomBesideValidHandler::class, TransactionalPhantomBesideValidHandler::class)->addTag('messenger.message_handler');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('Storm\Symfony\Tests\Fixture\MissingByTypo');

        new GrantTransactionalHandlerPass()->process($container);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_unsigned_handlers_unresolvable_candidate_is_not_this_passes_error(): void
    {
        // the roster derivation is lenient on purpose: a typo'd handles: on a handler that never
        // signed #[TransactionalHandler] is Messenger's own build error, with the right blame;
        // this pass failing here would accuse an attribute the handler does not carry
        $container = new ContainerBuilder;
        $container->register(UnsignedPhantomHandlesHandler::class, UnsignedPhantomHandlesHandler::class)->addTag('messenger.message_handler');
        $container->register(SignedTransactionalHandler::class, SignedTransactionalHandler::class)->addTag('messenger.message_handler');

        new GrantTransactionalHandlerPass()->process($container);

        // the signed handler's grant compiles untouched beside the unsigned phantom
        $this->assertSame([GrantFixtureCommand::class], $container->getParameter('storm.saga.transactional_handlers'));
    }

    #[Test]
    public function an_unresolvable_candidate_does_not_hide_the_co_handled_message_behind_it(): void
    {
        // the lenient derivation SKIPS the unresolvable candidate and must keep reading the union:
        // ending there would drop this handler from the roster of the message it also handles, and
        // the completeness gate would bless a signer standing beside a co-handler it cannot see
        $container = new ContainerBuilder;
        $container->register(UnsignedPhantomUnionCoHandler::class, UnsignedPhantomUnionCoHandler::class)->addTag('messenger.message_handler');
        $container->register(SignedTransactionalHandler::class, SignedTransactionalHandler::class)->addTag('messenger.message_handler');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('does not sign it');

        new GrantTransactionalHandlerPass()->process($container);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_lone_unresolvable_candidate_takes_the_naming_guard(): void
    {
        // with the typo as the ONLY declaration the build already failed, but through the empty-grant
        // guard, which blames the handler's shape; the resolvability guard fires earlier and names
        // the actual unresolvable candidate, so the author fixes the typo, not the attribute layout
        $container = new ContainerBuilder;
        $container->register(TransactionalPhantomHandlesHandler::class, TransactionalPhantomHandlesHandler::class)->addTag('messenger.message_handler');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('Storm\Symfony\Tests\Fixture\MissingByTypo');

        new GrantTransactionalHandlerPass()->process($container);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_contradictory_pair_of_signatures_fails_the_build(): void
    {
        // #[DispatchesUnderInboxTransaction] is a handler declaring it DOES leave behind an effect a
        // rollback cannot take back, a signed at-least-once broker send, which is precisely what
        // #[TransactionalHandler] promises never happens. Left to run, the pair would tell a saga to
        // compensate around a message already on the wire.
        $container = new ContainerBuilder;
        $container->register(ContradictorySignatureHandler::class, ContradictorySignatureHandler::class)->addTag('messenger.message_handler');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('contradict each other');

        new GrantTransactionalHandlerPass()->process($container);
    }
}
