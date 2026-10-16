<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Testing;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Parameter\ParameterSchema;

/** Converts JSON parameter containers while preserving jsonb object/array identity.
 * @phpstan-import-type Spec from ParameterSchema
 */
final class CaseParameters
{
    /** @param array<string,mixed> $parameters
     * @return array<string,mixed>
     */
    public function normalize(ParameterSchema $schema, #[\SensitiveParameter] array $parameters): array
    {
        foreach ($schema->definitions as $path => $spec) {
            $parameters = $this->adapt($parameters, explode('.', $path), $spec);
        }

        return $parameters;
    }

    /** @param array<string,mixed> $container
     * @param  non-empty-list<string>  $parts
     * @param  Spec  $spec
     * @return array<string,mixed>
     */
    private function adapt(#[\SensitiveParameter] array $container, array $parts, array $spec): array
    {
        $key = array_shift($parts);
        if (! array_key_exists($key, $container)) {
            return $container;
        }
        $value = $container[$key];
        if ($parts !== []) {
            if ($value instanceof \stdClass) {
                $value = $this->keys(get_object_vars($value));
            }
            if (is_array($value)) {
                $value = $this->adapt($this->keys($value), $parts, $spec);
            }
        } elseif ($spec['type'] === 'list<record>' && is_array($value)) {
            $fields = new ParameterSchema($spec['fields'] ?? []);
            foreach ($value as $index => $record) {
                if ($record instanceof \stdClass) {
                    $record = get_object_vars($record);
                }
                if (is_array($record)) {
                    $value[$index] = $this->normalize($fields, $this->keys($record));
                }
            }
        }
        $container[$key] = $value;

        return $container;
    }

    /** @param array<array-key,mixed> $input
     * @return array<string,mixed>
     */
    private function keys(#[\SensitiveParameter] array $input): array
    {
        $result = [];
        foreach ($input as $key => $value) {
            if (! is_string($key)) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
