<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel;

use Hft\TwoWaySql\Compiler\CompiledQuery;
use Hft\TwoWaySql\Contracts\Compiler;
use Hft\TwoWaySql\Contracts\ExecutionObserver;
use Hft\TwoWaySql\Contracts\ResultMapper;
use Hft\TwoWaySql\Contracts\SqlParameters;
use Hft\TwoWaySql\Laravel\Batch\BatchOptions;
use Hft\TwoWaySql\Laravel\Batch\BatchResult;
use Hft\TwoWaySql\Laravel\Executor\ExecutionOptions;
use Hft\TwoWaySql\Laravel\Executor\Executor;
use Hft\TwoWaySql\Laravel\Executor\PlanAdviceRegistry;
use Hft\TwoWaySql\Laravel\Pagination\PageQuery;
use Hft\TwoWaySql\Laravel\Pagination\PageRequest;
use Hft\TwoWaySql\Laravel\Pagination\PageResult;
use Hft\TwoWaySql\Mapping\DtoMapper;
use Hft\TwoWaySql\Parameter\ParameterInput;

final readonly class TwoWaySqlManager
{
    public function __construct(private Compiler $compiler, private ConnectionResolver $connections, private ResultMapper $mapper = new DtoMapper, private ?ExecutionObserver $observer = null, private PlanAdviceRegistry $planAdvice = new PlanAdviceRegistry) {}

    public function connection(?string $name = null): Executor
    {
        return new Executor($this->compiler, $this->connections, $name, $this->mapper, $this->observer, planAdvice: $this->planAdvice);
    }

    public function withOptions(ExecutionOptions $options): Executor
    {
        return $this->connection()->withOptions($options);
    }

    /** @param array<string, mixed>|SqlParameters $parameters
     * @param  callable(\stdClass):mixed  $consume
     */
    public function each(string $id, #[\SensitiveParameter] array|SqlParameters $parameters, #[\SensitiveParameter] callable $consume, int $fetchSize = 1000): int
    {
        return $this->connection()->each($id, $parameters, $consume, $fetchSize);
    }

    /** @template T of object
     * @param  class-string<T>  $class
     * @param  array<string, mixed>|SqlParameters  $parameters
     * @param  callable(T):mixed  $consume
     */
    public function eachAs(string $class, string $id, #[\SensitiveParameter] array|SqlParameters $parameters, #[\SensitiveParameter] callable $consume, int $fetchSize = 1000): int
    {
        return $this->connection()->eachAs($class, $id, $parameters, $consume, $fetchSize);
    }

    /** @param array<string, mixed>|SqlParameters $parameters */
    public function compile(string $id, #[\SensitiveParameter] array|SqlParameters $parameters): CompiledQuery
    {
        return $this->compiler->compile($id, ParameterInput::normalize($parameters));
    }

    /** @param array<string, mixed>|SqlParameters $parameters
     * @return list<\stdClass>
     */
    public function select(string $id, #[\SensitiveParameter] array|SqlParameters $parameters): array
    {
        return $this->connection()->select($id, $parameters);
    }

    /** @param array<string, mixed>|SqlParameters $parameters */
    public function one(string $id, #[\SensitiveParameter] array|SqlParameters $parameters): \stdClass
    {
        return $this->connection()->one($id, $parameters);
    }

    /** @param array<string, mixed>|SqlParameters $parameters */
    public function oneOrNull(string $id, #[\SensitiveParameter] array|SqlParameters $parameters): ?\stdClass
    {
        return $this->connection()->oneOrNull($id, $parameters);
    }

    /** @param array<string, mixed>|SqlParameters $parameters */
    public function scalar(string $id, #[\SensitiveParameter] array|SqlParameters $parameters): mixed
    {
        return $this->connection()->scalar($id, $parameters);
    }

    /** @param array<string, mixed>|SqlParameters $parameters */
    public function execute(string $id, #[\SensitiveParameter] array|SqlParameters $parameters): int
    {
        return $this->connection()->execute($id, $parameters);
    }

    /** @param array<string, mixed>|SqlParameters $parameters
     * @return list<\stdClass>
     */
    public function returning(string $id, #[\SensitiveParameter] array|SqlParameters $parameters): array
    {
        return $this->connection()->returning($id, $parameters);
    }

    /** @template T of object
     * @param  class-string<T>  $class
     * @param  array<string, mixed>|SqlParameters  $parameters
     * @return list<T>
     */
    public function selectAs(string $class, string $id, #[\SensitiveParameter] array|SqlParameters $parameters): array
    {
        return $this->connection()->selectAs($class, $id, $parameters);
    }

    /** @template T of object
     * @param  class-string<T>  $class
     * @param  array<string, mixed>|SqlParameters  $parameters
     * @return T
     */
    public function oneAs(string $class, string $id, #[\SensitiveParameter] array|SqlParameters $parameters): object
    {
        return $this->connection()->oneAs($class, $id, $parameters);
    }

    /** @template T of object
     * @param  class-string<T>  $class
     * @param  array<string, mixed>|SqlParameters  $parameters
     * @return T|null
     */
    public function oneOrNullAs(string $class, string $id, #[\SensitiveParameter] array|SqlParameters $parameters): ?object
    {
        return $this->connection()->oneOrNullAs($class, $id, $parameters);
    }

    /** @template T of object
     * @param  class-string<T>  $class
     * @param  array<string, mixed>|SqlParameters  $parameters
     * @return list<T>
     */
    public function returningAs(string $class, string $id, #[\SensitiveParameter] array|SqlParameters $parameters): array
    {
        return $this->connection()->returningAs($class, $id, $parameters);
    }

    /** @param array<string, mixed>|SqlParameters $parameters */
    public function executeExpecting(string $id, #[\SensitiveParameter] array|SqlParameters $parameters, int $expected): int
    {
        return $this->connection()->executeExpecting($id, $parameters, $expected);
    }

    /** @param iterable<mixed> $parameterSets */
    public function batch(string $id, #[\SensitiveParameter] iterable $parameterSets, BatchOptions $options = new BatchOptions): BatchResult
    {
        return $this->connection()->batch($id, $parameterSets, $options);
    }

    /** @param array<string, mixed>|SqlParameters $parameters */
    public function paginate(PageQuery $query, #[\SensitiveParameter] array|SqlParameters $parameters, PageRequest $page): PageResult
    {
        return $this->connection()->paginate($query, $parameters, $page);
    }

    /** @return array<string, never> */
    public function __debugInfo(): array
    {
        return [];
    }
}
