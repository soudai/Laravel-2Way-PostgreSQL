<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Contracts;

use Illuminate\Database\Connection;

/** The execution layer only needs connection selection and safety validation. */
interface ExecutionConnection
{
    public function resolve(?string $name): Connection;

    public function validate(#[\SensitiveParameter] Connection $connection, bool $read = false, int $minimumPostgres = 18): void;
}
