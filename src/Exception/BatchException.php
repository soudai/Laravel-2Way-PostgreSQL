<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Exception;

final class BatchException extends SqlException
{
    /** @param list<int> $completedIndexes */
    public function __construct(string $sqlId, public readonly array $completedIndexes, public readonly ?int $failedIndex, public readonly string $causeCode, public readonly bool $atomic, public readonly ?string $sqlState = null)
    {
        parent::__construct(ErrorCode::Batch, sqlId: $sqlId);
    }

    /** @return array<string, int|string|null> */
    public function __debugInfo(): array
    {
        return parent::__debugInfo() + ['completedCount' => count($this->completedIndexes), 'lastCompletedIndex' => $this->completedIndexes === [] ? null : $this->completedIndexes[array_key_last($this->completedIndexes)], 'failedIndex' => $this->failedIndex, 'causeCode' => $this->causeCode, 'atomic' => $this->atomic ? 1 : 0, 'sqlState' => $this->sqlState];
    }
}
