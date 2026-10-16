<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Diagnostics;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Template\Lexer\PgLexer;
use Hft\TwoWaySql\Template\Lexer\TokenKind;

/** Conservative lexical rejection supplements the trusted manifest kind. */
final class ExplainPolicy
{
    public static function validate(#[\SensitiveParameter] string $sql): void
    {
        $started = false;
        $previous = '';
        foreach ((new PgLexer(maxBytes: 8388608))->tokenize($sql) as $token) {
            if ($token->isTrivia()) {
                continue;
            }
            $word = $token->kind === TokenKind::Word ? strtoupper($token->rawText) : '';
            if (! $started && ! in_array($word, ['SELECT', 'WITH'], true)) {
                throw new SqlException(ErrorCode::QueryKind);
            }
            $started = true;
            if (in_array($word, ['INSERT', 'UPDATE', 'DELETE', 'MERGE', 'INTO', 'COPY', 'CALL', 'DO', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE'], true)
                || ($previous === 'FOR' && in_array($word, ['SHARE', 'KEY', 'NO'], true))) {
                throw new SqlException(ErrorCode::QueryKind);
            }
            $previous = $word;
        }
        if (! $started) {
            throw new SqlException(ErrorCode::QueryKind);
        }
    }
}
