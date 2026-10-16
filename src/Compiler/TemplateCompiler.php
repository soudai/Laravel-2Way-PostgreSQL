<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Compiler;

use Hft\TwoWaySql\Compiler\Emitter\PdoPgsqlEmitter;
use Hft\TwoWaySql\Compiler\Evaluator\BranchEvaluator;
use Hft\TwoWaySql\Compiler\Normalizer\ScopeNormalizer;
use Hft\TwoWaySql\Compiler\Trace\CoverageCollector;
use Hft\TwoWaySql\Contracts\AstCache;
use Hft\TwoWaySql\Contracts\Compiler;
use Hft\TwoWaySql\Contracts\DefinitionProvider;
use Hft\TwoWaySql\Contracts\TemplateRepository;
use Hft\TwoWaySql\Contracts\ValueEncoder;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Parameter\ParameterValidator;
use Hft\TwoWaySql\Parameter\Registry\CodecRegistry;
use Hft\TwoWaySql\Parameter\Registry\RegistryEncoder;
use Hft\TwoWaySql\Template\Cache\LruAstCache;
use Hft\TwoWaySql\Template\Lexer\PgLexer;
use Hft\TwoWaySql\Template\Parser\TemplateParser;
use Hft\TwoWaySql\Template\QueryDefinition;
use Hft\TwoWaySql\Template\TemplateLoader;

final readonly class TemplateCompiler implements Compiler, DefinitionProvider
{
    private TemplateParser $parser;

    private ValueEncoder $encoder;

    private PdoPgsqlEmitter $emitter;

    public function __construct(
        private TemplateRepository $repository,
        ?TemplateParser $parser = null,
        ?ValueEncoder $encoder = null,
        ?PdoPgsqlEmitter $emitter = null,
        private ?AstCache $cache = new LruAstCache,
        private Limits $limits = new Limits,
        private ?CoverageCollector $coverage = null,
    ) {
        $this->parser = $parser ?? new TemplateParser(new PgLexer($limits->get('template_bytes'), $limits->get('tokens'), $limits->get('depth')), $limits->get('depth'), $limits->get('ast_nodes'));
        $this->encoder = $encoder ?? new RegistryEncoder(new CodecRegistry, $limits->get('parameter_bytes'), $limits->get('list_items'));
        $this->emitter = $emitter ?? new PdoPgsqlEmitter($limits->get('sql_bytes'), $limits->get('bindings'), $limits->get('total_parameter_bytes'));
    }

    public function getDefinition(string $id): QueryDefinition
    {
        return $this->repository->get($id);
    }

    public function compile(string $id, #[\SensitiveParameter] array $parameters): CompiledQuery
    {
        $definition = $this->repository->get($id);
        $trace = null;
        try {
            if ($this->encoder instanceof RegistryEncoder) {
                $this->encoder->validateSchema($definition->parameters);
            }
            $definition->sorts->validateSelections($parameters);
            $definition->projections->validateSelections($parameters);
            $ast = (new TemplateLoader($this->parser, $this->cache, $this->limits->hash()))->load($definition);
            $trace = $this->coverage?->begin($definition, $ast);
            (new ParameterValidator($this->encoder, $this->limits->get('list_items'), $this->limits->get('total_parameter_bytes'), $this->limits->get('loop_items')))->validate($definition->parameters, $parameters);
            $selected = (new BranchEvaluator($this->limits, $trace))->select($ast, $definition->parameters, $parameters);
            $normalized = (new ScopeNormalizer)->normalize($selected);
            $pieces = (new SqlIrBuilder($this->encoder, $this->limits->get('list_items'), $this->limits->get('bindings'), $this->limits->get('total_parameter_bytes')))->build($normalized, $definition, $parameters);

            $query = $this->emitter->emit($definition, $pieces);

            return $trace === null ? $query : new CompiledQuery($query->sqlId, $query->sql(), $query->bindings(), $query->kind, $query->hash, $query->diagnostics + ['branches' => $trace->visitedIds()], $query->minimumPostgres);
        } catch (SqlException $e) {
            throw new SqlException(ErrorCode::from($e->errorCode), $e->span, $definition->id, $e->parameterPath);
        } finally {
            if ($trace !== null) {
                $this->coverage?->finish($trace);
            }
        }
    }
}
