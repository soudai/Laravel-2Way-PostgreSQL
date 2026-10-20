<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Executor;

use Hft\TwoWaySql\Compiler\CompiledQuery;
use Hft\TwoWaySql\Contracts\ExecutionObserver;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\ExpectedRowsException;
use Hft\TwoWaySql\Exception\MappingException;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Exception\SqlExecutionException;
use Hft\TwoWaySql\Laravel\Contracts\ExecutionConnection;
use Illuminate\Database\Connection;
use Throwable;

/** Owns Laravel's execution boundary, never the SQL operation or result mapping. */
final readonly class ExecutionPipeline
{
    public function __construct(
        private ExecutionConnection $connections,
        private ExecutionOptions $options = new ExecutionOptions,
        private PlanAdviceRegistry $planAdvice = new PlanAdviceRegistry,
        private ?ExecutionObserver $observer = null,
    ) {}

    /** @template T
     * @param  callable(Connection):T  $operation
     * @return T
     */
    public function run(?string $connectionName, #[\SensitiveParameter] CompiledQuery $query, ExecutionPolicy $policy, #[\SensitiveParameter] callable $operation): mixed
    {
        return (new ObservedExecution($this->observer))->run($query, fn (): mixed => $this->execute($connectionName, $query, $policy, $operation));
    }

    /** @template T
     * @param  callable(Connection):T  $operation
     * @return T
     */
    private function execute(?string $connectionName, #[\SensitiveParameter] CompiledQuery $query, ExecutionPolicy $policy, #[\SensitiveParameter] callable $operation): mixed
    {
        $connection = $this->connections->resolve($connectionName);
        if ($policy === ExecutionPolicy::Locking && $connection->transactionLevel() === 0) {
            throw new SqlException(ErrorCode::TransactionRequired, sqlId: $query->sqlId);
        }
        if ($policy === ExecutionPolicy::Cursor && $connection->pretending()) {
            return $operation($connection);
        }
        try {
            if (! $policy->validatesInsideTransaction()) {
                $this->validate($connection, $query, $policy);
            }
            $execute = function () use ($connection, $query, $policy, $operation): mixed {
                if ($policy->validatesInsideTransaction()) {
                    $this->validate($connection, $query, $policy);
                }

                return $operation($connection);
            };
            $scoped = fn (): mixed => (new ExecutionScope)->run(
                $connection, $policy === ExecutionPolicy::Read, $this->options, $execute,
                $this->planAdvice->forQuery($this->options->planAdviceId, $query),
            );

            return ! $connection->pretending() && $policy->startsTransaction($connection->transactionLevel())
                ? $connection->transaction($scoped, 1)
                : $scoped();
        } catch (SqlException $error) {
            // Recreate only the exceptions preserved by the original operation contract.
            throw match (true) {
                $error instanceof ExpectedRowsException && in_array($policy, [ExecutionPolicy::Write, ExecutionPolicy::CheckedWrite], true) => new ExpectedRowsException($error->expected, $error->actual, $query->sqlId),
                $error instanceof MappingException && in_array($policy, [ExecutionPolicy::MappedWrite, ExecutionPolicy::Cursor], true) => new MappingException(sqlId: $query->sqlId),
                default => new SqlException(ErrorCode::from($error->errorCode), $error->span, $query->sqlId, $error->parameterPath),
            };
        } catch (Throwable $error) {
            throw SqlExecutionException::translate($error, $query->sqlId);
        }
    }

    private function validate(#[\SensitiveParameter] Connection $connection, #[\SensitiveParameter] CompiledQuery $query, ExecutionPolicy $policy): void
    {
        if ($connection->pretending()) {
            return;
        }
        $this->connections->validate($connection, $policy->readsPdo(), $query->minimumPostgres);
        if ($policy === ExecutionPolicy::Cursor && $connection->getReadPdo() !== $connection->getRawPdo()) {
            throw new SqlException(ErrorCode::Unsupported);
        }
    }
}
