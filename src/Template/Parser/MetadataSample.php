<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Parser;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Template\Ast\Node;
use Hft\TwoWaySql\Template\Ast\SqlNode;
use Hft\TwoWaySql\Template\Lexer\Token;
use Hft\TwoWaySql\Template\Lexer\TokenKind;
use Hft\TwoWaySql\Template\Source\SourceSpan;

final class MetadataSample
{
    /** @param list<Node> $nodes */
    public function validate(array $nodes, bool $assignments, SourceSpan $span): void
    {
        $tokens = [];
        foreach ($nodes as $node) {
            if (! $node instanceof SqlNode) {
                throw new SqlException(ErrorCode::Sample, $span);
            }
            if (! $node->token->isTrivia()) {
                $tokens[] = $node->token;
            }
        }
        $i = 0;
        do {
            $this->identifier($tokens[$i++] ?? null, $span);
            if ($assignments) {
                if (($tokens[$i++]->rawText ?? '') !== '=') {
                    throw new SqlException(ErrorCode::Sample, $span);
                }
                $value = $tokens[$i++] ?? null;
                if ($value?->kind === TokenKind::Operator && in_array($value->rawText, ['+', '-'], true)) {
                    $number = $tokens[$i] ?? null;
                    $i++;
                    if ($number === null || $number->kind !== TokenKind::Number) {
                        throw new SqlException(ErrorCode::Sample, $span);
                    }
                } elseif ($value === null || (! in_array($value->kind, [TokenKind::Number, TokenKind::Literal], true) && ! ($value->kind === TokenKind::Word && in_array(strtoupper($value->rawText), ['NULL', 'TRUE', 'FALSE'], true)))) {
                    throw new SqlException(ErrorCode::Sample, $span);
                }
            } else {
                while (($tokens[$i]->rawText ?? '') === '.') {
                    $i++;
                    $this->identifier($tokens[$i++] ?? null, $span);
                }
                if (strtoupper($tokens[$i]->rawText ?? '') === 'AS') {
                    $i++;
                    $this->identifier($tokens[$i++] ?? null, $span);
                }
            }
            if ($i === count($tokens)) {
                return;
            }
            if (($tokens[$i++]->rawText ?? '') !== ',') {
                throw new SqlException(ErrorCode::Sample, $span);
            }
        } while (true);
    }

    private function identifier(?Token $token, SourceSpan $span): void
    {
        if ($token === null || ! in_array($token->kind, [TokenKind::Word, TokenKind::Identifier], true)) {
            throw new SqlException(ErrorCode::Sample, $span);
        }
    }
}
