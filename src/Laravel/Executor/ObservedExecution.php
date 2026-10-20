<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Executor;

use Hft\TwoWaySql\Compiler\CompiledQuery;
use Hft\TwoWaySql\Contracts\ExecutionObserver;
use Hft\TwoWaySql\Exception\ExpectedRowsException;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Exception\SqlExecutionException;
use Hft\TwoWaySql\Laravel\Diagnostics\ExecutionEvent;
use Throwable;

final readonly class ObservedExecution
{
    public function __construct(private ?ExecutionObserver $observer) {}

    /** @template T
     * @param  callable():T  $operation
     * @return T
     */
    public function run(#[\SensitiveParameter] CompiledQuery $query, #[\SensitiveParameter] callable $operation): mixed
    {
        if ($this->observer === null) {
            return $operation();
        }
        $start = hrtime(true);
        $rows = null;
        $error = null;
        $state = null;
        try {
            $result = $operation();
            $rows = is_array($result) ? count($result) : (is_int($result) ? $result : null);

            return $result;
        } catch (SqlException $e) {
            $error = $e->errorCode;
            $state = $e instanceof SqlExecutionException ? $e->sqlState : null;
            if ($e instanceof ExpectedRowsException) {
                $rows = $e->actual;
            }
            throw $e;
        } finally {
            $sourceHash = $query->diagnostics['sourceHash'] ?? $query->hash;
            $branches = $query->diagnostics['branches'] ?? [];
            try {
                $this->observer->record(new ExecutionEvent(
                    substr($query->sqlId, 0, 128), is_string($sourceHash) ? $sourceHash : $query->hash, $query->hash,
                    (int) ((hrtime(true) - $start) / 1000), $rows, $error, $state, is_array($branches) ? $branches : [],
                ));
            } catch (Throwable) { /* An observer cannot replace the operation result or error. */
            }
        }
    }
}
