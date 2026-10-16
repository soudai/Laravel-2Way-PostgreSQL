<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Contracts;

interface SqlParameters
{
    /** @return array<string, mixed> */
    public function toSqlParameters(): array;
}
