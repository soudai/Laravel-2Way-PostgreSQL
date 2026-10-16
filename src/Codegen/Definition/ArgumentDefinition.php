<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Codegen\Definition;

final readonly class ArgumentDefinition
{
    public function __construct(public string $name, public string $type, public ?string $doc = null) {}

    /** @return array{name:string, type:string, doc?:string} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'type' => $this->type] + ($this->doc === null ? [] : ['doc' => $this->doc]);
    }
}
