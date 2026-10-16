<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\StaticAnalysis;

use Hft\TwoWaySql\Codegen\Definition\QueryContract;
use Hft\TwoWaySql\Parameter\ParameterSchema;

final readonly class CatalogQuery
{
    public ParameterSchema $parameters;

    public function __construct(public QueryContract $contract, public string $savedSourceHash)
    {
        $this->parameters = new ParameterSchema($contract->parameters);
    }

    public function stale(): bool
    {
        return $this->savedSourceHash !== $this->contract->sourceHash;
    }
}
