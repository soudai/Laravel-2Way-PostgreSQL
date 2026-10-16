<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Parameter\Patch;

use Hft\TwoWaySql\Compiler\Metadata\Identifier;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Parameter\ParameterSchema;

/** @phpstan-import-type Spec from ParameterSchema */
final readonly class PatchSchema
{
    private const PROTECTED = ['id', 'tenant_id', 'lock_version', 'created_at', 'created_on', 'created_by'];

    /** @var array<string, array{type:string, nullable?:bool, elements_nullable?:bool, min?:int, max?:int, max_bytes?:int, fields?:array<string,mixed>, column:string, protected:bool}> */
    public array $fields;

    /** @param array<array-key,mixed> $input */
    public function __construct(array $input, int $depth = 0)
    {
        if ($input === [] || count($input) > 1000) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        $fields = [];
        $columns = [];
        foreach ($input as $name => $spec) {
            if (! is_string($name) || ! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) || ! is_array($spec)) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $column = Identifier::validate($spec['column'] ?? null);
            if (in_array($column, $columns, true)) {
                throw new SqlException(ErrorCode::Generation);
            }
            $columns[] = $column;
            $protected = $spec['protected'] ?? (in_array(strtolower($name), self::PROTECTED, true) || in_array(strtolower($column), self::PROTECTED, true));
            if (! is_bool($protected) || (array_key_exists('protected', $spec) && $spec['protected'] === null) || ! is_string($spec['type'] ?? null) || str_starts_with($spec['type'], 'list<') || $spec['type'] === 'patch<record>') {
                throw new SqlException(ErrorCode::ParameterType);
            }
            unset($spec['column'], $spec['protected']);
            $fields[$name] = (new ParameterSchema([$name => $spec], $depth + 1))->definitions[$name] + ['column' => $column, 'protected' => $protected];
        }
        $this->fields = $fields;
    }

    /** @return array<string,mixed> Selected fields in declaration order. */
    public function values(#[\SensitiveParameter] mixed $patch): array
    {
        if (! is_array($patch)) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        if ($patch === []) {
            throw new SqlException(ErrorCode::EmptySet);
        }
        foreach ($patch as $name => $value) {
            if (! is_string($name) || ! isset($this->fields[$name])) {
                throw new SqlException(ErrorCode::ParameterExtra);
            }
            if ($this->fields[$name]['protected']) {
                throw new SqlException(ErrorCode::ParameterType);
            }
        }

        $selected = [];
        foreach ($this->fields as $name => $field) {
            if (array_key_exists($name, $patch)) {
                $selected[$name] = $patch[$name];
            }
        }

        return $selected;
    }
}
