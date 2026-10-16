<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Batch;

use Hft\TwoWaySql\Contracts\SqlParameters;
use Hft\TwoWaySql\Exception\BatchException;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Exception\SqlExecutionException;
use Hft\TwoWaySql\Laravel\ConnectionResolver;
use Hft\TwoWaySql\Laravel\Executor\Executor;
use Throwable;

/** @internal */
final readonly class BatchRunner
{
    public function __construct(private Executor $executor, private ConnectionResolver $connections) {}

    /** @param iterable<mixed> $parameterSets */
    public function run(string $id, #[\SensitiveParameter] iterable $parameterSets, BatchOptions $options): BatchResult
    {
        $connection = $this->connections->resolve($this->executor->connectionName);
        $ambient = $connection->transactionLevel() > 0;
        if (! $options->atomic && $ambient) {
            throw new BatchException($id, [], null, ErrorCode::TransactionRequired->value, false);
        }
        $index = 0;
        $completed = [];
        try {
            $operation = function () use ($id, $parameterSets, $options, &$index, &$completed): array {
                $affected = [];
                foreach ($parameterSets as $parameters) {
                    if ($index >= $options->maxItems) {
                        throw new SqlException(ErrorCode::Limit, sqlId: $id);
                    }
                    if (! is_array($parameters) && ! $parameters instanceof SqlParameters) {
                        throw new SqlException(ErrorCode::ParameterType, sqlId: $id);
                    }
                    $parameters = $parameters instanceof SqlParameters ? $parameters : $this->parameterKeys($parameters);
                    $affected[] = $options->expectedRowsPerItem === null
                        ? $this->executor->execute($id, $parameters)
                        : $this->executor->executeExpecting($id, $parameters, $options->expectedRowsPerItem);
                    $completed[] = $index++;
                }

                return $affected;
            };
            $affected = $options->atomic && ! $connection->pretending() ? $connection->transaction($operation, 1) : $operation();

            return new BatchResult($affected, $completed, ! $ambient && ! $connection->pretending());
        } catch (Throwable $e) {
            throw new BatchException($id, $options->atomic ? [] : $completed, $index,
                $e instanceof SqlException ? $e->errorCode : ErrorCode::Batch->value, $options->atomic,
                $e instanceof SqlExecutionException ? $e->sqlState : null);
        }
    }

    /** @param array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    private function parameterKeys(#[\SensitiveParameter] array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
