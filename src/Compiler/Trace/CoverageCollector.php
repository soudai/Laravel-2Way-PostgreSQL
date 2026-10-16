<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Compiler\Trace;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Template\Ast\TemplateAst;
use Hft\TwoWaySql\Template\QueryDefinition;

/** @phpstan-import-type Report from CoverageSession */
final class CoverageCollector
{
    /** @var array<string, Report> */
    private array $reports = [];

    private int $evicted = 0;

    public function __construct(private readonly int $maxSources = 128, private readonly int $maxArms = 100000)
    {
        if ($maxSources < 1 || $maxArms < 1) {
            throw new SqlException(ErrorCode::ParameterType);
        }
    }

    public function begin(QueryDefinition $definition, TemplateAst $ast): CoverageSession
    {
        $session = new CoverageSession(substr($definition->id, 0, 128), $definition->sourceHash, $this->maxArms);
        $session->catalog($ast->nodes);

        return $session;
    }

    public function finish(CoverageSession $session): void
    {
        $report = $session->report();
        $key = hash('sha256', $report['sqlId'].':'.$report['sourceHash']);
        if (isset($this->reports[$key])) {
            $old = $this->reports[$key];
            $hits = array_column($old['arms'], 'hits', 'id');
            foreach ($report['arms'] as &$arm) {
                $arm['hits'] = $this->add($arm['hits'], $hits[$arm['id']] ?? 0);
            }
            unset($arm);
            $report['compilations'] = $this->add($old['compilations'], 1);
            unset($this->reports[$key]);
        }
        $this->reports[$key] = $report;
        while (count($this->reports) > $this->maxSources || array_sum(array_map(static fn (array $item): int => count($item['arms']), $this->reports)) > $this->maxArms) {
            $oldest = array_key_first($this->reports);
            if ($oldest === null) {
                break;
            }
            unset($this->reports[$oldest]);
            $this->evicted = $this->add($this->evicted, 1);
        }
    }

    private function add(int $a, int $b): int
    {
        return $a > PHP_INT_MAX - $b ? PHP_INT_MAX : $a + $b;
    }

    /** @return list<Report> */
    public function snapshot(): array
    {
        return array_values($this->reports);
    }

    public function clear(): void
    {
        $this->reports = [];
        $this->evicted = 0;
    }

    public function evictedSources(): int
    {
        return $this->evicted;
    }
}
