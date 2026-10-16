<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Types;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

/** Validated JSON tokens; never implicitly decodes numbers into PHP floats. */
final readonly class JsonDocument
{
    private function __construct(#[\SensitiveParameter] private string $json) {}

    public static function fromJson(#[\SensitiveParameter] string $json, int $maxBytes = 8388608, int $maxDepth = 64): self
    {
        if ($maxBytes < 1 || $maxDepth < 1 || $maxDepth > 512) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        if (strlen($json) > $maxBytes) {
            throw new SqlException(ErrorCode::Limit);
        }
        if (! json_validate($json, $maxDepth)) {
            throw new SqlException(ErrorCode::ParameterType);
        }

        return new self($json);
    }

    public function raw(): string
    {
        return $this->json;
    }

    public function kind(): string
    {
        return match (ltrim($this->json, " \t\r\n")[0]) {
            '{' => 'object', '[' => 'array', '"' => 'string', 'n' => 'null',
            't', 'f' => 'boolean', default => 'number',
        };
    }

    /** @return array{kind:string,bytes:int} */
    public function __debugInfo(): array
    {
        return ['kind' => $this->kind(), 'bytes' => strlen($this->json)];
    }
}
