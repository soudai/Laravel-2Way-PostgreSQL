<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Contracts;

use Hft\TwoWaySql\Compiler\CompiledQuery;

interface Compiler
{
    /** @param array<string, mixed> $parameters */
    public function compile(string $id, #[\SensitiveParameter] array $parameters): CompiledQuery;
}
