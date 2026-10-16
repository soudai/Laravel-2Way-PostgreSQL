<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Parameter\Range;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

/** @internal Fixed-depth multirange framing; endpoint validation belongs to RangeText. */
final class MultirangeText
{
    /** @template T of DateRange|TstzRange
     * @param  array<array-key,mixed>  $ranges
     * @param  class-string<T>  $class
     * @return list<T>
     */
    public static function checkedRanges(#[\SensitiveParameter] array $ranges, string $class): array
    {
        if (! array_is_list($ranges)) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        if (count($ranges) > 10000) {
            throw new SqlException(ErrorCode::Limit);
        }
        $checked = [];
        foreach ($ranges as $range) {
            if (! $range instanceof $class) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $checked[] = $range;
        }

        return $checked;
    }

    /** @return list<string> */
    public static function decode(#[\SensitiveParameter] string $wire, int $maxBytes = 8388608, int $maxRanges = 10000): array
    {
        self::limits($maxBytes, $maxRanges);
        if (strlen($wire) > $maxBytes) {
            throw new SqlException(ErrorCode::Limit);
        }
        if (! mb_check_encoding($wire, 'UTF-8') || str_contains($wire, "\0") || ! str_starts_with($wire, '{') || ! str_ends_with($wire, '}')) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        $end = strlen($wire) - 1;
        $parts = [];
        for ($index = 1; $index < $end;) {
            if (count($parts) >= $maxRanges) {
                throw new SqlException(ErrorCode::Limit);
            }
            $start = $index;
            if (substr($wire, $index, 5) === 'empty') {
                $index += 5;
            } else {
                if (! in_array($wire[$index++], ['[', '('], true)) {
                    throw new SqlException(ErrorCode::ParameterType);
                }
                $quoted = false;
                $closed = false;
                while ($index < $end) {
                    $char = $wire[$index++];
                    if ($char === '\\') {
                        if ($index >= $end) {
                            throw new SqlException(ErrorCode::ParameterType);
                        }
                        $index++;
                    } elseif ($char === '"') {
                        $quoted = ! $quoted;
                    } elseif (! $quoted && in_array($char, [']', ')'], true)) {
                        $closed = true;
                        break;
                    } elseif (! $quoted && str_contains('{}[(', $char)) {
                        throw new SqlException(ErrorCode::ParameterType);
                    }
                }
                if (! $closed || $quoted) {
                    throw new SqlException(ErrorCode::ParameterType);
                }
            }
            $parts[] = substr($wire, $start, $index - $start);
            if ($index === $end) {
                break;
            }
            if ($index >= $end || $wire[$index++] !== ',' || $index === $end) {
                throw new SqlException(ErrorCode::ParameterType);
            }
        }

        return $parts;
    }

    /** @param list<DateRange|TstzRange> $ranges */
    public static function encode(#[\SensitiveParameter] array $ranges, int $maxBytes = 8388608, int $maxRanges = 10000): string
    {
        self::limits($maxBytes, $maxRanges);
        if (count($ranges) > $maxRanges) {
            throw new SqlException(ErrorCode::Limit);
        }
        $wire = '{';
        foreach ($ranges as $index => $range) {
            $part = ($index === 0 ? '' : ',').$range->toPostgres();
            if (strlen($wire) + strlen($part) + 1 > $maxBytes) {
                throw new SqlException(ErrorCode::Limit);
            }
            $wire .= $part;
        }

        return $wire.'}';
    }

    public static function limits(int $maxBytes, int $maxRanges): void
    {
        if ($maxBytes < 2 || $maxRanges < 1) {
            throw new SqlException(ErrorCode::ParameterType);
        }
    }
}
