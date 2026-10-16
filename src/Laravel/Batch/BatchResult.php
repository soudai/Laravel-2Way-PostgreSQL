<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Batch;

final readonly class BatchResult
{
    /** @param list<int> $affectedRows
     * @param  list<int>  $completedIndexes
     */
    public function __construct(public array $affectedRows, public array $completedIndexes, public bool $committed) {}
}
