<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel;

use Hft\TwoWaySql\Compiler\Limits;
use Hft\TwoWaySql\Compiler\TemplateCompiler;
use Hft\TwoWaySql\Compiler\Trace\CoverageCollector;
use Hft\TwoWaySql\Contracts\AstCache;
use Hft\TwoWaySql\Contracts\Compiler;
use Hft\TwoWaySql\Contracts\ResultMapper;
use Hft\TwoWaySql\Contracts\TemplateRepository;
use Hft\TwoWaySql\Contracts\ValueEncoder;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Laravel\Codegen\GeneratedDaoRegistry;
use Hft\TwoWaySql\Laravel\Codegen\GenerationConfig;
use Hft\TwoWaySql\Laravel\Commands\CacheCommand;
use Hft\TwoWaySql\Laravel\Commands\CheckCommand;
use Hft\TwoWaySql\Laravel\Commands\ClearCommand;
use Hft\TwoWaySql\Laravel\Commands\CoverageCommand;
use Hft\TwoWaySql\Laravel\Commands\ExplainCommand;
use Hft\TwoWaySql\Laravel\Commands\GenerateCommand;
use Hft\TwoWaySql\Laravel\Commands\GenerateSchemaCommand;
use Hft\TwoWaySql\Laravel\Commands\InspectCommand;
use Hft\TwoWaySql\Laravel\Commands\LintCommand;
use Hft\TwoWaySql\Laravel\Diagnostics\ExecutionTrace;
use Hft\TwoWaySql\Laravel\Executor\PlanAdviceRegistry;
use Hft\TwoWaySql\Mapping\DtoMapper;
use Hft\TwoWaySql\Parameter\Registry\CodecRegistry;
use Hft\TwoWaySql\Parameter\Registry\RegistryEncoder;
use Hft\TwoWaySql\Template\Cache\FileAstCache;
use Hft\TwoWaySql\Template\Cache\LruAstCache;
use Hft\TwoWaySql\Template\Cache\TieredAstCache;
use Hft\TwoWaySql\Template\FileTemplateRepository;
use Hft\TwoWaySql\Template\Lexer\PgLexer;
use Hft\TwoWaySql\Template\Parser\TemplateParser;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

