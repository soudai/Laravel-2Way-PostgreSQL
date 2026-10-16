<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Compatibility;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Illuminate\Database\Connection;

/** @internal Accesses Laravel's protected execution hooks on the existing connection.
 * Never instantiated; the connection, PDO, events and transaction state stay owned by Laravel.
 */
abstract class Laravel12Select extends Connection
{
    /** @param list<int|string|null> $bindings
     * @return list<mixed>
     */
    public static function fetch(#[\SensitiveParameter] Connection $connection, #[\SensitiveParameter] string $sql, #[\SensitiveParameter] array $bindings, bool $read, int $mode): array
    {
        $rows = $connection->run($sql, $bindings, static function (string $sql, #[\SensitiveParameter] array $bindings) use ($connection, $read, $mode): array {
            if ($connection->pretending()) {
                return [];
            }
            $statement = $connection->getPdoForSelect($read)->prepare($sql);
            if ($statement === false) {
                throw new SqlException(ErrorCode::Connection);
            }
            $statement = $connection->prepared($statement);
            $connection->bindValues($statement, $connection->prepareBindings($bindings));
            $statement->execute();

            return $statement->fetchAll($mode);
        });
        if (! is_array($rows)) {
            throw new SqlException(ErrorCode::Connection);
        }

        return array_values($rows);
    }
}
