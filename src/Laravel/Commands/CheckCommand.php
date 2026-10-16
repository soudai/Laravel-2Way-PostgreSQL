<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Commands;

final class CheckCommand extends CaseCommand
{
    protected $signature = 'twoway:check {cases : Versioned JSON case file} {--execute : Execute on a verified disposable test database} {--connection= : Explicit dedicated test connection} {--json : Emit a value-free JSON report}';

    protected $description = 'Check SQL cases; compile only unless execution is explicitly enabled';
}
