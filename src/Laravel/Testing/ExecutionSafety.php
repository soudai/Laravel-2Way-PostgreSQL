<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Testing;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Laravel\ConnectionResolver;
use Illuminate\Database\Connection;

final readonly class ExecutionSafety
{
    /** @param array<array-key,mixed> $settings */
    public function __construct(private ConnectionResolver $connections, #[\SensitiveParameter] private array $settings) {}

    /** Opens one verified writer transaction. The caller must roll it back. */
    public function begin(?string $name): Connection
    {
        $nonce = $this->settings['nonce'] ?? null;
        if ($name === null || $name === '' || $name !== ($this->settings['connection'] ?? null)
            || ! is_string($nonce) || ! preg_match('/\A[a-f0-9]{32,128}\z/D', $nonce)
            || ! is_array($this->settings['allowed_hosts'] ?? null) || ! is_array($this->settings['allowed_databases'] ?? null)) {
            throw new SqlException(ErrorCode::Connection);
        }
        $connection = $this->connections->resolve($name);
        $config = $connection->getConfig();
        if (! is_array($config)) {
            throw new SqlException(ErrorCode::Connection);
        }
        $host = $config['host'] ?? null;
        $database = $config['database'] ?? null;
        if (! is_string($host) || ! is_string($database) || ! in_array($host, $this->settings['allowed_hosts'], true)
            || ! in_array($database, $this->settings['allowed_databases'], true) || preg_match('/prod|production|live/i', $database)
            || ! preg_match('/(?:\A|_)(?:test|testing)(?:_|\z)/i', $database)
            || ! empty($config['read']) || ! empty($config['write']) || ! empty($config['url'])
            || isset($config['connect_via_database']) || isset($config['connect_via_port'])
            || $connection->transactionLevel() !== 0 || $connection->pretending()) {
            throw new SqlException(ErrorCode::Connection);
        }
        try {
            $connection->beginTransaction();
            if ($connection->transactionLevel() !== 1 || ! $connection->getPdo()->inTransaction()) {
                throw new SqlException(ErrorCode::Connection);
            }
            $rows = $connection->select('SELECT current_database() AS database, nonce FROM public._twoway_case_marker', [], false);
            if (count($rows) !== 1 || ! $rows[0] instanceof \stdClass || ($rows[0]->database ?? null) !== $database
                || ! is_string($rows[0]->nonce ?? null) || ! hash_equals($nonce, $rows[0]->nonce)) {
                throw new SqlException(ErrorCode::Connection);
            }
            // Inside the writer transaction, read APIs cannot route to an unverified reader PDO.
            $this->connections->validate($connection);

            return $connection;
        } catch (\Throwable) {
            if ($connection->transactionLevel() > 0) {
                try {
                    $connection->rollBack(0);
                } catch (\Throwable) {
                }
            }
            throw new SqlException(ErrorCode::Connection);
        }
    }

    /** @return array<string,never> */
    public function __debugInfo(): array
    {
        return [];
    }
}
