<?php

declare(strict_types=1);

namespace Hft\TwoWaySql;

final class RuntimeRequirements
{
    public static function supported(int $phpVersion = PHP_VERSION_ID, int $integerSize = PHP_INT_SIZE): bool
    {
        return $integerSize === 8
            && $phpVersion >= 80400
            && $phpVersion < 80600
            && extension_loaded('pdo_pgsql')
            && extension_loaded('mbstring');
    }
}
