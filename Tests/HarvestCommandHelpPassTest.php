<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Symfony\Compiler\HarvestCommandHelpPass;
use Storm\Symfony\Tests\Fixture\HarvestableFixtureCommand;
use Storm\Symfony\Tests\Fixture\HelpfulInvokableFixtureCommand;
use Storm\Symfony\Tests\Fixture\HelplessInvokableFixtureCommand;
use Storm\Symfony\Tests\Fixture\SilentFixtureCommand;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * The pass moves each command's examples out of its docblock and into its `--help`, at build time.
 *
 * The suite already checks that every command CARRIES an examples block, and the two built commands
 * it then reads prove the wiring end to end. What was never exercised is the pass itself, on its own
 * container: the refusal that fails a build had no test at all, so replacing its throw with a return
 * left every gate green while the drift it exists to end reopened in silence.
 *
 * The fixtures are the four shapes a tagged service can take, and the pass answers each differently:
 * harvest it, leave it alone, accept the attribute that stands in for the harvest, or refuse.
 */
final class HarvestCommandHelpPassTest extends TestCase
{
    #[Test]
    public function it_moves_the_examples_block_onto_the_definition_and_leaves_the_fence_behind(): void
    {
        $container = $this->containerWith(HarvestableFixtureCommand::class);

        new HarvestCommandHelpPass()->process($container);

        $help = $this->harvestedHelp($container, HarvestableFixtureCommand::class);

        self::assertNotNull($help);
        self::assertStringStartsWith('Examples:', $help);
        self::assertStringContainsString('bin/console probe:harvestable --since 2026-01-01', $help);
        self::assertStringNotContainsString('```', $help, 'the fence is markdown for a reader of the source');
        self::assertStringNotContainsString(' * ', $help, 'and so are the comment markers');
    }

    #[Test]
    public function a_percent_sign_in_an_example_is_doubled_so_the_container_reads_it_as_text(): void
    {
        // a lone % is a parameter reference to the container, and the failure would be a build that
        // dies on the text of an example rather than on anything wrong with the command
        $container = $this->containerWith(HarvestableFixtureCommand::class);

        new HarvestCommandHelpPass()->process($container);

        self::assertStringContainsString('--sample 10%%', (string) $this->harvestedHelp($container, HarvestableFixtureCommand::class));
    }

    #[Test]
    public function a_command_with_nothing_to_harvest_keeps_its_definition_untouched(): void
    {
        $container = $this->containerWith(SilentFixtureCommand::class);

        new HarvestCommandHelpPass()->process($container);

        self::assertSame([], $container->findDefinition(SilentFixtureCommand::class)->getMethodCalls());
    }

    #[Test]
    #[Group('adversarial')]
    public function an_invokable_command_whose_examples_can_reach_nobody_fails_the_build(): void
    {
        // the refusal is the whole point: skipping this shape in silence is exactly the drift the
        // pass exists to end, a command whose invocations live only where an operator cannot look
        $container = $this->containerWith(HelplessInvokableFixtureCommand::class);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains(HelplessInvokableFixtureCommand::class);

        new HarvestCommandHelpPass()->process($container);
    }

    #[Test]
    public function an_invokable_command_carrying_help_on_its_attribute_is_accepted(): void
    {
        // and the refusal names a remedy that works: the attribute is the shape's own way of
        // reaching a terminal, so declaring it must pass, and no setHelp can be added either way
        $container = $this->containerWith(HelpfulInvokableFixtureCommand::class);

        new HarvestCommandHelpPass()->process($container);

        self::assertSame([], $container->findDefinition(HelpfulInvokableFixtureCommand::class)->getMethodCalls());
    }

    #[Test]
    public function a_foreign_command_registered_first_does_not_end_the_harvest(): void
    {
        // an application registers its own commands beside storm's, and this pass skips them: a walk
        // that ended on the first foreign command would harvest nothing in any real container, and
        // the drift it exists to end would come back silently
        $container = new ContainerBuilder;
        $container->setDefinition('app.console.foreign', new Definition(stdClass::class))->addTag('console.command');
        $container->setDefinition(HarvestableFixtureCommand::class, new Definition(HarvestableFixtureCommand::class))->addTag('console.command');

        new HarvestCommandHelpPass()->process($container);

        self::assertNotNull($this->harvestedHelp($container, HarvestableFixtureCommand::class));
    }

    #[Test]
    public function an_invokable_command_carrying_its_own_help_does_not_end_the_harvest(): void
    {
        // the third skip of the same walk, and the one the demasked list did not name: an invokable
        // command answers the refusal with its attribute and is then SKIPPED, so a walk ending there
        // would harvest nothing behind it
        $container = new ContainerBuilder;
        $container->setDefinition(HelpfulInvokableFixtureCommand::class, new Definition(HelpfulInvokableFixtureCommand::class))->addTag('console.command');
        $container->setDefinition(HarvestableFixtureCommand::class, new Definition(HarvestableFixtureCommand::class))->addTag('console.command');

        new HarvestCommandHelpPass()->process($container);

        self::assertNotNull($this->harvestedHelp($container, HarvestableFixtureCommand::class));
    }

    #[Test]
    public function a_storm_command_with_no_examples_does_not_end_the_harvest(): void
    {
        // a storm command carrying no Examples: block is skipped, not a stopping point: the harvest
        // must reach the commands declared behind it
        $container = $this->containerWith(SilentFixtureCommand::class, HarvestableFixtureCommand::class);

        new HarvestCommandHelpPass()->process($container);

        self::assertNotNull($this->harvestedHelp($container, HarvestableFixtureCommand::class));
    }

    /**
     * @param  class-string  ...$commands
     */
    private function containerWith(string ...$commands): ContainerBuilder
    {
        $container = new ContainerBuilder;

        foreach ($commands as $command) {
            $container->setDefinition($command, new Definition($command))->addTag('console.command');
        }

        return $container;
    }

    /**
     * @param  class-string  $command
     */
    private function harvestedHelp(ContainerBuilder $container, string $command): ?string
    {
        foreach ($container->findDefinition($command)->getMethodCalls() as [$method, $arguments]) {
            if ($method === 'setHelp') {
                return (string) $arguments[0];
            }
        }

        return null;
    }
}
