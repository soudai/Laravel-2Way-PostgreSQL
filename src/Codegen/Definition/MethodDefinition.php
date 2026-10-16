<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Codegen\Definition;

final readonly class MethodDefinition
{
    /** @param list<ArgumentDefinition> $arguments */
    public function __construct(public string $name, public string $mode, public QueryContract $query, public array $arguments, public ?string $parameterClass, public ?string $resultClass, public string $returnType, public string $returnDoc) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['name' => $this->name, 'mode' => $this->mode, 'query' => $this->query->toArray(), 'arguments' => array_map(static fn (ArgumentDefinition $argument): array => $argument->toArray(), $this->arguments), 'parameter_class' => $this->parameterClass, 'result_class' => $this->resultClass, 'return_type' => $this->returnType, 'return_doc' => $this->returnDoc];
    }
}
