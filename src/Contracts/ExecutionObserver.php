<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Contracts;

use Hft\TwoWaySql\Laravel\Diagnostics\ExecutionEvent;

interface ExecutionObserver
{
    public function record(ExecutionEvent $event): void;
}
