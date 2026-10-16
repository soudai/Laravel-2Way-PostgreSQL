<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Parameter\Registry;

use Hft\TwoWaySql\Contracts\ValueEncoder;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Parameter\Codec\BuiltinEncoder;
use Hft\TwoWaySql\Parameter\ParameterSchema;
use Hft\TwoWaySql\Types\TypeDescriptor;

final readonly class RegistryEncoder implements ValueEncoder
{
    private BuiltinEncoder $builtin;

    public function __construct(private CodecRegistry $registry, private int $maxBytes = 8388608, int $maxArrayItems = 10000)
    {
        $this->builtin = new BuiltinEncoder($maxBytes, $maxArrayItems);
    }

    public function encode(#[\SensitiveParameter] mixed $value, array $spec): int|string|null
    {
        if (! str_starts_with($spec['type'], 'codec:')) {
            return $this->builtin->encode($value, $spec);
        }
        $codec = $this->registry->get(substr($spec['type'], 6));
        try {
            $type = $this->registry->catalog()->find(substr($spec['type'], 6));
            if ($type !== null && ((($spec['nullable'] ?? false) && ! $type->nullable) || ($value !== null && ! TypeDescriptor::matches($type->inputType, $value)))) {
                throw new \RuntimeException;
            }
            $wire = $value === null ? null : $codec->encode($value);
            if (($type !== null && $value !== null && $wire === null) || ($wire === null && ! ($spec['nullable'] ?? false)) || (is_string($wire) && (! mb_check_encoding($wire, 'UTF-8') || str_contains($wire, "\0")))) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        if (strlen((string) $wire) > $this->maxBytes) {
            throw new SqlException(ErrorCode::Limit);
        }

        return $wire;
    }

    public function validateSchema(ParameterSchema $schema): void
    {
        $this->registry->validate($schema);
    }
}
