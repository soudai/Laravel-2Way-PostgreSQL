<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Types;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

final readonly class TypeCatalog
{
    /** @var array<string,TypeDescriptor> */
    private array $types;

    /** @param list<TypeDescriptor> $types */
    public function __construct(array $types = [])
    {
        if (count($types) > 256) {
            throw new SqlException(ErrorCode::Limit);
        }
        $byId = [];
        foreach ($types as $type) {
            if (isset($byId[$type->id])) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $byId[$type->id] = $type;
        }
        ksort($byId);
        $this->types = $byId;
    }

    public function find(string $id): ?TypeDescriptor
    {
        return $this->types[$id] ?? null;
    }

    public function get(string $id): TypeDescriptor
    {
        return $this->find($id) ?? throw new SqlException(ErrorCode::ParameterType);
    }

    /** @return array<string,array{input_type:string,result_type:string,postgres_type:string,nullable:bool,input_doc?:string,result_doc?:string}> */
    public function toArray(): array
    {
        return array_map(static fn (TypeDescriptor $type): array => $type->toArray(), $this->types);
    }

    /** @param array<array-key,mixed> $data */
    public static function fromArray(array $data): self
    {
        $types = [];
        foreach ($data as $id => $spec) {
            if (! is_string($id) || ! is_array($spec) || array_diff(array_keys($spec), ['input_type', 'result_type', 'postgres_type', 'nullable', 'input_doc', 'result_doc']) !== []
                || ! is_string($spec['input_type'] ?? null) || ! is_string($spec['result_type'] ?? null) || ! is_string($spec['postgres_type'] ?? null) || ! is_bool($spec['nullable'] ?? null)) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            foreach (['input_doc', 'result_doc'] as $key) {
                if (array_key_exists($key, $spec) && ! is_string($spec[$key])) {
                    throw new SqlException(ErrorCode::ParameterType);
                }
            }
            $inputDoc = $spec['input_doc'] ?? null;
            $resultDoc = $spec['result_doc'] ?? null;
            if (($inputDoc !== null && ! is_string($inputDoc)) || ($resultDoc !== null && ! is_string($resultDoc))) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $types[] = new TypeDescriptor($id, $spec['input_type'], $spec['result_type'], $spec['postgres_type'], $spec['nullable'], $inputDoc, $resultDoc);
        }

        return new self($types);
    }
}
