<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Compiler\Normalizer;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Template\Ast\ClauseNode;
use Hft\TwoWaySql\Template\Ast\Node;
use Hft\TwoWaySql\Template\Ast\SqlNode;
use Hft\TwoWaySql\Template\Lexer\Token;
use Hft\TwoWaySql\Template\Lexer\TokenKind;

final readonly class ScopeNormalizer
{
    /** @param list<Node> $nodes
     * @return list<Node>
     */
    public function normalize(array $nodes): array
    {
        $output = [];
        foreach ($nodes as $node) {
            if (! $node instanceof ClauseNode) {
                $output[] = $node;

                continue;
            }
            $body = $this->normalize($node->nodes);
            $significant = [];
            foreach ($body as $index => $piece) {
                if (! ($piece instanceof SqlNode && $piece->token->isTrivia())) {
                    $significant[] = $index;
                }
            }
            $keywordIndex = array_shift($significant);
            if ($keywordIndex === null) {
                throw new SqlException(ErrorCode::Syntax, $node->span);
            }
            $firstIndex = $significant[0] ?? null;
            $first = $firstIndex === null ? null : $body[$firstIndex];
            if ($first instanceof SqlNode && (($node->keyword === 'SET' && $first->token->rawText === ',')
                || ($node->keyword !== 'SET' && $first->token->kind === TokenKind::Word && in_array(strtoupper($first->token->rawText), ['AND', 'OR'], true)))) {
                $body[$firstIndex] = new SqlNode(new Token(TokenKind::Whitespace, ' ', $first->token->span));
                array_shift($significant);
            }
            if ($significant === []) {
                if ($node->keyword === 'SET') {
                    throw new SqlException(ErrorCode::EmptySet, $node->span);
                }
                $keyword = $body[$keywordIndex];
                if (! $keyword instanceof SqlNode) {
                    throw new SqlException(ErrorCode::Syntax, $node->span);
                }
                $body[$keywordIndex] = new SqlNode(new Token(TokenKind::Whitespace, ' ', $keyword->token->span));
            }
            array_push($output, ...$body);
        }

        return $output;
    }
}
