<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Codegen\Definition;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Types\TypeCatalog;
use Hft\TwoWaySql\Types\TypeDescriptor;
use ReflectionNamedType;
use ReflectionType;

final class TypeNames
{
    public static function identifier(string $name): bool
    {
        return preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) === 1;
    }

    public static function className(string $name): bool
    {
        return $name !== '' && array_all(explode('\\', $name), self::identifier(...));
    }

    public static function generatedClassName(string $name): bool
    {
        // TOKEN_PARSE catches syntax keywords; these type names fail only during compilation.
        return self::identifier($name) && ! in_array(strtolower($name), [
            'int', 'float', 'bool', 'string', 'null', 'true', 'false', 'void', 'never',
            'mixed', 'object', 'iterable', 'array', 'callable', 'self', 'parent', 'static',
        ], true);
    }

    public static function parameterName(string $name): bool
    {
        return self::identifier($name) && ! in_array($name, [
            'this', 'GLOBALS', '_GET', '_POST', '_COOKIE', '_SERVER', '_FILES', '_ENV', '_REQUEST', '_SESSION',
        ], true);
    }

    public static function render(?ReflectionType $type): string
    {
        if (! $type instanceof ReflectionNamedType || in_array($type->getName(), ['self', 'static', 'parent', 'void', 'never'], true)) {
            throw new SqlException(ErrorCode::Generation);
        }
        $name = ($type->isBuiltin() ? '' : '\\').$type->getName();

        return ($type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' : '').$name;
    }

    /** @param array<string, mixed> $spec */
    public static function parameter(array $spec, TypeCatalog $types = new TypeCatalog): ?string
    {
        if (is_string($spec['type'] ?? null) && str_starts_with($spec['type'], 'codec:')) {
            $descriptor = $types->find(substr($spec['type'], 6));
            if ($descriptor !== null) {
                if (($spec['nullable'] ?? false) && ! $descriptor->nullable) {
                    throw new SqlException(ErrorCode::Generation);
                }

                return (($spec['nullable'] ?? false) ? '?' : '').TypeDescriptor::phpType($descriptor->inputType);
            }
        }
        $type = match ($spec['type'] ?? null) {
            'int64' => 'int', 'text', 'uuid', 'decimal' => 'string', 'bool' => 'bool',
            default => null,
        };

        return $type === null ? null : (($spec['nullable'] ?? false) ? '?' : '').$type;
    }
}
