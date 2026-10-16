<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template;

use Hft\TwoWaySql\Contracts\AstCache;
use Hft\TwoWaySql\Template\Ast\TemplateAst;
use Hft\TwoWaySql\Template\Parser\TemplateParser;

final readonly class TemplateLoader
{
    public function __construct(private TemplateParser $parser, private ?AstCache $cache = null, private string $optionHash = '') {}

    public function load(QueryDefinition $definition): TemplateAst
    {
        $key = hash('sha256', implode(':', ['engine-6', $definition->grammar, $definition->sourceHash, $definition->definitionHash, $this->parser->optionHash(), $this->optionHash]));
        $ast = $this->cache?->get($key);
        if ($ast !== null && $ast->sourceHash === $definition->sourceHash && $ast->grammarVersion === $definition->grammar) {
            return $ast;
        }
        $ast = $this->parser->parse($definition);
        $this->cache?->put($key, $ast);

        return $ast;
    }
}
