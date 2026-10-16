<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Lexer;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Template\Source\SourceSpan;

final readonly class PgLexer
{
    public function __construct(private int $maxBytes = 1048576, private int $maxTokens = 100000, private int $maxDepth = 32) {}

    public function optionHash(): string
    {
        return hash('sha256', implode(':', [$this->maxBytes, $this->maxTokens, $this->maxDepth]));
    }

    /** @return list<Token> */
    public function tokenize(string $source, string $sourceId = 'query.sql', bool $checkStatement = true, int $baseOffset = 0, int $baseLine = 1, int $baseColumn = 1): array
    {
        $size = strlen($source);
        if ($this->maxBytes < 1 || $this->maxTokens < 1 || $this->maxDepth < 1 || $size > $this->maxBytes) {
            throw new SqlException(ErrorCode::Limit);
        }
        if (! mb_check_encoding($source, 'UTF-8') || str_contains($source, "\0")) {
            throw new SqlException(ErrorCode::Syntax);
        }
        $tokens = [];
        $offset = 0;
        $line = $baseLine;
        $column = $baseColumn;
        while ($offset < $size) {
            $start = $offset;
            $span = new SourceSpan($sourceId, $baseOffset + $start, 1, $line, $column);
            $char = $source[$offset];
            if (ctype_space($char)) {
                do {
                    $offset++;
                } while ($offset < $size && ctype_space($source[$offset]));
                $kind = TokenKind::Whitespace;
            } elseif (substr($source, $offset, 2) === '--') {
                $offset += 2;
                while ($offset < $size && $source[$offset] !== "\n" && $source[$offset] !== "\r") {
                    $offset++;
                }
                $kind = TokenKind::Comment;
            } elseif (substr($source, $offset, 2) === '/*') {
                $depth = 1;
                $offset += 2;
                while ($offset < $size && $depth > 0) {
                    $pair = substr($source, $offset, 2);
                    if ($pair === '/*') {
                        if (++$depth > $this->maxDepth) {
                            throw new SqlException(ErrorCode::Limit, $span);
                        }
                        $offset += 2;
                    } elseif ($pair === '*/') {
                        $depth--;
                        $offset += 2;
                    } else {
                        $offset++;
                    }
                }
                if ($depth !== 0) {
                    throw new SqlException(ErrorCode::Syntax, $span);
                }
                $kind = match (substr($source, $start, 3)) {
                    '/*:' => TokenKind::Bind,
                    '/*%' => TokenKind::Directive,
                    default => TokenKind::Comment,
                };
            } elseif (preg_match('/\G(?:[Uu]&[\'\"]|[BbXx]\')/', $source, $match, 0, $offset)) {
                throw new SqlException(ErrorCode::Unsupported, $span);
            } elseif ($char === "'" || $char === '"' || (($char === 'e' || $char === 'E') && ($source[$offset + 1] ?? '') === "'")) {
                $escape = $char === 'e' || $char === 'E';
                if ($escape) {
                    $offset++;
                }
                $quote = $source[$offset++];
                $closed = false;
                while ($offset < $size) {
                    if ($escape && $source[$offset] === '\\') {
                        $offset += min(2, $size - $offset);
                    } elseif ($source[$offset] === $quote) {
                        if (($source[$offset + 1] ?? '') === $quote) {
                            $offset += 2;
                        } else {
                            $offset++;
                            $closed = true;
                            break;
                        }
                    } else {
                        $offset++;
                    }
                }
                if (! $closed) {
                    throw new SqlException(ErrorCode::Syntax, $span);
                }
                $kind = $quote === '"' ? TokenKind::Identifier : TokenKind::Literal;
            } elseif ($char === '$' && preg_match('/\G\$(?:[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)?\$/', $source, $match, 0, $offset)) {
                $tag = $match[0];
                $end = strpos($source, $tag, $offset + strlen($tag));
                if ($end === false) {
                    throw new SqlException(ErrorCode::Syntax, $span);
                }
                $offset = $end + strlen($tag);
                $kind = TokenKind::Literal;
            } elseif (($char === '$' && ctype_digit($source[$offset + 1] ?? ''))
                || ($char === ':' && preg_match('/\G:[A-Za-z_]/', $source, $match, 0, $offset))) {
                throw new SqlException(ErrorCode::Unsupported, $span);
            } elseif (substr($source, $offset, 2) === '::') {
                $offset += 2;
                $kind = TokenKind::Operator;
            } elseif (preg_match('/\G[A-Za-z_\x80-\xff][A-Za-z0-9_$\x80-\xff]*/', $source, $match, 0, $offset)) {
                $offset += strlen($match[0]);
                $kind = TokenKind::Word;
            } elseif (preg_match('/\G(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?/', $source, $match, 0, $offset)) {
                $offset += strlen($match[0]);
                $kind = TokenKind::Number;
            } elseif (str_contains('+-*/<>=~!@#%^&|`?', $char)) {
                do {
                    $offset++;
                } while ($offset < $size && str_contains('+-*/<>=~!@#%^&|`?', $source[$offset]) && ! in_array(substr($source, $offset, 2), ['--', '/*'], true));
                $operator = substr($source, $start, $offset - $start);
                if (! preg_match('/[~!@#%^&|`?]/', $operator)) {
                    while ($offset - $start > 1 && str_contains('+-', $source[$offset - 1])) {
                        $offset--;
                    }
                }
                $kind = TokenKind::Operator;
            } else {
                $offset++;
                $kind = TokenKind::Punctuation;
            }
            if (count($tokens) >= $this->maxTokens) {
                throw new SqlException(ErrorCode::Limit, $span);
            }
            $raw = substr($source, $start, $offset - $start);
            $tokens[] = new Token($kind, $raw, new SourceSpan($sourceId, $baseOffset + $start, strlen($raw), $line, $column));
            $lines = preg_split('/\r\n|\r|\n/', $raw);
            if ($lines === false) {
                throw new SqlException(ErrorCode::Syntax, $span);
            }
            if (count($lines) > 1) {
                $line += count($lines) - 1;
                $column = mb_strlen($lines[count($lines) - 1], 'UTF-8') + 1;
            } else {
                $column += mb_strlen($raw, 'UTF-8');
            }
        }
        if ($checkStatement) {
            $this->assertSingleStatement($tokens);
        }

        return $tokens;
    }

    /** @param list<Token> $tokens */
    public function assertSingleStatement(array $tokens): void
    {
        $terminated = false;
        foreach ($tokens as $token) {
            if ($token->isTrivia() || $token->kind === TokenKind::Directive) {
                continue;
            }
            if ($terminated) {
                throw new SqlException(ErrorCode::Syntax, $token->span);
            }
            if ($token->kind === TokenKind::Punctuation && $token->rawText === ';') {
                $terminated = true;
            }
        }
    }

    /** @param list<Token> $tokens */
    public function assertQueryStatement(array $tokens): void
    {
        foreach ($tokens as $token) {
            if ($token->isTrivia() || $token->kind === TokenKind::Directive) {
                continue;
            }
            if ($token->kind !== TokenKind::Word || ! in_array(strtoupper($token->rawText), ['SELECT', 'WITH', 'INSERT', 'UPDATE', 'DELETE', 'MERGE'], true)) {
                throw new SqlException(ErrorCode::Unsupported, $token->span);
            }

            return;
        }
        throw new SqlException(ErrorCode::Syntax);
    }
}
