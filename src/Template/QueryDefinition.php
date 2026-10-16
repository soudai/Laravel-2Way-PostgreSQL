<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template;

use Hft\TwoWaySql\Compiler\Metadata\ProjectionDefinitions;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Parameter\ParameterSchema;

final readonly class QueryDefinition
{
    public string $sourceHash;

    public string $definitionHash;

    public SortDefinitions $sorts;

    public ProjectionDefinitions $projections;

    /** @param array<array-key, mixed> $sorts
     * @param  array<array-key, mixed>  $projections
     */
    public function __construct(
        public string $id,
        public string $path,
        public QueryKind $kind,
        public ParameterSchema $parameters,
        public string $source,
        array $sorts = [],
        array $projections = [],
        public string $grammar = 'canonical-v1',
        public int $minimumPostgres = 18,
    ) {
        if (! in_array($minimumPostgres, [18, 19], true)) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        if (! in_array($grammar, ['canonical-v1', 'doma-subset-v1'], true)) {
            throw new SqlException(ErrorCode::Compatibility);
        }
        $this->sourceHash = hash('sha256', $source);
        $this->sorts = new SortDefinitions($sorts, $parameters);
        $this->projections = new ProjectionDefinitions($projections, $parameters);
        $this->definitionHash = hash('sha256', json_encode([$id, $path, $kind->value, $parameters->definitions, $this->sorts->definitions, $this->projections->definitions, $grammar, $minimumPostgres], JSON_THROW_ON_ERROR));
    }
}
