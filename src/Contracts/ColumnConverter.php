<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Contracts;

interface ColumnConverter
{
    public function convert(#[\SensitiveParameter] mixed $value): mixed;
}
