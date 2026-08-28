<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Compiler;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Evolution\MappedEventTypeMapper;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Symfony\Compiler\RegisterEventTypesPass;
use Storm\Symfony\Tests\Fixture\ScannerFixtureEvent;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class RegisterEventTypesPassTest extends TestCase
{
    #[Test]
    public function no_op_when_the_mapped_mapper_is_not_registered(): void
    {
        $container = new ContainerBuilder;

        new RegisterEventTypesPass()->process($container);

        $this->assertFalse($container->hasAlias(EventTypeMapper::class));
    }

    #[Test]
    public function feeds_scanned_classes_and_activates_the_mapper(): void
    {
        $container = new ContainerBuilder;
        $container->register(MappedEventTypeMapper::class, MappedEventTypeMapper::class);
        $container->setParameter('storm.event_paths', []); // empty means an identity mapper, still wired

        new RegisterEventTypesPass()->process($container);

        // the scanned classes are fed to the mapper, and it becomes the active EventTypeMapper
        $this->assertSame([], $container->getDefinition(MappedEventTypeMapper::class)->getArgument('$eventClasses'));
        $this->assertTrue($container->hasAlias(EventTypeMapper::class));
        $this->assertSame(MappedEventTypeMapper::class, (string) $container->getAlias(EventTypeMapper::class));
    }

    #[Test]
    public function publishes_the_scanned_classes_as_the_compiled_enumeration_parameter(): void
    {
        $container = new ContainerBuilder;
        $container->register(MappedEventTypeMapper::class, MappedEventTypeMapper::class);
        $container->setParameter('storm.event_paths', [dirname(__DIR__).'/Fixture']);

        new RegisterEventTypesPass()->process($container);

        // the enumeration seam for storm:describe: the mapper answers per class and enumerates
        // nothing, so the scan product must be readable back from the compiled parameter
        $this->assertContains(ScannerFixtureEvent::class, $container->getParameter('storm.event_classes'));
    }

    #[Test]
    public function an_alias_two_classes_claim_fails_the_build_not_the_first_resolution(): void
    {
        // the mapper's own constructor refuses the duplicate, but a lazy service
        // constructs at FIRST USE, deep in a consumer, far from the wiring that caused it; the
        // compile gate moves the same refusal to the build, where the contradiction was authored.
        // The two claimants of `capture.done` live in separate fixture dirs, joined here on purpose.
        $container = new ContainerBuilder;
        $container->setParameter('storm.event_paths', [dirname(__DIR__).'/Fixture', dirname(__DIR__).'/AliasCollision']);
        $container->setDefinition(MappedEventTypeMapper::class, new Definition(MappedEventTypeMapper::class));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('capture.done');

        new RegisterEventTypesPass()->process($container);
    }
}
