<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Storm\Ledger\Console\PrivacyForgetCommand;
use Storm\Ledger\Console\StormInstallCommand;
use Storm\Ledger\Crypto\DbalCipherKeyStore;
use Storm\Ledger\Crypto\SubjectForgetter;
use Storm\Symfony\Tests\Boot\ConfigurableStormKernel;
use Storm\Symfony\Tests\Boot\KernelWorkspace;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

use function base64_encode;
use function getenv;
use function putenv;
use function sprintf;
use function str_repeat;

/**
 * The real-boot proof `RegisterPersonalDataPassTest` cannot give on its own: that unit hand-builds
 * a bare `ContainerBuilder` and calls the pass directly, so it never runs Symfony's own
 * `AutowirePass` across the whole Ledger package, the one pass that could still bind a crypto-
 * shredding consumer to `DbalCipherKeyStore` behind the compiler pass's back. This compiles the
 * ACTUAL bundle container both ways: no `#[Personal]` class must leave all three consumers unarmed
 * and the master-key env untouched; one must arm all three onto the same store instance.
 *
 * @see \Storm\Symfony\Tests\Compiler\RegisterPersonalDataPassTest the compiler-pass unit
 */
final class PersonalDataCipherWiringTest extends TestCase
{
    #[Override]
    public static function setUpBeforeClass(): void
    {
        new Filesystem()->remove(KernelWorkspace::dir('personal-cipher'));
    }

    #[Test]
    public function an_app_with_no_personal_class_never_resolves_the_master_key_env(): void
    {
        self::assertFalse(getenv('STORM_PRIVACY_MASTER_KEY'), 'the env must stay unset for this proof to mean anything');

        $kernel = $this->kernelWith('no-personal', ['event_paths' => [__DIR__.'/Boot/Domain']]);
        $kernel->boot();
        $container = $this->containerOf($kernel);

        // instantiating each service IS the proof: a reference to DbalCipherKeyStore left in place
        // would resolve %storm.privacy.master_key% right here and throw EnvNotFoundException
        // @phpstan-ignore symfonyContainer.privateService
        $this->assertNull($this->privacyKeysOf($container->get(StormInstallCommand::class)));
        // @phpstan-ignore symfonyContainer.privateService
        $this->assertNull($this->keysOf($container->get(SubjectForgetter::class)));
        // @phpstan-ignore symfonyContainer.privateService
        $this->assertNull($this->keysOf($container->get(PrivacyForgetCommand::class)));
    }

    #[Test]
    public function an_app_with_a_personal_class_arms_all_three_consumers_on_the_same_store(): void
    {
        // the one case that DOES need the master key, deliberately real: the marked class is what
        // turns the requirement on, and this proves the compiled wiring, not merely its absence
        putenv('STORM_PRIVACY_MASTER_KEY='.base64_encode(str_repeat('k', 32)));

        try {
            $kernel = $this->kernelWith('with-personal', ['event_paths' => [__DIR__.'/PersonalFixture']]);
            $kernel->boot();
            $container = $this->containerOf($kernel);

            // @phpstan-ignore symfonyContainer.privateService
            $store = $container->get(DbalCipherKeyStore::class);

            // @phpstan-ignore symfonyContainer.privateService
            $this->assertSame($store, $this->privacyKeysOf($container->get(StormInstallCommand::class)));
            // @phpstan-ignore symfonyContainer.privateService
            $this->assertSame($store, $this->keysOf($container->get(SubjectForgetter::class)));
            // @phpstan-ignore symfonyContainer.privateService
            $this->assertSame($store, $this->keysOf($container->get(PrivacyForgetCommand::class)));
        } finally {
            putenv('STORM_PRIVACY_MASTER_KEY');
        }
    }

    private function privacyKeysOf(StormInstallCommand $command): ?DbalCipherKeyStore
    {
        return new ReflectionProperty(StormInstallCommand::class, 'privacyKeys')->getValue($command);
    }

    private function keysOf(SubjectForgetter|PrivacyForgetCommand $service): ?DbalCipherKeyStore
    {
        return new ReflectionProperty($service, 'keys')->getValue($service);
    }

    /**
     * @param  array<string, mixed>  $storm
     */
    private function kernelWith(string $variant, array $storm): ConfigurableStormKernel
    {
        return new ConfigurableStormKernel($storm, sprintf('personal-cipher/%s', $variant));
    }

    private function containerOf(ConfigurableStormKernel $kernel): ContainerInterface
    {
        // the test container only exists under framework.test; every service, including the private
        // ones under proof here, is reachable through it
        // @phpstan-ignore symfonyContainer.serviceNotFound
        $container = $kernel->getContainer()->get('test.service_container');
        $this->assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }
}
