<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Expression;

use Hft\TwoWaySql\Parameter\ParameterSchema;
use Hft\TwoWaySql\Template\Source\SourceSpan;

final readonly class ExpressionParser
{
    public function __construct(private int $maxDepth = 32, private bool $functions = true) {}

    public function parse(string $source, ParameterSchema $schema, SourceSpan $span): Expression
    {
        return (new ExpressionSession($source, $schema, $span, $this->maxDepth, $this->functions))->parse();
    }
}
