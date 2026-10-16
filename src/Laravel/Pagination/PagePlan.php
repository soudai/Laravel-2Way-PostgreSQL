<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Pagination;

use Hft\TwoWaySql\Compiler\CompiledQuery;

/** @internal */
final readonly class PagePlan
{
    public function __construct(public CompiledQuery $data, public CompiledQuery $count) {}
}
