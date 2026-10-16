<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Diagnostics;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

/** A fresh allowlisted structure, never a redacted copy of a raw plan. */
final class PlanSummary
{
    private const array NODES = ['Result', 'ProjectSet', 'ModifyTable', 'Append', 'Merge Append', 'Recursive Union', 'BitmapAnd', 'BitmapOr', 'Seq Scan', 'Sample Scan', 'Index Scan', 'Index Only Scan', 'Bitmap Heap Scan', 'Bitmap Index Scan', 'Tid Scan', 'Tid Range Scan', 'Subquery Scan', 'Function Scan', 'Table Function Scan', 'Values Scan', 'CTE Scan', 'Named Tuplestore Scan', 'WorkTable Scan', 'Foreign Scan', 'Custom Scan', 'Nest Loop', 'Nested Loop', 'Merge Join', 'Hash Join', 'Materialize', 'Memoize', 'Sort', 'Incremental Sort', 'Group', 'Aggregate', 'WindowAgg', 'Unique', 'Gather', 'Gather Merge', 'Hash', 'SetOp', 'LockRows', 'Limit'];

    /** @return array<string,mixed> */
    public function sanitize(#[\SensitiveParameter] string $json, bool $includeAdviceFeedback = false): array
    {
        if (strlen($json) > 1048576) {
            throw new SqlException(ErrorCode::Limit);
        }
        try {
            $raw = json_decode($json, true, 128, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new SqlException(ErrorCode::Execution);
        }
        if (! is_array($raw) || ! array_is_list($raw) || count($raw) !== 1 || ! is_array($raw[0]) || ! is_array($raw[0]['Plan'] ?? null)) {
            throw new SqlException(ErrorCode::Execution);
        }
        $count = 0;
        $result = $this->node($raw[0]['Plan'], 1, $count);
        if ($includeAdviceFeedback) {
            if (! is_string($raw[0]['Supplied Plan Advice'] ?? null)) {
                throw new SqlException(ErrorCode::Execution);
            }
            $result['adviceFeedback'] = AdviceFeedback::summarize($raw[0]['Supplied Plan Advice']);
        }
        if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 65536) {
            throw new SqlException(ErrorCode::Limit);
        }

        return $result;
    }

    /** @param array<array-key,mixed> $raw
     * @return array<string,mixed>
     */
    private function node(#[\SensitiveParameter] array $raw, int $depth, int &$count): array
    {
        if ($depth > 32 || ++$count > 256) {
            throw new SqlException(ErrorCode::Limit);
        }
        $type = $raw['Node Type'] ?? null;
        if (! is_string($type)) {
            throw new SqlException(ErrorCode::Execution);
        }
        $result = ['nodeType' => in_array($type, self::NODES, true) ? $type : 'Unknown'];
        if (is_bool($raw['Disabled'] ?? null)) {
            $result['disabled'] = $raw['Disabled'];
        }
        foreach (['Startup Cost' => 'startupCost', 'Total Cost' => 'totalCost', 'Plan Rows' => 'planRows', 'Plan Width' => 'planWidth'] as $source => $target) {
            $value = $raw[$source] ?? null;
            if ((is_int($value) || is_float($value)) && is_finite($value) && $value >= 0) {
                $result[$target] = $value;
            }
        }
        if (array_key_exists('Plans', $raw)) {
            if (! is_array($raw['Plans']) || ! array_is_list($raw['Plans'])) {
                throw new SqlException(ErrorCode::Execution);
            }
            $children = [];
            foreach ($raw['Plans'] as $child) {
                if (! is_array($child)) {
                    throw new SqlException(ErrorCode::Execution);
                }
                $children[] = $this->node($child, $depth + 1, $count);
            }
            $result['children'] = $children;
        }

        return $result;
    }
}
