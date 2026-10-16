<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Compiler\IR;

use Hft\TwoWaySql\Parameter\BoundValue;
use Hft\TwoWaySql\Template\Source\SourceSpan;

final readonly class BindSlot implements SqlPiece
{
    public function __construct(public BoundValue $value, public SourceSpan $span) {}
}
