<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Types;

use Hft\TwoWaySql\Contracts\ColumnConverter;
use Hft\TwoWaySql\Contracts\ParameterCodec;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\MappingException;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Mapping\ScalarConversion;
use Hft\TwoWaySql\Parameter\Codec\BuiltinEncoder;

/** Explicit element type; only one dimension with lower bound 1 is supported. */
final readonly class PgArrayCodec implements ColumnConverter, ParameterCodec
{
    public function __construct(private string $elementType, private bool $elementsNullable = false, private int $maxBytes = 8388608, private int $maxItems = 10000)
    {
        if (! in_array($elementType, ['int64', 'text', 'uuid', 'bool', 'decimal'], true) || $maxBytes < 2 || $maxItems < 1) {
            throw new SqlException(ErrorCode::ParameterType);
        }
    }

    public function encode(#[\SensitiveParameter] mixed $value): string
    {
        $wire = (new BuiltinEncoder($this->maxBytes, $this->maxItems))->encode($value, ['type' => 'pg_array<'.$this->elementType.'>', 'elements_nullable' => $this->elementsNullable]);

        return is_string($wire) ? $wire : throw new SqlException(ErrorCode::ParameterType);
    }

    /** @return list<int|string|bool|null> */
    public function convert(#[\SensitiveParameter] mixed $value): array
    {
        try {
            if (! is_string($value) || strlen($value) > $this->maxBytes || ! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                throw new MappingException;
            }
            $index = 0;
            $declared = null;
            if (str_starts_with($value, '[')) {
                if (! preg_match('/\A\[1:([1-9][0-9]{0,9})\]=/', $value, $match) || (int) $match[1] > $this->maxItems) {
                    throw new MappingException;
                }
                $index = strlen($match[0]);
                $declared = (int) $match[1];
            }
            if (($value[$index] ?? null) !== '{') {
                throw new MappingException;
            }
            $index++;
            $items = [];
            if (($value[$index] ?? null) !== '}') {
                while (true) {
                    if (count($items) >= $this->maxItems) {
                        throw new MappingException;
                    }
                    [$text, $quoted] = $this->element($value, $index);
                    if (! $quoted && strcasecmp($text, 'NULL') === 0) {
                        if (! $this->elementsNullable) {
                            throw new MappingException;
                        }
                        $items[] = null;
                    } else {
                        $items[] = $this->scalar($text);
                    }
                    $next = $value[$index] ?? null;
                    if ($next === '}') {
                        break;
                    }
                    if ($next !== ',') {
                        throw new MappingException;
                    }
                    $index++;
                }
            }
            if ($value[$index] !== '}' || $index + 1 !== strlen($value) || ($declared !== null && $declared !== count($items))) {
                throw new MappingException;
            }

            return $items;
        } catch (\Throwable) {
            throw new MappingException;
        }
    }

    /** @return array{string,bool} */
    private function element(#[\SensitiveParameter] string $wire, int &$index): array
    {
        $quoted = ($wire[$index] ?? null) === '"';
        $text = '';
        if ($quoted) {
            $index++;
            while ($index < strlen($wire)) {
                $char = $wire[$index++];
                if ($char === '"') {
                    return [$text, true];
                }
                if ($char === '\\') {
                    if ($index >= strlen($wire)) {
                        throw new MappingException;
                    }
                    $char = $wire[$index++];
                }
                $text .= $char;
            }
            throw new MappingException;
        }
        while ($index < strlen($wire) && ! in_array($wire[$index], [',', '}'], true)) {
            $char = $wire[$index++];
            if (str_contains('{"\\', $char) || ctype_space($char)) {
                throw new MappingException;
            }
            $text .= $char;
        }
        if ($text === '') {
            throw new MappingException;
        }

        return [$text, false];
    }

    private function scalar(#[\SensitiveParameter] string $text): int|string|bool
    {
        if ($this->elementType === 'int64') {
            return ScalarConversion::integer($text);
        }
        if ($this->elementType === 'bool') {
            return ScalarConversion::boolean($text);
        }
        $wire = (new BuiltinEncoder($this->maxBytes))->encode($text, ['type' => $this->elementType]);

        return is_string($wire) ? $wire : throw new MappingException;
    }
}
