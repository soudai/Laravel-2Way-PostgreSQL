<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Parameter;

final readonly class JsonValue
{
    private function __construct() {}

    public static function null(): self
    {
        return new self;
    }
}
