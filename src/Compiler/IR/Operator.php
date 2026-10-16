<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Compiler\IR;

use Hft\TwoWaySql\Template\Source\SourceSpan;

final readonly class Operator implements SqlPiece
{
    public function __construct(public string $text, public SourceSpan $span) {}
}
