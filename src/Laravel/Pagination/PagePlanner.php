<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Pagination;

use Hft\TwoWaySql\Contracts\Compiler;
use Hft\TwoWaySql\Contracts\DefinitionProvider;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Parameter\ParameterSchema;
use Hft\TwoWaySql\Template\QueryKind;

/** @internal */
final readonly class PagePlanner
{
    public function __construct(private Compiler&DefinitionProvider $compiler) {}

    /** @param array<string, mixed> $parameters */
    public function plan(PageQuery $query, #[\SensitiveParameter] array $parameters, PageRequest $page): PagePlan
    {
        $data = $this->compiler->getDefinition($query->dataSqlId);
        $count = $this->compiler->getDefinition($query->countSqlId);
        if ($data->kind !== QueryKind::Read || $count->kind !== QueryKind::Read) {
            throw new SqlException(ErrorCode::QueryKind);
        }
        foreach (['_pageLimit', '_pageOffset'] as $reserved) {
            if (array_key_exists($reserved, $parameters)) {
                throw new SqlException(ErrorCode::ParameterExtra, parameterPath: $reserved);
            }
            $spec = $data->parameters->definitions[$reserved] ?? null;
            if ($spec === null || $spec['type'] !== 'int64' || ($spec['nullable'] ?? false)) {
                throw new SqlException(ErrorCode::ParameterType, parameterPath: $reserved);
            }
            foreach (array_keys($count->parameters->definitions) as $path) {
                if ($path === $reserved || str_starts_with($path, $reserved.'.')) {
                    throw new SqlException(ErrorCode::ParameterType, parameterPath: $reserved);
                }
            }
        }
        $combined = new ParameterSchema($data->parameters->definitions + $count->parameters->definitions);
        $flat = $combined->validateStructure($parameters + ['_pageLimit' => $page->perPage, '_pageOffset' => $page->offset]);

        // Compile both before either SQL is sent. Each retains its independent schema validation.
        return new PagePlan(
            $this->compiler->compile($data->id, $this->project($data->parameters, $flat)),
            $this->compiler->compile($count->id, $this->project($count->parameters, $flat)),
        );
    }

    /** @param array<string, mixed> $flat
     * @return array<string, mixed>
     */
    private function project(ParameterSchema $schema, #[\SensitiveParameter] array $flat): array
    {
        $selected = [];
        foreach (array_keys($schema->definitions) as $path) {
            $selected[$path] = $flat[$path];
        }

        return $this->tree($selected);
    }

    /** @param array<string, mixed> $flat
     * @return array<string, mixed>
     */
    private function tree(#[\SensitiveParameter] array $flat): array
    {
        $result = [];
        $children = [];
        foreach ($flat as $path => $value) {
            $parts = explode('.', $path, 2);
            if (isset($parts[1])) {
                $children[$parts[0]][$parts[1]] = $value;
            } else {
                $result[$parts[0]] = $value;
            }
        }
        foreach ($children as $key => $paths) {
            $result[$key] = $this->tree($paths);
        }

        return $result;
    }
}
