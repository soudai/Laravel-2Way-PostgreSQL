<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Contracts;

use Hft\TwoWaySql\Template\QueryDefinition;

interface TemplateRepository
{
    public function get(string $id): QueryDefinition;

    /** @return list<string> */
    public function ids(): array;
}
