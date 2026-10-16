<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Codegen\Definition;

final readonly class DaoDefinition
{
    public string $schemaHash;

    /** @param list<MethodDefinition> $methods */
    public function __construct(public string $interface, public array $methods)
    {
        $this->schemaHash = hash('sha256', json_encode($this->data(), JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return ['version' => 1, 'interface' => $this->interface, 'methods' => array_map(static fn (MethodDefinition $method): array => $method->toArray(), $this->methods)];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data() + ['schema_hash' => $this->schemaHash];
    }
}
