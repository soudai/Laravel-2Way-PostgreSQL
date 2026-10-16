<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Commands;

use Hft\TwoWaySql\Contracts\AstCache;
use Hft\TwoWaySql\Contracts\TemplateRepository;

final class CacheCommand extends TemplateCommand
{
    protected $signature = 'twoway:cache';

    protected $description = 'Prewarm template AST cache without connecting to a database';

    public function handle(TemplateRepository $repository, AstCache $cache): int
    {
        return $this->inspect($repository, $cache);
    }
}
