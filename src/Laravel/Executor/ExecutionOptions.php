<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Executor;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

final readonly class ExecutionOptions
{
    public function __construct(public ?int $statementTimeoutMs = null, public ?int $lockTimeoutMs = null, public ?string $planAdviceId = null)
    {
        if ($planAdviceId !== null && ! PlanAdviceRegistry::validId($planAdviceId)) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        foreach ([$statementTimeoutMs, $lockTimeoutMs] as $timeout) {
            if ($timeout !== null && ($timeout < 0 || $timeout > 2147483647)) {
                throw new SqlException(ErrorCode::ParameterType);
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->statementTimeoutMs === null && $this->lockTimeoutMs === null && $this->planAdviceId === null;
    }
}
