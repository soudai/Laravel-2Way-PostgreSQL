<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Contracts;

use Hft\TwoWaySql\Template\Ast\TemplateAst;

interface AstCache
{
    public function get(string $key): ?TemplateAst;

    public function put(string $key, TemplateAst $ast): void;

    public function clear(): void;
}
