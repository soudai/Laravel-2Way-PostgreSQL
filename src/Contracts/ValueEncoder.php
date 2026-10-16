<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Contracts;

/** @phpstan-import-type Spec from \Hft\TwoWaySql\Parameter\ParameterSchema */
interface ValueEncoder
{
    /** @param Spec $spec */
    public function encode(#[\SensitiveParameter] mixed $value, array $spec): int|string|null;
}
