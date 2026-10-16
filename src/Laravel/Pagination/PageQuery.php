<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Pagination;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

final readonly class PageQuery
{
    public function __construct(public string $dataSqlId, public string $countSqlId)
    {
        if ($dataSqlId === '' || $countSqlId === '' || $dataSqlId === $countSqlId) {
            throw new SqlException(ErrorCode::ParameterType);
        }
    }
}
