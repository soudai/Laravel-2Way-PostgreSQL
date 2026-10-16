<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Parameter\Range;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

final class RangeText
{
    public static function encode(#[\SensitiveParameter] ?string $lower, #[\SensitiveParameter] ?string $upper, bool $lowerInclusive, bool $upperInclusive, bool $empty): string
    {
        if ($empty) {
            return 'empty';
        }
        $quote = static fn (?string $value): string => $value === null ? '' : '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';

        return ($lower !== null && $lowerInclusive ? '[' : '(').$quote($lower).','.$quote($upper).($upper !== null && $upperInclusive ? ']' : ')');
    }

    /** @return array{lower:?string,upper:?string,lower_inclusive:bool,upper_inclusive:bool,empty:bool} */
    public static function decode(#[\SensitiveParameter] string $wire): array
    {
        if (strlen($wire) > 16384 || ! mb_check_encoding($wire, 'UTF-8') || str_contains($wire, "\0")) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        $wire = trim($wire);
        if ($wire === 'empty') {
            return ['lower' => null, 'upper' => null, 'lower_inclusive' => false, 'upper_inclusive' => false, 'empty' => true];
        }
        if (strlen($wire) < 3 || ! in_array($wire[0], ['(', '['], true) || ! in_array(substr($wire, -1), [')', ']'], true)) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        $parts = [];
        $part = '';
        $quoted = false;
        $closed = false;
        $wasQuoted = false;
        for ($i = 1, $end = strlen($wire) - 1; $i < $end; $i++) {
            $char = $wire[$i];
            if ($char === '\\' && ! $closed) {
                if (++$i >= $end) {
                    throw new SqlException(ErrorCode::ParameterType);
                }
                $part .= $wire[$i];
            } elseif ($char === '"') {
                if ($quoted && ($wire[$i + 1] ?? '') === '"') {
                    $part .= '"';
                    $i++;
                } elseif ($quoted) {
                    $quoted = false;
                    $closed = true;
                } elseif ($part === '' && ! $wasQuoted) {
                    $quoted = true;
                    $wasQuoted = true;
                } else {
                    throw new SqlException(ErrorCode::ParameterType);
                }
            } elseif ($char === ',' && ! $quoted) {
                $parts[] = $part === '' && ! $wasQuoted ? null : $part;
                $part = '';
                $closed = $wasQuoted = false;
                if (count($parts) > 1) {
                    throw new SqlException(ErrorCode::ParameterType);
                }
            } elseif ($closed || (! $quoted && str_contains('[]()', $char))) {
                throw new SqlException(ErrorCode::ParameterType);
            } else {
                $part .= $char;
            }
        }
        if ($quoted || count($parts) !== 1) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        $parts[] = $part === '' && ! $wasQuoted ? null : $part;

        return ['lower' => $parts[0], 'upper' => $parts[1], 'lower_inclusive' => $parts[0] !== null && $wire[0] === '[', 'upper_inclusive' => $parts[1] !== null && str_ends_with($wire, ']'), 'empty' => false];
    }
}
