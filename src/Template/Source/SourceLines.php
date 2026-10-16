<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Source;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

final readonly class SourceLines
{
    /** @var list<array{string, int}> */
    private array $lines;

    public function __construct(private string $source)
    {
        $lines = preg_split('/\r\n|\r|\n/', $source, flags: PREG_SPLIT_OFFSET_CAPTURE);
        if ($lines === false) {
            throw new SqlException(ErrorCode::Syntax);
        }
        $this->lines = $lines;
    }

    public function prefix(SourceSpan $span): string
    {
        $start = $this->lines[$span->line - 1][1];

        return substr($this->source, $start, $span->offset - $start);
    }

    public function suffix(SourceSpan $span): string
    {
        [$line, $start] = $this->lines[$span->line - 1];
        $end = $span->offset + $span->length;

        return substr($this->source, $end, max(0, $start + strlen($line) - $end));
    }
}
