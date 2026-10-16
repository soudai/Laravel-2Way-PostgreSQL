<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Ast;

use Hft\TwoWaySql\Template\Source\SourceSpan;

final readonly class LoopNode implements Node
{
    /** @param list<Node> $nodes */
    public function __construct(public string $variable, public string $collection, public string $separator, public string $empty, public array $nodes, public SourceSpan $span) {}
}
