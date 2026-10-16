<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Executor;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Laravel\ConnectionGuard;
use Illuminate\Database\Connection;
use stdClass;

/** @internal Owns only its Laravel transaction/savepoint, never the caller's transaction. */
final class ExecutionScope
{
    /** @template T
     * @param  callable():T  $operation
     * @return T
     */
    public function run(#[\SensitiveParameter] Connection $connection, bool $read, ExecutionOptions $options, #[\SensitiveParameter] callable $operation, ?PlanAdvice $advice = null): mixed
    {
        if ($options->planAdviceId !== $advice?->id) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        if ($options->isEmpty() || $connection->pretending()) {
            return $operation();
        }
        // A split reader PDO is not managed by Laravel's writer transaction counter.
        if ($read && $connection->getReadPdo() !== $connection->getRawPdo()) {
            throw new SqlException(ErrorCode::Unsupported);
        }

        if ($advice !== null) {
            (new ConnectionGuard)->check($connection, false, 19);
        }

        return $connection->transaction(function () use ($connection, $options, $operation, $advice): mixed {
            $original = $connection->selectOne("SELECT current_setting('statement_timeout') AS statement, current_setting('lock_timeout') AS lock", [], false);
            if (! $original instanceof stdClass || ! is_string($original->statement) || ! is_string($original->lock)) {
                throw new SqlException(ErrorCode::Connection);
            }
            $this->configure($connection, $options->statementTimeoutMs === null ? $original->statement : $options->statementTimeoutMs.'ms', $options->lockTimeoutMs === null ? $original->lock : $options->lockTimeoutMs.'ms');
            $adviceSettings = $advice === null ? [] : $this->applyAdvice($connection, $advice);
            $result = $operation();
            // RELEASE SAVEPOINT retains SET LOCAL: restore explicitly on success.
            // On failure Laravel rolls this scope back, restoring both settings automatically.
            $this->configure($connection, $original->statement, $original->lock);
            foreach ($adviceSettings as $name => $value) {
                $connection->selectOne('SELECT set_config(?, ?, true)', [$name, $value], false);
            }

            return $result;
        }, 1);
    }

    private function configure(#[\SensitiveParameter] Connection $connection, string $statement, string $lock): void
    {
        $connection->selectOne("SELECT set_config('statement_timeout', ?, true), set_config('lock_timeout', ?, true)", [$statement, $lock], false);
    }

    /** @return array<string,string> */
    private function applyAdvice(#[\SensitiveParameter] Connection $connection, PlanAdvice $advice): array
    {
        $rows = $connection->select("SELECT name, setting FROM pg_settings WHERE name IN ('pg_plan_advice.advice', 'pg_plan_advice.feedback_warnings', 'pg_plan_advice.trace_mask', 'plan_cache_mode')", [], false);
        $original = [];
        foreach ($rows as $row) {
            if (! $row instanceof stdClass || ! is_string($row->name) || ! is_string($row->setting)) {
                throw new SqlException(ErrorCode::Connection);
            }
            $original[$row->name] = $row->setting;
        }
        if (count($original) !== 4) {
            throw new SqlException(ErrorCode::Unsupported);
        }
        $connection->selectOne("SELECT set_config('pg_plan_advice.feedback_warnings', 'off', true), set_config('pg_plan_advice.trace_mask', 'off', true)", [], false);
        $connection->selectOne("SELECT set_config('pg_plan_advice.advice', ?, true), set_config('plan_cache_mode', 'force_custom_plan', true)", [$advice->text()], false);

        // Restore advice before restoring warning flags; never print supplied advice.
        return ['pg_plan_advice.advice' => $original['pg_plan_advice.advice'], 'plan_cache_mode' => $original['plan_cache_mode'], 'pg_plan_advice.feedback_warnings' => $original['pg_plan_advice.feedback_warnings'], 'pg_plan_advice.trace_mask' => $original['pg_plan_advice.trace_mask']];
    }
}
