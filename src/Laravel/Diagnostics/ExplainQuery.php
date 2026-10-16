<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Diagnostics;

use Hft\TwoWaySql\Compiler\CompiledQuery;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Laravel\ConnectionGuard;
use Hft\TwoWaySql\Laravel\Executor\ExecutionOptions;
use Hft\TwoWaySql\Laravel\Executor\ExecutionScope;
use Hft\TwoWaySql\Laravel\Executor\PlanAdvice;
use Hft\TwoWaySql\Template\QueryKind;
use Illuminate\Database\Connection;

/** @internal Caller must verify the isolated database and roll back its transaction. */
final class ExplainQuery
{
    /** @return array<string,mixed> */
    public function run(#[\SensitiveParameter] Connection $connection, #[\SensitiveParameter] CompiledQuery $query, int $timeoutMs = 1000, ?PlanAdvice $advice = null): array
    {
        if ($query->kind !== QueryKind::Read || $timeoutMs < 1 || $timeoutMs > 60000 || $connection->transactionLevel() < 1) {
            throw new SqlException(ErrorCode::QueryKind);
        }
        ExplainPolicy::validate($query->sql());
        (new ConnectionGuard)->check($connection, false, $query->minimumPostgres);

        if ($advice !== null && ($advice->queryHash !== $query->hash || $advice->queryId !== $query->sqlId || $query->minimumPostgres !== 19)) {
            throw new SqlException(ErrorCode::ParameterType);
        }

        return (new ExecutionScope)->run($connection, false, new ExecutionOptions($timeoutMs, planAdviceId: $advice?->id), static function () use ($connection, $query, $advice): array {
            $row = $connection->selectOne('EXPLAIN (ANALYZE FALSE, FORMAT JSON, VERBOSE FALSE, COSTS TRUE'.($advice === null ? '' : ', PLAN_ADVICE TRUE').') '.$query->sql(), $query->bindings(), false);
            if (! $row instanceof \stdClass || ! is_string($row->{'QUERY PLAN'} ?? null)) {
                throw new SqlException(ErrorCode::Execution);
            }

            return (new PlanSummary)->sanitize($row->{'QUERY PLAN'}, $advice !== null);
        }, $advice);
    }
}
