<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Parser;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Parameter\LoopScope;
use Hft\TwoWaySql\Parameter\ParameterSchema;
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
use Hft\TwoWaySql\Template\Expression\ExpressionParser;
use Hft\TwoWaySql\Template\Lexer\Token;
use Hft\TwoWaySql\Template\Lexer\TokenKind;
use Hft\TwoWaySql\Template\QueryDefinition;
use Hft\TwoWaySql\Template\Source\SourceLines;

/** Mutable cursor exists only for a single parse; cached AST contains no session. */
final class ParseSession
{
    private int $cursor = 0;

    private int $nodeCount = 0;

    /** @var array<string, true> */
    private array $references = [];

    private readonly SourceLines $lines;

    private ParameterSchema $scope;

    private int $loopDepth = 0;

    /** @param list<Token> $tokens */
    public function __construct(private readonly QueryDefinition $definition, private readonly array $tokens, private readonly int $maxDepth, private readonly int $maxNodes)
    {
        $this->lines = new SourceLines($definition->source);
        $this->scope = $definition->parameters;
    }

    public function parse(): TemplateAst
    {
        $nodes = $this->nodes([], 0);

        return new TemplateAst($nodes, $this->definition->sourceHash, array_keys($this->references), $this->definition->grammar);
    }

    /** @param list<string> $stops
     * @return list<Node>
     */
    private function nodes(array $stops, int $depth): array
    {
        $nodes = [];
        while (isset($this->tokens[$this->cursor])) {
            $token = $this->tokens[$this->cursor];
            if ($depth > $this->maxDepth || $this->nodeCount >= $this->maxNodes) {
                throw new SqlException(ErrorCode::Limit, $token->span);
            }
            if ($token->kind === TokenKind::Directive) {
                [$command, $argument] = $this->directive($token);
                if (in_array($command, $stops, true)) {
                    return $nodes;
                }
                $this->cursor++;
                $node = match ($command) {
                    'if' => $this->branch($token, $argument, $depth + 1),
                    'where', 'having', 'set' => $this->clause($token, $command, $argument, $depth + 1),
                    'for' => $this->loop($token, $argument, $depth + 1),
                    'orderBy' => $this->sort($token, $argument, $depth + 1),
                    'columns', 'assignments' => $this->metadata($token, $command, $argument, $depth + 1),
                    default => throw new SqlException(ErrorCode::Syntax, $token->span),
                };
            } elseif ($token->kind === TokenKind::Bind) {
                $index = $this->cursor++;
                $node = $this->bind($token, $index);
            } else {
                $this->cursor++;
                $node = new SqlNode($token);
            }
            if ($this->nodeCount >= $this->maxNodes) {
                throw new SqlException(ErrorCode::Limit, $token->span);
            }
            $this->nodeCount++;
            $nodes[] = $node;
        }

        return $nodes;
    }

    /** @return array{string,string} */
    private function directive(Token $token): array
    {
        if (trim($this->lines->prefix($token->span)) !== '' || trim($this->lines->suffix($token->span)) !== '' || str_contains($token->rawText, "\n") || str_contains($token->rawText, "\r")) {
            throw new SqlException(ErrorCode::Syntax, $token->span);
        }
        $body = trim(substr($token->rawText, 3, -2));
        if (! preg_match('/\A([A-Za-z]+)(?:\s+(.*))?\z/D', $body, $match)) {
            throw new SqlException(ErrorCode::Syntax, $token->span);
        }

        return [$match[1], $match[2] ?? ''];
    }

