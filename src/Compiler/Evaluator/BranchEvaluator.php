<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Compiler\Evaluator;

use Hft\TwoWaySql\Compiler\Limits;
use Hft\TwoWaySql\Compiler\Trace\CoverageSession;
use Hft\TwoWaySql\Parameter\ParameterSchema;
use Hft\TwoWaySql\Template\Ast\Node;
use Hft\TwoWaySql\Template\Ast\TemplateAst;

final readonly class BranchEvaluator
{
    public function __construct(private Limits $limits = new Limits, private ?CoverageSession $trace = null) {}

    /** @param array<string, mixed> $parameters
     * @return list<Node>
     */
    public function select(TemplateAst $ast, ParameterSchema $schema, #[\SensitiveParameter] array $parameters): array
    {
        return (new EvaluationSession($this->limits, $this->trace))->select($ast->nodes, $schema->validateStructure($parameters), $schema);
    }
}
