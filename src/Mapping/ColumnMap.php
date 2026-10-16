<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Mapping;

use Hft\TwoWaySql\Contracts\ColumnConverter;
use Hft\TwoWaySql\Exception\MappingException;

final readonly class ColumnMap
{
    /** @var array<string, string> */
    public array $columns;

    /** @var array<string, ColumnConverter> */
    public array $converters;

    /** @param array<array-key, mixed> $columns Constructor argument => SQL column.
     * @param  array<array-key, mixed>  $converters  Constructor argument => trusted converter.
     */
    public function __construct(array $columns = [], array $converters = [])
    {
        $validColumns = [];
        $validConverters = [];
        foreach ($columns as $argument => $column) {
            if (! is_string($argument) || $argument === '' || ! is_string($column) || $column === '') {
                throw new MappingException;
            }
            $validColumns[$argument] = $column;
        }
        foreach ($converters as $argument => $converter) {
            if (! is_string($argument) || $argument === '' || ! $converter instanceof ColumnConverter) {
                throw new MappingException;
            }
            $validConverters[$argument] = $converter;
        }
        $this->columns = $validColumns;
        $this->converters = $validConverters;
    }
}
