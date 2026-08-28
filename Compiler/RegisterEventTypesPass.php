<?php

declare(strict_types=1);

namespace Storm\Symfony\Compiler;

use LogicException;
use Override;
use ReflectionClass;
use Storm\Chronicler\Evolution\MappedEventTypeMapper;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Message\EventType;
use Storm\Symfony\Describe\StormDescriptor;
use Storm\Symfony\EventTypeScanner;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Build-time discovery of `#[EventType]` events, which aren't services so they can't be tagged: it
 * scans `storm.event_paths`, feeds the found classes to `MappedEventTypeMapper`, and makes it the
 * active `EventTypeMapper`, overriding the default identity mapper.
 *
 * Empty paths produce an empty list, so the mapper behaves as identity, all-FQCN; fine for an app
 * with no aliases.
 *
 * The scanned class list is ALSO published as the `storm.event_classes` parameter: the mapper
 * resolves per class and exposes no enumeration, so this compiled parameter is the one place the
 * scan product can be read back. `storm:describe` renders its `event_types` section from it,
 * through the mapper port, without re-scanning or reflecting. The bundle seeds the parameter to
 * `[]` so it resolves even when this pass no-ops.
 *
 * @see StormDescriptor the consumer of `storm.event_classes`
 */
final class RegisterEventTypesPass implements CompilerPassInterface
{
    /**
     * {@inheritDoc}
     *
     * @throws LogicException when two scanned classes claim one alias token, current or a
     *                        `replaces` entry; a stored row under it could not resolve to one class
     */
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (! $container->hasDefinition(MappedEventTypeMapper::class)) {
            return;
        }

        // `storm.event_paths` is always set by the bundle's loadExtension, which runs whenever the
        // pass is registered, so read it directly; resolve %kernel.project_dir% etc.
        $resolved = $container->getParameterBag()->resolveValue($container->getParameter('storm.event_paths'));

        /** @var array<array-key, string> $paths */
        $paths = array_filter((array) $resolved, is_string(...));

        $classes = new EventTypeScanner()->scan($paths);

        // The collision gate: two classes claiming one alias, current or former, would otherwise
        // compile fine and only break at the first mapper resolution, deep in a consumer.
        // Every alias token across the scan product must name exactly ONE class; a duplicate is a
        // wiring contradiction the boot must refuse, not a runtime surprise.
        $claimed = [];

        foreach ($classes as $class) {
            $eventType = new ReflectionClass($class)->getAttributes(EventType::class)[0]->newInstance();

            foreach ([$eventType->alias, ...$eventType->replaces] as $token) {
                if (isset($claimed[$token])) {
                    throw new LogicException(sprintf(
                        'Event type alias "%s" is claimed by both "%s" and "%s" (current alias or a replaces entry):'
                        .' a stored row under that alias cannot resolve to one class. Align the aliases — this used'
                        .' to surface only at the first mapper resolution, far from the wiring that caused it.',
                        $token,
                        $claimed[$token],
                        $class,
                    ));
                }

                $claimed[$token] = $class;
            }
        }

        $container->getDefinition(MappedEventTypeMapper::class)->setArgument('$eventClasses', $classes);
        $container->setAlias(EventTypeMapper::class, MappedEventTypeMapper::class);

        // the enumeration seam: the mapper answers per class, never "which classes exist", so the
        // scan product is baked in as a parameter for the compiled-wiring description
        $container->setParameter('storm.event_classes', $classes);
    }
}
