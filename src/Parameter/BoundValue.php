<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Parameter;

final readonly class BoundValue
{
    public function __construct(
        public string $type,
        #[\SensitiveParameter] private int|string|null $value,
        public string $path,
        public int $occurrence,
    ) {}

    public function value(): int|string|null
    {
        return $this->value;
    }

    /** @return array{type:string, path:string, occurrence:int} */
    public function __debugInfo(): array
    {
        return ['type' => $this->type, 'path' => $this->path, 'occurrence' => $this->occurrence];
    }

    /** @return array{type:string, path:string, occurrence:int} */
    public function __serialize(): array
    {
        return $this->__debugInfo();
    }
}
