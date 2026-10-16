<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Codegen\Dao;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

final class PhpSource
{
    public static function validate(string $source): void
    {
        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
            if (array_any($tokens, static fn (array|string $token): bool => is_array($token) && in_array($token[0], [T_EVAL, T_INLINE_HTML, T_CLOSE_TAG], true))) {
                throw new SqlException(ErrorCode::Generation);
            }
        } catch (\ParseError) {
            throw new SqlException(ErrorCode::Generation);
        }
    }
}
