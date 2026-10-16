<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Testing;

use Hft\TwoWaySql\Compiler\CompiledQuery;
use Hft\TwoWaySql\Contracts\Compiler;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

/** @internal Executes exactly the query already compiled and compared by the case runner. */
final readonly class PreparedCaseCompiler implements Compiler
{
    public function __construct(#[\SensitiveParameter] private CompiledQuery $query) {}

    public function compile(string $id, #[\SensitiveParameter] array $parameters): CompiledQuery
    {
        if ($id !== $this->query->sqlId || $parameters !== []) {
            throw new SqlException(ErrorCode::ParameterType);
        }

        return $this->query;
    }
}
