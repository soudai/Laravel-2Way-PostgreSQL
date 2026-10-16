<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template;

use Hft\TwoWaySql\Contracts\TemplateRepository;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Parameter\ParameterSchema;

final readonly class FileTemplateRepository implements TemplateRepository
{
    /** @param array<string, mixed> $queries */
    public function __construct(private string $root, private array $queries, private int $maxBytes = 1048576) {}

    public function get(string $id): QueryDefinition
    {
        if (strlen($id) > 128 || ! preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*\z/D', $id)
            || ! array_key_exists($id, $this->queries)) {
            throw new SqlException(ErrorCode::TemplatePath);
        }
        $config = $this->queries[$id];
        if (! is_array($config) || array_diff(array_keys($config), ['path', 'kind', 'parameters', 'sorts', 'projections', 'grammar', 'postgres_min_version']) !== []) {
            throw new SqlException(ErrorCode::ParameterType, sqlId: $id);
        }
        $path = $config['path'] ?? null;
        if (! is_string($path) || ! preg_match('/\A[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*\.sql\z/D', $path)) {
            throw new SqlException(ErrorCode::TemplatePath, sqlId: $id);
        }
        $root = realpath($this->root);
        $ancestor = $this->root;
        while ($ancestor !== dirname($ancestor)) {
            if (is_link($ancestor)) {
                throw new SqlException(ErrorCode::TemplatePath, sqlId: $id);
            }
            $ancestor = dirname($ancestor);
        }
        if ($root === false || ! is_dir($root) || is_link($this->root)) {
            throw new SqlException(ErrorCode::TemplatePath, sqlId: $id);
        }
        $full = $root;
        foreach (explode('/', $path) as $part) {
            $full .= DIRECTORY_SEPARATOR.$part;
            if (is_link($full)) {
                throw new SqlException(ErrorCode::TemplatePath, sqlId: $id);
            }
        }
        $real = realpath($full);
        if ($real === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR) || ! is_file($real) || ! is_readable($real)) {
            throw new SqlException(ErrorCode::TemplatePath, sqlId: $id);
        }
        $kind = $config['kind'] ?? null;
        if (! is_string($kind) || ($queryKind = QueryKind::tryFrom($kind)) === null) {
            throw new SqlException(ErrorCode::QueryKind, sqlId: $id);
        }
        $parameters = $config['parameters'] ?? null;
        if (! is_array($parameters) || array_filter(array_keys($parameters), is_int(...)) !== []) {
            throw new SqlException(ErrorCode::ParameterType, sqlId: $id);
        }
        if ($this->maxBytes < 1) {
            throw new SqlException(ErrorCode::Limit);
        }
        $source = file_get_contents($real, length: $this->maxBytes + 1);
        if ($source === false) {
            throw new SqlException(ErrorCode::TemplatePath, sqlId: $id);
        }
        if (strlen($source) > $this->maxBytes) {
            throw new SqlException(ErrorCode::Limit, sqlId: $id);
        }

        $sorts = array_key_exists('sorts', $config) ? $config['sorts'] : [];
        if (! is_array($sorts)) {
            throw new SqlException(ErrorCode::Sort, sqlId: $id);
        }

        $projections = array_key_exists('projections', $config) ? $config['projections'] : [];
        if (! is_array($projections)) {
            throw new SqlException(ErrorCode::Generation, sqlId: $id);
        }

        $grammar = array_key_exists('grammar', $config) ? $config['grammar'] : 'canonical-v1';
        if (! is_string($grammar)) {
            throw new SqlException(ErrorCode::Compatibility, sqlId: $id);
        }

        $minimumPostgres = array_key_exists('postgres_min_version', $config) ? $config['postgres_min_version'] : 18;
        if (! is_int($minimumPostgres)) {
            throw new SqlException(ErrorCode::ParameterType, sqlId: $id);
        }

        return new QueryDefinition($id, $path, $queryKind, new ParameterSchema($parameters), $source, $sorts, $projections, $grammar, $minimumPostgres);
    }

    public function ids(): array
    {
        return array_keys($this->queries);
    }
}