    private function branch(Token $start, string $condition, int $depth): BranchNode
    {
        if ($condition === '') {
            throw new SqlException(ErrorCode::Syntax, $start->span);
        }
        $arms = [];
        $armToken = $start;
        $seenElse = false;
        while (true) {
            $body = $this->nodes(['elseif', 'else', 'end'], $depth);
            $expression = $seenElse ? null : (new ExpressionParser($this->maxDepth))->parse($condition, $this->scope, $armToken->span);
            foreach ($expression->references ?? [] as $path) {
                $this->references[$path] = true;
            }
            $arms[] = new BranchArm($expression, $body, $armToken->span);
            $end = $this->tokens[$this->cursor] ?? null;
            if ($end === null) {
                throw new SqlException(ErrorCode::Syntax, $start->span);
            }
            [$command, $argument] = $this->directive($end);
            $this->cursor++;
            if ($command === 'end') {
                if ($argument !== '') {
                    throw new SqlException(ErrorCode::Syntax, $end->span);
                }

                return new BranchNode($arms, $start->span);
            }
            if ($seenElse || ($command === 'else' && $argument !== '') || ($command === 'elseif' && $argument === '')) {
                throw new SqlException(ErrorCode::Syntax, $end->span);
            }
            $seenElse = $command === 'else';
            $condition = $argument;
            $armToken = $end;
        }
    }

    private function clause(Token $start, string $command, string $argument, int $depth): ClauseNode
    {
        if ($argument !== '') {
            throw new SqlException(ErrorCode::Syntax, $start->span);
        }
        $nodes = $this->nodes(['end'], $depth);
        $end = $this->tokens[$this->cursor] ?? null;
        if ($end === null || $this->directive($end) !== ['end', '']) {
            throw new SqlException(ErrorCode::Syntax, $start->span);
        }
        $this->cursor++;
        $first = null;
        foreach ($nodes as $node) {
            if ($node instanceof SqlNode && $node->token->isTrivia()) {
                continue;
            }
            $first = $node;
            break;
        }
        if (! $first instanceof SqlNode || $first->token->kind !== TokenKind::Word || strcasecmp($first->token->rawText, $command) !== 0) {
            throw new SqlException(ErrorCode::Syntax, $start->span);
        }

        (new ClauseScopeValidator)->check(strtoupper($command), $nodes);

        return new ClauseNode(strtoupper($command), $nodes, $start->span);
    }

    private function sort(Token $start, string $path, int $depth): SortNode
    {
        if (! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*\z/D', $path)) {
            throw new SqlException(ErrorCode::Syntax, $start->span);
        }
        $this->definition->sorts->assertPath($path, $start->span);
        $nodes = $this->nodes(['end'], $depth);
        $end = $this->tokens[$this->cursor] ?? null;
        if ($end === null || $this->directive($end) !== ['end', '']) {
            throw new SqlException(ErrorCode::Syntax, $start->span);
        }
        $this->cursor++;
        (new SortSample)->validate($nodes, $start->span);
        $this->references[$path] = true;

        return new SortNode($path, $start->span);
    }

    private function loop(Token $start, string $argument, int $depth): LoopNode
    {
        if (! preg_match('/\A([A-Za-z_][A-Za-z0-9_]*)\s+in\s+([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)\s+separator="(, | AND | OR )"(?:\s+empty="(error|omit)")?\z/D', $argument, $match)) {
            throw new SqlException(ErrorCode::Syntax, $start->span);
        }
        [$variable, $collection, $separator, $empty] = [$match[1], $match[2], $match[3], $match[4] ?? 'error'];
        $parent = $this->scope;
        $this->scope = (new LoopScope)->extend($parent, $variable, $collection, $start->span, $this->loopDepth > 0);
        $this->references[$collection] = true;
        $this->loopDepth++;
        try {
            $nodes = $this->nodes(['end'], $depth);
        } finally {
            $this->scope = $parent;
            $this->loopDepth--;
        }
        $end = $this->tokens[$this->cursor] ?? null;
        if ($end === null || $this->directive($end) !== ['end', '']) {
            throw new SqlException(ErrorCode::Syntax, $start->span);
        }
        $this->cursor++;

        return new LoopNode($variable, $collection, $separator, $empty, $nodes, $start->span);
    }

