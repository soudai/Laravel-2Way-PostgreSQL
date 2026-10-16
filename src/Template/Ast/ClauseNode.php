<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Ast;

use Hft\TwoWaySql\Template\Source\SourceSpan;

final readonly class ClauseNode implements Node
{
    /** @param list<Node> $nodes */
    public function __construct(public string $keyword, public array $nodes, public SourceSpan $span) {}
}
