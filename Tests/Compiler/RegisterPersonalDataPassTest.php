<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Compiler;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Ledger\Console\PrivacyForgetCommand;
use Storm\Ledger\Console\StormInstallCommand;
use Storm\Ledger\Crypto\DbalCipherKeyStore;
use Storm\Ledger\Crypto\SubjectForgetter;
use Storm\Message\Exception\InvalidPersonalDeclaration;
use Storm\Serializer\CipheringMessageSerializer;
use Storm\Serializer\DefaultMessageSerializer;
use Storm\Serializer\MessageSerializer;
use Storm\Symfony\Compiler\RegisterPersonalDataPass;
use Storm\Symfony\Tests\PersonalFixture\MarkedPersonalEvent;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class RegisterPersonalDataPassTest extends TestCase
{
    #[Test]
    public function no_op_when_the_serializer_alias_is_not_registered(): void
    {
        $container = new ContainerBuilder;

        new RegisterPersonalDataPass()->process($container);

        // @phpstan-ignore method.alreadyNarrowedType (the symfony extension resolves parameters against the compiled dev container, where the bundle always seeds this one; the bare builder under test has no such seed)
        $this->assertFalse($container->hasParameter('storm.personal_data'));
    }

    #[Test]
    public function an_empty_scan_compiles_an_empty_map_and_registers_no_decorator(): void
    {
        // an empty map means zero decoration, zero cost: the serializer stays the bare codec
        $container = $this->container(paths: []);

        new RegisterPersonalDataPass()->process($container);

        $this->assertSame([], $container->getParameter('storm.personal_data'));
        $this->assertFalse($container->hasDefinition(CipheringMessageSerializer::class));
    }

    #[Test]
    public function compiles_the_declared_map_from_the_scan(): void
    {
        $container = $this->container(paths: [dirname(__DIR__).'/PersonalFixture']);

        new RegisterPersonalDataPass()->process($container);

        $this->assertSame([
            MarkedPersonalEvent::class => [
                'subject' => 'customer_id',
                'keys' => ['full_name'],
                'fallbacks' => ['full_name' => '⌫'],
            ],
        ], $container->getParameter('storm.personal_data'));
    }

    #[Test]
    public function a_non_empty_map_decorates_the_serializer_alias(): void
    {
        // the ONE aliased id is re-routed, the decoration gotcha: every framework
        // consumer autowires the interface, which is exactly this alias
        $container = $this->container(paths: [dirname(__DIR__).'/PersonalFixture']);

        new RegisterPersonalDataPass()->process($container);

        $this->assertTrue($container->hasDefinition(CipheringMessageSerializer::class));
        $decorated = $container->getDefinition(CipheringMessageSerializer::class)->getDecoratedService();
        $this->assertNotNull($decorated);
        $this->assertSame(MessageSerializer::class, $decorated[0]);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_non_empty_map_arms_every_crypto_shredding_consumers_master_key_proof(): void
    {
        // the deploy gate this map turns on, and nothing else does: without the argument each
        // consumer's key store stays null and its proof is SKIPPED in silence, so a rotated key
        // surfaces at the first personal event on a production write path rather than at deploy.
        // Each consumer's own refusals are pinned against a store handed in by hand, which is
        // exactly why the wiring that hands it over had to be pinned too.
        $container = $this->container(paths: [dirname(__DIR__).'/PersonalFixture']);
        $container->register(StormInstallCommand::class, StormInstallCommand::class)->setArgument('$privacyKeys', null);
        $container->register(SubjectForgetter::class, SubjectForgetter::class)->setArgument('$keys', null);
        $container->register(PrivacyForgetCommand::class, PrivacyForgetCommand::class)->setArgument('$keys', null);

        new RegisterPersonalDataPass()->process($container);

        foreach ([
            StormInstallCommand::class => '$privacyKeys',
            SubjectForgetter::class => '$keys',
            PrivacyForgetCommand::class => '$keys',
        ] as $service => $argument) {
            $armed = $container->getDefinition($service)->getArguments()[$argument] ?? null;

            $this->assertInstanceOf(Reference::class, $armed, sprintf('%s is not armed at all', $service));
            $this->assertSame(DbalCipherKeyStore::class, (string) $armed);
        }
    }

    #[Test]
    public function an_empty_map_leaves_every_crypto_shredding_consumer_unarmed(): void
    {
        // the other half of the same switch, and the reason the wiring lives in this pass rather
        // than in the Ledger config: an app with no marked class must never resolve the master-key
        // env, not through the installer, not through the forget verb, not through its assembly
        $container = $this->container(paths: []);
        $container->register(StormInstallCommand::class, StormInstallCommand::class)->setArgument('$privacyKeys', null);
        $container->register(SubjectForgetter::class, SubjectForgetter::class)->setArgument('$keys', null);
        $container->register(PrivacyForgetCommand::class, PrivacyForgetCommand::class)->setArgument('$keys', null);

        new RegisterPersonalDataPass()->process($container);

        foreach ([
            StormInstallCommand::class => '$privacyKeys',
            SubjectForgetter::class => '$keys',
            PrivacyForgetCommand::class => '$keys',
        ] as $service => $argument) {
            $this->assertNull($container->getDefinition($service)->getArgument($argument), sprintf('%s must stay unarmed', $service));
        }
    }

    #[Test]
    public function a_declared_key_without_its_fallback_breaks_the_build(): void
    {
        // attributes are lazy, so the class LOADS fine; only this pass
        // instantiating the declaration can refuse it before rows are written unrecoverable
        $container = $this->container(paths: [dirname(__DIR__).'/ScanBadPersonal']);

        $this->expectException(InvalidPersonalDeclaration::class);
        $this->expectExceptionMessageMatches('/email/');

        new RegisterPersonalDataPass()->process($container);
    }

    /**
     * @param  list<string>  $paths
     */
    private function container(array $paths): ContainerBuilder
    {
        $container = new ContainerBuilder;
        $container->register(DefaultMessageSerializer::class, DefaultMessageSerializer::class);
        $container->setAlias(MessageSerializer::class, DefaultMessageSerializer::class);
        $container->setParameter('storm.event_paths', $paths);

        return $container;
    }
}
