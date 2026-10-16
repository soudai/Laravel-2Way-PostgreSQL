<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Throwable;

final readonly class ConnectionResolver
{
    public function __construct(private DatabaseManager $database, private ConnectionGuard $guard = new ConnectionGuard) {}

    /** Returns Laravel's lazy connection without opening a PDO session. */
    public function resolve(?string $name): Connection
    {
        try {
            $connection = $this->database->connection($name);
            if ($connection->getDriverName() !== 'pgsql') {
                throw new SqlException(ErrorCode::Connection);
            }

            return $connection;
        } catch (Throwable) {
            throw new SqlException(ErrorCode::Connection);
        }
    }

    public function validate(#[\SensitiveParameter] Connection $connection, bool $read = false, int $minimumPostgres = 18): void
    {
        $this->guard->check($connection, $read, $minimumPostgres);
    }

    /** @return array<string, never> */
    public function __debugInfo(): array
    {
        return [];
    }
}
