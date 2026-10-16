<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Cache;

use Hft\TwoWaySql\Template\Ast\AssignmentsNode;
use Hft\TwoWaySql\Template\Ast\BindNode;
use Hft\TwoWaySql\Template\Ast\BranchArm;
use Hft\TwoWaySql\Template\Ast\BranchNode;
use Hft\TwoWaySql\Template\Ast\ClauseNode;
use Hft\TwoWaySql\Template\Ast\ColumnsNode;
use Hft\TwoWaySql\Template\Ast\LoopNode;
use Hft\TwoWaySql\Template\Ast\Node;
use Hft\TwoWaySql\Template\Ast\SortNode;
use Hft\TwoWaySql\Template\Ast\SqlNode;
use Hft\TwoWaySql\Template\Ast\TemplateAst;
use Hft\TwoWaySql\Template\Expression\Expression;
use Hft\TwoWaySql\Template\Expression\ExpressionNode;
use Hft\TwoWaySql\Template\Lexer\Token;
use Hft\TwoWaySql\Template\Lexer\TokenKind;
use Hft\TwoWaySql\Template\Source\SourceSpan;
use InvalidArgumentException;
use stdClass;
use Throwable;

final readonly class AstCodec
{
    public function encode(TemplateAst $ast): string
    {
        $data = ['version' => 5, 'grammar' => $ast->grammarVersion, 'hash' => $ast->sourceHash, 'references' => $ast->references, 'nodes' => array_map($this->encodeNode(...), $ast->nodes)];
        $data['checksum'] = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function encodeNode(Node $node): array
    {
        return match (true) {
            $node instanceof SqlNode => ['type' => 'sql', 'token' => $node->token],
            $node instanceof BindNode => ['type' => 'bind', 'path' => $node->path, 'list' => $node->list, 'span' => $node->span],
            $node instanceof SortNode => ['type' => 'sort', 'path' => $node->path, 'span' => $node->span],
            $node instanceof ColumnsNode => ['type' => 'columns', 'path' => $node->path, 'span' => $node->span],
            $node instanceof AssignmentsNode => ['type' => 'assignments', 'path' => $node->path, 'span' => $node->span],
            $node instanceof ClauseNode => ['type' => 'clause', 'keyword' => $node->keyword, 'nodes' => array_map($this->encodeNode(...), $node->nodes), 'span' => $node->span],
            $node instanceof LoopNode => ['type' => 'loop', 'variable' => $node->variable, 'collection' => $node->collection, 'separator' => $node->separator, 'empty' => $node->empty, 'nodes' => array_map($this->encodeNode(...), $node->nodes), 'span' => $node->span],
            $node instanceof BranchNode => ['type' => 'branch', 'arms' => array_map(fn (BranchArm $arm): array => ['condition' => $arm->condition, 'nodes' => array_map($this->encodeNode(...), $arm->nodes), 'span' => $arm->span], $node->arms), 'span' => $node->span],
            default => throw new InvalidArgumentException('Unknown AST node'),
        };
    }

    public function decode(string $json): ?TemplateAst
    {
        if (strlen($json) > 67108864) {
            return null;
        }
        try {
            $data = $this->object(json_decode($json, false, 256, JSON_THROW_ON_ERROR));
            $checksum = $this->string($data->checksum ?? null);
            unset($data->checksum);
            if (! hash_equals($checksum, hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)))) {
                return null;
            }
            $grammar = $this->string($data->grammar ?? null);
            if (($data->version ?? null) !== 5 || ! in_array($grammar, ['canonical-v1', 'doma-subset-v1'], true)) {
                return null;
            }
            $count = 0;
            $nodes = $this->nodes($data->nodes ?? null, $count);
            $hash = $this->string($data->hash ?? null);
            if (! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                return null;
            }

            return new TemplateAst($nodes, $hash, $this->strings($data->references ?? null), $grammar);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<Node> */
    private function nodes(mixed $value, int &$count): array
    {
        $nodes = [];
        foreach ($this->items($value) as $item) {
            if (++$count > 100000) {
                throw new InvalidArgumentException;
            }
            $data = $this->object($item);
            $type = $this->string($data->type ?? null);
            if ($type === 'sql') {
                $token = $this->object($data->token ?? null);
                $nodes[] = new SqlNode(new Token(TokenKind::from($this->string($token->kind ?? null)), $this->string($token->rawText ?? null), $this->span($token->span ?? null)));

                continue;
            }
            $span = $this->span($data->span ?? null);
            if ($type === 'bind') {
                if (! is_bool($data->list ?? null)) {
                    throw new InvalidArgumentException;
                }
                $nodes[] = new BindNode($this->string($data->path ?? null), $data->list, $span);
            } elseif (in_array($type, ['sort', 'columns', 'assignments'], true)) {
                $path = $this->string($data->path ?? null);
                if (! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*\z/D', $path)) {
                    throw new InvalidArgumentException;
                }
                $nodes[] = match ($type) {
                    'columns' => new ColumnsNode($path, $span),
                    'assignments' => new AssignmentsNode($path, $span),
                    default => new SortNode($path, $span),
                };
            } elseif ($type === 'clause') {
                $keyword = $this->string($data->keyword ?? null);
                if (! in_array($keyword, ['WHERE', 'HAVING', 'SET'], true)) {
                    throw new InvalidArgumentException;
                }
                $nodes[] = new ClauseNode($keyword, $this->nodes($data->nodes ?? null, $count), $span);
            } elseif ($type === 'loop') {
                $variable = $this->string($data->variable ?? null);
                $collection = $this->string($data->collection ?? null);
                $separator = $this->string($data->separator ?? null);
                $empty = $this->string($data->empty ?? null);
                if (! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $variable) || ! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*\z/D', $collection) || ! in_array($separator, [', ', ' AND ', ' OR '], true) || ! in_array($empty, ['error', 'omit'], true)) {
                    throw new InvalidArgumentException;
                }
                $nodes[] = new LoopNode($variable, $collection, $separator, $empty, $this->nodes($data->nodes ?? null, $count), $span);
            } elseif ($type === 'branch') {
                $arms = [];
                foreach ($this->items($data->arms ?? null) as $arm) {
                    $arm = $this->object($arm);
                    $expression = null;
                    if (($arm->condition ?? null) !== null) {
                        $condition = $this->object($arm->condition);
                        $expression = new Expression($this->expression($condition->root ?? null), $this->strings($condition->references ?? null), $this->span($condition->span ?? null));
                    }
                    $arms[] = new BranchArm($expression, $this->nodes($arm->nodes ?? null, $count), $this->span($arm->span ?? null));
                }
                if ($arms === []) {
                    throw new InvalidArgumentException;
                }
                $nodes[] = new BranchNode($arms, $span);
            } else {
                throw new InvalidArgumentException;
            }
        }

        return $nodes;
    }

    private function expression(mixed $value): ExpressionNode
    {
        $data = $this->object($value);
        $operation = $this->string($data->operation ?? null);
        $arity = match ($operation) {
            'literal', 'path' => 0, '!', 'isEmpty', 'count' => 1, '&&', '||', '==', '!=', '<', '<=', '>', '>=' => 2, default => throw new InvalidArgumentException
        };
        $children = array_map($this->expression(...), $this->items($data->children ?? null));
        if (count($children) !== $arity) {
            throw new InvalidArgumentException;
        }
        $value = $data->value ?? null;
        if (! is_int($value) && ! is_string($value) && ! is_bool($value) && $value !== null) {
            throw new InvalidArgumentException;
        }

        return new ExpressionNode($operation, $value, $children);
    }

    private function span(mixed $value): SourceSpan
    {
        $data = $this->object($value);

        return new SourceSpan($this->string($data->sourceId ?? null), $this->integer($data->offset ?? null), $this->integer($data->length ?? null), $this->integer($data->line ?? null, 1), $this->integer($data->column ?? null, 1));
    }

    private function object(mixed $value): stdClass
    {
        return $value instanceof stdClass ? $value : throw new InvalidArgumentException;
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : throw new InvalidArgumentException;
    }

    private function integer(mixed $value, int $min = 0): int
    {
        return is_int($value) && $value >= $min ? $value : throw new InvalidArgumentException;
    }

    /** @return list<mixed> */
    private function items(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : throw new InvalidArgumentException;
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return array_map($this->string(...), $this->items($value));
    }
}
