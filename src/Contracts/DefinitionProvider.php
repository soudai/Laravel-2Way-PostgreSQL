<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Contracts;

use Hft\TwoWaySql\Template\QueryDefinition;

/** Optional compiler capability for APIs that need manifest metadata. */
interface DefinitionProvider
{
    public function getDefinition(string $id): QueryDefinition;
}
