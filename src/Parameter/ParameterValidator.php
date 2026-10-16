<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Parameter;

use Hft\TwoWaySql\Contracts\ValueEncoder;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Parameter\Codec\BuiltinEncoder;
use Hft\TwoWaySql\Parameter\Patch\PatchSchema;

final readonly class ParameterValidator
{
    public function __construct(private ValueEncoder $encoder = new BuiltinEncoder, private int $maxList = 10000, private int $maxTotalBytes = 33554432, private int $maxRecords = 1000) {}

    /** @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function validate(ParameterSchema $schema, #[\SensitiveParameter] array $parameters): array
    {
        $bytes = 0;

        return $this->validateSchema($schema, $parameters, $bytes);
    }

    /** @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function validateSchema(ParameterSchema $schema, #[\SensitiveParameter] array $parameters, int &$bytes): array
    {
        $values = $schema->validateStructure($parameters);
        foreach ($schema->definitions as $path => $spec) {
            $value = $values[$path];
            if ($spec['type'] === 'patch<record>') {
                $patch = new PatchSchema($spec['fields'] ?? []);
                foreach ($patch->values($value) as $field => $item) {
                    $this->count($this->encoder->encode($item, $patch->fields[$field]), $bytes);
                }
            } elseif ($value !== null && str_starts_with($spec['type'], 'list<')) {
                if (! is_array($value) || ! array_is_list($value)) {
                    throw new SqlException(ErrorCode::ParameterType, parameterPath: $path);
                }
                if (count($value) > ($spec['type'] === 'list<record>' ? $this->maxRecords : $this->maxList)) {
                    throw new SqlException(ErrorCode::Limit);
                }
                foreach ($value as $element) {
                    if ($spec['type'] === 'list<record>') {
                        if (! is_array($element) || ! isset($spec['fields'])) {
                            throw new SqlException(ErrorCode::ParameterType, parameterPath: $path);
                        }
                        $record = [];
                        foreach ($element as $key => $item) {
                            if (! is_string($key)) {
                                throw new SqlException(ErrorCode::ParameterType, parameterPath: $path);
                            }
                            $record[$key] = $item;
                        }
                        $this->validateSchema(new ParameterSchema($spec['fields']), $record, $bytes);
                    } else {
                        $this->count($this->encoder->encode($element, ['type' => substr($spec['type'], 5, -1)]), $bytes);
                    }
                }
            } else {
                $this->count($this->encoder->encode($value, $spec), $bytes);
            }
        }

        return $values;
    }

    private function count(#[\SensitiveParameter] int|string|null $wire, int &$bytes): void
    {
        $bytes += strlen((string) $wire);
        if ($bytes > $this->maxTotalBytes) {
            throw new SqlException(ErrorCode::Limit);
        }
    }
}
