<?php

declare(strict_types=1);

return [
    'limits' => [], // Positive overrides; defaults are defined by Compiler\Limits.
    'diagnostics' => ['coverage' => false, 'execution' => false, 'max_sources' => 128, 'max_arms' => 100000, 'max_events' => 256],
    'testing' => ['connection' => null, 'allowed_hosts' => [], 'allowed_databases' => [], 'nonce' => null],
    'codegen' => ['root' => 'app/Generated/TwoWaySql', 'namespace' => 'App\\Generated\\TwoWaySql', 'daos' => [], 'parameters' => [], 'results' => [], 'connections' => []],
    'cache' => ['path' => storage_path('framework/cache/twoway-sql'), 'entries' => 256, 'bytes' => 67108864],
    'root' => resource_path('sql'),
    'grammar' => 'canonical-v1',
    'queries' => [],
    'plan_advice' => [], // Trusted ID => query_id / query_hash / advice; explicitly selected per execution.
];
