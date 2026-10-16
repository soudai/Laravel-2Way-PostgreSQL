<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Mapping;

use DateTimeImmutable;
use DateTimeZone;
use Hft\TwoWaySql\Exception\MappingException;

final class ScalarConversion
{
    public static function integer(#[\SensitiveParameter] mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (! is_string($value) || preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $value) !== 1) {
            throw new MappingException;
        }
        $digits = ltrim($value, '-');
        $bound = str_starts_with($value, '-') ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($bound) || (strlen($digits) === strlen($bound) && strcmp($digits, $bound) > 0)) {
            throw new MappingException;
        }

        return (int) $value;
    }

    public static function boolean(#[\SensitiveParameter] mixed $value): bool
    {
        return match ($value) {
            true, 1, '1', 't', 'true' => true,
            false, 0, '0', 'f', 'false' => false,
            default => throw new MappingException,
        };
    }

    public static function datetime(#[\SensitiveParameter] mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-](?:[01][0-9]|2[0-3])(?::?[0-5][0-9])?)?\z/D', $value) !== 1) {
            throw new MappingException;
        }
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new MappingException;
        }

        return $date;
    }
}
