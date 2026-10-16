<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Lexer;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

/** Identifies the outer command; this is not a full PostgreSQL syntax validator. */
final readonly class StatementShape
{
    private function __construct(public string $verb, public bool $returning, public bool $doSelect) {}

    public static function inspect(string $sql): self
    {
        $tokens = array_values(array_filter((new PgLexer(maxBytes: 8388608))->tokenize($sql), static fn (Token $token): bool => ! $token->isTrivia()));
        $doSelect = false;
        foreach ($tokens as $position => $token) {
            if (self::word($tokens, $position) === 'DO' && self::word($tokens, $position + 1) === 'SELECT') {
                $doSelect = true;
            }
        }
        $index = 0;
        if (self::word($tokens, $index) === 'WITH') {
            $index++;
            if (self::word($tokens, $index) === 'RECURSIVE') {
                $index++;
            }
            do {
                self::identifier($tokens, $index);
                if (($tokens[$index]->rawText ?? null) === '(') {
                    self::parentheses($tokens, $index);
                }
                self::expect($tokens, $index, 'AS');
                if (self::word($tokens, $index) === 'NOT') {
                    $index++;
                    self::expect($tokens, $index, 'MATERIALIZED');
                } elseif (self::word($tokens, $index) === 'MATERIALIZED') {
                    $index++;
                }
                self::parentheses($tokens, $index);
                if (self::word($tokens, $index) === 'SEARCH') {
                    $index++;
                    if (! in_array(self::word($tokens, $index), ['BREADTH', 'DEPTH'], true)) {
                        throw new SqlException(ErrorCode::Syntax);
                    }
                    $index++;
                    self::expect($tokens, $index, 'FIRST');
                    self::expect($tokens, $index, 'BY');
                    self::identifiers($tokens, $index);
                    self::expect($tokens, $index, 'SET');
                    self::identifier($tokens, $index);
                }
                if (self::word($tokens, $index) === 'CYCLE') {
                    $index++;
                    self::identifiers($tokens, $index);
                    self::expect($tokens, $index, 'SET');
                    self::identifier($tokens, $index);
                    if (self::word($tokens, $index) === 'TO') {
                        $index++;
                        self::constant($tokens, $index);
                        self::expect($tokens, $index, 'DEFAULT');
                        self::constant($tokens, $index);
                    }
                    self::expect($tokens, $index, 'USING');
                    self::identifier($tokens, $index);
                }
                $another = ($tokens[$index]->rawText ?? null) === ',';
                if ($another) {
                    $index++;
                }
            } while ($another);
        }
        $verb = self::word($tokens, $index);
        $returning = false;
        $depth = 0;
        for ($i = $index; $i < count($tokens); $i++) {
            $raw = $tokens[$i]->rawText;
            if ($tokens[$i]->kind === TokenKind::Punctuation) {
                $depth += $raw === '(' ? 1 : ($raw === ')' ? -1 : 0);
            }
            if ($depth === 0 && self::word($tokens, $i) === 'RETURNING') {
                $returning = true;
            }
        }

        return new self($verb, $returning, $doSelect);
    }

    /** @param list<Token> $tokens */
    private static function word(array $tokens, int $index): string
    {
        return isset($tokens[$index]) && $tokens[$index]->kind === TokenKind::Word ? strtoupper($tokens[$index]->rawText) : '';
    }

    /** @param list<Token> $tokens */
    private static function expect(array $tokens, int &$index, string $word): void
    {
        if (self::word($tokens, $index) !== $word) {
            throw new SqlException(ErrorCode::Syntax);
        }
        $index++;
    }

    /** @param list<Token> $tokens */
    private static function identifier(array $tokens, int &$index): void
    {
        if (! isset($tokens[$index]) || ! in_array($tokens[$index]->kind, [TokenKind::Word, TokenKind::Identifier], true)) {
            throw new SqlException(ErrorCode::Syntax);
        }
        $index++;
    }

    /** @param list<Token> $tokens */
    private static function identifiers(array $tokens, int &$index): void
    {
        do {
            self::identifier($tokens, $index);
            $another = ($tokens[$index]->rawText ?? null) === ',';
            if ($another) {
                $index++;
            }
        } while ($another);
    }

    /** @param list<Token> $tokens */
    private static function parentheses(array $tokens, int &$index): void
    {
        if (($tokens[$index]->rawText ?? null) !== '(') {
            throw new SqlException(ErrorCode::Syntax);
        }
        $depth = 0;
        do {
            $token = $tokens[$index] ?? throw new SqlException(ErrorCode::Syntax);
            if ($token->kind === TokenKind::Punctuation) {
                $depth += $token->rawText === '(' ? 1 : ($token->rawText === ')' ? -1 : 0);
            }
            $index++;
        } while ($depth > 0);
    }

    /** @param list<Token> $tokens */
    private static function constant(array $tokens, int &$index): void
    {
        if (in_array($tokens[$index]->rawText ?? null, ['+', '-'], true)) {
            $index++;
        }
        if (! isset($tokens[$index]) || (! in_array($tokens[$index]->kind, [TokenKind::Literal, TokenKind::Number], true) && ! in_array(self::word($tokens, $index), ['TRUE', 'FALSE', 'NULL'], true))) {
            throw new SqlException(ErrorCode::Syntax);
        }
        $index++;
    }
}
