<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Types;

use Hft\TwoWaySql\Contracts\ColumnConverter;
use Hft\TwoWaySql\Contracts\ParameterCodec;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\MappingException;
use Hft\TwoWaySql\Exception\SqlException;

final readonly class JsonDocumentCodec implements ColumnConverter, ParameterCodec
{
    public function __construct(private int $maxBytes = 8388608, private int $maxDepth = 64)
    {
        if ($maxBytes < 1 || $maxDepth < 1 || $maxDepth > 512) {
            throw new SqlException(ErrorCode::ParameterType);
        }
    }

    public function encode(#[\SensitiveParameter] mixed $value): string
    {
        if (! $value instanceof JsonDocument) {
            throw new SqlException(ErrorCode::ParameterType);
        }

        return JsonDocument::fromJson($value->raw(), $this->maxBytes, $this->maxDepth)->raw();
    }

    public function convert(#[\SensitiveParameter] mixed $value): JsonDocument
    {
        try {
            return is_string($value) ? JsonDocument::fromJson($value, $this->maxBytes, $this->maxDepth) : throw new MappingException;
        } catch (\Throwable) {
            throw new MappingException;
        }
    }
}
