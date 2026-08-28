<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Ledger\Console\PrivacyForgetCommand;
use Storm\Serializer\CipheringMessageSerializer;
use Storm\Serializer\DefaultMessageSerializer;
use Storm\Serializer\MessageSerializer;
use Storm\Symfony\Tests\Boot\ConfigurableStormKernel;
use Storm\Symfony\Tests\Boot\KernelWorkspace;
use Storm\Symfony\Tests\PersonalFixture\MarkedPersonalEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The wiring witness of crypto-shredding, both halves of the on/off switch: a kernel whose scan
 * paths hold ONE `#[Personal]` class compiles the map and re-routes the `MessageSerializer` alias
 * through the ciphering decorator, pinning the decoration gotcha live since every framework
 * consumer autowires exactly that alias, while the nominal kernel, marking nothing, keeps the
 * bare codec: zero decoration, zero cost, and the master key never even resolves.
 */
final class CipheringBootTest extends TestCase
{
    #[Override]
    public static function setUpBeforeClass(): void
    {
        // every variant compiles fresh; a cached container would skip the bundle entirely
        new Filesystem()->remove(KernelWorkspace::dir('ciphering'));
    }

    #[Test]
    public function a_marked_class_compiles_the_map_and_decorates_the_serializer_alias(): void
    {
        $kernel = $this->kernelWith('marked', [
            'event_paths' => [__DIR__.'/PersonalFixture'],
            // a literal test key: the store must instantiate without any env var in play
            'privacy' => ['master_key' => base64_encode(str_repeat('k', 32))],
        ]);

        $kernel->boot();
        $container = $this->containerOf($kernel);

        // @phpstan-ignore symfonyContainer.privateService (the test container hands out the private alias)
        $this->assertInstanceOf(CipheringMessageSerializer::class, $container->get(MessageSerializer::class));

        $this->assertSame([
            MarkedPersonalEvent::class => [
                'subject' => 'customer_id',
                'keys' => ['full_name'],
                'fallbacks' => ['full_name' => '⌫'],
            ],
        ], $kernel->getContainer()->getParameter('storm.personal_data'));

        // the forgetting verb rides the same wiring: its key store resolves, lazily, on the same alias
        // @phpstan-ignore symfonyContainer.privateService
        $this->assertInstanceOf(PrivacyForgetCommand::class, $container->get(PrivacyForgetCommand::class));
    }

    #[Test]
    public function no_marked_class_keeps_the_bare_serializer(): void
    {
        $kernel = $this->kernelWith('bare', ['event_paths' => []]);

        $kernel->boot();

        $this->assertSame([], $kernel->getContainer()->getParameter('storm.personal_data'));
        // @phpstan-ignore symfonyContainer.privateService
        $this->assertInstanceOf(DefaultMessageSerializer::class, $this->containerOf($kernel)->get(MessageSerializer::class));
    }

    /**
     * @param  array<string, mixed>  $storm
     */
    private function kernelWith(string $variant, array $storm): ConfigurableStormKernel
    {
        return new ConfigurableStormKernel($storm, 'ciphering/'.$variant);
    }

    private function containerOf(ConfigurableStormKernel $kernel): ContainerInterface
    {
        // the test container only exists under framework.test; stan reads the dev container's XML
        // @phpstan-ignore symfonyContainer.serviceNotFound
        $container = $kernel->getContainer()->get('test.service_container');
        $this->assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }
}
