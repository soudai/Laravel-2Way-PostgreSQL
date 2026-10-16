<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Parser;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Template\Ast\Node;
use Hft\TwoWaySql\Template\Ast\SqlNode;
use Hft\TwoWaySql\Template\Lexer\TokenKind;
use Hft\TwoWaySql\Template\Source\SourceSpan;

final readonly class SortSample
{
    /** @param list<Node> $nodes */
    public function validate(array $nodes, SourceSpan $span): void
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
            do {
                $token = $tokens[$i] ?? null;
                if ($token === null || ! in_array($token->kind, [TokenKind::Word, TokenKind::Identifier], true)) {
                    throw new SqlException(ErrorCode::Sample, $span);
                }
                $i++;
                $dot = ($tokens[$i]->rawText ?? '') === '.';
                if ($dot) {
                    $i++;
                }
            } while ($dot);
            if (in_array(strtoupper($tokens[$i]->rawText ?? ''), ['ASC', 'DESC'], true)) {
                $i++;
            }
            if (strtoupper($tokens[$i]->rawText ?? '') === 'NULLS') {
                $i++;
                if (! in_array(strtoupper($tokens[$i]->rawText ?? ''), ['FIRST', 'LAST'], true)) {
                    throw new SqlException(ErrorCode::Sample, $span);
                }
                $i++;
            }
            if ($i === count($tokens)) {
                return;
            }
            if (($tokens[$i]->rawText ?? '') !== ',') {
                throw new SqlException(ErrorCode::Sample, $span);
            }
            $i++;
        } while (true);
    }
}
