<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Codegen\Dao;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

final readonly class GeneratedBundle
{
    /** @var array<string, string> */
    public array $files;

    /** @var array<string, string> */
    public array $bindings;

    /** @var array<string, array<array-key, mixed>> */
    public array $schemas;

    /** @param array<string, string> $files
     * @param  array<string, string>  $bindings
     * @param  array<string, array<array-key, mixed>>  $schemas
     */
    public function __construct(array $files, array $bindings = [], array $schemas = [])
    {
        if (array_key_exists('manifest.json', $files)) {
            throw new SqlException(ErrorCode::Generation);
        }
        ksort($files);
        ksort($bindings);
        ksort($schemas);
        $this->bindings = $bindings;
        $this->schemas = $schemas;
        $hashes = array_map(static fn (string $source): string => hash('sha256', $source), $files);
        $files['manifest.json'] = json_encode(['version' => 1, 'files' => $hashes, 'bindings' => $bindings, 'schemas' => $schemas], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $this->files = $files;
    }
}
