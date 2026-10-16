<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Codegen\Schema;

use Hft\TwoWaySql\Codegen\Dao\GeneratedBundle;
use Hft\TwoWaySql\Codegen\Dao\PhpSource;
use Hft\TwoWaySql\Codegen\Definition\QueryContract;
use Hft\TwoWaySql\Codegen\Definition\ResultSchema;
use Hft\TwoWaySql\Codegen\Definition\TypeNames;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Template\QueryDefinition;
use Hft\TwoWaySql\Types\TypeCatalog;

/** @phpstan-import-type Field from ParameterShape */
final class SchemaGenerator
{
    public function __construct(private readonly TypeCatalog $types = new TypeCatalog) {}

    public function generate(QueryDefinition $query, ResultSchema $result, string $namespace, ?string $params = null, ?string $dto = null): GeneratedBundle
    {
        if (! TypeNames::className($namespace) || ($params === null && $dto === null)
            || ($params !== null && ! TypeNames::generatedClassName($params)) || ($dto !== null && ! TypeNames::generatedClassName($dto))
            || ($params !== null && $dto !== null && strcasecmp($params, $dto) === 0)) {
            throw new SqlException(ErrorCode::Generation);
        }
        $contract = (new QueryContract($query, $result, $this->types))->toArray();
        $files = [];
        $schemas = [];
        foreach (['params' => $params, 'dto' => $dto] as $kind => $name) {
            if ($name === null) {
                continue;
            }
            $fields = $kind === 'params' ? (new ParameterShape($this->types))->fields($query->parameters->definitions) : $this->resultFields($result);
            $schema = ['kind' => $kind, 'query' => $contract];
            $schema['schema_hash'] = hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR));
            $source = "<?php\n\ndeclare(strict_types=1);\n\nnamespace ".$namespace.";\n\n// Generated schema ".$schema['schema_hash']."; keep custom code outside this directory.\n";
            $source .= 'final readonly class '.$name.($kind === 'params' ? ' implements \\Hft\\TwoWaySql\\Contracts\\SqlParameters' : '')."\n{\n";
            $source .= "    /**\n";
            $arguments = [];
            $values = [];
            foreach ($fields as $fieldName => $field) {
                if (! TypeNames::parameterName($fieldName)) {
                    throw new SqlException(ErrorCode::Generation);
                }
                $source .= '     * @param '.$field['doc'].' $'.$fieldName."\n";
                $codec = $kind === 'dto' ? ($result->columns[$fieldName]['codec'] ?? null) : null;
                $attribute = $codec === null ? '' : '#[\\Hft\\TwoWaySql\\Attributes\\SqlType('.var_export($codec, true).')] ';
                $arguments[] = $attribute.'#[\\SensitiveParameter] public '.$field['type'].' $'.$fieldName;
                $values[] = var_export($fieldName, true).' => $this->'.$fieldName;
            }
            $source .= "     */\n    public function __construct(".implode(', ', $arguments).") {}\n";
            if ($kind === 'params') {
                $source .= "\n    /** @return ".(new ParameterShape)->shape($fields)." */\n    public function toSqlParameters(): array\n    {\n        return [".implode(', ', $values)."];\n    }\n";
            }
            $source .= "}\n";
            PhpSource::validate($source);
            $files[$name.'.php'] = $source;
            $schemas[$namespace.'\\'.$name] = $schema;
        }

        return new GeneratedBundle($files, schemas: $schemas);
    }

    /** @return array<string, Field> */
    private function resultFields(ResultSchema $result): array
    {
        if ($result->columns === []) {
            throw new SqlException(ErrorCode::Generation);
        }
        $fields = [];
        foreach ($result->columns as $alias => $column) {
            // Float is allowed for scalar declarations, but the default DTO mapper requires an explicit converter.
            if ($column['type'] === 'float' && ! isset($column['codec'])) {
                throw new SqlException(ErrorCode::Generation);
            }
            $native = ResultSchema::phpType($column);
            $doc = isset($column['doc']) ? (($column['nullable'] ? '?' : '').$column['doc']) : ($column['type'] === 'array' ? (($column['nullable'] ? '?' : '').'array<array-key,mixed>') : $native);
            $fields[$alias] = ['type' => $native, 'doc' => $doc];
        }

        return $fields;
    }
}
