<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Parameter\ParameterSchema;
use Hft\TwoWaySql\Template\Source\SourceSpan;

/** @phpstan-type SortItem array{column:non-empty-list<string>, direction:'ASC'|'DESC', nulls?:'FIRST'|'LAST'} */
final readonly class SortDefinitions
{
    /** @var array<string, array<string, non-empty-list<SortItem>>> */
    public array $definitions;

    /** @param array<array-key, mixed> $definitions */
    public function __construct(array $definitions, ParameterSchema $schema)
    {
        $validated = [];
        foreach ($definitions as $path => $keys) {
            if (! is_string($path) || ($schema->definitions[$path]['type'] ?? null) !== 'text' || ! is_array($keys) || $keys === []) {
                throw new SqlException(ErrorCode::Sort);
            }
            $sorts = [];
            foreach ($keys as $key => $items) {
                if (! is_string($key) || $key === '' || ! is_array($items) || ! array_is_list($items) || $items === []) {
                    throw new SqlException(ErrorCode::Sort);
                }
                $columns = [];
                foreach ($items as $item) {
                    $columns[] = $this->item($item);
                }
                $sorts[$key] = $columns;
            }
            $validated[$path] = $sorts;
        }
        $this->definitions = $validated;
    }

    /** @return SortItem */
    private function item(mixed $item): array
    {
        if (! is_array($item) || array_diff(array_keys($item), ['column', 'direction', 'nulls']) !== []) {
            throw new SqlException(ErrorCode::Sort);
        }
        $column = $item['column'] ?? null;
        $direction = $item['direction'] ?? null;
        if (! is_array($column) || ! array_is_list($column) || $column === [] || ! is_string($direction) || ! in_array(strtolower($direction), ['asc', 'desc'], true)) {
            throw new SqlException(ErrorCode::Sort);
        }
        $parts = [];
        foreach ($column as $part) {
            if (! is_string($part) || $part === '' || strlen($part) > 63 || ! mb_check_encoding($part, 'UTF-8') || str_contains($part, "\0")) {
                throw new SqlException(ErrorCode::Sort);
            }
            $parts[] = $part;
        }
        $result = ['column' => $parts, 'direction' => strtolower($direction) === 'asc' ? 'ASC' : 'DESC'];
        if (array_key_exists('nulls', $item)) {
            if (! is_string($item['nulls']) || ! in_array(strtolower($item['nulls']), ['first', 'last'], true)) {
                throw new SqlException(ErrorCode::Sort);
            }
            $result['nulls'] = strtolower($item['nulls']) === 'first' ? 'FIRST' : 'LAST';
        }

        return $result;
    }

    public function assertPath(string $path, ?SourceSpan $span = null): void
    {
        if (! isset($this->definitions[$path])) {
            throw new SqlException(ErrorCode::Sort, $span, parameterPath: $path);
        }
    }

    /** @param array<string, mixed> $parameters */
    public function validateSelections(#[\SensitiveParameter] array $parameters): void
    {
        foreach ($this->definitions as $path => $keys) {
            $value = $parameters;
            foreach (explode('.', $path) as $part) {
                if (! is_array($value) || ! array_key_exists($part, $value)) {
                    continue 2;
                }
                $value = $value[$part];
            }
            $this->items($path, $value);
        }
    }

    /** @return non-empty-list<SortItem> */
    public function items(string $path, #[\SensitiveParameter] mixed $key): array
    {
        if (! is_string($key) || ! isset($this->definitions[$path][$key])) {
            throw new SqlException(ErrorCode::Sort, parameterPath: $path);
        }

        return $this->definitions[$path][$key];
    }
}
