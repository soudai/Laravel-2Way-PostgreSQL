<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Pagination;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

final readonly class PageRequest
{
    public int $offset;

    public function __construct(public int $page = 1, public int $perPage = 50, int $maxPerPage = 1000)
    {
        if ($page < 1 || $perPage < 1 || $maxPerPage < 1 || $perPage > $maxPerPage) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        if ($page - 1 > intdiv(PHP_INT_MAX, $perPage)) {
            throw new SqlException(ErrorCode::Limit);
        }
        $this->offset = ($page - 1) * $perPage;
    }
}
