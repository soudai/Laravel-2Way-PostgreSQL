<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Codegen\Definition;

use BackedEnum;
use DateTimeImmutable;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Parameter\Range\DateRange;
use Hft\TwoWaySql\Parameter\Range\TstzRange;
use Hft\TwoWaySql\Types\TypeCatalog;
use Hft\TwoWaySql\Types\TypeDescriptor;

/** @phpstan-type Column array{type:string, nullable:bool, codec?:string, doc?:string} */
final readonly class ResultSchema
{
    /** @var array<string, Column> */
    public array $columns;

    /** @param array<array-key, mixed> $columns
     * @param  null|callable(string):bool  $enumCheck  Static analysis can supply its own reflection provider.
     */
    public function __construct(array $columns = [], ?callable $enumCheck = null, TypeCatalog $types = new TypeCatalog)
    {
        $normalized = [];
        foreach ($columns as $alias => $column) {
            if (! is_string($alias) || ! TypeNames::identifier($alias) || ! is_array($column)
                || array_diff(array_keys($column), ['type', 'nullable', 'codec', 'doc']) !== []) {
                throw new SqlException(ErrorCode::Generation);
            }
            $type = $column['type'] ?? null;
            $nullable = array_key_exists('nullable', $column) ? $column['nullable'] : false;
            $codec = $column['codec'] ?? null;
            if (is_string($type) && str_starts_with($type, 'codec:')) {
                if (array_key_exists('codec', $column)) {
                    throw new SqlException(ErrorCode::Generation);
                }
                $codec = substr($type, 6);
                $type = $types->find($codec)?->resultType;
            }
            if ($codec !== null || array_key_exists('codec', $column)) {
                $descriptor = is_string($codec) ? $types->find($codec) : null;
                if ($descriptor === null || $descriptor->resultType !== $type || ! is_bool($nullable) || ($nullable && ! $descriptor->nullable)) {
                    throw new SqlException(ErrorCode::Generation);
                }
                if (array_key_exists('doc', $column) && $column['doc'] !== $descriptor->resultDoc) {
                    throw new SqlException(ErrorCode::Generation);
                }
                $normalized[$alias] = ['type' => $descriptor->resultType, 'nullable' => $nullable, 'codec' => $descriptor->id];
                if ($descriptor->resultDoc !== null) {
                    $normalized[$alias]['doc'] = $descriptor->resultDoc;
                }

                continue;
            }
            if (array_key_exists('doc', $column) || ! is_string($type) || ! is_bool($nullable)
                || (! in_array($type, ['int', 'string', 'bool', 'float', DateTimeImmutable::class, DateRange::class, TstzRange::class], true) && ! (TypeNames::className($type) && ($enumCheck !== null ? $enumCheck($type) : is_a($type, BackedEnum::class, true))))) {
                throw new SqlException(ErrorCode::Generation);
            }
            $normalized[$alias] = ['type' => $type, 'nullable' => $nullable];
        }
        $this->columns = $normalized;
    }

    /** @param Column $column */
    public static function phpType(array $column): string
    {
        $name = TypeDescriptor::phpType($column['type']);

        return ($column['nullable'] ? '?' : '').$name;
    }
}
