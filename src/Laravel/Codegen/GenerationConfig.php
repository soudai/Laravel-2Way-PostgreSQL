<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Codegen;

use Hft\TwoWaySql\Codegen\Definition\TypeNames;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

final readonly class GenerationConfig
{
    public string $root;

    public string $namespace;

    /** @var list<string> */
    public array $daos;

    /** @var array<string, mixed> */
    public array $parameters;

    /** @var array<string, mixed> */
    public array $results;

    /** @var array<string, string|null> */
    public array $connections;

    public function __construct(mixed $config)
    {
        if (! is_array($config) || array_diff(array_keys($config), ['root', 'namespace', 'daos', 'parameters', 'results', 'connections']) !== []) {
            throw new SqlException(ErrorCode::Generation);
        }
        $config += ['root' => 'app/Generated/TwoWaySql', 'namespace' => 'App\\Generated\\TwoWaySql', 'daos' => [], 'parameters' => [], 'results' => [], 'connections' => []];
        $root = $config['root'];
        $namespace = $config['namespace'];
        $daos = $config['daos'];
        if (! is_string($root) || ! is_string($namespace) || ! TypeNames::className($namespace) || ! is_array($daos) || ! array_is_list($daos) || count($daos) > 256) {
            throw new SqlException(ErrorCode::Generation);
        }
        $names = [];
        foreach ($daos as $name) {
            if (! is_string($name) || ! TypeNames::className($name) || in_array($name, $names, true)) {
                throw new SqlException(ErrorCode::Generation);
            }
            $names[] = $name;
        }
        $this->daos = $names;
        $this->root = $root;
        $this->namespace = $namespace;
        $this->parameters = $this->map($config['parameters']);
        $this->results = $this->map($config['results']);
        $connections = [];
        foreach ($this->map($config['connections']) as $name => $connection) {
            if (! in_array($name, $names, true) || ($connection !== null && (! is_string($connection) || $connection === ''))) {
                throw new SqlException(ErrorCode::Generation);
            }
            $connections[$name] = $connection;
        }
        $this->connections = $connections;
    }

    /** @return array<string, mixed> */
    private function map(mixed $input): array
    {
        if (! is_array($input)) {
            throw new SqlException(ErrorCode::Generation);
        }
        $result = [];
        foreach ($input as $key => $value) {
            if (! is_string($key)) {
                throw new SqlException(ErrorCode::Generation);
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
