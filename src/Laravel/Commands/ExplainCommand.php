<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Commands;

use Hft\TwoWaySql\Contracts\Compiler;
use Hft\TwoWaySql\Contracts\TemplateRepository;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Laravel\ConnectionResolver;
use Hft\TwoWaySql\Laravel\Diagnostics\ExplainPolicy;
use Hft\TwoWaySql\Laravel\Diagnostics\ExplainQuery;
use Hft\TwoWaySql\Laravel\Executor\PlanAdviceRegistry;
use Hft\TwoWaySql\Laravel\Testing\CaseFile;
use Hft\TwoWaySql\Laravel\Testing\CaseParameters;
use Hft\TwoWaySql\Laravel\Testing\ExecutionSafety;
use Hft\TwoWaySql\Template\QueryKind;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;

final class ExplainCommand extends Command
{
    protected $signature = 'twoway:explain {cases : Synthetic case file} {--case= : Case ID (required for multiple cases)} {--connection= : Explicit isolated test connection} {--timeout-ms=1000 : Planning timeout (1..60000 milliseconds)} {--advice= : Explicit trusted plan advice ID (PostgreSQL 19)}';

    protected $description = 'Report allowlisted EXPLAIN estimates for one verified synthetic read case';

    public function handle(TemplateRepository $repository, Compiler $compiler, ConnectionResolver $connections, Config $config, PlanAdviceRegistry $registry): int
    {
        try {
            $path = $this->argument('cases');
            $name = $this->option('connection');
            $caseId = $this->option('case');
            $timeout = $this->option('timeout-ms');
            $adviceId = $this->option('advice');
            $settings = $config->get('twoway-sql.testing');
            if (! is_string($path) || ! is_string($name) || $name === '' || ! is_array($settings)
                || ($adviceId !== null && ! is_string($adviceId)) || ($caseId !== null && ! is_string($caseId)) || ! is_string($timeout) || ! preg_match('/\A[1-9][0-9]{0,4}\z/D', $timeout) || (int) $timeout > 60000) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $cases = (new CaseFile)->load($path);
            if ($caseId !== null) {
                $cases = array_values(array_filter($cases, static fn (array $case): bool => $case['id'] === $caseId));
            }
            if (count($cases) !== 1 || $cases[0]['expected_error'] !== null || $cases[0]['prepare'] !== [] || $cases[0]['assertions'] !== [] || $cases[0]['expect_rows'] !== null) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            $case = $cases[0];
            $definition = $repository->get($case['query_id']);
            $query = $compiler->compile($case['query_id'], (new CaseParameters)->normalize($definition->parameters, $case['parameters']));
            if ($query->kind !== QueryKind::Read) {
                throw new SqlException(ErrorCode::QueryKind);
            }
            ExplainPolicy::validate($query->sql());
            $advice = $registry->forQuery($adviceId, $query);
            $connection = (new ExecutionSafety($connections, $settings))->begin($name);
            try {
                $plan = (new ExplainQuery)->run($connection, $query, (int) $timeout, $advice);
            } finally {
                $connection->rollBack(0);
            }
            $this->line(json_encode(['schema_version' => '1.0', 'case_id' => $case['id'], 'query_id' => $query->sqlId, 'query_hash' => $query->hash, 'analyze' => false, 'plan' => $plan], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return 0;
        } catch (\Throwable $error) {
            $this->line(json_encode(['schema_version' => '1.0', 'errorCode' => $error instanceof SqlException ? $error->errorCode : ErrorCode::Execution->value], JSON_THROW_ON_ERROR));

            return 2;
        }
    }
}
