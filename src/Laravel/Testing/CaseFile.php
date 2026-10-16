<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Testing;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use stdClass;

/** @phpstan-type Step array{query_id:string,parameters:array<string,mixed>,expected_affected_rows:?int,expected_rows:?list<array<array-key,mixed>>,row_order:string}
 * @phpstan-type CaseSpec array{id:string,query_id:string,parameters:array<string,mixed>,expected_error:?string,error_phase:string,expected_pdo_sql:?string,expected_bindings:?list<int|string|null>,comparison:string,expected_rows:?list<array<array-key,mixed>>,row_order:string,expected_affected_rows:?int,expected_branches:?list<string>,prepare:list<Step>,assertions:list<Step>,expect_rows:?int}
 */
final class CaseFile
{
    /** @return list<CaseSpec> */
    public function load(string $path): array
    {
        try {
            if (str_contains($path, '://') || ! is_file($path) || is_link($path)) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $json = @file_get_contents($path, false, null, 0, 4194305);
            if (! is_string($json) || strlen($json) > 4194304) {
                throw new SqlException(ErrorCode::Limit);
            }
            $root = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
            $items = $root instanceof stdClass ? ($root->cases ?? null) : null;
            if (! $root instanceof stdClass || ! in_array($root->schema_version ?? null, ['1.0', '2.0'], true) || ! is_array($items)
                || ! array_is_list($items) || count($items) < 1 || count($items) > 1000
                || array_diff(array_keys(get_object_vars($root)), ['schema_version', 'cases', 'note']) !== []
                || (property_exists($root, 'note') && ! is_string($root->note))) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $cases = [];
            $ids = [];
            foreach ($items as $item) {
                $case = $this->validate($item, $root->schema_version === '2.0');
                if (isset($ids[$case['id']])) {
                    throw new SqlException(ErrorCode::ParameterType);
                }
                $ids[$case['id']] = true;
                $cases[] = $case;
            }

            return $cases;
        } catch (\Throwable) {
            throw new SqlException(ErrorCode::ParameterType);
        }
    }