final class TwoWaySqlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/twoway-sql.php', 'twoway-sql');
        $this->app->singleton(Limits::class, function (Application $app): Limits {
            $overrides = $app->make(Config::class)->get('twoway-sql.limits');
            if (! is_array($overrides)) {
                throw new SqlException(ErrorCode::Limit);
            }

            return new Limits($overrides);
        });
        $this->app->singleton(TemplateParser::class, function (Application $app): TemplateParser {
            $limits = $app->make(Limits::class);

            return new TemplateParser(new PgLexer($limits->get('template_bytes'), $limits->get('tokens'), $limits->get('depth')), $limits->get('depth'), $limits->get('ast_nodes'));
        });
        $this->app->singleton(TemplateRepository::class, function (Application $app): TemplateRepository {
            $config = $app->make(Config::class);
            if ($config->get('twoway-sql.grammar') !== 'canonical-v1') {
                throw new SqlException(ErrorCode::Unsupported);
            }
            $root = $config->get('twoway-sql.root');
            $queries = $config->get('twoway-sql.queries');
            if (! is_string($root) || ! is_array($queries)) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $definitions = [];
            foreach ($queries as $id => $definition) {
                if (! is_string($id)) {
                    throw new SqlException(ErrorCode::TemplatePath);
                }
                $definitions[$id] = $definition;
            }

            return new FileTemplateRepository($root, $definitions, $app->make(Limits::class)->get('template_bytes'));
        });
        $this->app->singleton(AstCache::class, function (Application $app): AstCache {
            $config = $app->make(Config::class);
            $path = $config->get('twoway-sql.cache.path');
            $entries = $config->get('twoway-sql.cache.entries');
            $bytes = $config->get('twoway-sql.cache.bytes');
            if (! is_string($path) || ! is_int($entries) || ! is_int($bytes) || $entries < 1 || $bytes < 1) {
                throw new SqlException(ErrorCode::ParameterType);
            }

            return new TieredAstCache(new LruAstCache($entries, $bytes), new FileAstCache($path));
        });
        $this->app->singleton(CoverageCollector::class, fn (Application $app): CoverageCollector => new CoverageCollector($this->diagnosticLimit($app, 'max_sources'), $this->diagnosticLimit($app, 'max_arms')));
        $this->app->singleton(ExecutionTrace::class, fn (Application $app): ExecutionTrace => new ExecutionTrace($this->diagnosticLimit($app, 'max_events')));
        $this->app->singleton(CodecRegistry::class, fn (): CodecRegistry => new CodecRegistry);
        $this->app->singleton(ValueEncoder::class, fn (Application $app): ValueEncoder => new RegistryEncoder($app->make(CodecRegistry::class), $app->make(Limits::class)->get('parameter_bytes'), $app->make(Limits::class)->get('list_items')));
        $this->app->singleton(Compiler::class, fn (Application $app): Compiler => new TemplateCompiler($app->make(TemplateRepository::class), parser: $app->make(TemplateParser::class), encoder: $app->make(ValueEncoder::class), cache: $app->make(AstCache::class), limits: $app->make(Limits::class), coverage: $this->diagnosticsEnabled($app, 'coverage') ? $app->make(CoverageCollector::class) : null));
        $this->app->singleton(ConnectionResolver::class, fn (Application $app): ConnectionResolver => new ConnectionResolver($app->make(DatabaseManager::class)));
        $this->app->singleton(ResultMapper::class, fn (Application $app): ResultMapper => new DtoMapper(codecs: $app->make(CodecRegistry::class)));
        $this->app->singleton(PlanAdviceRegistry::class, fn (Application $app): PlanAdviceRegistry => new PlanAdviceRegistry($app->make(Config::class)->get('twoway-sql.plan_advice')));
        $this->app->singleton(TwoWaySqlManager::class, fn (Application $app): TwoWaySqlManager => new TwoWaySqlManager($app->make(Compiler::class), $app->make(ConnectionResolver::class), $app->make(ResultMapper::class), $this->diagnosticsEnabled($app, 'execution') ? $app->make(ExecutionTrace::class) : null, $app->make(PlanAdviceRegistry::class)));
        $this->app->singleton(GenerationConfig::class, fn (Application $app): GenerationConfig => new GenerationConfig($app->make(Config::class)->get('twoway-sql.codegen')));
        $this->app->singleton(GeneratedDaoRegistry::class, fn (Application $app): GeneratedDaoRegistry => new GeneratedDaoRegistry($app, $app->make(GenerationConfig::class)));
    }

    private function diagnosticsEnabled(Application $app, string $name): bool
    {
        $value = $app->make(Config::class)->get('twoway-sql.diagnostics.'.$name);
        if (! is_bool($value)) {
            throw new SqlException(ErrorCode::ParameterType);
        }

        return $value;
    }

    private function diagnosticLimit(Application $app, string $name): int
    {
        $value = $app->make(Config::class)->get('twoway-sql.diagnostics.'.$name);
        if (! is_int($value) || $value < 1) {
            throw new SqlException(ErrorCode::ParameterType);
        }

        return $value;
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([LintCommand::class, CacheCommand::class, ClearCommand::class, CheckCommand::class, CoverageCommand::class, GenerateCommand::class, GenerateSchemaCommand::class, InspectCommand::class, ExplainCommand::class]);
        }
        $this->app->make(GeneratedDaoRegistry::class)->register();
        $this->publishes([dirname(__DIR__, 2).'/config/twoway-sql.php' => $this->app->configPath('twoway-sql.php')], 'twoway-sql-config');
    }
}
