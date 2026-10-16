<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Ast;

use Hft\TwoWaySql\Template\Expression\Expression;
use Hft\TwoWaySql\Template\Source\SourceSpan;

final readonly class BranchArm
{
    /** @param list<Node> $nodes */
    public function __construct(public ?Expression $condition, public array $nodes, public SourceSpan $span) {}
}
