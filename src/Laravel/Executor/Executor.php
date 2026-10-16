<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Executor;

use Hft\TwoWaySql\Compiler\CompiledQuery;
use Hft\TwoWaySql\Contracts\Compiler;
use Hft\TwoWaySql\Contracts\DefinitionProvider;
use Hft\TwoWaySql\Contracts\ExecutionObserver;
use Hft\TwoWaySql\Contracts\ResultMapper;
use Hft\TwoWaySql\Contracts\SqlParameters;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\ExpectedRowsException;
use Hft\TwoWaySql\Exception\MappingException;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Exception\SqlExecutionException;
use Hft\TwoWaySql\Laravel\Batch\BatchOptions;
use Hft\TwoWaySql\Laravel\Batch\BatchResult;
use Hft\TwoWaySql\Laravel\Batch\BatchRunner;
use Hft\TwoWaySql\Laravel\Compatibility\SelectAdapter;
use Hft\TwoWaySql\Laravel\ConnectionResolver;
use Hft\TwoWaySql\Laravel\Diagnostics\ExecutionEvent;
use Hft\TwoWaySql\Laravel\Pagination\PagePlanner;
use Hft\TwoWaySql\Laravel\Pagination\PageQuery;
use Hft\TwoWaySql\Laravel\Pagination\PageRequest;
use Hft\TwoWaySql\Laravel\Pagination\PageResult;
use Hft\TwoWaySql\Mapping\DtoMapper;
use Hft\TwoWaySql\Mapping\ScalarConversion;
use Hft\TwoWaySql\Parameter\ParameterInput;
use Hft\TwoWaySql\Template\QueryKind;
use Illuminate\Database\Connection;
use PDO;
use stdClass;
use Throwable;

