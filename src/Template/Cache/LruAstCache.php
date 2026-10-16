<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Cache;

use Hft\TwoWaySql\Contracts\AstCache;
use Hft\TwoWaySql\Template\Ast\TemplateAst;
use InvalidArgumentException;

final class LruAstCache implements AstCache
{
    /** @var array<string, array{ast:TemplateAst, size:int}> */
    private array $entries = [];

    private int $bytes = 0;

    public function __construct(private readonly int $maxEntries = 256, private readonly int $maxBytes = 67108864)
    {
        if ($maxEntries < 1 || $maxBytes < 1) {
            throw new InvalidArgumentException('Positive cache limits required');
        }
    }

    public function get(string $key): ?TemplateAst
    {
        if (! isset($this->entries[$key])) {
            return null;
        }
        $entry = $this->entries[$key];
        unset($this->entries[$key]);
        $this->entries[$key] = $entry;

        return $entry['ast'];
    }

    public function put(string $key, TemplateAst $ast): void
    {
        $size = strlen((new AstCodec)->encode($ast));
        if ($size > $this->maxBytes) {
            return;
        }
        if (isset($this->entries[$key])) {
            $this->bytes -= $this->entries[$key]['size'];
            unset($this->entries[$key]);
        }
        while (count($this->entries) >= $this->maxEntries || $this->bytes + $size > $this->maxBytes) {
            $oldest = array_key_first($this->entries);
            if ($oldest === null) {
                break;
            }
            $this->bytes -= $this->entries[$oldest]['size'];
            unset($this->entries[$oldest]);
        }
        $this->entries[$key] = ['ast' => $ast, 'size' => $size];
        $this->bytes += $size;
    }

    public function clear(): void
    {
        $this->entries = [];
        $this->bytes = 0;
    }

    /** @return array{entries:int, bytes:int} */
    public function stats(): array
    {
        return ['entries' => count($this->entries), 'bytes' => $this->bytes];
    }
}
