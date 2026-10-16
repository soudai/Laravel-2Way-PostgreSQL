<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Parser;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Template\Ast\BranchNode;
use Hft\TwoWaySql\Template\Ast\ClauseNode;
use Hft\TwoWaySql\Template\Ast\LoopNode;
use Hft\TwoWaySql\Template\Ast\Node;
use Hft\TwoWaySql\Template\Ast\SqlNode;
use Hft\TwoWaySql\Template\Lexer\TokenKind;

final readonly class ClauseScopeValidator
{
    /** @param list<Node> $nodes */
    public function check(string $keyword, array $nodes, int $depth = 0): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof SqlNode && $node->token->kind === TokenKind::Punctuation) {
                if ($node->token->rawText === '(') {
                    $depth++;
                } elseif ($node->token->rawText === ')') {
                    $depth--;
                }
            } elseif ($node instanceof ClauseNode) {
                if ($node->keyword === $keyword && $depth === 0) {
                    throw new SqlException(ErrorCode::Syntax, $node->span);
                }
                $this->check($keyword, $node->nodes, $depth);
            } elseif ($node instanceof LoopNode) {
                $this->check($keyword, $node->nodes, $depth);
            } elseif ($node instanceof BranchNode) {
                foreach ($node->arms as $arm) {
                    $this->check($keyword, $arm->nodes, $depth);
                }
            }
        }
    }
}
