<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use Override;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Storm\Chronicler\Evolution\UpcasterChain;
use Storm\Story\Transport\NeutralMessageSerializer;
use Storm\Symfony\Tests\Boot\KernelWorkspace;
use Storm\Symfony\Tests\Boot\NeutralTransportKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The per-transport neutral wire wiring, proven at the WIRING level: each `storm.neutral_transports`
 * entry yields its own `NeutralMessageSerializer` carrying THAT entry's bus and trust posture, an
 * omitted allowlist is carried as null, meaning same-trust over every resolvable type, and no
 * busless global instance exists. The class is excluded from the Story auto-load, so the config
 * node is the only way to put the serializer on a wire. A real, uncached container compile through
 * {@see NeutralTransportKernel}; the kernel also references one instance from a real messenger
 * transport's `serializer` key, so a broken reference fails the compile itself.
 */
final class NeutralTransportBootTest extends KernelTestCase
{
    #[Override]
    public static function setUpBeforeClass(): void
    {
        // a fresh compile every run; a stale cached container would skip the bundle entirely
        new Filesystem()->remove(KernelWorkspace::dir('storm-neutral-transport-kernel'));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new NeutralTransportKernel('test', true);
    }

    /**
     * The end of a transport serializer chain: FrameworkBundle wraps every serializer a messenger
     * transport references in its `SigningSerializer`, a pass-through until signing is configured;
     * the wiring truth pinned here is OUR instance at the chain's end, whatever the wrapping.
     */
    private static function unwrap(object $serializer): NeutralMessageSerializer
    {
        while (! $serializer instanceof NeutralMessageSerializer) {
            $serializer = new ReflectionProperty($serializer::class, 'inner')->getValue($serializer);
            self::assertIsObject($serializer);
        }

        return $serializer;
    }

    #[Test]
    public function each_entry_carries_its_own_bus_and_trust_posture(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // the same-trust entry: the configured bus, and the omitted allowlist carried as null
        // @phpstan-ignore symfonyContainer.serviceNotFound (the fixture kernel's own public test alias)
        $sameTrust = self::unwrap($container->get('test.neutral_storm_events'));
        self::assertSame('storm.event.bus', new ReflectionProperty(NeutralMessageSerializer::class, 'bus')->getValue($sameTrust));
        self::assertNull(new ReflectionProperty(NeutralMessageSerializer::class, 'allowedTypes')->getValue($sameTrust));
        // the container's tagged chain, not a fresh empty default: the app's #[AsUpcaster] services reach the wire
        self::assertInstanceOf(UpcasterChain::class, new ReflectionProperty(NeutralMessageSerializer::class, 'upcasters')->getValue($sameTrust));

        // the integration-edge entry: its own instance, the declared allowlist, no bus
        // @phpstan-ignore symfonyContainer.serviceNotFound (the fixture kernel's own public test alias)
        $edge = self::unwrap($container->get('test.neutral_partner_feed'));
        self::assertNotSame($sameTrust, $edge);
        self::assertNull(new ReflectionProperty(NeutralMessageSerializer::class, 'bus')->getValue($edge));
        self::assertSame(['bank.account_opened'], new ReflectionProperty(NeutralMessageSerializer::class, 'allowedTypes')->getValue($edge));
    }

    #[Test]
    public function no_busless_global_instance_is_registered(): void
    {
        self::bootKernel();

        // excluded from the Story auto-load: an autowired instance would carry no bus, so a decoded
        // event would fall to the DEFAULT bus and its event-bus handlers would never be found
        self::assertFalse(self::getContainer()->has(NeutralMessageSerializer::class));
    }
}
