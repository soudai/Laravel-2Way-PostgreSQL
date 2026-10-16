<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Lexer;

enum TokenKind: string
{
    case Word = 'word';
    case Number = 'number';
    case Literal = 'literal';
    case Identifier = 'identifier';
    case Whitespace = 'whitespace';
    case Comment = 'comment';
    case Bind = 'bind';
    case Directive = 'directive';
    case Operator = 'operator';
    case Punctuation = 'punctuation';
}
