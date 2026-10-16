<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class SqlQuery
{
    public function __construct(public string $id, public string $mode = 'select', public ?string $result = null) {}
}
