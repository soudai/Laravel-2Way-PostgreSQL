<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Expression;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Parameter\ParameterSchema;
use Hft\TwoWaySql\Template\Source\SourceSpan;

final class ExpressionSession
{
    /** @var list<string> */
    private array $tokens = [];

    /** @var array<string, true> */
    private array $references = [];

    private int $cursor = 0;

    public function __construct(string $source, private readonly ParameterSchema $schema, private readonly SourceSpan $span, private readonly int $maxDepth, private readonly bool $functions = true)
    {
        $offset = 0;
        while ($offset < strlen($source)) {
            if (preg_match('/\G\s+/', $source, $match, 0, $offset)) {
                $offset += strlen($match[0]);

                continue;
            }
            if (! preg_match("/\\G(?:'(?:[^']|'')*'|[+-]?[0-9]+|[A-Za-z_][A-Za-z0-9_]*(?:\\.[A-Za-z_][A-Za-z0-9_]*)*|==|!=|<=|>=|&&|\\|\\||[!<>()])/", $source, $match, 0, $offset)) {
                throw new SqlException(ErrorCode::Syntax, $span);
            }
            if (count($this->tokens) >= 100000) {
                throw new SqlException(ErrorCode::Limit, $span);
            }
            $this->tokens[] = $match[0];
            $offset += strlen($match[0]);
        }
    }

    public function parse(): Expression
    {
        $root = $this->expression(1, 1);
        if (isset($this->tokens[$this->cursor])) {
            throw new SqlException(ErrorCode::Syntax, $this->span);
        }

        return new Expression($root, array_keys($this->references), $this->span);
    }

    private function expression(int $minimum, int $depth): ExpressionNode
    {
        $left = $this->primary($depth);
        $compared = false;
        while (isset($this->tokens[$this->cursor])) {
            $operator = $this->tokens[$this->cursor];
            $precedence = match ($operator) {
                '||' => 1, '&&' => 2, '==', '!=', '<', '<=', '>', '>=' => 3, default => 0
            };
            if ($precedence < $minimum) {
                break;
            }
            if ($precedence === 3 && $compared) {
                throw new SqlException(ErrorCode::Syntax, $this->span);
            }
            $compared = $precedence === 3;
            $this->cursor++;
            $right = $this->expression($precedence + 1, $depth + 1);
            $left = new ExpressionNode($operator, children: [$left, $right]);
            $this->checkDepth($left->depth);
        }

        return $left;
    }

    private function primary(int $depth, bool $allowNot = true): ExpressionNode
    {
        $this->checkDepth($depth);
        $token = $this->tokens[$this->cursor] ?? '';
        $this->cursor++;
        if ($token === '(') {
            $node = $this->expression(1, $depth + 1);
            $this->expect(')');

            return $node;
        }
        if ($token === '!' && $allowNot) {
            $node = new ExpressionNode('!', children: [$this->primary($depth + 1, false)]);
            $this->checkDepth($node->depth);

            return $node;
        }
        if (in_array($token, ['null', 'true', 'false'], true)) {
            return new ExpressionNode('literal', match ($token) {
                'null' => null, 'true' => true, 'false' => false
            });
        }
        if (str_starts_with($token, "'")) {
            return new ExpressionNode('literal', str_replace("''", "'", substr($token, 1, -1)));
        }
        if (preg_match('/\A[+-]?[0-9]+\z/D', $token)) {
            $negative = str_starts_with($token, '-');
            $digits = ltrim(ltrim($token, '+-'), '0');
            $digits = $digits === '' ? '0' : $digits;
            $maximum = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
            if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
                throw new SqlException(ErrorCode::ParameterType, $this->span);
            }

            return new ExpressionNode('literal', (int) (($negative ? '-' : '').$digits));
        }
        if ($token === 'isEmpty' || $token === 'count') {
            if (! $this->functions) {
                throw new SqlException(ErrorCode::Compatibility, $this->span);
            }
            $this->expect('(');
            $path = $this->tokens[$this->cursor] ?? '';
            $this->cursor++;
            $node = $this->path($path);
            $type = $this->schema->definitions[$path]['type'];
            if (! ($token === 'isEmpty' && $type === 'text') && ! str_starts_with($type, 'list<') && ! str_starts_with($type, 'pg_array<')) {
                throw new SqlException(ErrorCode::ParameterType, $this->span);
            }
            $this->expect(')');

            return new ExpressionNode($token, children: [$node]);
        }

        if (($this->tokens[$this->cursor] ?? '') === '(') {
            throw new SqlException(ErrorCode::Syntax, $this->span);
        }

        return $this->path($token);
    }

    private function path(string $path): ExpressionNode
    {
        if (! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*\z/D', $path)) {
            throw new SqlException(ErrorCode::Syntax, $this->span);
        }
        if (! isset($this->schema->definitions[$path])) {
            throw new SqlException(ErrorCode::ParameterMissing, $this->span, parameterPath: $path);
        }
        $this->references[$path] = true;

        return new ExpressionNode('path', $path);
    }

    private function expect(string $value): void
    {
        if (($this->tokens[$this->cursor] ?? '') !== $value) {
            throw new SqlException(ErrorCode::Syntax, $this->span);
        }
        $this->cursor++;
    }

    private function checkDepth(int $depth): void
    {
        if ($depth > $this->maxDepth) {
            throw new SqlException(ErrorCode::Limit, $this->span);
        }
    }
}
