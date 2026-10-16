<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Commands;

use Hft\TwoWaySql\Compiler\Limits;
use Hft\TwoWaySql\Compiler\TemplateCompiler;
use Hft\TwoWaySql\Compiler\Trace\CoverageCollector;
use Hft\TwoWaySql\Contracts\AstCache;
use Hft\TwoWaySql\Contracts\TemplateRepository;
use Hft\TwoWaySql\Contracts\ValueEncoder;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Laravel\ConnectionResolver;
use Hft\TwoWaySql\Laravel\Testing\CaseFile;
use Hft\TwoWaySql\Laravel\Testing\CaseRunner;
use Hft\TwoWaySql\Laravel\Testing\ExecutionSafety;
use Hft\TwoWaySql\Template\Parser\TemplateParser;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;

abstract class CaseCommand extends Command
{
    public function handle(TemplateRepository $repository, ConnectionResolver $connections, Config $config): int
    {
        $summary = '';
        $details = [];
        try {
            $path = $this->argument('cases');
            if (! is_string($path)) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $cases = (new CaseFile)->load($path);
            $coverage = clone $this->laravel->make(CoverageCollector::class);
            $coverage->clear();
            $compiler = new TemplateCompiler($repository, parser: $this->laravel->make(TemplateParser::class), encoder: $this->laravel->make(ValueEncoder::class), cache: $this->laravel->make(AstCache::class), limits: $this->laravel->make(Limits::class), coverage: $coverage);
            $connection = $this->option('connection');
            $safety = null;
            if ($this->option('execute')) {
                $settings = $config->get('twoway-sql.testing');
                if (! is_array($settings) || ! is_string($connection)) {
                    throw new SqlException(ErrorCode::Connection);
                }
                $safety = new ExecutionSafety($connections, $settings);
            }
            $report = (new CaseRunner($compiler, $connections, $coverage))->run($cases, $safety, is_string($connection) ? $connection : null);
            $code = $report['failed'] === 0 ? 0 : 1;
            $summary = $report['passed'].' passed, '.$report['failed'].' failed.';
            foreach ($report['cases'] as $case) {
                $details[] = $case['caseId'].': '.strtoupper($case['status']).($case['errorCode'] === null ? '' : ' '.$case['errorCode']);
            }
            if ($this->getName() === 'twoway:coverage') {
                foreach ($report['coverage'] as $source) {
                    $hit = count(array_filter($source['arms'], static fn (array $arm): bool => $arm['hits'] > 0));
                    $details[] = $source['sqlId'].' '.$source['sourceHash'].': '.$hit.'/'.count($source['arms']).' arms hit'.($source['truncated'] ? ' (partial)' : '');
                    foreach ($source['arms'] as $arm) {
                        if ($arm['hits'] === 0) {
                            $details[] = 'Uncovered '.$arm['kind'].' '.$arm['line'].':'.$arm['column'].' '.$arm['id'];
                        }
                    }
                }
                $details[] = 'Evicted sources: '.$report['evictedSources'];
            }
        } catch (\Throwable $e) {
            $code = 2;
            $report = ['schema_version' => '1.0', 'errorCode' => $e instanceof SqlException ? $e->errorCode : ErrorCode::ParameterType->value];
        }
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } elseif ($code === 2) {
            $this->error('Invalid case input or execution safety settings.');
        } else {
            $this->line($summary);
            foreach ($details as $detail) {
                $this->line($detail);
            }
            if (! $this->option('execute')) {
                $this->line('Compile only; database expectations were not checked.');
            }
        }

        return $code;
    }
}
