<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Codegen\Dao;

use Hft\TwoWaySql\Codegen\Definition\TypeNames;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Throwable;

/** @phpstan-type Manifest array{version:1, files:array<string,string>, bindings:array<string,string>, schemas:array<string,array<array-key,mixed>>}
 * @phpstan-type Change array{status:string, diff:string}
 */
final readonly class GeneratedWriter
{
    public function __construct(private string $basePath, private string $relativeRoot = 'app/Generated/TwoWaySql') {}

    public function root(): string
    {
        if (! preg_match('/\A[A-Za-z_][A-Za-z0-9_-]*(?:\/[A-Za-z_][A-Za-z0-9_-]*)*\z/D', $this->relativeRoot)) {
            throw new SqlException(ErrorCode::Generation);
        }
        $path = $this->basePath;
        while ($path !== dirname($path)) {
            if (is_link($path)) {
                throw new SqlException(ErrorCode::Generation);
            }
            $path = dirname($path);
        }
        $base = realpath($this->basePath);
        if ($base === false || ! is_dir($base)) {
            throw new SqlException(ErrorCode::Generation);
        }
        $path = $base;
        foreach (explode('/', $this->relativeRoot) as $part) {
            $path .= '/'.$part;
            if (is_link($path) || (file_exists($path) && ! is_dir($path))) {
                throw new SqlException(ErrorCode::Generation);
            }
        }

        return $path;
    }

    private function path(string $name): string
    {
        if ($name !== 'manifest.json' && ! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\.php\z/D', $name)) {
            throw new SqlException(ErrorCode::Generation);
        }
        $path = $this->root().'/'.$name;
        if (is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new SqlException(ErrorCode::Generation);
        }

        return $path;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path, length: 4194305);
        if ($contents === false || strlen($contents) > 4194304) {
            throw new SqlException(ErrorCode::Generation);
        }

        return $contents;
    }

    /** @return Manifest|null */
    public function manifest(bool $verifyFiles = true): ?array
    {
        $path = $this->path('manifest.json');
        if (! file_exists($path)) {
            return null;
        }
        try {
            $data = json_decode($this->read($path), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($data) || array_diff(array_keys($data), ['version', 'files', 'bindings', 'schemas']) !== [] || ($data['version'] ?? null) !== 1 || ! is_array($data['files'] ?? null) || ! is_array($data['bindings'] ?? null) || ! is_array($data['schemas'] ?? null)) {
                throw new SqlException(ErrorCode::Generation);
            }
            $files = [];
            foreach ($data['files'] as $name => $hash) {
                if (! is_string($name) || $name === 'manifest.json' || ! is_string($hash) || ! preg_match('/\A[a-f0-9]{64}\z/D', $hash)) {
                    throw new SqlException(ErrorCode::Generation);
                }
                $file = $this->path($name);
                if ($verifyFiles && (! is_file($file) || ! hash_equals($hash, hash('sha256', $this->read($file))))) {
                    throw new SqlException(ErrorCode::Generation);
                }
                $files[$name] = $hash;
            }
            $bindings = [];
            foreach ($data['bindings'] as $interface => $implementation) {
                if (! is_string($interface) || ! TypeNames::className($interface) || ! is_string($implementation) || ! TypeNames::className($implementation)) {
                    throw new SqlException(ErrorCode::Generation);
                }
                $bindings[$interface] = $implementation;
            }
            $schemas = [];
            foreach ($data['schemas'] as $name => $schema) {
                if (! is_string($name) || ! is_array($schema)) {
                    throw new SqlException(ErrorCode::Generation);
                }
                $schemas[$name] = $schema;
            }

            return ['version' => 1, 'files' => $files, 'bindings' => $bindings, 'schemas' => $schemas];
        } catch (Throwable) {
            throw new SqlException(ErrorCode::Generation);
        }
    }

    /** @return array<string, Change> */
    public function check(GeneratedBundle $bundle, bool $partial = false): array
    {
        if (array_sum(array_map(strlen(...), $bundle->files)) > 4194304) {
            throw new SqlException(ErrorCode::Generation);
        }
        $previous = $this->manifest(false);
        $expected = $bundle->files;
        if ($partial && $previous !== null) {
            /** @var Manifest $current */
            $current = json_decode($expected['manifest.json'], true, flags: JSON_THROW_ON_ERROR);
            foreach (['files', 'bindings', 'schemas'] as $field) {
                $previous[$field] = array_replace($previous[$field], $current[$field]);
                ksort($previous[$field]);
            }
            $expected['manifest.json'] = json_encode($previous, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        }
        $changes = [];
        foreach ($expected as $name => $contents) {
            $path = $this->path($name);
            $status = ! is_file($path) ? 'missing' : ($this->read($path) === $contents ? 'unchanged' : 'changed');
            $changes[$name] = ['status' => $status, 'diff' => ''];
        }
        if (! $partial) {
            foreach ($previous['files'] ?? [] as $name => $hash) {
                if (! array_key_exists($name, $expected)) {
                    $changes[$name] = ['status' => 'obsolete', 'diff' => ''];
                }
            }
        }
        ksort($changes);

        return $changes;
    }

    /** @return array<string, Change> */
    public function apply(GeneratedBundle $bundle, bool $write = false): array
    {
        if (array_sum(array_map(strlen(...), $bundle->files)) > 4194304) {
            throw new SqlException(ErrorCode::Generation);
        }
        $changes = $this->changes($bundle);
        if (! $write) {
            return $changes;
        }
        $root = $this->root();
        if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new SqlException(ErrorCode::Generation);
        }
        $lockPath = $root.'/.twoway-generation.lock';
        if (is_link($lockPath) || (file_exists($lockPath) && ! is_file($lockPath))) {
            throw new SqlException(ErrorCode::Generation);
        }
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new SqlException(ErrorCode::Generation);
        }
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new SqlException(ErrorCode::Generation);
            }
            $changes = $this->changes($bundle);
            // The manifest is inserted last by GeneratedBundle and acts as the commit marker.
            foreach ($bundle->files as $name => $contents) {
                if ($name === 'manifest.json') {
                    foreach ($changes as $obsolete => $change) {
                        if ($change['status'] === 'obsolete' && is_file($this->path($obsolete)) && ! unlink($this->path($obsolete))) {
                            throw new SqlException(ErrorCode::Generation);
                        }
                    }
                }
                if ($changes[$name]['status'] !== 'unchanged') {
                    $this->atomicWrite($this->path($name), $contents);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $changes;
    }

    /** Retain previously generated schema stubs when generating another explicit query. */
    public function merge(GeneratedBundle $bundle): GeneratedBundle
    {
        $previous = $this->manifest(false);
        if ($previous === null) {
            return $bundle;
        }
        $files = [];
        foreach ($previous['files'] as $name => $hash) {
            if (array_key_exists($name, $bundle->files)) {
                continue;
            }
            if (! is_file($this->path($name)) || ! hash_equals($hash, hash('sha256', $this->read($this->path($name))))) {
                throw new SqlException(ErrorCode::Generation);
            }
            $files[$name] = $this->read($this->path($name));
        }
        foreach ($bundle->files as $name => $contents) {
            if ($name !== 'manifest.json') {
                $files[$name] = $contents;
            }
        }

        return new GeneratedBundle($files, array_replace($previous['bindings'], $bundle->bindings), array_replace($previous['schemas'], $bundle->schemas));
    }

    /** @return array<string, Change> */
    private function changes(GeneratedBundle $bundle): array
    {
        $manifest = $this->manifest(false);
        $changes = [];
        foreach ($bundle->files as $name => $contents) {
            $path = $this->path($name);
            $exists = is_file($path);
            $old = $exists ? $this->read($path) : '';
            if ($exists && $name !== 'manifest.json' && (! isset($manifest['files'][$name]) || ! hash_equals($manifest['files'][$name], hash('sha256', $old)))) {
                throw new SqlException(ErrorCode::Generation);
            }
            $status = ! $exists ? 'create' : ($old === $contents ? 'unchanged' : 'update');
            $diff = $status === 'unchanged' ? '' : '--- '.$name."\n+++ ".$name."\n".($old === '' ? '' : '-'.str_replace("\n", "\n-", rtrim($old, "\n"))."\n").'+'.str_replace("\n", "\n+", rtrim($contents, "\n"))."\n";
            $changes[$name] = ['status' => $status, 'diff' => $diff];
        }
        foreach ($manifest['files'] ?? [] as $name => $hash) {
            if (array_key_exists($name, $bundle->files)) {
                continue;
            }
            $path = $this->path($name);
            if (is_file($path) && ! hash_equals($hash, hash('sha256', $this->read($path)))) {
                throw new SqlException(ErrorCode::Generation);
            }
            $changes[$name] = ['status' => 'obsolete', 'diff' => ''];
        }

        return $changes;
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $temporary = tempnam(dirname($path), '.twoway-');
        if ($temporary === false) {
            throw new SqlException(ErrorCode::Generation);
        }
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents) || ! chmod($temporary, 0600) || ! rename($temporary, $path)) {
                throw new SqlException(ErrorCode::Generation);
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
