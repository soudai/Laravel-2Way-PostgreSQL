<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Compatibility;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Template\Ast\TemplateAst;
use Hft\TwoWaySql\Template\Expression\ExpressionNode;
use Hft\TwoWaySql\Template\Expression\ExpressionParser;
use Hft\TwoWaySql\Template\Lexer\PgLexer;
use Hft\TwoWaySql\Template\Lexer\Token;
use Hft\TwoWaySql\Template\Lexer\TokenKind;
use Hft\TwoWaySql\Template\Parser\ParseSession;
use Hft\TwoWaySql\Template\QueryDefinition;
use Hft\TwoWaySql\Template\Source\SourceSpan;

/** Explicit adapter: only comment tokens change; SQL text and source positions remain intact. */
final readonly class DomaSubsetParser
{
    public function __construct(private PgLexer $lexer, private int $maxDepth, private int $maxNodes) {}

    public function parse(QueryDefinition $definition): TemplateAst
    {
        try {
            $tokens = $this->lexer->tokenize($definition->source, $definition->path);
            $this->lexer->assertQueryStatement($tokens);
            $adapted = [];
            $previous = null;
            $depth = 0;
            foreach ($tokens as $index => $token) {
                if ($token->kind === TokenKind::Bind) {
                    throw new SqlException(ErrorCode::Compatibility, $token->span);
                }
                if ($token->kind === TokenKind::Directive) {
                    $body = trim(substr($token->rawText, 3, -2));
                    if (preg_match('/\Aif\s+(.+)\z/D', $body, $match)) {
                        if ($previous !== null && ($previous->kind === TokenKind::Operator || in_array(strtoupper($previous->rawText), ['WHERE', 'HAVING', 'SET', 'AND', 'OR', '('], true))) {
                            throw new SqlException(ErrorCode::Compatibility, $token->span);
                        }
                        $expression = (new ExpressionParser($this->maxDepth, false))->parse($match[1], $definition->parameters, $token->span);
                        $this->expression($expression->root, $token->span);
                        if (++$depth > $this->maxDepth) {
                            throw new SqlException(ErrorCode::Limit, $token->span);
                        }
                    } elseif ($body === 'end') {
                        $depth--;
                    } else {
                        throw new SqlException(ErrorCode::Compatibility, $token->span);
                    }
                } elseif ($token->kind === TokenKind::Comment) {
                    if (str_starts_with($token->rawText, '--|')) {
                        throw new SqlException(ErrorCode::Compatibility, $token->span);
                    }
                    if (str_starts_with($token->rawText, '/*')) {
                        $body = trim(substr($token->rawText, 2, -2));
                        $path = preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*\z/D', $body) === 1;
                        if ($path && isset($definition->parameters->definitions[$body])) {
                            $token = new Token(TokenKind::Bind, '/*:'.$body.'*/', $token->span);
                        } elseif (preg_match('/\A[#^@!+]/', $body) || ($this->sampleAfter($tokens, $index) && ($path || preg_match('~[()@#^+*/<>=!?\[\]-]~', $body)))) {
                            throw new SqlException(ErrorCode::Compatibility, $token->span);
                        }
                    }
                } elseif ($token->kind === TokenKind::Word && $depth > 0 && in_array(strtoupper($token->rawText), ['WHERE', 'HAVING', 'SET'], true)) {
                    throw new SqlException(ErrorCode::Compatibility, $token->span);
                }
                if (! $token->isTrivia() && $token->kind !== TokenKind::Directive) {
                    $previous = $token;
                }
                $adapted[] = $token;
            }

            return (new ParseSession($definition, $adapted, $this->maxDepth, $this->maxNodes))->parse();
        } catch (SqlException $error) {
            if (in_array($error->errorCode, ['TW_SYNTAX', 'TW_UNSUPPORTED'], true)) {
                throw new SqlException(ErrorCode::Compatibility, $error->span, $definition->id, $error->parameterPath);
            }
            throw $error;
        }
    }

    /** @param list<Token> $tokens */
    private function sampleAfter(array $tokens, int $index): bool
    {
        do {
            $next = $tokens[++$index] ?? null;
        } while ($next?->kind === TokenKind::Whitespace);

        return $next !== null && (in_array($next->kind, [TokenKind::Literal, TokenKind::Number], true)
            || in_array(strtoupper($next->rawText), ['NULL', 'TRUE', 'FALSE', '(', '+', '-'], true));
    }

    private function expression(ExpressionNode $node, SourceSpan $span): void
    {
        if (! in_array($node->operation, ['path', 'literal', '!', '&&', '||', '==', '!=', '<', '<=', '>', '>='], true)) {
            throw new SqlException(ErrorCode::Compatibility, $span);
        }
        foreach ($node->children as $child) {
            $this->expression($child, $span);
        }
    }
}
