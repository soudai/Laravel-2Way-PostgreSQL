<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Mapping;

use BackedEnum;
use DateTimeImmutable;
use Hft\TwoWaySql\Attributes\SqlType;
use Hft\TwoWaySql\Contracts\ResultMapper;
use Hft\TwoWaySql\Exception\MappingException;
use Hft\TwoWaySql\Parameter\Range\DateRange;
use Hft\TwoWaySql\Parameter\Range\TstzRange;
use Hft\TwoWaySql\Parameter\Registry\CodecRegistry;
use Hft\TwoWaySql\Types\TypeDescriptor;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionParameter;
use stdClass;
use Throwable;

final readonly class DtoMapper implements ResultMapper
{
    /** @param array<class-string, ColumnMap> $maps */
    public function __construct(private array $maps = [], private ?CodecRegistry $codecs = null) {}

    public function validate(string $class): void
    {
        try {
            $reflection = new ReflectionClass($class);
            $constructor = $reflection->getConstructor();
            if (! $reflection->isInstantiable() || $reflection->isSubclassOf('Illuminate\\Database\\Eloquent\\Model') || $class === 'Illuminate\\Database\\Eloquent\\Model' || $constructor === null || ! $constructor->isPublic()) {
                throw new MappingException;
            }
            $map = $this->maps[$class] ?? new ColumnMap;
            $arguments = [];
            $columns = [];
            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();
                $name = $parameter->getName();
                if (! $type instanceof ReflectionNamedType || $parameter->isVariadic() || $parameter->isPassedByReference()) {
                    throw new MappingException;
                }
                $descriptor = $this->descriptor($parameter);
                if ($descriptor !== null && (isset($map->converters[$name]) || $type->getName() !== $descriptor->resultType || ($type->allowsNull() && ! $descriptor->nullable))) {
                    throw new MappingException;
                }
                if ($descriptor === null && ! isset($map->converters[$name]) && ! in_array($type->getName(), ['int', 'string', 'bool', DateTimeImmutable::class, DateRange::class, TstzRange::class], true) && ! is_a($type->getName(), BackedEnum::class, true)) {
                    throw new MappingException;
                }
                $column = $map->columns[$name] ?? $name;
                if (in_array($column, $columns, true)) {
                    throw new MappingException;
                }
                $columns[] = $column;
                $arguments[] = $name;
            }
            if (array_diff(array_keys($map->columns), $arguments) !== [] || array_diff(array_keys($map->converters), $arguments) !== []) {
                throw new MappingException;
            }
        } catch (Throwable) {
            throw new MappingException;
        }
    }

    public function map(string $class, #[\SensitiveParameter] stdClass $row): object
    {
        $this->validate($class);
        try {
            $reflection = new ReflectionClass($class);
            $constructor = $reflection->getConstructor();
            if ($constructor === null) {
                throw new MappingException;
            }
            $map = $this->maps[$class] ?? new ColumnMap;
            $values = get_object_vars($row);
            $arguments = [];
            foreach ($constructor->getParameters() as $parameter) {
                $name = $parameter->getName();
                $column = $map->columns[$name] ?? $name;
                $type = $parameter->getType();
                if (! $type instanceof ReflectionNamedType || ! array_key_exists($column, $values)) {
                    throw new MappingException;
                }
                $value = $values[$column];
                unset($values[$column]);
                if ($value === null) {
                    if (! $type->allowsNull()) {
                        throw new MappingException;
                    }
                    $arguments[] = null;

                    continue;
                }
                $descriptor = $this->descriptor($parameter);
                $converted = $descriptor === null
                    ? (isset($map->converters[$name]) ? $map->converters[$name]->convert($value) : $this->convert($type->getName(), $value))
                    : $this->codecs?->converter($descriptor->id)->convert($value);
                if ($descriptor !== null && ! TypeDescriptor::matches($descriptor->resultType, $converted)) {
                    throw new MappingException;
                }
                if (! $this->matches($type, $converted)) {
                    throw new MappingException;
                }
                $arguments[] = $converted;
            }
            if ($values !== []) {
                throw new MappingException;
            }

            return $reflection->newInstanceArgs($arguments);
        } catch (Throwable) {
            // Never retain a row, constructor exception or converter exception.
            throw new MappingException;
        }
    }

    private function descriptor(ReflectionParameter $parameter): ?TypeDescriptor
    {
        $attributes = $parameter->getAttributes(SqlType::class);
        if ($attributes === []) {
            return null;
        }
        if (count($attributes) !== 1 || $this->codecs === null) {
            throw new MappingException;
        }

        return $this->codecs->catalog()->get($attributes[0]->newInstance()->id);
    }

    private function convert(string $type, #[\SensitiveParameter] mixed $value): mixed
    {
        if (is_a($type, BackedEnum::class, true)) {
            $backing = (new ReflectionEnum($type))->getBackingType();
            $value = $backing?->getName() === 'int' ? ScalarConversion::integer($value) : (is_string($value) ? $value : throw new MappingException);

            return $type::tryFrom($value) ?? throw new MappingException;
        }

        return match ($type) {
            'int' => ScalarConversion::integer($value),
            'string' => is_string($value) ? $value : throw new MappingException,
            'bool' => ScalarConversion::boolean($value),
            DateTimeImmutable::class => ScalarConversion::datetime($value),
            DateRange::class => is_string($value) ? DateRange::fromPostgres($value) : throw new MappingException,
            TstzRange::class => is_string($value) ? TstzRange::fromPostgres($value) : throw new MappingException,
            default => throw new MappingException,
        };
    }

    private function matches(ReflectionNamedType $type, #[\SensitiveParameter] mixed $value): bool
    {
        if ($value === null) {
            return $type->allowsNull();
        }
        $name = $type->getName();

        return match ($name) {
            'int' => is_int($value), 'string' => is_string($value), 'bool' => is_bool($value), 'float' => is_float($value), 'array' => is_array($value),
            default => is_object($value) && is_a($value, $name),
        };
    }
}
