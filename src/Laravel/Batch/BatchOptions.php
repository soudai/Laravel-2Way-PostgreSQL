<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Batch;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

final readonly class BatchOptions
{
    public function __construct(public bool $atomic = true, public int $maxItems = 1000, public ?int $expectedRowsPerItem = null)
    {
        if ($maxItems < 1 || ($expectedRowsPerItem !== null && $expectedRowsPerItem < 0)) {
            throw new SqlException(ErrorCode::ParameterType);
        }
    }
}
