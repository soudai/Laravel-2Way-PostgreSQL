<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Commands;

use Hft\TwoWaySql\Contracts\TemplateRepository;

final class LintCommand extends TemplateCommand
{
    protected $signature = 'twoway:lint';

    protected $description = 'Validate all registered templates without connecting to a database';

    public function handle(TemplateRepository $repository): int
    {
        return $this->inspect($repository);
    }
}
