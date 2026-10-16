<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Pagination;

final readonly class PageResult
{
    public int $totalPages;

    /** @param list<\stdClass> $rows */
    public function __construct(#[\SensitiveParameter] public array $rows, public int $total, public int $page, public int $perPage)
    {
        $this->totalPages = intdiv($total, $perPage) + ($total % $perPage === 0 ? 0 : 1);
    }

    /** @return array<string, int> */
    public function __debugInfo(): array
    {
        return ['rowCount' => count($this->rows), 'total' => $this->total, 'page' => $this->page, 'perPage' => $this->perPage];
    }
}
