<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Executor;

use Hft\TwoWaySql\Compiler\CompiledQuery;
use Hft\TwoWaySql\Laravel\Compatibility\SelectAdapter;
use Illuminate\Database\Connection;

/** @internal The caller owns the Laravel transaction/savepoint. */
final class CursorRunner
{
    /** @param callable(mixed):mixed $consume */
    public function run(#[\SensitiveParameter] Connection $connection, #[\SensitiveParameter] CompiledQuery $query, int $fetchSize, int $mode, #[\SensitiveParameter] callable $consume): int
    {
        $name = 'twoway_cursor_'.bin2hex(random_bytes(12));
        $connection->select('DECLARE '.$name.' NO SCROLL CURSOR WITHOUT HOLD FOR '.$query->sql(), $query->bindings(), false);
        $failed = true;
        try {
            $fetch = new CompiledQuery($query->sqlId, 'FETCH FORWARD '.$fetchSize.' FROM '.$name, [], $query->kind, $query->hash, minimumPostgres: $query->minimumPostgres);
            $count = 0;
            do {
                $rows = (new SelectAdapter)->select($connection, $fetch, false, $mode);
                foreach ($rows as $row) {
                    $count++;
                    if ($consume($row) === false) {
                        $failed = false;

                        return $count;
                    }
                }
            } while (count($rows) === $fetchSize);

            $failed = false;

            return $count;
        } finally {
            try {
                $connection->select('CLOSE '.$name, [], false);
            } catch (\Throwable $error) {
                // An aborted transaction cannot CLOSE; the caller's rollback closes its cursor.
                if (! $failed) {
                    throw $error;
                }
            }
        }
    }
}
