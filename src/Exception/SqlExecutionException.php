<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Exception;

use PDOException;
use Throwable;

final class SqlExecutionException extends SqlException
{
    public function __construct(string $sqlId, public readonly ?string $sqlState)
    {
        parent::__construct(ErrorCode::Execution, sqlId: $sqlId);
    }

    public static function translate(#[\SensitiveParameter] Throwable $cause, string $sqlId): self
    {
        $state = $cause instanceof PDOException ? ($cause->errorInfo[0] ?? $cause->getCode()) : null;

        return new self($sqlId, is_string($state) && preg_match('/\A[A-Z0-9]{5}\z/D', $state) ? $state : null);
    }

    public function __debugInfo(): array
    {
        return parent::__debugInfo() + ['sqlState' => $this->sqlState];
    }
}
