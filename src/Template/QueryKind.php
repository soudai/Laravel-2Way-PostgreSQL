<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template;

enum QueryKind: string
{
    case Read = 'read';
    case Locking = 'locking';
    case Write = 'write';
    case WriteReturning = 'write_returning';
}