    private function metadata(Token $start, string $command, string $path, int $depth): ColumnsNode|AssignmentsNode
    {
        if (! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*\z/D', $path)) {
            throw new SqlException(ErrorCode::Syntax, $start->span);
        }
        $assignments = $command === 'assignments';
        if ($assignments) {
            if (($this->definition->parameters->definitions[$path]['type'] ?? null) !== 'patch<record>') {
                throw new SqlException(ErrorCode::Generation, $start->span);
            }
        } else {
            $this->definition->projections->assertPath($path, $start->span);
        }
        $nodes = $this->nodes(['end'], $depth);
        $end = $this->tokens[$this->cursor] ?? null;
        if ($end === null || $this->directive($end) !== ['end', '']) {
            throw new SqlException(ErrorCode::Syntax, $start->span);
        }
        $this->cursor++;
        (new MetadataSample)->validate($nodes, $assignments, $start->span);
        $this->references[$path] = true;

        return $assignments ? new AssignmentsNode($path, $start->span) : new ColumnsNode($path, $start->span);
    }

    private function bind(Token $token, int $index): BindNode
    {
        $path = substr($token->rawText, 3, -2);
        if (! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*\z/D', $path)) {
            throw new SqlException(ErrorCode::Syntax, $token->span);
        }
        $spec = $this->scope->definitions[$path] ?? null;
        if ($spec === null) {
            throw new SqlException(ErrorCode::ParameterMissing, $token->span, parameterPath: $path);
        }
        $this->references[$path] = true;
        if (in_array($spec['type'], ['list<record>', 'patch<record>'], true)) {
            throw new SqlException(ErrorCode::ParameterType, $token->span);
        }
        $list = str_starts_with($spec['type'], 'list<');
        $this->whitespace();
        if ($list) {
            $previous = $index - 1;
            while ($previous >= 0 && $this->tokens[$previous]->isTrivia()) {
                $previous--;
            }
            if ($previous < 0 || $this->tokens[$previous]->kind !== TokenKind::Word || strcasecmp($this->tokens[$previous]->rawText, 'IN') !== 0
                || ($this->tokens[$this->cursor]->rawText ?? '') !== '(') {
                throw new SqlException(ErrorCode::Sample, $token->span);
            }
            $this->cursor++;
            do {
                $this->whitespace();
                $this->scalar($token);
                $this->whitespace();
                $next = $this->tokens[$this->cursor] ?? null;
                $this->cursor++;
                if ($next?->rawText === ')') {
                    break;
                }
                if ($next?->rawText !== ',') {
                    throw new SqlException(ErrorCode::Sample, $token->span);
                }
            } while (true);
        } else {
            $this->scalar($token);
            $next = $this->cursor;
            while (isset($this->tokens[$next]) && $this->tokens[$next]->isTrivia()) {
                $next++;
            }
            if (($this->tokens[$next]->kind ?? null) === TokenKind::Literal) {
                throw new SqlException(ErrorCode::Sample, $token->span);
            }
        }

        return new BindNode($path, $list, $token->span);
    }

    private function scalar(Token $bind): void
    {
        $token = $this->tokens[$this->cursor] ?? null;
        $this->cursor++;
        if ($token === null) {
            throw new SqlException(ErrorCode::Sample, $bind->span);
        }
        if ($token->kind === TokenKind::Operator && in_array($token->rawText, ['+', '-'], true)) {
            $number = $this->tokens[$this->cursor] ?? null;
            $this->cursor++;
            if ($number?->kind !== TokenKind::Number) {
                throw new SqlException(ErrorCode::Sample, $bind->span);
            }

            return;
        }
        if (in_array($token->kind, [TokenKind::Literal, TokenKind::Number], true)
            || ($token->kind === TokenKind::Word && in_array(strtoupper($token->rawText), ['NULL', 'TRUE', 'FALSE'], true))) {
            return;
        }
        throw new SqlException(ErrorCode::Sample, $bind->span);
    }

    private function whitespace(): void
    {
        while (($this->tokens[$this->cursor]->kind ?? null) === TokenKind::Whitespace) {
            $this->cursor++;
        }
    }
}