final readonly class Executor
{
    public function __construct(private Compiler $compiler, private ConnectionResolver $connections, public ?string $connectionName = null, private ResultMapper $mapper = new DtoMapper, private ?ExecutionObserver $observer = null, private ExecutionOptions $options = new ExecutionOptions, private PlanAdviceRegistry $planAdvice = new PlanAdviceRegistry) {}

    public function withOptions(ExecutionOptions $options): self
    {
        return new self($this->compiler, $this->connections, $this->connectionName, $this->mapper, $this->observer, $options, $this->planAdvice);
    }

    /** @param array<string, mixed>|SqlParameters $parameters
     * @param  callable(stdClass):mixed  $consume
     */
    public function each(string $id, #[\SensitiveParameter] array|SqlParameters $parameters, #[\SensitiveParameter] callable $consume, int $fetchSize = 1000): int
    {
        return $this->eachRows($id, $parameters, function (#[\SensitiveParameter] mixed $row) use ($consume): mixed {
            if (! $row instanceof stdClass) {
                throw new MappingException;
            }

            return $consume($row);
        }, $fetchSize, PDO::FETCH_OBJ);
    }

    /** @template T of object
     * @param  class-string<T>  $class
     * @param  array<string, mixed>|SqlParameters  $parameters
     * @param  callable(T):mixed  $consume
     */
    public function eachAs(string $class, string $id, #[\SensitiveParameter] array|SqlParameters $parameters, #[\SensitiveParameter] callable $consume, int $fetchSize = 1000): int
    {
        $this->mapper->validate($class);

        return $this->eachRows($id, $parameters, fn (#[\SensitiveParameter] mixed $row): mixed => $consume($this->mapRow($class, $id, $row)), $fetchSize, PDO::FETCH_NAMED);
    }

    /** @param array<string, mixed>|SqlParameters $parameters
     * @param  callable(mixed):mixed  $consume
     */
    private function eachRows(string $id, #[\SensitiveParameter] array|SqlParameters $parameters, #[\SensitiveParameter] callable $consume, int $fetchSize, int $mode): int
    {
        if ($fetchSize < 1 || $fetchSize > 10000) {
            throw new SqlException(ErrorCode::ParameterType, sqlId: $id);
        }
        $query = $this->compile($id, $parameters);
        if ($query->kind !== QueryKind::Read) {
            throw new SqlException(ErrorCode::QueryKind, sqlId: $id);
        }

        return $this->observe($query, function () use ($query, $consume, $fetchSize, $mode): int {
            $connection = $this->connections->resolve($this->connectionName);
            if ($connection->pretending()) {
                return 0;
            }
            try {
                $this->connections->validate($connection, true, $query->minimumPostgres);
                if ($connection->getReadPdo() !== $connection->getRawPdo()) {
                    throw new SqlException(ErrorCode::Unsupported);
                }

                return $connection->transaction(fn (): int => $this->scope($connection, false, $query, fn (): int => (new CursorRunner)->run($connection, $query, $fetchSize, $mode, $consume)), 1);
            } catch (MappingException) {
                throw new MappingException(sqlId: $query->sqlId);
            } catch (SqlException $error) {
                throw new SqlException(ErrorCode::from($error->errorCode), $error->span, $query->sqlId, $error->parameterPath);
            } catch (Throwable $error) {
                throw SqlExecutionException::translate($error, $query->sqlId);
            }
        });
    }

    /** @param array<string, mixed>|SqlParameters $parameters */
    public function compile(string $id, #[\SensitiveParameter] array|SqlParameters $parameters): CompiledQuery
    {
        return $this->compiler->compile($id, ParameterInput::normalize($parameters));
    }

    /** @param array<string, mixed>|SqlParameters $parameters
     * @return list<stdClass>
     */
    public function select(string $id, #[\SensitiveParameter] array|SqlParameters $parameters): array
    {
        $query = $this->compile($id, $parameters);
        if (! in_array($query->kind, [QueryKind::Read, QueryKind::Locking], true)) {
            throw new SqlException(ErrorCode::QueryKind, sqlId: $id);
        }

        $rows = [];
        foreach ($this->read($query) as $row) {
            if (! $row instanceof stdClass) {
                throw new SqlException(ErrorCode::Connection, sqlId: $id);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param array<string, mixed>|SqlParameters $parameters */
    public function one(string $id, #[\SensitiveParameter] array|SqlParameters $parameters): stdClass
    {
        $rows = $this->select($id, $parameters);
        if (count($rows) !== 1) {
            throw new SqlException(ErrorCode::Cardinality, sqlId: $id);
        }

        return $rows[0];
    }

    /** @param array<string, mixed>|SqlParameters $parameters */
    public function oneOrNull(string $id, #[\SensitiveParameter] array|SqlParameters $parameters): ?stdClass
    {
        $rows = $this->select($id, $parameters);
        if (count($rows) > 1) {
            throw new SqlException(ErrorCode::Cardinality, sqlId: $id);
        }

        return $rows[0] ?? null;
    }

    /** @param array<string, mixed>|SqlParameters $parameters */
    public function scalar(string $id, #[\SensitiveParameter] array|SqlParameters $parameters): mixed
    {
        $query = $this->compile($id, $parameters);
        if (! in_array($query->kind, [QueryKind::Read, QueryKind::Locking], true)) {
            throw new SqlException(ErrorCode::QueryKind, sqlId: $id);
        }
        $rows = $this->read($query, PDO::FETCH_NUM);
        if (count($rows) !== 1 || ! is_array($rows[0]) || count($rows[0]) !== 1) {
            throw new SqlException(ErrorCode::Cardinality, sqlId: $id);
        }

        return array_values($rows[0])[0];
    }

    /** @param array<string, mixed>|SqlParameters $parameters */
    public function execute(string $id, #[\SensitiveParameter] array|SqlParameters $parameters): int
    {
        $query = $this->compile($id, $parameters);
        if ($query->kind !== QueryKind::Write) {
            throw new SqlException(ErrorCode::QueryKind, sqlId: $id);
        }
        $result = $this->write($query);
        if (! is_int($result)) {
            throw new SqlException(ErrorCode::Execution, sqlId: $id);
        }

        return $result;
    }

    /** @param array<string, mixed>|SqlParameters $parameters
     * @return list<stdClass>
     */
    public function returning(string $id, #[\SensitiveParameter] array|SqlParameters $parameters): array
    {
        $query = $this->compile($id, $parameters);
        if ($query->kind !== QueryKind::WriteReturning) {
            throw new SqlException(ErrorCode::QueryKind, sqlId: $id);
        }
        $result = $this->write($query);
        if (! is_array($result)) {
            throw new SqlException(ErrorCode::Execution, sqlId: $id);
        }

        return $result;
    }

    /** @template T of object
     * @param  class-string<T>  $class
     * @param  array<string, mixed>|SqlParameters  $parameters
     * @return list<T>
     */
    public function selectAs(string $class, string $id, #[\SensitiveParameter] array|SqlParameters $parameters): array
    {
        $this->mapper->validate($class);

        $mapped = [];
        foreach ($this->mappedRows($id, $parameters) as $row) {
            $mapped[] = $this->mapRow($class, $id, $row);
        }

        return $mapped;
    }

    /** @template T of object
     * @param  class-string<T>  $class
     * @param  array<string, mixed>|SqlParameters  $parameters
     * @return T
     */
    public function oneAs(string $class, string $id, #[\SensitiveParameter] array|SqlParameters $parameters): object
    {
        $this->mapper->validate($class);
        $rows = $this->mappedRows($id, $parameters);
        if (count($rows) !== 1) {
            throw new SqlException(ErrorCode::Cardinality, sqlId: $id);
        }

        return $this->mapRow($class, $id, $rows[0]);
    }

    /** @template T of object
     * @param  class-string<T>  $class
     * @param  array<string, mixed>|SqlParameters  $parameters
     * @return T|null
     */
    public function oneOrNullAs(string $class, string $id, #[\SensitiveParameter] array|SqlParameters $parameters): ?object
    {
        $this->mapper->validate($class);
        $rows = $this->mappedRows($id, $parameters);
        if (count($rows) > 1) {
            throw new SqlException(ErrorCode::Cardinality, sqlId: $id);
        }

        return $rows === [] ? null : $this->mapRow($class, $id, $rows[0]);
    }

    /** @template T of object
     * @param  class-string<T>  $class
     * @param  array<string, mixed>|SqlParameters  $parameters
     * @return list<T>
     */
    public function returningAs(string $class, string $id, #[\SensitiveParameter] array|SqlParameters $parameters): array
    {
        $this->mapper->validate($class);
        $query = $this->compile($id, $parameters);
        if ($query->kind !== QueryKind::WriteReturning) {
            throw new SqlException(ErrorCode::QueryKind, sqlId: $id);
        }

        return $this->observe($query, function () use ($class, $query): array {
            $connection = $this->connections->resolve($this->connectionName);
            try {
                $operation = function () use ($connection, $class, $query): array {
                    if (! $connection->pretending()) {
                        $this->connections->validate($connection, false, $query->minimumPostgres);
                    }
                    $rows = (new SelectAdapter)->select($connection, $query, false, PDO::FETCH_NAMED);
                    if (! $connection->pretending()) {
                        $connection->recordsHaveBeenModified(true);
                    }

                    return array_map(fn (#[\SensitiveParameter] mixed $row): object => $this->mapRow($class, $query->sqlId, $row), $rows);
                };

                // Mapping failure must roll back this write, including inside an ambient transaction.
                $scoped = fn (): array => $this->scope($connection, false, $query, $operation);

                return $connection->pretending() ? $scoped() : $connection->transaction($scoped, 1);
            } catch (MappingException) {
                throw new MappingException(sqlId: $query->sqlId);
            } catch (SqlException $error) {
                throw new SqlException(ErrorCode::from($error->errorCode), $error->span, $query->sqlId, $error->parameterPath);
            } catch (Throwable $error) {
                throw SqlExecutionException::translate($error, $query->sqlId);
            }
        });
    }

    /** @param array<string, mixed>|SqlParameters $parameters
     * @return list<mixed>
     */
    private function mappedRows(string $id, #[\SensitiveParameter] array|SqlParameters $parameters): array
    {
        $query = $this->compile($id, $parameters);
        if (! in_array($query->kind, [QueryKind::Read, QueryKind::Locking], true)) {
            throw new SqlException(ErrorCode::QueryKind, sqlId: $id);
        }

        return $this->read($query, PDO::FETCH_NAMED);
    }

    /** @template T of object
     * @param  class-string<T>  $class
     * @return T
     */
    private function mapRow(string $class, string $id, #[\SensitiveParameter] mixed $row): object
    {
        try {
            if (! is_array($row)) {
                throw new MappingException;
            }
            foreach ($row as $value) {
                // FETCH_NAMED exposes duplicate aliases as arrays; PG array/json cells remain strings.
                if (is_array($value)) {
                    throw new MappingException;
                }
            }

            return $this->mapper->map($class, (object) $row);
        } catch (Throwable) {
            throw new MappingException(sqlId: $id);
        }
    }

    /** @return list<mixed> */
    private function read(#[\SensitiveParameter] CompiledQuery $query, int $fetchMode = PDO::FETCH_OBJ): array
    {
        return $this->observe($query, fn (): array => $this->performRead($query, $fetchMode));
    }

    /** @return list<mixed> */
    private function performRead(#[\SensitiveParameter] CompiledQuery $query, int $fetchMode): array
    {
        $connection = $this->connections->resolve($this->connectionName);
        if ($query->kind === QueryKind::Locking && $connection->transactionLevel() === 0) {
            throw new SqlException(ErrorCode::TransactionRequired, sqlId: $query->sqlId);
        }
        try {
            if (! $connection->pretending()) {
                $this->connections->validate($connection, $query->kind === QueryKind::Read, $query->minimumPostgres);
            }

            return $this->scope($connection, $query->kind === QueryKind::Read, $query, fn (): array => (new SelectAdapter)->select($connection, $query, $query->kind === QueryKind::Read, $fetchMode));
        } catch (SqlException $e) {
            throw new SqlException(ErrorCode::from($e->errorCode), $e->span, $query->sqlId, $e->parameterPath);
        } catch (Throwable $e) {
            throw SqlExecutionException::translate($e, $query->sqlId);
        }
    }

    /** @param array<string, mixed>|SqlParameters $parameters */
    public function executeExpecting(string $id, #[\SensitiveParameter] array|SqlParameters $parameters, int $expected): int
    {
        if ($expected < 0) {
            throw new SqlException(ErrorCode::ParameterType, sqlId: $id);
        }
        $query = $this->compile($id, $parameters);
        if ($query->kind !== QueryKind::Write) {
            throw new SqlException(ErrorCode::QueryKind, sqlId: $id);
        }
        $result = $this->write($query, $expected);
        if (! is_int($result)) {
            throw new SqlException(ErrorCode::Execution, sqlId: $id);
        }

        return $result;
    }

    /** @return int|list<stdClass> */
    private function write(#[\SensitiveParameter] CompiledQuery $query, ?int $expected = null): int|array
    {
        return $this->observe($query, fn (): int|array => $this->performWrite($query, $expected));
    }

    /** @return int|list<stdClass> */
    private function performWrite(#[\SensitiveParameter] CompiledQuery $query, ?int $expected): int|array
    {
        $connection = $this->connections->resolve($this->connectionName);
        try {
            $operation = function () use ($connection, $query, $expected): int|array {
                if (! $connection->pretending()) {
                    $this->connections->validate($connection, false, $query->minimumPostgres);
                }
                $result = $query->kind === QueryKind::Write
                    ? $connection->affectingStatement($query->sql(), $query->bindings())
                    : $this->fetch($connection, $query, false);
                if (! $connection->pretending()) {
                    $connection->recordsHaveBeenModified(true);
                    if ($expected !== null && is_int($result) && $result !== $expected) {
                        throw new ExpectedRowsException($expected, $result, $query->sqlId);
                    }
                }

                return $result;
            };
            $scoped = fn (): int|array => $this->scope($connection, false, $query, $operation);
            if ($connection->pretending() || ($expected === null && $connection->transactionLevel() > 0)) {
                return $scoped();
            }

            return $connection->transaction($scoped, 1);
        } catch (ExpectedRowsException $e) {
            throw new ExpectedRowsException($e->expected, $e->actual, $query->sqlId);
        } catch (SqlException $e) {
            throw new SqlException(ErrorCode::from($e->errorCode), $e->span, $query->sqlId, $e->parameterPath);
        } catch (Throwable $e) {
            throw SqlExecutionException::translate($e, $query->sqlId);
        }
    }

    /** @return list<stdClass> */
    private function fetch(#[\SensitiveParameter] Connection $connection, #[\SensitiveParameter] CompiledQuery $query, bool $read): array
    {
        $rows = [];
        foreach ((new SelectAdapter)->select($connection, $query, $read, PDO::FETCH_OBJ) as $row) {
            if (! $row instanceof stdClass) {
                throw new SqlException(ErrorCode::Connection, sqlId: $query->sqlId);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param iterable<mixed> $parameterSets */
    public function batch(string $id, #[\SensitiveParameter] iterable $parameterSets, BatchOptions $options = new BatchOptions): BatchResult
    {
        return (new BatchRunner($this, $this->connections))->run($id, $parameterSets, $options);
    }

    /** @param array<string, mixed>|SqlParameters $parameters */
    public function paginate(PageQuery $query, #[\SensitiveParameter] array|SqlParameters $parameters, PageRequest $page): PageResult
    {
        if (! $this->compiler instanceof DefinitionProvider) {
            throw new SqlException(ErrorCode::Unsupported);
        }
        $plan = (new PagePlanner($this->compiler))->plan($query, ParameterInput::normalize($parameters), $page);
        $countRows = $this->read($plan->count, PDO::FETCH_NUM);
        if (count($countRows) !== 1 || ! is_array($countRows[0]) || count($countRows[0]) !== 1) {
            throw new SqlException(ErrorCode::Cardinality, sqlId: $query->countSqlId);
        }
        try {
            $total = ScalarConversion::integer(array_values($countRows[0])[0]);
        } catch (MappingException) {
            throw new SqlException(ErrorCode::Cardinality, sqlId: $query->countSqlId);
        }
        if ($total < 0) {
            throw new SqlException(ErrorCode::Cardinality, sqlId: $query->countSqlId);
        }
        $rows = [];
        foreach ($this->read($plan->data) as $row) {
            if (! $row instanceof stdClass) {
                throw new SqlException(ErrorCode::Connection, sqlId: $query->dataSqlId);
            }
            $rows[] = $row;
        }

        return new PageResult($rows, $total, $page->page, $page->perPage);
    }

    /** @template T
     * @param  callable():T  $operation
     * @return T
     */
    private function observe(#[\SensitiveParameter] CompiledQuery $query, #[\SensitiveParameter] callable $operation): mixed
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

    /** @template T
     * @param  callable():T  $operation
     * @return T
     */
    private function scope(#[\SensitiveParameter] Connection $connection, bool $read, #[\SensitiveParameter] CompiledQuery $query, #[\SensitiveParameter] callable $operation): mixed
    {
        return (new ExecutionScope)->run($connection, $read, $this->options, $operation, $this->planAdvice->forQuery($this->options->planAdviceId, $query));
    }

    /** @return array{connection:?string} */
    public function __debugInfo(): array
    {
        return ['connection' => $this->connectionName];
    }
}
