<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Parameter;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

final class LikePattern
{
    public static function contains(#[\SensitiveParameter] string $value): string
    {
        return '%'.self::escape($value).'%';
    }

    public static function startsWith(#[\SensitiveParameter] string $value): string
    {
        return self::escape($value).'%';
    }

    public static function endsWith(#[\SensitiveParameter] string $value): string
    {
        return '%'.self::escape($value);
    }

    private static function escape(#[\SensitiveParameter] string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw new SqlException(ErrorCode::ParameterType);
        }

        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }
}
