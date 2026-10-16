<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Compiler;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

final readonly class Limits
{
    /** @var array<string, positive-int> */
    public array $values;

    /** @param array<array-key, mixed> $overrides */
    public function __construct(array $overrides = [])
    {
        $defaults = ['template_bytes' => 1048576, 'sql_bytes' => 8388608, 'tokens' => 100000, 'ast_nodes' => 100000, 'depth' => 32, 'list_items' => 10000, 'bindings' => 10000, 'parameter_bytes' => 8388608, 'total_parameter_bytes' => 33554432, 'loop_items' => 1000, 'loop_iterations' => 10000];
        foreach ($overrides as $key => $value) {
            if (! array_key_exists($key, $defaults) || ! is_int($value) || $value < 1 || $value >= PHP_INT_MAX) {
                throw new SqlException(ErrorCode::Limit);
            }
            $defaults[$key] = $value;
        }
        $this->values = $defaults;
    }

    /** @return positive-int */
    public function get(string $key): int
    {
        return $this->values[$key] ?? throw new SqlException(ErrorCode::Limit);
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->values, JSON_THROW_ON_ERROR));
    }
}
