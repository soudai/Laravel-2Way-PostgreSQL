<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Execution;

use Hft\TwoWaySql\Template\QueryKind;

/** Shared contracts for execution, generated DAOs and static analysis. */
enum QueryOperation: string
{
    case Compile = 'compile';
    case Select = 'select';
    case One = 'one';
    case OneOrNull = 'oneOrNull';
    case Scalar = 'scalar';
    case Execute = 'execute';
    case Returning = 'returning';
    case ExecuteExpecting = 'executeExpecting';
    case Batch = 'batch';
    case Each = 'each';

    public function allows(QueryKind $kind): bool
    {
        return match ($this) {
            self::Compile => true,
            self::Execute, self::ExecuteExpecting, self::Batch => $kind === QueryKind::Write,
            self::Returning => $kind === QueryKind::WriteReturning,
            self::Each => $kind === QueryKind::Read,
            self::Select, self::One, self::OneOrNull, self::Scalar => $kind === QueryKind::Read || $kind === QueryKind::Locking,
        };
    }

    public function dtoMethod(): ?string
    {
        return match ($this) {
            self::Select, self::One, self::OneOrNull, self::Returning, self::Each => $this->value.'As',
            default => null,
        };
    }

    public function supportsDao(): bool
    {
        return match ($this) {
            self::Select, self::One, self::OneOrNull, self::Scalar, self::Execute, self::Returning => true,
            default => false,
        };
    }

    public static function forMethod(string $method): ?self
    {
        $operation = self::tryFrom($method);
        if ($operation !== null) {
            return $operation;
        }
        foreach (self::cases() as $candidate) {
            if ($candidate->dtoMethod() === $method) {
                return $candidate;
            }
        }

        return null;
    }
}
