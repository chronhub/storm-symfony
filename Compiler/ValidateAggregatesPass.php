<?php

declare(strict_types=1);

namespace Storm\Symfony\Compiler;

use Override;
use Storm\AggregateRepository\AggregateRepositoryManager;
use Storm\Contracts\Aggregate\AggregateIdentity;
use Storm\Contracts\Aggregate\AggregateRoot;
use Storm\Contracts\Aggregate\SnapshotableAggregateRoot;
use Storm\Stream\Exception\InvalidStreamException;
use Storm\Stream\StreamName;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Build-time well-formedness check for `storm.aggregates`: each entry's aggregate class must exist
 * and implement `AggregateRoot`, and its id class must exist and implement `AggregateIdentity`. A
 * `snapshot` block, when present, must be coherent and sit on a class that actually implements
 * `SnapshotableAggregateRoot`. Coherent means `min_interval_seconds <= max_age_seconds`, since the
 * frequency floor cannot exceed the staleness ceiling that overrides it. The sweep silently skips a
 * non-snapshotable class forever, so a mismatched block is a config bug, not a preference.
 *
 * Categories are validated CANONICAL, not merely unique: `StreamName` trims and lowercases at
 * runtime, so `Order` and `order` would pass a raw-string uniqueness check yet converge on the
 * same `order-*` streams, and the sweep compares the RAW configured value to the stored stream
 * prefix, finding nothing, silently, forever. A non-canonical category fails the build naming the
 * canonical form to declare.
 *
 * Fails the container build with a clear message instead of a confusing runtime error when the
 * `AggregateRepositoryManager` later builds the repository from a typo'd or wrong-typed config entry.
 *
 * @see \Storm\AggregateRepository\AggregateRepositoryManager
 * @see \Storm\Stream\StreamName the runtime normalization this pass front-runs
 */
final class ValidateAggregatesPass implements CompilerPassInterface
{
    /**
     * {@inheritDoc}
     *
     * @throws InvalidConfigurationException when a `storm.aggregates` entry is malformed: a missing or
     *                                       wrong-typed aggregate or id class, a category that is not a
     *                                       valid stream category or not its canonical form, a category
     *                                       shared by two aggregates, a snapshot block on a class that is
     *                                       not snapshotable, or an incoherent snapshot interval
     */
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (! $container->hasParameter('storm.aggregates')) { // @phpstan-ignore booleanNot.alwaysFalse (defensive: the param is absent in a unit test / bundle-less container)
            return;
        }

        /** @var array<string, array{id?: string, category?: string, snapshot?: array{threshold?: int, max_age_seconds?: int|null, min_interval_seconds?: int|null}}> $aggregates */
        $aggregates = (array) $container->getParameter('storm.aggregates');
        $categories = [];

        foreach ($aggregates as $class => $config) {
            if (! class_exists($class)) {
                throw new InvalidConfigurationException(sprintf('storm.aggregates: aggregate class "%s" does not exist.', $class));
            }

            if (! is_a($class, AggregateRoot::class, true)) {
                throw new InvalidConfigurationException(sprintf('storm.aggregates: "%s" must implement %s.', $class, AggregateRoot::class));
            }

            $id = $config['id'] ?? '';

            if (! class_exists($id)) {
                throw new InvalidConfigurationException(sprintf('storm.aggregates: id class "%s" (for "%s") does not exist.', $id, $class));
            }

            if (! is_a($id, AggregateIdentity::class, true)) {
                throw new InvalidConfigurationException(sprintf('storm.aggregates: id class "%s" (for "%s") must implement %s.', $id, $class, AggregateIdentity::class));
            }

            $category = $config['category'] ?? null;

            if ($category !== null) {
                try {
                    $canonical = new StreamName($category)->category;
                } catch (InvalidStreamException $e) {
                    throw new InvalidConfigurationException(sprintf(
                        'storm.aggregates: "%s" category "%s" is not a valid stream category: %s',
                        $class,
                        $category,
                        $e->getMessage(),
                    ), previous: $e);
                }

                if ($canonical !== $category) {
                    throw new InvalidConfigurationException(sprintf(
                        'storm.aggregates: "%s" category "%s" is not canonical — StreamName normalizes it to "%s" at runtime, while the raw value would be used by the sweep and this uniqueness check; declare it exactly as "%s".',
                        $class,
                        $category,
                        $canonical,
                        $canonical,
                    ));
                }

                if (isset($categories[$category])) {
                    throw new InvalidConfigurationException(sprintf(
                        'storm.aggregates: "%s" and "%s" both use the stream category "%s" — categories must be unique (a shared category collides their event streams and snapshots).',
                        $categories[$category],
                        $class,
                        $category,
                    ));
                }

                $categories[$category] = $class;
            }

            $snapshot = $config['snapshot'] ?? null;

            if (is_array($snapshot)) {
                if (! is_a($class, SnapshotableAggregateRoot::class, true)) {
                    throw new InvalidConfigurationException(sprintf(
                        'storm.aggregates: "%s" declares a snapshot block but does not implement %s — the sweep would silently skip it forever; implement the contract or drop the block.',
                        $class,
                        SnapshotableAggregateRoot::class,
                    ));
                }

                $maxAge = $snapshot['max_age_seconds'] ?? null;
                $minInterval = $snapshot['min_interval_seconds'] ?? null;

                if ($minInterval !== null && $maxAge !== null && $minInterval > $maxAge) {
                    throw new InvalidConfigurationException(sprintf(
                        'storm.aggregates: "%s" snapshot.min_interval_seconds (%d) must be <= max_age_seconds (%d) — the frequency floor cannot exceed the staleness ceiling.',
                        $class,
                        $minInterval,
                        $maxAge,
                    ));
                }
            }
        }
    }
}
