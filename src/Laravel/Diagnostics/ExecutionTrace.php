<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Diagnostics;

use Hft\TwoWaySql\Contracts\ExecutionObserver;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

/** @phpstan-import-type Metadata from ExecutionEvent */
final class ExecutionTrace implements ExecutionObserver
{
    /** @var list<ExecutionEvent> */
    private array $events = [];

    public function __construct(private readonly int $maxEvents = 256)
    {
        if ($maxEvents < 1) {
            throw new SqlException(ErrorCode::ParameterType);
        }
    }

    public function record(ExecutionEvent $event): void
    {
        if (count($this->events) >= $this->maxEvents) {
            array_shift($this->events);
        }
        $this->events[] = $event;
    }

    /** @return list<Metadata> */
    public function snapshot(): array
    {
        return array_map(static fn (ExecutionEvent $event): array => $event->metadata(), $this->events);
    }

    public function clear(): void
    {
        $this->events = [];
    }
}
