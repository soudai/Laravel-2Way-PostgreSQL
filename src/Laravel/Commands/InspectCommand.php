<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Commands;

use Hft\TwoWaySql\Codegen\Dao\GeneratedWriter;
use Hft\TwoWaySql\Codegen\Definition\TypeNames;
use Hft\TwoWaySql\Codegen\Schema\MetadataDiff;
use Hft\TwoWaySql\Contracts\Compiler;
use Hft\TwoWaySql\Contracts\TemplateRepository;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Laravel\Codegen\GenerationConfig;
use Hft\TwoWaySql\Laravel\Codegen\InspectionPlan;
use Hft\TwoWaySql\Laravel\Codegen\MetadataQuery;
use Hft\TwoWaySql\Laravel\ConnectionResolver;
use Hft\TwoWaySql\Laravel\Testing\CaseFile;
use Hft\TwoWaySql\Laravel\Testing\CaseParameters;
use Hft\TwoWaySql\Laravel\Testing\ExecutionSafety;
use Hft\TwoWaySql\Template\QueryKind;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;

/** @phpstan-import-type CaseSpec from CaseFile
 * @phpstan-import-type Column from MetadataDiff
 *
 * @phpstan-type Report array{schema_version:string,query_id:string,source_hash:string,candidate:bool,limitations:list<string>,columns:list<Column>,diff:?array<string,bool|list<string>>}
 */
final class InspectCommand extends Command
{
    protected $signature = 'twoway:inspect {cases : Synthetic case file supplying parameters} {--case= : Case ID (required for multiple cases without check)} {--connection= : Explicit isolated test connection} {--against= : Generated DTO short name for schema/hash comparison} {--check : Fail on drift or unverifiable cases} {--plan= : JSON mapping every case to a DTO or explicit exclusion}';

    protected $description = 'Execute one synthetic read case in a verified test transaction and report metadata candidates';

    public function handle(TemplateRepository $repository, Compiler $compiler, ConnectionResolver $connections, Config $config, GenerationConfig $generation): int
    {
        try {
            $path = $this->argument('cases');
            $name = $this->option('connection');
            $caseId = $this->option('case');
            $against = $this->option('against');
            $planPath = $this->option('plan');
            $check = $this->option('check') === true;
            $settings = $config->get('twoway-sql.testing');
            if (! is_string($path) || ! is_string($name) || $name === '' || ! is_array($settings)
                || ($caseId !== null && ! is_string($caseId)) || ($against !== null && (! is_string($against) || ! TypeNames::identifier($against)))
                || ($planPath !== null && (! is_string($planPath) || ! $check)) || ($check && $caseId !== null)) {
                throw new SqlException(ErrorCode::Generation);
            }
            $cases = (new CaseFile)->load($path);
            if ($check) {
                $plan = InspectionPlan::load($planPath, $against, array_column($cases, 'id'));
                $reports = [];
                $exit = 0;
                foreach ($cases as $case) {
                    if (! isset($plan->cases[$case['id']])) {
                        continue;
                    }
                    try {
                        $report = $this->inspect($case, $plan->cases[$case['id']], $name, $settings, $repository, $compiler, $connections, $generation);
                        $unknown = $report['columns'] === [] || array_filter($report['columns'], static fn (array $column): bool => $column['suggested_php_type'] === null) !== [];
                        $drift = $report['diff'] !== null && array_filter($report['diff'], static fn (bool|array $value): bool => $value !== false && $value !== []) !== [];
                        $status = $unknown ? 'unverifiable' : ($drift ? 'drift' : 'matched_candidates');
                        $exit = max($exit, $unknown ? 2 : ($drift ? 1 : 0));
                        $reports[] = ['case_id' => $case['id'], 'against' => $plan->cases[$case['id']], 'status' => $status] + $report;
                    } catch (\Throwable $error) {
                        $exit = 2;
                        $reports[] = ['case_id' => $case['id'], 'status' => 'error', 'errorCode' => $error instanceof SqlException ? $error->errorCode : ErrorCode::Generation->value];
                    }
                }
                $this->line(json_encode(['schema_version' => '1.0', 'cases' => $reports, 'exclusions' => (object) $plan->exclusions], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

                return $exit;
            }
            if ($caseId !== null) {
                $cases = array_values(array_filter($cases, static fn (array $case): bool => $case['id'] === $caseId));
            }
            if (count($cases) !== 1 || $cases[0]['expected_error'] !== null) {
                throw new SqlException(ErrorCode::Generation);
            }
            $report = $this->inspect($cases[0], $against, $name, $settings, $repository, $compiler, $connections, $generation);
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return 0;
        } catch (\Throwable $error) {
            $this->line(json_encode(['schema_version' => '1.0', 'errorCode' => $error instanceof SqlException ? $error->errorCode : ErrorCode::Generation->value], JSON_THROW_ON_ERROR));

            return 2;
        }
    }

    /** @param CaseSpec $case
     * @param  array<array-key,mixed>  $settings
     * @return Report
     */
    private function inspect(array $case, ?string $against, string $name, array $settings, TemplateRepository $repository, Compiler $compiler, ConnectionResolver $connections, GenerationConfig $generation): array
    {
        if ($case['expected_error'] !== null || $case['prepare'] !== [] || $case['assertions'] !== [] || $case['expect_rows'] !== null) {
            throw new SqlException(ErrorCode::Generation);
        }
        $definition = $repository->get($case['query_id']);
        $query = $compiler->compile($case['query_id'], (new CaseParameters)->normalize($definition->parameters, $case['parameters']));
        $sourceHash = $query->diagnostics['sourceHash'] ?? null;
        if ($query->kind !== QueryKind::Read || ! is_string($sourceHash)) {
            throw new SqlException(ErrorCode::Generation);
        }
        $baseline = null;
        if ($against !== null) {
            $manifest = (new GeneratedWriter($this->laravel->basePath(), $generation->root.'/Schema'))->manifest();
            $saved = $manifest['schemas'][$generation->namespace.'\\Schema\\'.$against] ?? null;
            if (! is_array($saved) || ($saved['kind'] ?? null) !== 'dto' || ! is_array($saved['query'] ?? null) || ($saved['query']['id'] ?? null) !== $query->sqlId) {
                throw new SqlException(ErrorCode::Generation);
            }
            $baseline = $saved['query'];
        }
        $connection = (new ExecutionSafety($connections, $settings))->begin($name);
        try {
            $columns = MetadataQuery::inspect($connection, $query);
        } finally {
            $connection->rollBack(0);
        }
        if ($baseline !== null) {
            $columns = MetadataDiff::candidates($baseline, $columns);
        }

        return ['schema_version' => '1.0', 'query_id' => $query->sqlId, 'source_hash' => $sourceHash, 'candidate' => true,
            'limitations' => ['Nullability, outer joins, domains and application mappings require explicit review.', 'Only the supplied cases are checked; unexecuted branches are not verified.', 'Case expectations are not checked by inspect; use twoway:check for conformance.'],
            'columns' => $columns, 'diff' => $baseline === null ? null : MetadataDiff::compare($baseline, $sourceHash, $columns)];
    }
}
