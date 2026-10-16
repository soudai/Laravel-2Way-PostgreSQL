<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Illuminate\Database\Connection;
use PDO;
use stdClass;
use Throwable;
use WeakMap;

final readonly class ConnectionGuard
{
    /** @var WeakMap<PDO, true> */
    private WeakMap $verified;

    public function __construct()
    {
        $this->verified = new WeakMap;
    }

    public function check(#[\SensitiveParameter] Connection $connection, bool $read = false, int $minimumPostgres = 18): void
    {
        try {
            if ($connection->getDriverName() !== 'pgsql') {
                throw new SqlException(ErrorCode::Connection);
            }
            $pdo = $read ? $connection->getReadPdo() : $connection->getPdo();
            if ($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) !== false || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
                throw new SqlException(ErrorCode::Connection);
            }
            // SET / SET LOCAL can change safety settings without replacing the PDO.
            $settings = $connection->selectOne("SELECT current_setting('server_encoding') AS encoding, current_setting('standard_conforming_strings') AS strings, current_setting('server_version_num') AS version", [], $read);
            if (! $settings instanceof stdClass || $settings->encoding !== 'UTF8' || $settings->strings !== 'on'
                || ! is_string($settings->version) || ! ctype_digit($settings->version)
                || ! in_array($minimumPostgres, [18, 19], true)
                || (int) $settings->version < $minimumPostgres * 10000 || (int) $settings->version >= 200000) {
                throw new SqlException(ErrorCode::Connection);
            }
            $verified = $this->verified;
            $verified[$pdo] = true;
        } catch (Throwable) {
            throw new SqlException(ErrorCode::Connection);
        }
    }

    /** @return array{verifiedSessions:int} */
    public function __debugInfo(): array
    {
        return ['verifiedSessions' => count($this->verified)];
    }
}
