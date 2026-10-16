<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Parameter\Codec;

use BackedEnum;
use Hft\TwoWaySql\Contracts\ParameterCodec;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

final readonly class BackedEnumCodec implements ParameterCodec
{
    /** @var class-string<BackedEnum> */
    private string $class;

    /** @param string $class Trusted provider declaration, never a parameter value. */
    public function __construct(string $class)
    {
        if (! is_a($class, BackedEnum::class, true)) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        $this->class = $class;
    }

    public function encode(#[\SensitiveParameter] mixed $value): int|string
    {
        if (! $value instanceof $this->class) {
            throw new SqlException(ErrorCode::ParameterType);
        }

        return $value->value;
    }
}
