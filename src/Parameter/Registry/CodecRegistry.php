<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Parameter\Registry;

use Hft\TwoWaySql\Contracts\ColumnConverter;
use Hft\TwoWaySql\Contracts\ParameterCodec;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Parameter\ParameterSchema;
use Hft\TwoWaySql\Types\TypeCatalog;
use Hft\TwoWaySql\Types\TypeDescriptor;

final class CodecRegistry
{
    /** @var array<string,ParameterCodec> */
    private array $codecs = [];

    /** @var array<string,TypeDescriptor> */
    private array $types = [];

    /** @var array<string,ColumnConverter> */
    private array $converters = [];

    public function registerType(TypeDescriptor $type, ParameterCodec $input, ColumnConverter $result): void
    {
        $this->register($type->id, $input);
        $this->types[$type->id] = $type;
        $this->converters[$type->id] = $result;
    }

    public function catalog(): TypeCatalog
    {
        return new TypeCatalog(array_values($this->types));
    }

    public function converter(string $id): ColumnConverter
    {
        return $this->converters[$id] ?? throw new SqlException(ErrorCode::ParameterType);
    }

    public function register(string $id, ParameterCodec $codec): void
    {
        if (! preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $id) || isset($this->codecs[$id]) || count($this->codecs) >= 256) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        $this->codecs[$id] = $codec;
    }

    public function get(string $id): ParameterCodec
    {
        return $this->codecs[$id] ?? throw new SqlException(ErrorCode::ParameterType);
    }

    public function validate(ParameterSchema $schema): void
    {
        $this->fields($schema->definitions);
    }

    /** @param array<string,array<string,mixed>> $fields */
    private function fields(array $fields): void
    {
        foreach ($fields as $spec) {
            $type = $spec['type'] ?? null;
            if (is_string($type) && str_starts_with($type, 'codec:')) {
                $id = substr($type, 6);
                $this->get($id);
                if (isset($this->types[$id]) && ($spec['nullable'] ?? false) && ! $this->types[$id]->nullable) {
                    throw new SqlException(ErrorCode::ParameterType);
                }
            }
            $nested = $spec['fields'] ?? null;
            if (is_array($nested)) {
                foreach ($nested as $field) {
                    if (! is_array($field)) {
                        throw new SqlException(ErrorCode::ParameterType);
                    }
                    $safe = [];
                    foreach ($field as $name => $value) {
                        if (! is_string($name)) {
                            throw new SqlException(ErrorCode::ParameterType);
                        }
                        $safe[$name] = $value;
                    }
                    $this->fields(['field' => $safe]);
                }
            }
        }
    }
}
