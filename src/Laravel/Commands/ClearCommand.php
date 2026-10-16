<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Commands;

use Hft\TwoWaySql\Contracts\AstCache;
use Illuminate\Console\Command;

final class ClearCommand extends Command
{
    protected $signature = 'twoway:clear';

    protected $description = 'Clear only the configured template AST cache';

    public function handle(AstCache $cache): int
    {
        $cache->clear();
        $this->info('Template cache cleared.');

        return self::SUCCESS;
    }
}
