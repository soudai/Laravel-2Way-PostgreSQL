<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Cache;

use Hft\TwoWaySql\Contracts\AstCache;
use Hft\TwoWaySql\Template\Ast\TemplateAst;

final readonly class TieredAstCache implements AstCache
{
    public function __construct(private AstCache $memory, private AstCache $file) {}

    public function get(string $key): ?TemplateAst
    {
        $ast = $this->memory->get($key);
        if ($ast !== null) {
            return $ast;
        }
        $ast = $this->file->get($key);
        if ($ast !== null) {
            $this->memory->put($key, $ast);
        }

        return $ast;
    }

    public function put(string $key, TemplateAst $ast): void
    {
        $this->file->put($key, $ast);
        $this->memory->put($key, $ast);
    }

    public function clear(): void
    {
        $this->memory->clear();
        $this->file->clear();
    }
}
