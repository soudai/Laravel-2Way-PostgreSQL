<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Compiler\Metadata;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Parameter\ParameterSchema;
use Hft\TwoWaySql\Template\Source\SourceSpan;

/** @phpstan-type Column array{column:non-empty-list<string>, alias?:string} */
final readonly class ProjectionDefinitions
{
    /** @var array<string,array<string,non-empty-list<Column>>> */
    public array $definitions;

    /** @param array<array-key,mixed> $input */
    public function __construct(array $input, ParameterSchema $schema)
    {
        $definitions = [];
        foreach ($input as $path => $choices) {
            if (! is_string($path) || ($schema->definitions[$path]['type'] ?? null) !== 'text' || ($schema->definitions[$path]['nullable'] ?? false) || ! is_array($choices) || $choices === []) {
                throw new SqlException(ErrorCode::Generation);
            }
            $keys = [];
            foreach ($choices as $key => $columns) {
                if (! is_string($key) || $key === '' || ! is_array($columns) || ! array_is_list($columns) || $columns === [] || count($columns) > 1000) {
                    throw new SqlException(ErrorCode::Generation);
                }
                $items = [];
                foreach ($columns as $column) {
                    if (! is_array($column) || array_diff(array_keys($column), ['column', 'alias']) !== [] || ! is_array($column['column'] ?? null) || ! array_is_list($column['column']) || $column['column'] === []) {
                        throw new SqlException(ErrorCode::Generation);
                    }
                    $item = ['column' => array_map(Identifier::validate(...), $column['column'])];
                    if (array_key_exists('alias', $column)) {
                        $item['alias'] = Identifier::validate($column['alias']);
                    }
                    $items[] = $item;
                }
                $keys[$key] = $items;
            }
            $definitions[$path] = $keys;
        }
        $this->definitions = $definitions;
    }

    public function assertPath(string $path, ?SourceSpan $span = null): void
    {
        if (! isset($this->definitions[$path])) {
            throw new SqlException(ErrorCode::Generation, $span, parameterPath: $path);
        }
    }

    /** @param array<string,mixed> $parameters */
    public function validateSelections(#[\SensitiveParameter] array $parameters): void
    {
        foreach ($this->definitions as $path => $choices) {
            $value = $parameters;
            foreach (explode('.', $path) as $part) {
                if (! is_array($value) || ! array_key_exists($part, $value)) {
                    continue 2;
                }
                $value = $value[$part];
            }
            $this->columns($path, $value);
        }
    }

    /** @return non-empty-list<Column> */
    public function columns(string $path, #[\SensitiveParameter] mixed $key): array
    {
        if (! is_string($key) || ! isset($this->definitions[$path][$key])) {
            throw new SqlException(ErrorCode::Generation, parameterPath: $path);
        }

        return $this->definitions[$path][$key];
    }
}
