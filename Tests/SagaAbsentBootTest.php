<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Storm\Symfony\Tests\Boot\KernelWorkspace;
use Storm\Symfony\Tests\Boot\SagaAbsentKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The claim the atomic saga guard rests on, compiled and booted: Saga absent degrades to a no-op
 * container, never to a half-wired one failing on `getDefinition` of absent services. Untestable
 * from the monorepo tree itself, where every sibling package directory exists; the `SagaAbsent`
 * bundle subclass overrides `packageDir()`, the seam kept protected for exactly this test.
 */
final class SagaAbsentBootTest extends KernelTestCase
{
    #[Override]
    public static function setUpBeforeClass(): void
    {
        // a fresh compile every run; a stale cached container would keep serving a saga-full one
        new Filesystem()->remove(KernelWorkspace::dir('saga-absent'));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new SagaAbsentKernel('test', false);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_container_compiles_and_boots_saga_free_when_the_package_is_absent(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // wireSaga never ran: its unconditional parameter is the discriminant. Stan's symfony
        // extension answers hasParameter from the NOMINAL compiled container, but this kernel
        // compiles its own, saga-free; the parameter set is a runtime fact here, not a type-level
        // one.
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertFalse($container->hasParameter('storm.saga.priority_default'));

        // the absence is saga's alone; a required package's seeded parameter still resolves
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertTrue($container->hasParameter('storm.event_classes'));
    }
}
