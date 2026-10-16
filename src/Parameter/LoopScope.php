<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Parameter;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Template\Source\SourceSpan;

final readonly class LoopScope
{
    public function fields(ParameterSchema $schema, string $collection, SourceSpan $span): ParameterSchema
    {
        $spec = $schema->definitions[$collection] ?? null;
        if ($spec === null) {
            throw new SqlException(ErrorCode::ParameterMissing, $span, parameterPath: $collection);
        }
        if ($spec['type'] !== 'list<record>' || ! isset($spec['fields'])) {
            throw new SqlException(ErrorCode::ParameterType, $span);
        }

        return new ParameterSchema($spec['fields']);
    }

    public function extend(ParameterSchema $schema, string $variable, string $collection, SourceSpan $span, bool $nested = false): ParameterSchema
    {
        if ($variable === 'loop') {
            throw new SqlException(ErrorCode::Syntax, $span);
        }
        foreach (array_keys($schema->definitions) as $path) {
            if ($path === $variable || str_starts_with($path, $variable.'.')) {
                throw new SqlException(ErrorCode::Syntax, $span);
            }
            if (! $nested && ($path === 'loop' || str_starts_with($path, 'loop.'))) {
                throw new SqlException(ErrorCode::Syntax, $span);
            }
        }
        $definitions = $schema->definitions;
        foreach ($this->fields($schema, $collection, $span)->definitions as $path => $spec) {
            $definitions[$variable.'.'.$path] = $spec;
        }
        if (isset($definitions['loop'])) {
            throw new SqlException(ErrorCode::Syntax, $span);
        }
        foreach (['loop.index' => 'int64', 'loop.first' => 'bool', 'loop.last' => 'bool'] as $path => $type) {
            $definitions[$path] = ['type' => $type];
        }

        return new ParameterSchema($definitions);
    }
}
