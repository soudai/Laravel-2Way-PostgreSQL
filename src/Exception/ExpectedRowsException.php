<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Exception;

final class ExpectedRowsException extends SqlException
{
    public function __construct(public readonly int $expected, public readonly int $actual, string $sqlId)
    {
        parent::__construct(ErrorCode::AffectedRows, sqlId: $sqlId);
    }

    /** @return array<string, int|string|null> */
    public function __debugInfo(): array
    {
        return parent::__debugInfo() + ['expected' => $this->expected, 'actual' => $this->actual];
    }
}
