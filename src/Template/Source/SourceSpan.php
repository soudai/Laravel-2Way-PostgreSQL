<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Source;

final readonly class SourceSpan
{
    public function __construct(
        public string $sourceId,
        public int $offset,
        public int $length,
        public int $line,
        public int $column,
    ) {}
}
