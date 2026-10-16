<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Parameter\Codec;

use DateTimeImmutable;
use DateTimeInterface;
use Hft\TwoWaySql\Contracts\ValueEncoder;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Parameter\JsonValue;
use Hft\TwoWaySql\Parameter\Range\DateRange;
use Hft\TwoWaySql\Parameter\Range\TstzRange;
use JsonException;
use stdClass;

/** @phpstan-import-type Spec from \Hft\TwoWaySql\Parameter\ParameterSchema */
final readonly class BuiltinEncoder implements ValueEncoder
{
    public function __construct(private int $maxEncodedBytes = 8388608, private int $maxArrayItems = 10000) {}

    public function encode(#[\SensitiveParameter] mixed $value, array $spec): int|string|null
    {
        if ($value === null) {
            if (! ($spec['nullable'] ?? false)) {
                throw new SqlException(ErrorCode::ParameterType);
            }

            return null;
        }
        $type = $spec['type'];
        if (str_starts_with($type, 'pg_array<')) {
            return $this->array($value, $spec);
        }
        $wire = match ($type) {
            'int64' => $this->integer($value, $spec),
            'text' => $this->text($value, $spec['max_bytes'] ?? $this->maxEncodedBytes),
            'bool' => is_bool($value) ? ($value ? 'true' : 'false') : throw new SqlException(ErrorCode::ParameterType),
            'uuid' => strtolower($this->matching($value, '/\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\z/D')),
            'decimal' => $this->matching($value, '/\A-?[0-9]+(?:\.[0-9]+)?\z/D'),
            'date' => $this->date($value),
            'timestamp' => $this->timestamp($value),
            'timestamptz' => $this->timestampWithZone($value),
            'jsonb' => $this->json($value),
            'daterange' => $value instanceof DateRange ? $value->toPostgres() : throw new SqlException(ErrorCode::ParameterType),
            'tstzrange' => $value instanceof TstzRange ? $value->toPostgres() : throw new SqlException(ErrorCode::ParameterType),
            default => throw new SqlException(ErrorCode::ParameterType),
        };
        if (strlen((string) $wire) > $this->maxEncodedBytes) {
            throw new SqlException(ErrorCode::Limit);
        }

        return $wire;
    }

    /** @param Spec $spec */
    private function integer(#[\SensitiveParameter] mixed $value, array $spec): int
    {
        if (! is_int($value) || (isset($spec['min']) && $value < $spec['min']) || (isset($spec['max']) && $value > $spec['max'])) {
            throw new SqlException(ErrorCode::ParameterType);
        }

        return $value;
    }

    private function text(#[\SensitiveParameter] mixed $value, int $maximum): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        if (strlen($value) > $maximum || strlen($value) > $this->maxEncodedBytes) {
            throw new SqlException(ErrorCode::Limit);
        }

        return $value;
    }

    private function matching(#[\SensitiveParameter] mixed $value, string $pattern): string
    {
        $text = $this->text($value, $this->maxEncodedBytes);
        if (! preg_match($pattern, $text)) {
            throw new SqlException(ErrorCode::ParameterType);
        }

        return $text;
    }

    private function date(#[\SensitiveParameter] mixed $value): string
    {
        $text = $this->matching($value, '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D');
        if (! checkdate((int) substr($text, 5, 2), (int) substr($text, 8, 2), (int) substr($text, 0, 4))) {
            throw new SqlException(ErrorCode::ParameterType);
        }

        return $text;
    }

    private function timestamp(#[\SensitiveParameter] mixed $value): string
    {
        $text = $this->matching($value, '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}\z/D');
        $this->date(substr($text, 0, 10));
        if ((int) substr($text, 11, 2) > 23 || (int) substr($text, 14, 2) > 59 || (int) substr($text, 17, 2) > 59) {
            throw new SqlException(ErrorCode::ParameterType);
        }

        return $text;
    }

    private function timestampWithZone(#[\SensitiveParameter] mixed $value): string
    {
        if (! $value instanceof DateTimeInterface) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        $date = DateTimeImmutable::createFromInterface($value);
        $this->date($date->format('Y-m-d'));

        return $date->format('Y-m-d\TH:i:s.uP');
    }

    /** @param Spec $spec */
    private function array(#[\SensitiveParameter] mixed $value, array $spec): string
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        if (count($value) > $this->maxArrayItems) {
            throw new SqlException(ErrorCode::Limit);
        }
        $type = substr($spec['type'], 9, -1);
        $output = '{';
        foreach ($value as $index => $element) {
            $wire = $this->encode($element, ['type' => $type, 'nullable' => $spec['elements_nullable'] ?? false]);
            $part = $wire === null ? 'NULL' : (is_int($wire) ? (string) $wire : '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $wire).'"');
            if (strlen($output) + strlen($part) + ($index > 0 ? 1 : 0) + 1 > $this->maxEncodedBytes) {
                throw new SqlException(ErrorCode::Limit);
            }
            $output .= ($index > 0 ? ',' : '').$part;
        }

        if (strlen($output) + 1 > $this->maxEncodedBytes) {
            throw new SqlException(ErrorCode::Limit);
        }

        return $output.'}';
    }

    private function json(#[\SensitiveParameter] mixed $value): string
    {
        if ($value instanceof JsonValue) {
            return 'null';
        }
        $bytes = 0;
        $this->measureJson($value, 1, $bytes);

        return $this->jsonEncode($value);
    }

    private function measureJson(#[\SensitiveParameter] mixed $value, int $depth, int &$bytes): void
    {
        if ($depth > 512) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        if (is_object($value) && get_class($value) !== stdClass::class) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        if (is_array($value) || $value instanceof stdClass) {
            $items = is_array($value) ? $value : get_object_vars($value);
            $object = $value instanceof stdClass || ! array_is_list($items);
            $this->jsonBudget(2 + max(0, count($items) - 1), $bytes);
            foreach ($items as $key => $element) {
                if ($object) {
                    $this->text((string) $key, $this->maxEncodedBytes);
                    $this->jsonBudget(strlen($this->jsonEncode((string) $key)) + 1, $bytes);
                }
                $this->measureJson($element, $depth + 1, $bytes);
            }
        } else {
            if (is_resource($value)) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            if (is_string($value)) {
                $this->text($value, $this->maxEncodedBytes);
            }
            $this->jsonBudget(strlen($this->jsonEncode($value)), $bytes);
        }
    }

    private function jsonBudget(int $additional, int &$bytes): void
    {
        if ($bytes + $additional > $this->maxEncodedBytes) {
            throw new SqlException(ErrorCode::Limit);
        }
        $bytes += $additional;
    }

    private function jsonEncode(#[\SensitiveParameter] mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException) {
            throw new SqlException(ErrorCode::ParameterType);
        }
    }
}
