<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Exception;

use Hft\TwoWaySql\Template\Source\SourceSpan;
use RuntimeException;

class SqlException extends RuntimeException
{
    public readonly string $errorCode;

    public function __construct(
        ErrorCode $error,
        public readonly ?SourceSpan $span = null,
        public readonly ?string $sqlId = null,
        public readonly ?string $parameterPath = null,
    ) {
        $this->errorCode = $error->value;
        parent::__construct($error->value.($span ? ' at '.$span->line.':'.$span->column : ''));
    }

    /** @return array<string, int|string|null> */
    public function __debugInfo(): array
    {
        return ['errorCode' => $this->errorCode, 'sqlId' => $this->sqlId, 'source' => $this->span?->sourceId, 'line' => $this->span?->line, 'column' => $this->span?->column, 'parameterPath' => $this->parameterPath];
    }

    /** @return array<string, int|string|null> */
    public function __serialize(): array
    {
        return $this->__debugInfo();
    }
}
