<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Exception;

final class MappingException extends SqlException
{
    public function __construct(?string $column = null, ?string $sqlId = null)
    {
        parent::__construct(ErrorCode::Mapping, sqlId: $sqlId, parameterPath: $column);
    }
}
