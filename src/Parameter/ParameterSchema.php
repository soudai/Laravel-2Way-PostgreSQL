<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Parameter;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Parameter\Patch\PatchSchema;

/** @phpstan-type Spec array{type:string, nullable?:bool, elements_nullable?:bool, min?:int, max?:int, max_bytes?:int, fields?:array<string, mixed>, column?:string, protected?:bool} */
final readonly class ParameterSchema
{
    /** @var array<string, Spec> */
    public array $definitions;

    /** @param array<array-key, mixed> $definitions */
    public function __construct(array $definitions, int $depth = 0)
    {
        if ($depth > 32) {
            throw new SqlException(ErrorCode::Limit);
        }
        $validated = [];
        foreach ($definitions as $path => $spec) {
            if (! is_string($path) || ! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*\z/D', $path)
                || ! is_array($spec) || ! isset($spec['type']) || ! is_string($spec['type'])) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $type = $spec['type'];
            if (! in_array($type, ['int64', 'text', 'bool', 'uuid', 'decimal', 'jsonb', 'date', 'timestamp', 'timestamptz', 'daterange', 'tstzrange', 'list<record>', 'patch<record>'], true)
                && ! preg_match('/\Alist<(?:int64|text|uuid)>\z/D', $type)
                && ! preg_match('/\Apg_array<(?:int64|text|uuid|bool|decimal)>\z/D', $type)
                && ! preg_match('/\Acodec:[a-z][a-z0-9_.-]{0,63}\z/D', $type)) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $allowed = ['type', 'nullable'];
            if (in_array($type, ['list<record>', 'patch<record>'], true)) {
                $allowed[] = 'fields';
            }
            if ($type === 'int64') {
                array_push($allowed, 'min', 'max');
            }
            if ($type === 'text') {
                $allowed[] = 'max_bytes';
            }
            if (str_starts_with($type, 'pg_array<')) {
                $allowed[] = 'elements_nullable';
            }
            if (array_diff(array_keys($spec), $allowed) !== []) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            /** @var Spec $item */
            $item = ['type' => $type];
            if (in_array($type, ['list<record>', 'patch<record>'], true)) {
                $fields = $spec['fields'] ?? null;
                if (! is_array($fields) || $fields === []) {
                    throw new SqlException(ErrorCode::ParameterType);
                }
                $item['fields'] = $type === 'patch<record>' ? (new PatchSchema($fields, $depth + 1))->fields : (new self($fields, $depth + 1))->definitions;
                if ($type === 'patch<record>' && ($spec['nullable'] ?? false)) {
                    throw new SqlException(ErrorCode::ParameterType);
                }
            }
            foreach (['nullable', 'elements_nullable'] as $key) {
                if (array_key_exists($key, $spec)) {
                    if (! is_bool($spec[$key])) {
                        throw new SqlException(ErrorCode::ParameterType);
                    }
                    $item[$key] = $spec[$key];
                }
            }
            foreach (['min', 'max', 'max_bytes'] as $key) {
                if (array_key_exists($key, $spec)) {
                    if (! is_int($spec[$key]) || ($key === 'max_bytes' && $spec[$key] < 0)) {
                        throw new SqlException(ErrorCode::ParameterType);
                    }
                    $item[$key] = $spec[$key];
                }
            }
            if (isset($item['min'], $item['max']) && $item['min'] > $item['max']) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            foreach (array_keys($validated) as $existing) {
                if (str_starts_with($path, $existing.'.') || str_starts_with($existing, $path.'.')) {
                    throw new SqlException(ErrorCode::ParameterType);
                }
            }
            $validated[$path] = $item;
        }
        $this->definitions = $validated;
    }

    /** @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function validateStructure(#[\SensitiveParameter] array $parameters): array
    {
        $flat = [];
        foreach ($this->definitions as $path => $spec) {
            $value = $parameters;
            foreach (explode('.', $path) as $part) {
                if (! is_array($value)) {
                    throw new SqlException(ErrorCode::ParameterType, parameterPath: $path);
                }
                if (! array_key_exists($part, $value)) {
                    throw new SqlException(ErrorCode::ParameterMissing, parameterPath: $path);
                }
                $value = $value[$part];
            }
            if ($value === null && ! ($spec['nullable'] ?? false)) {
                throw new SqlException(ErrorCode::ParameterType, parameterPath: $path);
            }
            $flat[$path] = $value;
        }
        $this->checkExtras($parameters, '');

        return $flat;
    }

    /** @param array<array-key, mixed> $parameters */
    private function checkExtras(#[\SensitiveParameter] array $parameters, string $prefix): void
    {
        foreach ($parameters as $key => $value) {
            if (str_contains((string) $key, '.')) {
                throw new SqlException(ErrorCode::ParameterExtra);
            }
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (isset($this->definitions[$path])) {
                continue;
            }
            $isParent = false;
            foreach (array_keys($this->definitions) as $declared) {
                if (str_starts_with($declared, $path.'.')) {
                    $isParent = true;
                    break;
                }
            }
            if (! $isParent) {
                throw new SqlException(ErrorCode::ParameterExtra);
            }
            if (! is_array($value)) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $this->checkExtras($value, $path);
        }
    }
}
