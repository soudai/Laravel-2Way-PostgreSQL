<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Codegen;

use Hft\TwoWaySql\Codegen\Dao\GeneratedWriter;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Laravel\TwoWaySqlManager;
use Illuminate\Contracts\Foundation\Application;

final readonly class GeneratedDaoRegistry
{
    public function __construct(private Application $app, private GenerationConfig $config) {}

    public function register(): void
    {
        if ($this->config->daos === []) {
            return;
        }
        $manifest = (new GeneratedWriter($this->app->basePath(), $this->config->root))->manifest();
        if ($manifest === null) {
            return;
        }
        foreach ($this->config->daos as $interface) {
            $implementation = $manifest['bindings'][$interface] ?? null;
            if ($implementation === null) {
                continue;
            }
            $parts = explode('\\', $interface);
            $filename = end($parts).'Impl.php';
            if ($implementation !== $this->config->namespace.'\\'.end($parts).'Impl' || ! isset($manifest['files'][$filename])) {
                throw new SqlException(ErrorCode::Generation);
            }
            $connection = $this->config->connections[$interface] ?? null;
            $this->app->bind($interface, static function (Application $app) use ($implementation, $interface, $connection): object {
                if (! class_exists($implementation) || ! is_a($implementation, $interface, true)) {
                    throw new SqlException(ErrorCode::Generation);
                }

                return new $implementation($app->make(TwoWaySqlManager::class)->connection($connection));
            });
        }
    }
}
