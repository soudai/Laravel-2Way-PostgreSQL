<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Executor;

/** Internal lifecycle declarations; query kinds remain a separate SQL contract. */
enum ExecutionPolicy
{
    case Read;
    case Locking;
    case Write;
    case CheckedWrite;
    case MappedWrite;
    case Cursor;

    public function readsPdo(): bool
    {
        return $this === self::Read || $this === self::Cursor;
    }

    public function validatesInsideTransaction(): bool
    {
        return match ($this) {
            self::Write, self::CheckedWrite, self::MappedWrite => true,
            default => false,
        };
    }

    public function startsTransaction(int $level): bool
    {
        return match ($this) {
            self::Read, self::Locking => false,
            self::Write => $level === 0,
            self::CheckedWrite, self::MappedWrite, self::Cursor => true,
        };
    }
}
