<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Codegen;

use Hft\TwoWaySql\Compiler\CompiledQuery;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Laravel\ConnectionGuard;
use Illuminate\Database\Connection;

/** @internal Uses existing Laravel execution hooks; never instantiated.
 * @phpstan-import-type Column from \Hft\TwoWaySql\Codegen\Schema\MetadataDiff
 */
abstract class MetadataQuery extends Connection
{
    /** @return list<Column> */
    public static function inspect(#[\SensitiveParameter] Connection $connection, #[\SensitiveParameter] CompiledQuery $query): array
    {
        if ($connection->transactionLevel() < 1) {
            throw new SqlException(ErrorCode::TransactionRequired);
        }
        (new ConnectionGuard)->check($connection, false, $query->minimumPostgres);

        $columns = [];
        $connection->run($query->sql(), $query->bindings(), static function (string $sql, #[\SensitiveParameter] array $bindings) use ($connection, &$columns): array {
            $statement = $connection->getPdoForSelect(false)->prepare($sql);
            if ($statement === false) {
                throw new SqlException(ErrorCode::Execution);
            }
            $statement = $connection->prepared($statement);
            $connection->bindValues($statement, $connection->prepareBindings($bindings));
            $statement->execute();
            try {
                $count = $statement->columnCount();
                if ($count > 1000) {
                    throw new SqlException(ErrorCode::Limit);
                }
                $columns = [];
                for ($i = 0; $i < $count; $i++) {
                    $columns[] = self::column($statement->getColumnMeta($i));
                }

                return $columns;
            } finally {
                $statement->closeCursor();
            }
        });

        return $columns;
    }

    /** @return Column */
    private static function column(mixed $meta): array
    {
        if (! is_array($meta) || ! is_string($meta['name'] ?? null) || ! is_string($meta['native_type'] ?? null)) {
            throw new SqlException(ErrorCode::Generation);
        }
        $native = $meta['native_type'];
        $suggested = match ($native) {
            'int2', 'int4', 'int8' => 'int', 'bool' => 'bool', 'float4', 'float8' => 'float',
            'timestamp', 'timestamptz' => \DateTimeImmutable::class,
            'text', 'varchar', 'bpchar', 'numeric', 'uuid', 'date', 'json', 'jsonb' => 'string',
            default => null,
        };

        return ['alias' => $meta['name'], 'native_type' => $native, 'suggested_php_type' => $suggested, 'nullable' => null];
    }
}
