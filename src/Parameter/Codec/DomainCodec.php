<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Parameter\Codec;

use Closure;
use Hft\TwoWaySql\Contracts\ParameterCodec;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

/** @template T of object */
final readonly class DomainCodec implements ParameterCodec
{
    /** @param class-string<T> $class
     * @param  Closure(T): (int|string|null)  $encoder  Trusted, deterministic application code.
     */
    public function __construct(private string $class, private Closure $encoder)
    {
        if (! class_exists($class)) {
            throw new SqlException(ErrorCode::ParameterType);
        }
    }

    public function encode(#[\SensitiveParameter] mixed $value): int|string|null
    {
        if (! $value instanceof $this->class) {
            throw new SqlException(ErrorCode::ParameterType);
        }

        return ($this->encoder)($value);
    }
}
