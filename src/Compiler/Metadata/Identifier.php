<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Compiler\Metadata;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

final class Identifier
{
    public static function validate(mixed $part): string
    {
        if (! is_string($part) || $part === '' || strlen($part) > 63 || ! mb_check_encoding($part, 'UTF-8') || str_contains($part, "\0")) {
            throw new SqlException(ErrorCode::Generation);
        }

        return $part;
    }

    public static function quote(string $part): string
    {
        return '"'.str_replace('"', '""', self::validate($part)).'"';
    }
}
