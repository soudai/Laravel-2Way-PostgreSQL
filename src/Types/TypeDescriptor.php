<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Types;

use Hft\TwoWaySql\Codegen\Definition\TypeNames;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

/** Metadata only: construction never invokes application conversion code. */
final readonly class TypeDescriptor
{
    public function __construct(public string $id, public string $inputType, public string $resultType, public string $postgresType, public bool $nullable = false, public ?string $inputDoc = null, public ?string $resultDoc = null)
    {
        if (! preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $id) || ! self::validType($inputType) || ! self::validType($resultType)
            || ! self::validDoc($inputType, $inputDoc) || ! self::validDoc($resultType, $resultDoc)
            || ! in_array($postgresType, ['int2', 'int4', 'int8', 'text', 'varchar', 'bool', 'float4', 'float8', 'numeric', 'uuid', 'date', 'timestamp', 'timestamptz', 'json', 'jsonb', 'daterange', 'tstzrange', 'datemultirange', 'tstzmultirange', 'int8[]', 'text[]', 'uuid[]', 'bool[]', 'numeric[]'], true)) {
            throw new SqlException(ErrorCode::ParameterType);
        }
    }

    private static function validDoc(string $type, ?string $doc): bool
    {
        return $doc === null || ($type === 'array' && preg_match('/\Alist<(?:int|string|bool)(?:\|null)?>\z/D', $doc) === 1);
    }

    private static function validType(string $type): bool
    {
        return in_array($type, ['int', 'string', 'bool', 'float', 'array'], true)
            || (TypeNames::className($type) && array_all(explode('\\', $type), TypeNames::generatedClassName(...)));
    }

    public static function phpType(string $type): string
    {
        return in_array($type, ['int', 'string', 'bool', 'float', 'array'], true) ? $type : '\\'.$type;
    }

    public static function matches(string $type, #[\SensitiveParameter] mixed $value): bool
    {
        return match ($type) {
            'int' => is_int($value), 'string' => is_string($value), 'bool' => is_bool($value),
            'float' => is_float($value), 'array' => is_array($value),
            default => is_object($value) && is_a($value, $type),
        };
    }

    /** @return array{input_type:string,result_type:string,postgres_type:string,nullable:bool,input_doc?:string,result_doc?:string} */
    public function toArray(): array
    {
        $data = ['input_type' => $this->inputType, 'result_type' => $this->resultType, 'postgres_type' => $this->postgresType, 'nullable' => $this->nullable];
        if ($this->inputDoc !== null) {
            $data['input_doc'] = $this->inputDoc;
        }
        if ($this->resultDoc !== null) {
            $data['result_doc'] = $this->resultDoc;
        }

        return $data;
    }
}
