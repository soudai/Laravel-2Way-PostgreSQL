<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Compiler\Evaluator;

use Hft\TwoWaySql\Compiler\Limits;
use Hft\TwoWaySql\Compiler\Normalizer\ScopeNormalizer;
use Hft\TwoWaySql\Compiler\Trace\CoverageSession;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Parameter\LoopScope;
use Hft\TwoWaySql\Parameter\ParameterSchema;
use Hft\TwoWaySql\Template\Ast\BindNode;
use Hft\TwoWaySql\Template\Ast\BranchNode;
use Hft\TwoWaySql\Template\Ast\ClauseNode;
use Hft\TwoWaySql\Template\Ast\LoopNode;
use Hft\TwoWaySql\Template\Ast\Node;
use Hft\TwoWaySql\Template\Ast\SqlNode;
use Hft\TwoWaySql\Template\Lexer\Token;
use Hft\TwoWaySql\Template\Lexer\TokenKind;

final class EvaluationSession
{
    private int $iterations = 0;

    private int $nodes = 0;

    public function __construct(private readonly Limits $limits, private readonly ?CoverageSession $trace = null) {}

    /** @param list<Node> $nodes
     * @param  array<string, mixed>  $values
     * @return list<Node>
     */
    public function select(array $nodes, #[\SensitiveParameter] array $values, ParameterSchema $schema, bool $inLoop = false): array
    {
        $result = [];
        foreach ($nodes as $node) {
            if ($node instanceof BranchNode) {
                $selectedArm = null;
                foreach ($node->arms as $index => $arm) {
                    if ($arm->condition === null || $arm->condition->evaluate($values)) {
                        $selectedArm = $index;
                        $this->trace?->branch($node->span, $index);
                        array_push($result, ...$this->select($arm->nodes, $values, $schema, $inLoop));
                        break;
                    }
                }
                if ($selectedArm === null) {
                    $this->trace?->branch($node->span, null);
                }
            } elseif ($node instanceof LoopNode) {
                array_push($result, ...$this->loop($node, $values, $schema, $inLoop));
            } elseif ($node instanceof ClauseNode) {
                $body = $this->select($node->nodes, $values, $schema, $inLoop);
                $this->reserve();
                $result[] = new ClauseNode($node->keyword, $body, $node->span);
            } elseif ($node instanceof BindNode) {
                if (! array_key_exists($node->path, $values) || ! isset($schema->definitions[$node->path])) {
                    throw new SqlException(ErrorCode::ParameterMissing, $node->span);
                }
                $this->reserve();
                $result[] = new ResolvedBind($node, $schema->definitions[$node->path], $values[$node->path]);
            } else {
                $this->reserve();
                $result[] = $node;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $values
     * @return list<Node>
     */
    private function loop(LoopNode $node, #[\SensitiveParameter] array $values, ParameterSchema $schema, bool $nested): array
    {
        $records = $values[$node->collection] ?? null;
        if (! is_array($records) || ! array_is_list($records)) {
            throw new SqlException(ErrorCode::ParameterType, $node->span);
        }
        if (count($records) > $this->limits->get('loop_items')) {
            throw new SqlException(ErrorCode::Limit, $node->span);
        }
        $this->trace?->loop($node->span, count($records));
        $scope = (new LoopScope)->extend($schema, $node->variable, $node->collection, $node->span, $nested);
        $fields = (new LoopScope)->fields($schema, $node->collection, $node->span);
        $result = [];
        foreach ($records as $index => $record) {
            if ($this->iterations >= $this->limits->get('loop_iterations')) {
                throw new SqlException(ErrorCode::Limit, $node->span);
            }
            $this->iterations++;
            if (! is_array($record)) {
                throw new SqlException(ErrorCode::ParameterType, $node->span);
            }
            $input = [];
            foreach ($record as $key => $value) {
                if (! is_string($key)) {
                    throw new SqlException(ErrorCode::ParameterType, $node->span);
                }
                $input[$key] = $value;
            }
            $scoped = $values;
            foreach ($fields->validateStructure($input) as $path => $value) {
                $scoped[$node->variable.'.'.$path] = $value;
            }
            $scoped['loop.index'] = $index;
            $scoped['loop.first'] = $index === 0;
            $scoped['loop.last'] = $index === count($records) - 1;
            $body = (new ScopeNormalizer)->normalize($this->select($node->nodes, $scoped, $scope, true));
            $significant = array_filter($body, static fn (Node $item): bool => ! ($item instanceof SqlNode && $item->token->isTrivia()));
            if ($significant === []) {
                continue;
            }
            if ($result !== []) {
                $this->reserve();
                $result[] = new SqlNode(new Token(TokenKind::Punctuation, $node->separator, $node->span));
            }
            array_push($result, ...$body);
        }
        if ($result === [] && $node->empty === 'error') {
            throw new SqlException(ErrorCode::EmptyList, $node->span);
        }

        return $result;
    }

    private function reserve(): void
    {
        if ($this->nodes >= $this->limits->get('ast_nodes')) {
            throw new SqlException(ErrorCode::Limit);
        }
        $this->nodes++;
    }
}
