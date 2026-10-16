<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Commands;

use Hft\TwoWaySql\Codegen\Dao\DaoGenerator;
use Hft\TwoWaySql\Codegen\Dao\GeneratedBundle;
use Hft\TwoWaySql\Codegen\Dao\GeneratedWriter;
use Hft\TwoWaySql\Codegen\Definition\DaoNormalizer;
use Hft\TwoWaySql\Codegen\Definition\QueryContract;
use Hft\TwoWaySql\Codegen\Definition\ResultSchema;
use Hft\TwoWaySql\Codegen\Definition\TypeNames;
use Hft\TwoWaySql\Contracts\TemplateRepository;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Laravel\Codegen\GenerationConfig;
use Hft\TwoWaySql\Parameter\ParameterSchema;
use Hft\TwoWaySql\Parameter\Registry\CodecRegistry;
use Hft\TwoWaySql\Template\Parser\TemplateParser;
use Illuminate\Console\Command;

final class GenerateCommand extends Command
{
    protected $signature = 'twoway:generate {--dry-run : Show changes without writing (default)} {--write : Write verified generated files} {--check : Fail on stale generated files without writing}';

    protected $description = 'Generate deterministic DAO implementations from explicit contracts';

    public function handle(TemplateRepository $repository, GenerationConfig $config, TemplateParser $parser, CodecRegistry $codecs): int
    {
        if ((int) $this->option('dry-run') + (int) $this->option('write') + (int) $this->option('check') > 1) {
            $this->error('Choose one of --dry-run, --write or --check.');

            return 2;
        }
        try {
            if ($this->option('check') && $config->daos === [] && $repository->ids() === [] && $config->parameters === []) {
                throw new \RuntimeException;
            }
            $normalizer = new DaoNormalizer($repository, $config->parameters, $config->results, $parser, $codecs);
            $definitions = array_map($normalizer->normalize(...), $config->daos);
            $bundle = (new DaoGenerator)->generate($definitions, $config->namespace);
            $schemas = $bundle->schemas;
            foreach ($repository->ids() as $id) {
                $query = $repository->get($id);
                $codecs->validate($query->parameters);
                $parser->parse($query);
                $columns = $config->results[$id] ?? [];
                if (! is_array($columns)) {
                    throw new \RuntimeException;
                }
                $types = $codecs->catalog();
                $schemas['query:'.$id] = ['kind' => 'query', 'query' => (new QueryContract($query, new ResultSchema($columns, types: $types), $types))->toArray()];
            }
            foreach ($config->parameters as $class => $schema) {
                if (! TypeNames::className($class) || ! is_array($schema)) {
                    throw new \RuntimeException;
                }
                $schemas['parameters:'.$class] = ['kind' => 'parameters', 'class' => $class, 'parameters' => (new ParameterSchema($schema))->definitions];
            }
            $files = $bundle->files;
            unset($files['manifest.json']);
            $bundle = new GeneratedBundle($files, $bundle->bindings, $schemas);
            $writer = new GeneratedWriter($this->laravel->basePath(), $config->root);
            $changes = $this->option('check') ? $writer->check($bundle) : $writer->apply($bundle, (bool) $this->option('write'));
            foreach ($changes as $path => $change) {
                $this->line($path.': '.$change['status']);
                if (! $this->option('write') && $change['diff'] !== '') {
                    $this->line($change['diff']);
                }
            }

            return $this->option('check') && array_any($changes, static fn (array $change): bool => $change['status'] !== 'unchanged') ? 1 : 0;
        } catch (\Throwable $error) {
            $location = $error instanceof SqlException && $error->span !== null ? ' '.$error->span->sourceId.':'.$error->span->line : '';
            $this->error('TW_GENERATION'.$location);

            return 2;
        }
    }
}
