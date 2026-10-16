<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Compatibility;

use Hft\TwoWaySql\Compiler\CompiledQuery;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Illuminate\Database\Connection;
use ReflectionMethod;

final class SelectAdapter
{
    /** @return list<mixed> */
    public function select(#[\SensitiveParameter] Connection $connection, #[\SensitiveParameter] CompiledQuery $query, bool $read, int $mode): array
    {
        $select = new ReflectionMethod($connection, 'select');
        if ($select->getNumberOfParameters() >= 4) {
            $rows = $select->invoke($connection, $query->sql(), $query->bindings(), $read, [$mode]);
            if (! is_array($rows)) {
                throw new SqlException(ErrorCode::Connection);
            }

            return array_values($rows);
        }

        return Laravel12Select::fetch($connection, $query->sql(), $query->bindings(), $read, $mode);
    }
}
