<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Cache;

use Hft\TwoWaySql\Contracts\AstCache;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Template\Ast\TemplateAst;

final readonly class FileAstCache implements AstCache
{
    public function __construct(private string $root, private AstCodec $codec = new AstCodec) {}

    private function directory(): ?string
    {
        $current = $this->root;
        while ($current !== dirname($current)) {
            if (is_link($current)) {
                throw new SqlException(ErrorCode::TemplatePath);
            }
            $current = dirname($current);
        }

        return is_dir($this->root) ? (realpath($this->root) ?: null) : null;
    }

    private function valid(string $key): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $key) === 1;
    }

    public function get(string $key): ?TemplateAst
    {
        $root = $this->directory();
        if ($root === null || ! $this->valid($key)) {
            return null;
        }
        $path = $root.'/'.$key.'.ast.json';
        if (is_link($path) || ! is_file($path) || filesize($path) > 67108864) {
            return null;
        }
        $json = @file_get_contents($path, length: 67108865);

        return is_string($json) ? $this->codec->decode($json) : null;
    }

    public function put(string $key, TemplateAst $ast): void
    {
        if (! $this->valid($key)) {
            throw new SqlException(ErrorCode::TemplatePath);
        }
        $root = $this->directory();
        if ($root === null) {
            if (! @mkdir($this->root, 0700, true) && ! is_dir($this->root)) {
                throw new SqlException(ErrorCode::TemplatePath);
            }
            $root = $this->directory();
        }
        if ($root === null) {
            throw new SqlException(ErrorCode::TemplatePath);
        }
        $json = $this->codec->encode($ast);
        $temp = tempnam($root, '.twoway-');
        if ($temp === false) {
            throw new SqlException(ErrorCode::TemplatePath);
        }
        try {
            if (file_put_contents($temp, $json, LOCK_EX) !== strlen($json) || ! rename($temp, $root.'/'.$key.'.ast.json')) {
                throw new SqlException(ErrorCode::TemplatePath);
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    public function clear(): void
    {
        $root = $this->directory();
        if ($root === null) {
            return;
        }
        foreach (glob($root.'/*.ast.json') ?: [] as $path) {
            if ($this->valid(substr(basename($path), 0, -9)) && is_file($path) && ! is_link($path)) {
                unlink($path);
            }
        }
    }
}
