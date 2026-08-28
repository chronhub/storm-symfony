<?php

declare(strict_types=1);

namespace Storm\Symfony\Compiler;

use Override;
use ReflectionClass;
use Storm\Contracts\Serializer\CipherKeyStore;
use Storm\Ledger\Console\PrivacyForgetCommand;
use Storm\Ledger\Console\StormInstallCommand;
use Storm\Ledger\Crypto\DbalCipherKeyStore;
use Storm\Ledger\Crypto\SubjectForgetter;
use Storm\Message\Attribute\Personal;
use Storm\Message\Exception\InvalidPersonalDeclaration;
use Storm\Serializer\CipheringMessageSerializer;
use Storm\Serializer\MessageSerializer;
use Storm\Symfony\EventTypeScanner;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Build-time discovery of `#[Personal]` classes, compiled into the `storm.personal_data`
 * parameter: `class => {subject, keys, fallbacks}`. The marked events aren't services, so the same
 * file scan as `RegisterEventTypesPass` walks `storm.event_paths`. That map is what the ciphering
 * serializer reads with zero reflection at runtime, and the one place the declarations can be read
 * back; `storm:describe` renders them per event type.
 *
 * Instantiating each attribute IS the compile guard: a degenerate declaration, a missing fallback
 * above all, throws from the attribute's own constructor and breaks the build here, before any row
 * is written unprotected or unrecoverable.
 *
 * A non-empty map is ALSO what turns the feature on: only then is `CipheringMessageSerializer`
 * registered, decorating the `MessageSerializer` alias. An app with no marked class therefore
 * carries zero decoration and zero cost. The decoration re-routes the ONE aliased id, the known
 * gotcha: every framework consumer autowires the interface, which is exactly that alias.
 *
 * The same non-empty map is what arms every crypto-shredding consumer against the cipher-key
 * store: `StormInstallCommand`, `SubjectForgetter` and `PrivacyForgetCommand` each carry the
 * argument as an explicit null in the package's own service config, the one thing autowiring
 * cannot silently override, so this pass is the SOLE place any of the three ever resolves the
 * master-key env. An app with no marked class never reaches it, through any of the three.
 *
 * @see RegisterEventTypesPass the sibling scan this one mirrors
 */
final class RegisterPersonalDataPass implements CompilerPassInterface
{
    /**
     * {@inheritDoc}
     *
     * @throws InvalidPersonalDeclaration when a scanned declaration is degenerate, a missing fallback above all
     */
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (! $container->hasAlias(MessageSerializer::class)) {
            return;
        }

        // `storm.event_paths` is always set by the bundle's loadExtension; resolve %kernel.project_dir% etc.
        $resolved = $container->getParameterBag()->resolveValue($container->getParameter('storm.event_paths'));

        /** @var array<array-key, string> $paths */
        $paths = array_filter((array) $resolved, is_string(...));

        $map = [];

        foreach (new EventTypeScanner()->scan($paths, Personal::class) as $class) {
            // newInstance() runs the attribute's constructor: the declaration invariants explode
            // HERE, at container build, the guard the attribute documents
            $attribute = new ReflectionClass($class)->getAttributes(Personal::class)[0]->newInstance();

            $map[$class] = [
                'subject' => $attribute->subject,
                'keys' => $attribute->keys,
                'fallbacks' => $attribute->fallback,
            ];
        }

        $container->setParameter('storm.personal_data', $map);

        if ($map === []) {
            return;
        }

        $container->register(CipheringMessageSerializer::class, CipheringMessageSerializer::class)
            ->setDecoratedService(MessageSerializer::class)
            ->setArguments([
                new Reference('.inner'),
                new Reference(CipherKeyStore::class),
                '%storm.personal_data%',
            ]);

        // the map being non-empty is ALSO what arms every crypto-shredding consumer: wiring them
        // here, never in the Ledger package config, is what keeps an app without #[Personal]
        // classes from ever resolving the master-key env through any of the three
        foreach ([
            StormInstallCommand::class => '$privacyKeys',
            SubjectForgetter::class => '$keys',
            PrivacyForgetCommand::class => '$keys',
        ] as $service => $argument) {
            if ($container->hasDefinition($service)) {
                $container->getDefinition($service)->setArgument($argument, new Reference(DbalCipherKeyStore::class));
            }
        }
    }
}
