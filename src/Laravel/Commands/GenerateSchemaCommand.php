<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Commands;

use Hft\TwoWaySql\Codegen\Dao\GeneratedWriter;
use Hft\TwoWaySql\Codegen\Definition\ResultSchema;
use Hft\TwoWaySql\Codegen\Schema\SchemaGenerator;
use Hft\TwoWaySql\Contracts\TemplateRepository;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Laravel\Codegen\GenerationConfig;
use Hft\TwoWaySql\Parameter\Registry\CodecRegistry;
use Hft\TwoWaySql\Template\Parser\TemplateParser;
use Illuminate\Console\Command;

final class GenerateSchemaCommand extends Command
{
    protected $signature = 'twoway:generate-schema {query : Registered query ID} {--params= : Input class short name} {--dto= : Result class short name} {--dry-run : Show changes (default)} {--write : Write verified schema stubs} {--check : Fail on stale selected stubs without writing}';

    protected $description = 'Generate Params and result DTO stubs from explicit declarations, without a database';

    public function handle(TemplateRepository $repository, GenerationConfig $config, TemplateParser $parser, CodecRegistry $codecs): int
    {
        try {
            $id = $this->argument('query');
            $params = $this->option('params');
            $dto = $this->option('dto');
            if (! is_string($id) || ($params !== null && ! is_string($params)) || ($dto !== null && ! is_string($dto)) || (int) $this->option('write') + (int) $this->option('dry-run') + (int) $this->option('check') > 1) {
                throw new SqlException(ErrorCode::Generation);
            }
            $query = $repository->get($id);
            $codecs->validate($query->parameters);
            $parser->parse($query);
            $columns = array_key_exists($id, $config->results) ? $config->results[$id] : [];
            if (! is_array($columns)) {
                throw new SqlException(ErrorCode::Generation);
            }
            $types = $codecs->catalog();
            $bundle = (new SchemaGenerator($types))->generate($query, new ResultSchema($columns, types: $types), $config->namespace.'\\Schema', $params, $dto);
            $writer = new GeneratedWriter($this->laravel->basePath(), $config->root.'/Schema');
            $changes = $this->option('check') ? $writer->check($bundle, true) : $writer->apply($writer->merge($bundle), (bool) $this->option('write'));
            foreach ($changes as $path => $change) {
                $this->line($path.': '.$change['status']);
                if (! $this->option('write') && $change['diff'] !== '') {
                    $this->line($change['diff']);
                }
            }

            return $this->option('check') && array_any($changes, static fn (array $change): bool => $change['status'] !== 'unchanged') ? 1 : 0;
        } catch (\Throwable) {
            $this->error('TW_GENERATION');

            return 2;
        }
    }
}
