<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Facades;

use Hft\TwoWaySql\Laravel\TwoWaySqlManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Hft\TwoWaySql\Compiler\CompiledQuery compile(string $id, array<string, mixed>|\Hft\TwoWaySql\Contracts\SqlParameters $parameters)
 * @method static list<\stdClass> select(string $id, array<string, mixed>|\Hft\TwoWaySql\Contracts\SqlParameters $parameters)
 * @method static \stdClass one(string $id, array<string, mixed>|\Hft\TwoWaySql\Contracts\SqlParameters $parameters)
 * @method static ?\stdClass oneOrNull(string $id, array<string, mixed>|\Hft\TwoWaySql\Contracts\SqlParameters $parameters)
 * @method static mixed scalar(string $id, array<string, mixed>|\Hft\TwoWaySql\Contracts\SqlParameters $parameters)
 * @method static int execute(string $id, array<string, mixed>|\Hft\TwoWaySql\Contracts\SqlParameters $parameters)
 * @method static list<\stdClass> returning(string $id, array<string, mixed>|\Hft\TwoWaySql\Contracts\SqlParameters $parameters)
 * @method static list<object> selectAs(class-string $class, string $id, array<string, mixed>|\Hft\TwoWaySql\Contracts\SqlParameters $parameters)
 * @method static object oneAs(class-string $class, string $id, array<string, mixed>|\Hft\TwoWaySql\Contracts\SqlParameters $parameters)
 * @method static ?object oneOrNullAs(class-string $class, string $id, array<string, mixed>|\Hft\TwoWaySql\Contracts\SqlParameters $parameters)
 * @method static list<object> returningAs(class-string $class, string $id, array<string, mixed>|\Hft\TwoWaySql\Contracts\SqlParameters $parameters)
 * @method static int executeExpecting(string $id, array<string, mixed>|\Hft\TwoWaySql\Contracts\SqlParameters $parameters, int $expected)
 * @method static \Hft\TwoWaySql\Laravel\Batch\BatchResult batch(string $id, iterable<mixed> $parameterSets, \Hft\TwoWaySql\Laravel\Batch\BatchOptions $options)
 * @method static \Hft\TwoWaySql\Laravel\Pagination\PageResult paginate(\Hft\TwoWaySql\Laravel\Pagination\PageQuery $query, array<string, mixed>|\Hft\TwoWaySql\Contracts\SqlParameters $parameters, \Hft\TwoWaySql\Laravel\Pagination\PageRequest $page)
 * @method static \Hft\TwoWaySql\Laravel\Executor\Executor connection(?string $name = null)
 * @method static \Hft\TwoWaySql\Laravel\Executor\Executor withOptions(\Hft\TwoWaySql\Laravel\Executor\ExecutionOptions $options)
 * @method static int each(string $id, array<string, mixed>|\Hft\TwoWaySql\Contracts\SqlParameters $parameters, callable $consume, int $fetchSize = 1000)
 * @method static int eachAs(class-string $class, string $id, array<string, mixed>|\Hft\TwoWaySql\Contracts\SqlParameters $parameters, callable $consume, int $fetchSize = 1000)
 */
final class TwoWaySql extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TwoWaySqlManager::class;
    }
}
