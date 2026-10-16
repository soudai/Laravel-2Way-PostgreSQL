<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Ast;

use Hft\TwoWaySql\Template\Source\SourceSpan;

final readonly class SortNode implements Node
{
    public function __construct(public string $path, public SourceSpan $span) {}
}
