<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Ast;

use Hft\TwoWaySql\Template\Lexer\Token;

final readonly class SqlNode implements Node
{
    public function __construct(public Token $token) {}
}
