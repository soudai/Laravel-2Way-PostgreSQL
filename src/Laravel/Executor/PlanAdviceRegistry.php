<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Executor;

use Hft\TwoWaySql\Compiler\CompiledQuery;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

final readonly class PlanAdviceRegistry
{
    /** @var array<string,PlanAdvice> */
    private array $entries;

    public function __construct(#[\SensitiveParameter] mixed $configuration = [])
    {
        if (! is_array($configuration) || count($configuration) > 128) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        $entries = [];
        foreach ($configuration as $id => $entry) {
            if (! is_string($id) || ! self::validId($id) || ! is_array($entry) || array_diff(array_keys($entry), ['query_id', 'query_hash', 'advice']) !== []
                || ! is_string($entry['query_id'] ?? null) || ! self::validId($entry['query_id'])
                || ! is_string($entry['query_hash'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $entry['query_hash'])
                || ! is_string($entry['advice'] ?? null) || trim($entry['advice']) === '' || strlen($entry['advice']) > 16384
                || ! mb_check_encoding($entry['advice'], 'UTF-8') || str_contains($entry['advice'], "\0")) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $entries[$id] = new PlanAdvice($id, $entry['query_id'], $entry['query_hash'], $entry['advice']);
        }
        $this->entries = $entries;
    }

    public static function validId(string $id): bool
    {
        return preg_match('/\A[a-zA-Z][a-zA-Z0-9_.-]{0,127}\z/D', $id) === 1;
    }

    public function forQuery(?string $id, #[\SensitiveParameter] CompiledQuery $query): ?PlanAdvice
    {
        if ($id === null) {
            return null;
        }
        $entry = $this->entries[$id] ?? null;
        if ($entry === null || $entry->queryId !== $query->sqlId || $entry->queryHash !== $query->hash) {
            throw new SqlException(ErrorCode::ParameterType, sqlId: $query->sqlId);
        }
        if ($query->minimumPostgres !== 19) {
            throw new SqlException(ErrorCode::Compatibility, sqlId: $query->sqlId);
        }

        return $entry;
    }
}
