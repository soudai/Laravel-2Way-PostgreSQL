<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Codegen\Schema;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Types\TypeCatalog;

/** @phpstan-type Column array{alias:string, native_type:string, suggested_php_type:?string, nullable:null} */
final class MetadataDiff
{
    /** Apply a declared codec's PHP candidate only when its explicit native type agrees.
     * @param  array<array-key,mixed>  $baseline
     * @param  list<Column>  $columns
     * @return list<Column>
     */
    public static function candidates(array $baseline, array $columns): array
    {
        $data = $baseline['types'] ?? [];
        if (! is_array($data) || ! is_array($baseline['columns'] ?? null)) {
            throw new SqlException(ErrorCode::Generation);
        }
        $types = TypeCatalog::fromArray($data);
        foreach ($columns as &$column) {
            $saved = $baseline['columns'][$column['alias']] ?? null;
            $codec = is_array($saved) ? ($saved['codec'] ?? null) : null;
            if (! is_string($codec)) {
                continue;
            }
            $descriptor = $types->get($codec);
            $native = str_ends_with($descriptor->postgresType, '[]') ? '_'.substr($descriptor->postgresType, 0, -2) : $descriptor->postgresType;
            if ($column['native_type'] === $native) {
                $column['suggested_php_type'] = $descriptor->resultType;
            }
        }
        unset($column);

        return $columns;
    }

    /** @param array<array-key,mixed> $baseline
     * @param  list<Column>  $columns
     * @return array{sql_changed:bool,added_aliases:list<string>,removed_aliases:list<string>,duplicate_aliases:list<string>,type_candidates_changed:list<string>}
     */
    public static function compare(array $baseline, string $sourceHash, array $columns): array
    {
        if (! is_string($baseline['source_hash'] ?? null) || ! is_array($baseline['columns'] ?? null)) {
            throw new SqlException(ErrorCode::Generation);
        }
        $columns = self::candidates($baseline, $columns);
        $typeData = $baseline['types'] ?? [];
        if (! is_array($typeData)) {
            throw new SqlException(ErrorCode::Generation);
        }
        $types = TypeCatalog::fromArray($typeData);
        $old = [];
        $oldNative = [];
        foreach ($baseline['columns'] as $alias => $spec) {
            if (! is_string($alias) || ! is_array($spec) || ! is_string($spec['type'] ?? null)) {
                throw new SqlException(ErrorCode::Generation);
            }
            $old[$alias] = $spec['type'];
            if (is_string($spec['codec'] ?? null)) {
                $native = $types->get($spec['codec'])->postgresType;
                $oldNative[$alias] = str_ends_with($native, '[]') ? '_'.substr($native, 0, -2) : $native;
            }
        }
        $aliases = array_column($columns, 'alias');
        $counts = array_count_values($aliases);
        $duplicates = [];
        $changed = [];
        foreach ($columns as $column) {
            if ($counts[$column['alias']] > 1 && ! in_array($column['alias'], $duplicates, true)) {
                $duplicates[] = $column['alias'];
            }
            if (isset($old[$column['alias']]) && $column['suggested_php_type'] !== null && $old[$column['alias']] !== $column['suggested_php_type']) {
                $changed[] = $column['alias'];
            }
            if (isset($oldNative[$column['alias']]) && $oldNative[$column['alias']] !== $column['native_type']) {
                $changed[] = $column['alias'];
            }
        }

        return ['sql_changed' => $baseline['source_hash'] !== $sourceHash, 'added_aliases' => array_values(array_diff($aliases, array_keys($old))), 'removed_aliases' => array_values(array_diff(array_keys($old), $aliases)), 'duplicate_aliases' => $duplicates, 'type_candidates_changed' => array_values(array_unique($changed))];
    }
}
