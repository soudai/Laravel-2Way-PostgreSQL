<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Codegen\Definition;

use Hft\TwoWaySql\Attributes\SqlQuery;
use Hft\TwoWaySql\Attributes\SqlType;
use Hft\TwoWaySql\Contracts\SqlParameters;
use Hft\TwoWaySql\Contracts\TemplateRepository;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Execution\QueryOperation;
use Hft\TwoWaySql\Mapping\DtoMapper;
use Hft\TwoWaySql\Parameter\ParameterSchema;
use Hft\TwoWaySql\Parameter\Registry\CodecRegistry;
use Hft\TwoWaySql\Template\Parser\TemplateParser;
use Hft\TwoWaySql\Template\Source\SourceSpan;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

final readonly class DaoNormalizer
{
    /** @param array<string, mixed> $parameterContracts SqlParameters class => explicit parameter schema.
     * @param  array<string, mixed>  $resultContracts  Query ID => declared result columns (not inferred).
     */
    public function __construct(private TemplateRepository $repository, private array $parameterContracts = [], private array $resultContracts = [], private TemplateParser $parser = new TemplateParser, private CodecRegistry $codecs = new CodecRegistry) {}

    public function normalize(string $interface): DaoDefinition
    {
        if (! TypeNames::className($interface) || ! interface_exists($interface)) {
            throw new SqlException(ErrorCode::Generation);
        }
        $reflection = new ReflectionClass($interface);
        $methods = $reflection->getMethods();
        if ($methods === [] || count($methods) > 256) {
            throw new SqlException(ErrorCode::Generation);
        }
        usort($methods, static fn (ReflectionMethod $a, ReflectionMethod $b): int => strcmp($a->getName(), $b->getName()));

        return new DaoDefinition($interface, array_map($this->method(...), $methods));
    }

    private function method(ReflectionMethod $method): MethodDefinition
    {
        $span = new SourceSpan(basename($method->getFileName() ?: 'dao.php'), 0, 0, $method->getStartLine() ?: 1, 1);
        $id = null;
        try {
            $attributes = $method->getAttributes(SqlQuery::class);
            if (count($attributes) !== 1 || $method->isStatic() || $method->returnsReference() || ! $method->isPublic() || str_starts_with($method->getName(), '__')) {
                throw new SqlException(ErrorCode::Generation);
            }
            $attribute = $attributes[0]->newInstance();
            $id = $attribute->id;
            $operation = QueryOperation::tryFrom($attribute->mode);
            if ($operation === null || ! $operation->supportsDao()) {
                throw new SqlException(ErrorCode::Generation);
            }
            $query = $this->repository->get($id);
            $this->parser->parse($query);
            if (! $operation->allows($query->kind)) {
                throw new SqlException(ErrorCode::Generation);
            }
            $columns = array_key_exists($id, $this->resultContracts) ? $this->resultContracts[$id] : [];
            if (! is_array($columns)) {
                throw new SqlException(ErrorCode::Generation);
            }
            $types = $this->codecs->catalog();
            $contract = new QueryContract($query, new ResultSchema($columns, types: $types), $types);
            [$arguments, $parameterClass] = $this->arguments($method, $query->parameters);
            $returnType = TypeNames::render($method->getReturnType());
            $returnDoc = $this->returnType($attribute, $returnType, $contract->result);

            return new MethodDefinition($method->getName(), $attribute->mode, $contract, $arguments, $parameterClass, $attribute->result, $returnType, $returnDoc);
        } catch (Throwable) {
            throw new SqlException(ErrorCode::Generation, $span, $id);
        }
    }

    /** @return array{list<ArgumentDefinition>, ?string} */
    private function arguments(ReflectionMethod $method, ParameterSchema $schema): array
    {
        $arguments = [];
        $parameterClass = null;
        foreach ($method->getParameters() as $argument) {
            $type = $argument->getType();
            if (! $type instanceof ReflectionNamedType || $argument->isVariadic() || $argument->isPassedByReference() || $argument->isOptional()) {
                throw new SqlException(ErrorCode::Generation);
            }
            $name = $argument->getName();
            $directType = isset($schema->definitions[$name]) ? TypeNames::parameter($schema->definitions[$name], $this->codecs->catalog()) : null;
            if ($directType !== null && ($type->isBuiltin() || $directType === TypeNames::render($type))) {
                if ($directType !== TypeNames::render($type)) {
                    throw new SqlException(ErrorCode::Generation);
                }
            } elseif (! $type->isBuiltin()) {
                $class = $type->getName();
                $declared = $this->parameterContracts[$class] ?? null;
                if (count($method->getParameters()) !== 1 || $type->allowsNull() || ! is_a($class, SqlParameters::class, true)
                    || ! is_array($declared) || (new ParameterSchema($declared))->definitions != $schema->definitions) {
                    throw new SqlException(ErrorCode::Generation);
                }
                $parameterClass = $class;
            } else {
                throw new SqlException(ErrorCode::Generation);
            }
            $doc = null;
            $spec = $schema->definitions[$name] ?? null;
            if ($parameterClass === null && $spec !== null && str_starts_with($spec['type'], 'codec:')) {
                $descriptor = $this->codecs->catalog()->find(substr($spec['type'], 6));
                if ($descriptor?->inputDoc !== null) {
                    $doc = (($spec['nullable'] ?? false) ? '?' : '').$descriptor->inputDoc;
                }
            }
            $arguments[] = new ArgumentDefinition($name, TypeNames::render($type), $doc);
        }
        if ($parameterClass === null && count($arguments) !== count($schema->definitions)) {
            throw new SqlException(ErrorCode::Generation);
        }

        return [$arguments, $parameterClass];
    }

    private function returnType(SqlQuery $attribute, string $type, ResultSchema $schema): string
    {
        if ($attribute->result !== null) {
            if (QueryOperation::from($attribute->mode)->dtoMethod() === null || ! TypeNames::className($attribute->result) || ! class_exists($attribute->result)) {
                throw new SqlException(ErrorCode::Generation);
            }
            $this->dto($attribute->result, $schema);
        }
        $row = '\\'.($attribute->result ?? 'stdClass');
        $expected = match ($attribute->mode) {
            'select', 'returning' => 'array', 'one' => $row, 'oneOrNull' => '?'.$row, 'execute' => 'int', 'scalar' => $type,
            default => throw new SqlException(ErrorCode::Generation),
        };
        if ($type !== $expected) {
            throw new SqlException(ErrorCode::Generation);
        }
        if ($attribute->mode === 'scalar' && $type !== 'mixed') {
            if (! in_array(ltrim($type, '?'), ['int', 'string', 'bool', 'float'], true) || count($schema->columns) !== 1 || ResultSchema::phpType(array_values($schema->columns)[0]) !== $type) {
                throw new SqlException(ErrorCode::Generation);
            }
        }

        return in_array($attribute->mode, ['select', 'returning'], true) ? 'list<'.$row.'>' : $type;
    }

    /** @param class-string $class */
    private function dto(string $class, ResultSchema $schema): void
    {
        (new DtoMapper(codecs: $this->codecs))->validate($class);
        $constructor = (new ReflectionClass($class))->getConstructor();
        if ($constructor === null) {
            throw new SqlException(ErrorCode::Generation);
        }
        $arguments = $constructor->getParameters();
        if (count($arguments) !== count($schema->columns)) {
            throw new SqlException(ErrorCode::Generation);
        }
        foreach ($arguments as $argument) {
            $column = $schema->columns[$argument->getName()] ?? null;
            if ($column === null || TypeNames::render($argument->getType()) !== ResultSchema::phpType($column)) {
                throw new SqlException(ErrorCode::Generation);
            }
            $attributes = $argument->getAttributes(SqlType::class);
            $codec = count($attributes) === 1 ? $attributes[0]->newInstance()->id : null;
            if ($codec !== ($column['codec'] ?? null)) {
                throw new SqlException(ErrorCode::Generation);
            }
        }
    }
}
