<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Codegen\Definition;

use Hft\TwoWaySql\Template\QueryDefinition;
use Hft\TwoWaySql\Types\TypeCatalog;

final readonly class QueryContract
{
    public string $id;

    public string $kind;

    public string $path;

    public string $grammar;

    public string $sourceHash;

    public string $definitionHash;

    public int $minimumPostgres;

    /** @var array<string, array<string, mixed>> */
    public array $parameters;

    public function __construct(QueryDefinition $query, public ResultSchema $result, public TypeCatalog $types = new TypeCatalog)
    {
        $this->id = $query->id;
        $this->kind = $query->kind->value;
        $this->path = $query->path;
        $this->grammar = $query->grammar;
        $this->sourceHash = $query->sourceHash;
        $this->definitionHash = $query->definitionHash;
        $this->minimumPostgres = $query->minimumPostgres;
        $this->parameters = $query->parameters->definitions;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = ['id' => $this->id, 'path' => $this->path, 'kind' => $this->kind, 'grammar' => $this->grammar, 'postgres_min_version' => $this->minimumPostgres, 'source_hash' => $this->sourceHash, 'definition_hash' => $this->definitionHash, 'parameters' => $this->parameters, 'columns' => $this->result->columns];
        if ($this->types->toArray() !== []) {
            $result['types'] = $this->types->toArray();
        }

        return $result;
    }
}