    /** @return CaseSpec */
    private function validate(#[\SensitiveParameter] mixed $item, bool $v2): array
    {
        if (! $item instanceof stdClass) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        $raw = get_object_vars($item);
        foreach ($raw as $key => $value) {
            if ($value === null) {
                throw new SqlException(ErrorCode::ParameterType);
            }
        }
        $allowed = ['id', 'query_id', 'parameters', 'minimum_milestone', 'expected_error', 'error_phase', 'expected_pdo_sql', 'expected_bindings', 'comparison', 'expected_rows', 'row_order', 'expected_affected_rows', 'expected_branches'];
        if ($v2) {
            array_push($allowed, 'prepare', 'assertions', 'expect_rows');
        }
        if (array_diff(array_keys($raw), $allowed) !== [] || ! $this->identifier($raw['id'] ?? null) || ! $this->identifier($raw['query_id'] ?? null)
            || ! ($raw['parameters'] ?? null) instanceof stdClass
            || (isset($raw['minimum_milestone']) && ! in_array($raw['minimum_milestone'], ['M1', 'M2', 'M3'], true))) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        $id = $raw['id'];
        $queryId = $raw['query_id'];
        if (! is_string($id) || ! is_string($queryId)) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        $error = $raw['expected_error'] ?? null;
        if ($error !== null && (! is_string($error) || ErrorCode::tryFrom($error) === null)) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        $phase = $raw['error_phase'] ?? 'compile';
        $comparison = $raw['comparison'] ?? 'bytes';
        $order = $raw['row_order'] ?? 'ordered';
        if (! in_array($phase, ['compile', 'execute'], true) || ! in_array($comparison, ['tokens', 'bytes'], true) || ! in_array($order, ['ordered', 'unordered'], true)) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        $sql = $raw['expected_pdo_sql'] ?? null;
        if ($sql !== null && ! is_string($sql)) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        if ($error === null && ($sql === null || ! array_key_exists('expected_bindings', $raw) || ! isset($raw['comparison']))) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        if ($error !== null && array_intersect(array_keys($raw), ['expected_pdo_sql', 'expected_bindings', 'expected_rows', 'expected_affected_rows', 'expected_branches']) !== []) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        $bindings = array_key_exists('expected_bindings', $raw) ? $this->bindings($raw['expected_bindings']) : null;
        $rows = null;
        if (array_key_exists('expected_rows', $raw)) {
            if (! is_array($raw['expected_rows']) || ! array_is_list($raw['expected_rows'])) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $rows = [];
            foreach ($raw['expected_rows'] as $row) {
                if (! $row instanceof stdClass) {
                    throw new SqlException(ErrorCode::ParameterType);
                }
                $rows[] = $this->objectArray($row);
            }
        }
        $affected = $raw['expected_affected_rows'] ?? null;
        if ($affected !== null && (! is_int($affected) || $affected < 0 || $rows !== null)) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        $branches = null;
        if (array_key_exists('expected_branches', $raw)) {
            if (! is_array($raw['expected_branches']) || ! array_is_list($raw['expected_branches'])) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $branches = [];
            foreach ($raw['expected_branches'] as $branch) {
                if (! is_string($branch) || ! preg_match('/\A[a-f0-9]{64}\z/D', $branch)) {
                    throw new SqlException(ErrorCode::ParameterType);
                }
                $branches[] = $branch;
            }
            if (count(array_unique($branches)) !== count($branches)) {
                throw new SqlException(ErrorCode::ParameterType);
            }
        }

        $prepare = $this->steps($raw['prepare'] ?? [], true);
        $assertions = $this->steps($raw['assertions'] ?? [], false);
        $expectRows = $raw['expect_rows'] ?? null;
        if (($expectRows !== null && (! is_int($expectRows) || $expectRows < 0))
            || ($error !== null && $phase === 'compile' && ($prepare !== [] || $assertions !== [] || $expectRows !== null))) {
            throw new SqlException(ErrorCode::ParameterType);
        }

        return ['id' => $id, 'query_id' => $queryId, 'parameters' => $this->rootArray($raw['parameters']), 'expected_error' => $error, 'error_phase' => $phase, 'expected_pdo_sql' => $sql, 'expected_bindings' => $bindings, 'comparison' => $comparison, 'expected_rows' => $rows, 'row_order' => $order, 'expected_affected_rows' => $affected, 'expected_branches' => $branches, 'prepare' => $prepare, 'assertions' => $assertions, 'expect_rows' => $expectRows];
    }

    /** @return list<Step> */
    private function steps(#[\SensitiveParameter] mixed $items, bool $prepare): array
    {
        if (! is_array($items) || ! array_is_list($items) || count($items) > 20) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        $steps = [];
        $total = 0;
        foreach ($items as $item) {
            if (! $item instanceof stdClass) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $raw = get_object_vars($item);
            $allowed = $prepare ? ['query_id', 'parameters', 'expected_affected_rows'] : ['query_id', 'parameters', 'expected_rows', 'row_order'];
            $id = $raw['query_id'] ?? null;
            if (array_diff(array_keys($raw), $allowed) !== [] || ! is_string($id) || ! $this->identifier($id) || ! ($raw['parameters'] ?? null) instanceof stdClass) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $affected = null;
            $rows = null;
            $order = $raw['row_order'] ?? 'ordered';
            if (! is_string($order)) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            if ($prepare) {
                $affected = $raw['expected_affected_rows'] ?? null;
                if (! is_int($affected) || $affected < 0 || $affected > 10000 || ($total += $affected) > 10000) {
                    throw new SqlException(ErrorCode::ParameterType);
                }
            } else {
                $expected = $raw['expected_rows'] ?? null;
                if (! is_array($expected) || ! array_is_list($expected) || count($expected) > 1000 || ! in_array($order, ['ordered', 'unordered'], true)
                    || (array_key_exists('row_order', $raw) && $raw['row_order'] === null)) {
                    throw new SqlException(ErrorCode::ParameterType);
                }
                $rows = [];
                foreach ($expected as $row) {
                    if (! $row instanceof stdClass) {
                        throw new SqlException(ErrorCode::ParameterType);
                    }
                    $rows[] = $this->objectArray($row);
                }
            }
            $steps[] = ['query_id' => $id, 'parameters' => $this->rootArray($raw['parameters']), 'expected_affected_rows' => $affected, 'expected_rows' => $rows, 'row_order' => $order];
        }

        return $steps;
    }

    private function identifier(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 128 && preg_match('/\A[A-Za-z_][A-Za-z0-9_.-]*\z/D', $value) === 1;
    }

    /** @return array<string,mixed> */
    private function rootArray(#[\SensitiveParameter] stdClass $object): array
    {
        $result = [];
        // Numeric JSON property names become integer array keys in PHP.
        /** @var array<array-key, mixed> $properties */
        $properties = get_object_vars($object);
        foreach ($properties as $key => $value) {
            if (! is_string($key)) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /** @return list<int|string|null> */
    private function bindings(#[\SensitiveParameter] mixed $values): array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        $result = [];
        foreach ($values as $value) {
            if ($value !== null && ! is_int($value) && ! is_string($value)) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $result[] = $value;
        }

        return $result;
    }

    /** @return array<array-key,mixed> */
    private function objectArray(#[\SensitiveParameter] stdClass $object): array
    {
        $result = [];
        foreach (get_object_vars($object) as $key => $value) {
            $result[$key] = $this->arrays($value);
        }

        return $result;
    }

    private function arrays(#[\SensitiveParameter] mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            return $this->objectArray($value);
        }
        if (is_array($value)) {
            return array_map(fn (#[\SensitiveParameter] mixed $item): mixed => $this->arrays($item), $value);
        }

        return $value;
    }
}
