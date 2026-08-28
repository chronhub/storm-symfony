<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Chronicler\Evolution\MappedEventTypeMapper;
use Storm\Chronicler\Exception\UnknownEventType;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Symfony\MappedEventResolver;
use Storm\Symfony\Tests\Fixture\PayloadEvent;
use Storm\Symfony\Tests\Fixture\ScannerFixtureEvent;

/**
 * The PROD saga-routing resolver; the test suites use anonymous fixture resolvers, while this is
 * the one the bundle actually wires.
 */
final class MappedEventResolverTest extends TestCase
{
    #[Test]
    public function alias_for_returns_the_declared_event_type_alias(): void
    {
        $resolver = new MappedEventResolver(new MappedEventTypeMapper([ScannerFixtureEvent::class]));

        $this->assertSame('scanner.fixture.real-event', $resolver->aliasFor(new ScannerFixtureEvent));
    }

    #[Test]
    public function alias_for_falls_back_to_the_fqcn_without_an_attribute(): void
    {
        $resolver = new MappedEventResolver(new MappedEventTypeMapper([]));

        $this->assertSame(stdClass::class, $resolver->aliasFor(new stdClass));
    }

    #[Test]
    public function alias_for_returns_null_when_the_mapper_knows_no_stable_alias(): void
    {
        // the contracted UnknownEventType path, EventTypeMapper::toType @throws, yields no alias, not a crash
        $mapper = new class() implements EventTypeMapper
        {
            public function toType(string $class): string
            {
                throw UnknownEventType::cannotResolve($class);
            }

            public function toClass(string $type): string
            {
                throw UnknownEventType::cannotResolve($type);
            }

            public function versionOf(string $class): int
            {
                return 1;
            }

            /** @return list<string> */
            public function storedTypesOf(string $class): array
            {
                return [$class];
            }
        };

        $this->assertNull(new MappedEventResolver($mapper)->aliasFor(new stdClass));
    }

    #[Test]
    public function payload_for_uses_the_explicit_pair_or_yields_empty(): void
    {
        $resolver = new MappedEventResolver(new MappedEventTypeMapper([]));

        $this->assertSame(['orderId' => 'o-1', 'amount' => 5], $resolver->payloadFor(new PayloadEvent('o-1', 5)));
        $this->assertSame([], $resolver->payloadFor(new stdClass));
    }
}
