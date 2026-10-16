<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\StaticAnalysis;

use BackedEnum;
use Hft\TwoWaySql\Codegen\Dao\GeneratedWriter;
use Hft\TwoWaySql\Codegen\Definition\ArgumentDefinition;
use Hft\TwoWaySql\Codegen\Definition\MethodDefinition;
use Hft\TwoWaySql\Codegen\Definition\QueryContract;
use Hft\TwoWaySql\Codegen\Definition\ResultSchema;
use Hft\TwoWaySql\Codegen\Definition\TypeNames;
use Hft\TwoWaySql\Parameter\ParameterSchema;
use Hft\TwoWaySql\Template\FileTemplateRepository;
use Hft\TwoWaySql\Types\TypeCatalog;
use PHPStan\Reflection\ReflectionProvider;
use RuntimeException;

final class ContractCatalog
{
    /** @var array<string, CatalogQuery> */
    private array $queries = [];

    /** @var array<string, array<string, MethodDefinition>> */
    private array $methods = [];

    /** @var array<string, ParameterSchema> */
    private array $parameters = [];

    /** @var array<string, true> */
    private array $interfaces = [];

    private TypeCatalog $types;

    /** @param list<string> $manifests */
    public function __construct(array $manifests, private string $sqlRoot, private ReflectionProvider $reflection)
    {
        $this->types = new TypeCatalog;
        try {
            if ($manifests === []) {
                throw new RuntimeException;
            }
            foreach ($manifests as $path) {
                if (basename($path) !== 'manifest.json') {
                    throw new RuntimeException;
                }
                $directory = dirname($path);
                $manifest = (new GeneratedWriter(dirname($directory), basename($directory)))->manifest();
                if ($manifest === null) {
                    throw new RuntimeException;
                }
                foreach ($manifest['schemas'] as $name => $schema) {
                    $this->schema($name, $schema);
                }
                foreach ($manifest['bindings'] as $interface => $implementation) {
                    if (! isset($this->methods[$interface])) {
                        throw new RuntimeException;
                    }
                    $this->methods[$implementation] = $this->methods[$interface];
                }
            }
        } catch (\Throwable) {
            throw new RuntimeException('TW_GENERATION: Invalid static analysis manifest; regenerate the declared contracts.');
        }
    }

    public function query(string $id): ?CatalogQuery
    {
        return $this->queries[$id] ?? null;
    }

    public function method(string $class, string $name): ?MethodDefinition
    {
        return $this->methods[$class][$name] ?? null;
    }

    public function isDao(string $class): bool
    {
        return isset($this->methods[$class]);
    }

    public function isInterface(string $class): bool
    {
        return isset($this->interfaces[$class]);
    }

    public function parameters(string $class): ?ParameterSchema
    {
        return $this->parameters[$class] ?? null;
    }

    public function types(): TypeCatalog
    {
        return $this->types;
    }

    /** @param array<array-key,mixed> $schema */
    private function schema(string $name, array $schema): void
    {
        if (isset($schema['methods'])) {
            if (! TypeNames::className($name) || ($schema['interface'] ?? null) !== $name || ! is_array($schema['methods']) || ! array_is_list($schema['methods'])) {
                throw new RuntimeException;
            }
            $hash = $schema['schema_hash'] ?? null;
            unset($schema['schema_hash']);
            if (! is_string($hash) || ! hash_equals($hash, hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR)))) {
                throw new RuntimeException;
            }
            $methods = [];
            foreach ($schema['methods'] as $method) {
                if (! is_array($method)) {
                    throw new RuntimeException;
                }
                $normalized = $this->readMethod($method);
                $methods[$normalized->name] = $normalized;
                if ($normalized->parameterClass !== null) {
                    $this->addParameters($normalized->parameterClass, new ParameterSchema($normalized->query->parameters));
                }
            }
            $this->methods[$name] = $methods;
            $this->interfaces[$name] = true;

