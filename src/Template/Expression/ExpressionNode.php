<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Expression;

final readonly class ExpressionNode
{
    public int $depth;

    /** @param list<ExpressionNode> $children */
    public function __construct(public string $operation, public int|string|bool|null $value = null, public array $children = [])
    {
        $this->depth = 1 + ($children === [] ? 0 : max(array_map(static fn (self $node): int => $node->depth, $children)));
    }
}
