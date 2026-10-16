<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Compiler\Sort;

use Hft\TwoWaySql\Compiler\IR\SqlPiece;
use Hft\TwoWaySql\Compiler\IR\Text;
use Hft\TwoWaySql\Template\Ast\SortNode;
use Hft\TwoWaySql\Template\SortDefinitions;

final readonly class SortRenderer
{
    /** @return list<SqlPiece> */
    public function render(SortNode $node, SortDefinitions $sorts, #[\SensitiveParameter] mixed $key): array
    {
        $pieces = [];
        foreach ($sorts->items($node->path, $key) as $index => $item) {
            if ($index > 0) {
                $pieces[] = new Text(', ', $node->span);
            }
            $identifier = implode('.', array_map(static fn (string $part): string => '"'.str_replace('"', '""', $part).'"', $item['column']));
            $pieces[] = new Text($identifier.' '.$item['direction'].(isset($item['nulls']) ? ' NULLS '.$item['nulls'] : ''), $node->span);
        }

        return $pieces;
    }
}