            return;
        }
        if (($schema['kind'] ?? null) === 'parameters') {
            if (! is_string($schema['class'] ?? null) || ! is_array($schema['parameters'] ?? null)) {
                throw new RuntimeException;
            }
            $this->addParameters($schema['class'], new ParameterSchema($schema['parameters']));

            return;
        }
        if (! in_array($schema['kind'] ?? null, ['params', 'dto', 'query'], true) || ! is_array($schema['query'] ?? null)) {
            throw new RuntimeException;
        }
        $query = $this->readQuery($schema['query']);
        if ($schema['kind'] === 'params') {
            $this->addParameters($name, $query->parameters);
        }
    }

    private function addParameters(string $class, ParameterSchema $schema): void
    {
        if (! TypeNames::className($class) || (isset($this->parameters[$class]) && $this->parameters[$class]->definitions != $schema->definitions)) {
            throw new RuntimeException;
        }
        $this->parameters[$class] = $schema;
    }

    /** @param array<array-key,mixed> $data */
    private function readQuery(array $data): CatalogQuery
    {
        $id = $this->string($data['id'] ?? null);
        $path = $this->string($data['path'] ?? null);
        $kind = $this->string($data['kind'] ?? null);
        $source = $this->string($data['source_hash'] ?? null);
        $this->string($data['definition_hash'] ?? null);
        if (! preg_match('/\A[a-f0-9]{64}\z/D', $source)
            || ! is_array($data['parameters'] ?? null) || ! is_array($data['columns'] ?? null)) {
            throw new RuntimeException;
        }
        $grammar = $this->string($data['grammar'] ?? null);
        $descriptors = $data['types'] ?? [];
        if (! is_array($descriptors)) {
            throw new RuntimeException;
        }
        $types = TypeCatalog::fromArray($descriptors);
        $merged = $this->types->toArray();
        foreach ($types->toArray() as $typeId => $descriptor) {
            if (isset($merged[$typeId]) && $merged[$typeId] !== $descriptor) {
                throw new RuntimeException;
            }
            $merged[$typeId] = $descriptor;
        }
        $this->types = TypeCatalog::fromArray($merged);
        $definition = (new FileTemplateRepository($this->sqlRoot, [$id => ['path' => $path, 'kind' => $kind, 'grammar' => $grammar, 'parameters' => $data['parameters'], 'postgres_min_version' => $data['postgres_min_version'] ?? 18]]))->get($id);
        $result = new ResultSchema($data['columns'], fn (string $type): bool => $this->reflection->hasClass($type) && $this->reflection->getClass($type)->implementsInterface(BackedEnum::class), $types);
        $query = new CatalogQuery(new QueryContract($definition, $result, $types), $source);
        if (isset($this->queries[$id])) {
            $previous = $this->queries[$id];
            if ($previous->savedSourceHash !== $source || $previous->contract->parameters != $query->contract->parameters || $previous->contract->result->columns !== $result->columns) {
                throw new RuntimeException;
            }
        }
        $this->queries[$id] = $query;

        return $query;
    }

    /** @param array<array-key,mixed> $data */
    private function readMethod(array $data): MethodDefinition
    {
        $name = $this->string($data['name'] ?? null);
        $mode = $this->string($data['mode'] ?? null);
        $type = $this->string($data['return_type'] ?? null);
        $doc = $this->string($data['return_doc'] ?? null);
        if (! is_array($data['query'] ?? null) || ! is_array($data['arguments'] ?? null) || ! array_is_list($data['arguments'])) {
            throw new RuntimeException;
        }
        $arguments = [];
        foreach ($data['arguments'] as $argument) {
            if (! is_array($argument) || ! is_string($argument['name'] ?? null) || ! is_string($argument['type'] ?? null)) {
                throw new RuntimeException;
            }
            $argumentDoc = $argument['doc'] ?? null;
            if ($argumentDoc !== null && ! is_string($argumentDoc)) {
                throw new RuntimeException;
            }
            $arguments[] = new ArgumentDefinition($argument['name'], $argument['type'], $argumentDoc);
        }
        $parameter = $data['parameter_class'] ?? null;
        $result = $data['result_class'] ?? null;
        if (($parameter !== null && ! is_string($parameter)) || ($result !== null && ! is_string($result))) {
            throw new RuntimeException;
        }

        return new MethodDefinition($name, $mode, $this->readQuery($data['query'])->contract, $arguments, $parameter, $result, $type, $doc);
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : throw new RuntimeException;
    }
}
