<?php

declare(strict_types=1);

namespace Storm\Symfony\Compiler;

use Override;
use ReflectionClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Moves each console command's `Examples:` block from its docblock into its `--help`, at build time.
 *
 * The charter already asks every command for two to four representative invocations, and every one of
 * them has them; they were simply unreachable from a terminal. An operator holding an incident has
 * `--help` and an error message and nothing else, so a rule stated only in a docblock is a rule stated
 * to the wrong reader: the three mutually exclusive modes of an install, the exit contract a scheduler
 * needs, the option pairs that cannot be combined.
 *
 * Harvested rather than hand-written, so the two cannot drift and a command added tomorrow is covered
 * without anyone remembering. At build time rather than at run time, so the help is baked into the
 * compiled container and `--help` costs nothing.
 *
 * A command whose class does not extend `Command`, the invokable shape, cannot receive a
 * `setHelp()` call and has to carry its help in its own attribute; the build fails naming it rather
 * than skipping it in silence, which would be exactly the drift this pass exists to end.
 */
final class HarvestCommandHelpPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        foreach (array_keys($container->findTaggedServiceIds('console.command')) as $id) {
            $definition = $container->findDefinition($id);
            $class = $definition->getClass();

            if ($class === null || ! str_starts_with($class, 'Storm\\') || ! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $help = self::examplesOf($reflection);

            if ($help === null) {
                continue;
            }

            if (! $reflection->isSubclassOf(Command::class)) {
                self::assertAttributeCarriesHelp($reflection, $class);

                continue;
            }

            // doubled because the container reads a lone % as a parameter reference; no example uses
            // one today, and a future one must not fail the build on its own text
            $definition->addMethodCall('setHelp', [str_replace('%', '%%', $help)]);
        }
    }

    /**
     * The `Examples:` block of a class docblock, comment markers stripped and fences removed.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    private static function examplesOf(ReflectionClass $reflection): ?string
    {
        $doc = $reflection->getDocComment();

        if ($doc === false || ! str_contains($doc, 'Examples:')) {
            return null;
        }

        $lines = [];

        foreach (explode("\n", substr($doc, (int) strpos($doc, 'Examples:'))) as $line) {
            $line = preg_replace('/^\s*\*\/?\s?/', '', $line) ?? $line;

            if (str_starts_with(trim($line), '```')) {
                continue; // the fence is markdown for a reader of the source, noise in a terminal
            }

            $lines[] = rtrim($line);
        }

        $help = trim(implode("\n", $lines));

        return $help === '' ? null : $help;
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     */
    private static function assertAttributeCarriesHelp(ReflectionClass $reflection, string $class): void
    {
        foreach ($reflection->getAttributes(AsCommand::class) as $attribute) {
            if ($attribute->newInstance()->help !== null) {
                return;
            }
        }

        throw new InvalidConfigurationException(sprintf(
            '%s declares #[AsCommand] without extending Command, so its help cannot be set on the service: carry it in the attribute\'s `help:` argument, since its Examples block cannot reach an operator otherwise.',
            $class,
        ));
    }
}
