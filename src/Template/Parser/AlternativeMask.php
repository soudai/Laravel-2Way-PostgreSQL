<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Parser;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Template\Lexer\PgLexer;
use Hft\TwoWaySql\Template\Lexer\Token;
use Hft\TwoWaySql\Template\Lexer\TokenKind;
use Hft\TwoWaySql\Template\QueryDefinition;
use Hft\TwoWaySql\Template\Source\SourceLines;

final readonly class AlternativeMask
{
    public function __construct(private int $maxDepth = 32) {}

    /** @return list<Token> */
    public function tokens(QueryDefinition $definition, PgLexer $lexer): array
    {
        $tokens = $lexer->tokenize($definition->source, $definition->path);
        $lexer->assertQueryStatement($tokens);
        $lines = new SourceLines($definition->source);
        $chunks = [];
        $copied = 0;
        $stack = [];
        foreach ($tokens as $token) {
            $alternate = in_array(true, $stack, true);
            if ($token->kind === TokenKind::Directive) {
                preg_match('/\A\s*([A-Za-z]+)/', substr($token->rawText, 3, -2), $match);
                $command = $match[1] ?? '';
                if (in_array($command, ['if', 'where', 'having', 'set', 'for', 'orderBy', 'columns', 'assignments'], true)) {
                    $stack[] = false;
                    if (count($stack) > $this->maxDepth) {
                        throw new SqlException(ErrorCode::Limit, $token->span);
                    }
                } elseif (in_array($command, ['else', 'elseif'], true) && $stack !== []) {
                    $stack[array_key_last($stack)] = true;
                } elseif ($command === 'end') {
                    array_pop($stack);
                }

                continue;
            }
            if ($token->kind === TokenKind::Comment && str_starts_with($token->rawText, '--|')) {
                if (trim($lines->prefix($token->span)) !== '') {
                    continue;
                }
                if (! $alternate || ! str_starts_with($token->rawText, '--| ') || preg_match('/\A\s*\/\*%.*\*\/\s*\z/D', substr($token->rawText, 4))) {
                    throw new SqlException(ErrorCode::Syntax, $token->span);
                }
                // Same byte length preserves every original offset and Unicode column.
                $chunks[] = substr($definition->source, $copied, $token->span->offset - $copied).'    ';
                $copied = $token->span->offset + 4;
            } elseif ($alternate && ! $token->isTrivia()) {
                throw new SqlException(ErrorCode::Syntax, $token->span);
            }
        }

        if ($chunks === []) {
            return $tokens;
        }
        $chunks[] = substr($definition->source, $copied);

        return $lexer->tokenize(implode('', $chunks), $definition->path, false);
    }
}
