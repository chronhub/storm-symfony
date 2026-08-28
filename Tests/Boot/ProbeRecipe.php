<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests\Boot;

use Storm\Chronicler\Query\QueryFilter;
use Storm\LiveQuery\Filter\InspectFilter;
use Storm\LiveQuery\Recipe\LiveQueryRecipe;
use Storm\LiveQuery\Recipe\RecipeParam;

/**
 * An "application" recipe with NO tag and NO attribute, the boot test's proof of the README
 * promise: implementing `LiveQueryRecipe` alone is enough, the bundle's global autoconfiguration
 * delivers the tag. The package's own `_instanceof` rule never reaches app definitions, so
 * without the global rule an app recipe would vanish silently.
 */
final class ProbeRecipe implements LiveQueryRecipe
{
    public function name(): string
    {
        return 'boot_probe';
    }

    public function description(): string
    {
        return 'Boot-test probe: discovered by interface alone.';
    }

    public function params(): array
    {
        return [new RecipeParam('id', required: false, description: 'probe id')];
    }

    public function filter(array $vars): QueryFilter
    {
        return new InspectFilter(limit: 1);
    }
}
