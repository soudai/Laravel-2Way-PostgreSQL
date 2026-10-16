<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Expression;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Template\Source\SourceSpan;

final readonly class Expression
{
    /** @param list<string> $references */
    public function __construct(public ExpressionNode $root, public array $references, public SourceSpan $span) {}

    /** @param array<string, mixed> $values */
    public function evaluate(#[\SensitiveParameter] array $values): bool
    {
        return $this->boolean($this->value($this->root, $values));
    }

    private function boolean(#[\SensitiveParameter] mixed $value): bool
    {
        if (! is_bool($value)) {
            throw new SqlException(ErrorCode::ParameterType, $this->span);
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function value(ExpressionNode $node, #[\SensitiveParameter] array $values): mixed
    {
        if ($node->operation === 'literal') {
            return $node->value;
        }
        if ($node->operation === 'path') {
            if (! is_string($node->value) || ! array_key_exists($node->value, $values)) {
                throw new SqlException(ErrorCode::ParameterMissing, $this->span);
            }

            return $values[$node->value];
        }
        $left = $this->value($node->children[0], $values);
        if ($node->operation === '!') {
            return ! $this->boolean($left);
        }
        if ($node->operation === '&&') {
            return $this->boolean($left) && $this->boolean($this->value($node->children[1], $values));
        }
        if ($node->operation === '||') {
            return $this->boolean($left) || $this->boolean($this->value($node->children[1], $values));
        }
        if ($node->operation === 'count') {
            if (! is_array($left) || ! array_is_list($left)) {
                throw new SqlException(ErrorCode::ParameterType, $this->span);
            }

            return count($left);
        }
        if ($node->operation === 'isEmpty') {
            if (! is_string($left) && ! (is_array($left) && array_is_list($left))) {
                throw new SqlException(ErrorCode::ParameterType, $this->span);
            }

            return $left === '' || $left === [];
        }
        $right = $this->value($node->children[1], $values);
        if (is_object($left) || is_object($right) || is_resource($left) || is_resource($right)) {
            throw new SqlException(ErrorCode::ParameterType, $this->span);
        }
        if ($node->operation === '==') {
            return $left === $right;
        }
        if ($node->operation === '!=') {
            return $left !== $right;
        }
        if (is_int($left) && is_int($right)) {
            $comparison = $left <=> $right;
        } elseif (is_string($left) && is_string($right)) {
            $comparison = strcmp($left, $right);
        } else {
            throw new SqlException(ErrorCode::ParameterType, $this->span);
        }

        return match ($node->operation) {
            '<' => $comparison < 0, '<=' => $comparison <= 0, '>' => $comparison > 0, '>=' => $comparison >= 0,
            default => throw new SqlException(ErrorCode::Syntax, $this->span),
        };
    }
}
