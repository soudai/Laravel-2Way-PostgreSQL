<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Commands;

final class CoverageCommand extends CaseCommand
{
    protected $signature = 'twoway:coverage {cases : Versioned JSON case file} {--execute : Execute on a verified disposable test database} {--connection= : Explicit dedicated test connection} {--json : Emit a value-free JSON report}';

    protected $description = 'Collect SQL branch coverage from explicit cases';
}
