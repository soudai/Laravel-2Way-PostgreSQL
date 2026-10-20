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
use Hft\TwoWaySql\Execution\QueryOperation;
use Hft\TwoWaySql\Laravel\Batch\BatchOptions;
use Hft\TwoWaySql\Laravel\Batch\BatchResult;
use Hft\TwoWaySql\Laravel\Batch\BatchRunner;
use Hft\TwoWaySql\Laravel\Compatibility\SelectAdapter;
use Hft\TwoWaySql\Laravel\ConnectionResolver;
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
        $query = $this->compileFor(QueryOperation::Each, $id, $parameters);

        return $this->pipeline()->run($this->connectionName, $query, ExecutionPolicy::Cursor,
            fn (Connection $connection): int => $connection->pretending()
                ? 0
                : (new CursorRunner)->run($connection, $query, $fetchSize, $mode, $consume));
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
        $query = $this->compileFor(QueryOperation::Select, $id, $parameters);

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
        $query = $this->compileFor(QueryOperation::Scalar, $id, $parameters);
        $rows = $this->read($query, PDO::FETCH_NUM);
        if (count($rows) !== 1 || ! is_array($rows[0]) || count($rows[0]) !== 1) {
            throw new SqlException(ErrorCode::Cardinality, sqlId: $id);
        }

        return array_values($rows[0])[0];
    }

    /** @param array<string, mixed>|SqlParameters $parameters */
    public function execute(string $id, #[\SensitiveParameter] array|SqlParameters $parameters): int
    {
        $query = $this->compileFor(QueryOperation::Execute, $id, $parameters);
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
        $query = $this->compileFor(QueryOperation::Returning, $id, $parameters);
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
        $query = $this->compileFor(QueryOperation::Returning, $id, $parameters);

        return $this->pipeline()->run($this->connectionName, $query, ExecutionPolicy::MappedWrite, function (Connection $connection) use ($class, $query): array {
            $rows = (new SelectAdapter)->select($connection, $query, false, PDO::FETCH_NAMED);
            if (! $connection->pretending()) {
                $connection->recordsHaveBeenModified(true);
            }
            $mapped = [];
            foreach ($rows as $row) {
                $mapped[] = $this->mapRow($class, $query->sqlId, $row);
            }

            return $mapped;
        });
    }

    /** @param array<string, mixed>|SqlParameters $parameters
     * @return list<mixed>
     */
    private function mappedRows(string $id, #[\SensitiveParameter] array|SqlParameters $parameters): array
    {
        $query = $this->compileFor(QueryOperation::Select, $id, $parameters);

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
        $policy = $query->kind === QueryKind::Read ? ExecutionPolicy::Read : ExecutionPolicy::Locking;

        return $this->pipeline()->run($this->connectionName, $query, $policy,
            fn (Connection $connection): array => (new SelectAdapter)->select($connection, $query, $policy->readsPdo(), $fetchMode));
    }

    /** @param array<string, mixed>|SqlParameters $parameters */
    public function executeExpecting(string $id, #[\SensitiveParameter] array|SqlParameters $parameters, int $expected): int
    {
        if ($expected < 0) {
            throw new SqlException(ErrorCode::ParameterType, sqlId: $id);
        }
        $query = $this->compileFor(QueryOperation::ExecuteExpecting, $id, $parameters);
        $result = $this->write($query, $expected);
        if (! is_int($result)) {
            throw new SqlException(ErrorCode::Execution, sqlId: $id);
        }

        return $result;
    }

    /** @return int|list<stdClass> */
    private function write(#[\SensitiveParameter] CompiledQuery $query, ?int $expected = null): int|array
    {
        $policy = $expected === null ? ExecutionPolicy::Write : ExecutionPolicy::CheckedWrite;

        return $this->pipeline()->run($this->connectionName, $query, $policy, function (Connection $connection) use ($query, $expected): int|array {
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
        });
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

    /** @param array<string, mixed>|SqlParameters $parameters */
    private function compileFor(QueryOperation $operation, string $id, #[\SensitiveParameter] array|SqlParameters $parameters): CompiledQuery
    {
        $query = $this->compile($id, $parameters);
        if (! $operation->allows($query->kind)) {
            throw new SqlException(ErrorCode::QueryKind, sqlId: $id);
        }

        return $query;
    }

    private function pipeline(): ExecutionPipeline
    {
        return new ExecutionPipeline($this->connections, $this->options, $this->planAdvice, $this->observer);
    }

    /** @return array{connection:?string} */
    public function __debugInfo(): array
    {
        return ['connection' => $this->connectionName];
    }
}
