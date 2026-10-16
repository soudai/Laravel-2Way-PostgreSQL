<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Attributes;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class SqlType
{
    public function __construct(public string $id) {}
}
