<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Lexer;

use Hft\TwoWaySql\Template\Source\SourceSpan;

final readonly class Token
{
    public function __construct(public TokenKind $kind, public string $rawText, public SourceSpan $span) {}

    public function isTrivia(): bool
    {
        return $this->kind === TokenKind::Whitespace || $this->kind === TokenKind::Comment;
    }
}
