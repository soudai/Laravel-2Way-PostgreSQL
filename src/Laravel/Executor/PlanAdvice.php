<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Executor;

/** @internal Constructed only from validated trusted registry entries. */
final readonly class PlanAdvice
{
    public function __construct(public string $id, public string $queryId, public string $queryHash, #[\SensitiveParameter] private string $advice) {}

    public function text(): string
    {
        return $this->advice;
    }

    /** @return array{id:string,queryId:string,queryHash:string} */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'queryId' => $this->queryId, 'queryHash' => $this->queryHash];
    }
}
