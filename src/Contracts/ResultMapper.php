<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Contracts;

interface ResultMapper
{
    /** @param class-string $class */
    public function validate(string $class): void;

    /** @template T of object
     * @param  class-string<T>  $class
     * @return T
     */
    public function map(string $class, #[\SensitiveParameter] \stdClass $row): object;
}
