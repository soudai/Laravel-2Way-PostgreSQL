<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Parser;

use Hft\TwoWaySql\Template\Ast\TemplateAst;
use Hft\TwoWaySql\Template\Compatibility\DomaSubsetParser;
use Hft\TwoWaySql\Template\Lexer\PgLexer;
use Hft\TwoWaySql\Template\QueryDefinition;

final readonly class TemplateParser
{
    public function __construct(private PgLexer $lexer = new PgLexer, private int $maxDepth = 32, private int $maxNodes = 100000) {}

    public function parse(QueryDefinition $definition): TemplateAst
    {
        if ($definition->grammar === 'doma-subset-v1') {
            return (new DomaSubsetParser($this->lexer, $this->maxDepth, $this->maxNodes))->parse($definition);
        }

        return (new ParseSession($definition, (new AlternativeMask($this->maxDepth))->tokens($definition, $this->lexer), $this->maxDepth, $this->maxNodes))->parse();
    }

    public function optionHash(): string
    {
        return hash('sha256', implode(':', [$this->lexer->optionHash(), $this->maxDepth, $this->maxNodes]));
    }
}
