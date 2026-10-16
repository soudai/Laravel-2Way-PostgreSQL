<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Parameter;

use Hft\TwoWaySql\Contracts\SqlParameters;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

final class ParameterInput
{
    /** @param array<string, mixed>|SqlParameters $parameters
     * @return array<string, mixed>
     */
    public static function normalize(#[\SensitiveParameter] array|SqlParameters $parameters): array
    {
        try {
            return self::validateKeys($parameters instanceof SqlParameters ? $parameters->toSqlParameters() : $parameters);
        } catch (\Throwable) {
            throw new SqlException(ErrorCode::ParameterType);
        }
    }

    /** @param array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    private static function validateKeys(#[\SensitiveParameter] array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
