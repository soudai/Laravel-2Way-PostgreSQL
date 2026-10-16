<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Contracts;

interface ParameterCodec
{
    public function encode(#[\SensitiveParameter] mixed $value): int|string|null;
}
