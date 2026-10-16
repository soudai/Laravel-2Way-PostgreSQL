<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Ast;

use Hft\TwoWaySql\Template\Source\SourceSpan;

final readonly class BranchNode implements Node
{
    /** @param list<BranchArm> $arms */
    public function __construct(public array $arms, public SourceSpan $span) {}
}
