<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Testing;

use Hft\TwoWaySql\Compiler\CompiledQuery;
use Hft\TwoWaySql\Compiler\TemplateCompiler;
use Hft\TwoWaySql\Compiler\Trace\CoverageCollector;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Laravel\ConnectionResolver;
use Hft\TwoWaySql\Laravel\Executor\Executor;
use Hft\TwoWaySql\Template\Lexer\PgLexer;
use Hft\TwoWaySql\Template\QueryKind;

/** @phpstan-import-type CaseSpec from CaseFile
 * @phpstan-import-type Report from \Hft\TwoWaySql\Compiler\Trace\CoverageSession as CoverageReport
 *
 * @phpstan-type Result array{caseId:string,sqlId:string,status:string,errorCode:?string,executed:bool,rowsChecked:bool,branchesChecked:bool,phase:string,step:?int,stepSqlId:?string,preparationsChecked:int,assertionsChecked:int}
 */
final readonly class CaseRunner
{
    public function __construct(private TemplateCompiler $compiler, private ConnectionResolver $connections, private CoverageCollector $coverage) {}

    /** @param list<CaseSpec> $cases
     * @return array{schema_version:string,mode:string,passed:int,failed:int,cases:list<Result>,coverage:list<CoverageReport>,evictedSources:int}
     */
    public function run(#[\SensitiveParameter] array $cases, ?ExecutionSafety $safety = null, ?string $connectionName = null): array
    {
        if ($safety !== null) {
            $safety->begin($connectionName)->rollBack();
        }
        $results = [];
        $passed = 0;
        foreach ($cases as $case) {
            $result = $this->check($case, $safety, $connectionName);
            $results[] = $result;
            if ($result['status'] === 'pass') {
                $passed++;
            }
        }

        return ['schema_version' => '1.0', 'mode' => $safety === null ? 'compile' : 'execute', 'passed' => $passed, 'failed' => count($cases) - $passed, 'cases' => $results, 'coverage' => $this->coverage->snapshot(), 'evictedSources' => $this->coverage->evictedSources()];
    }

    /** @param CaseSpec $case
     * @return Result
     */
    private function check(#[\SensitiveParameter] array $case, ?ExecutionSafety $safety, ?string $connectionName): array
    {
        $result = ['caseId' => $case['id'], 'sqlId' => $case['query_id'], 'status' => 'fail', 'errorCode' => null, 'executed' => false, 'rowsChecked' => false, 'branchesChecked' => false, 'phase' => 'compile', 'step' => null, 'stepSqlId' => null, 'preparationsChecked' => 0, 'assertionsChecked' => 0];
        $steps = ['prepare' => [], 'assertions' => []];
        try {
            foreach (['prepare' => QueryKind::Write, 'assertions' => QueryKind::Read] as $phase => $kind) {
                foreach ($case[$phase] as $index => $step) {
                    $result['phase'] = $phase.'_compile';
                    $result['step'] = $index + 1;
                    $result['stepSqlId'] = $step['query_id'];
                    $compiled = $this->compile($step['query_id'], $step['parameters']);
                    if ($compiled->kind !== $kind) {
                        throw new SqlException(ErrorCode::QueryKind);
                    }
                    $steps[$phase][] = $compiled;
                }
            }
            $result['phase'] = 'compile';
            $result['step'] = null;
            $result['stepSqlId'] = null;
            $query = $this->compile($case['query_id'], $case['parameters']);
            if ($case['expect_rows'] !== null && $query->kind !== QueryKind::Write) {
                throw new SqlException(ErrorCode::QueryKind);
            }
        } catch (SqlException $e) {
            $result['errorCode'] = $e->errorCode;
            if ($result['phase'] === 'compile' && $case['error_phase'] === 'compile' && $case['expected_error'] === $e->errorCode) {
                $result['status'] = 'pass';
            }

            return $result;
        }
        if ($case['expected_error'] !== null && $case['error_phase'] === 'compile') {
            return $result;
        }
        if ($case['expected_error'] === null) {
            $actualSql = $case['comparison'] === 'tokens' ? $this->tokens($query->sql()) : $query->sql();
            $expectedSql = $case['comparison'] === 'tokens' ? $this->tokens($case['expected_pdo_sql'] ?? '') : $case['expected_pdo_sql'];
            if ($actualSql !== $expectedSql || $query->bindings() !== $case['expected_bindings']) {
                return $result;
            }
            if ($case['expected_branches'] !== null) {
                $actual = $query->diagnostics['branches'] ?? [];
                $expected = $case['expected_branches'];
                if (! is_array($actual)) {
                    return $result;
                }
                sort($actual);
                sort($expected);
                $result['branchesChecked'] = true;
                if ($actual !== $expected) {
                    return $result;
                }
            }
        }
        if ($safety === null) {
            $result['status'] = 'pass';

            return $result;
        }
        $connection = $safety->begin($connectionName);
        try {
            foreach ($steps['prepare'] as $index => $prepared) {
                $result['phase'] = 'prepare';
                $result['step'] = $index + 1;
                $result['stepSqlId'] = $prepared->sqlId;
                $this->execute($prepared, $connectionName, $case['prepare'][$index]['expected_affected_rows']);
                $result['preparationsChecked']++;
            }
            $result['phase'] = 'execute';
            $result['step'] = null;
            $result['stepSqlId'] = null;
            $result['executed'] = true;
            try {
                $actual = $connection->transaction(fn (): int|array => $this->execute($query, $connectionName, $case['expect_rows']), attempts: 1);
                if ($case['expected_error'] !== null) {
                    return $result;
                }
                if ($case['expected_rows'] !== null) {
                    $result['rowsChecked'] = true;
                    if (! is_array($actual) || ! $this->sameRows($actual, $case['expected_rows'], $case['row_order'])) {
                        return $result;
                    }
                }
                if ($case['expected_affected_rows'] !== null) {
                    $result['rowsChecked'] = true;
                    if ($actual !== $case['expected_affected_rows']) {
                        return $result;
                    }
                }
            } catch (SqlException $e) {
                $result['errorCode'] = $e->errorCode;
                if ($case['error_phase'] !== 'execute' || $case['expected_error'] !== $e->errorCode) {
                    return $result;
                }
            }
            foreach ($steps['assertions'] as $index => $assertion) {
                $result['phase'] = 'assert';
                $result['step'] = $index + 1;
                $result['stepSqlId'] = $assertion->sqlId;
                $actual = $this->assertionRows($assertion, $connectionName);
                $expected = $case['assertions'][$index];
                $result['assertionsChecked']++;
                if (! $this->sameRows($actual, $expected['expected_rows'] ?? [], $expected['row_order'])) {
                    return $result;
                }
            }
            $result['status'] = 'pass';
            $result['phase'] = 'complete';
            $result['step'] = null;
            $result['stepSqlId'] = null;

            return $result;
        } catch (SqlException $e) {
            $result['errorCode'] = $e->errorCode;

            return $result;
        } finally {
            $connection->rollBack(0);
        }
    }

    /** @return int|list<array<array-key,mixed>> */
    private function execute(#[\SensitiveParameter] CompiledQuery $query, ?string $connection, ?int $expected = null): int|array
    {
        $executor = new Executor(new PreparedCaseCompiler($query), $this->connections, $connection);
        $rows = match ($query->kind) {
            QueryKind::Write => $expected === null ? $executor->execute($query->sqlId, []) : $executor->executeExpecting($query->sqlId, [], $expected),
            QueryKind::WriteReturning => $executor->returning($query->sqlId, []),
            QueryKind::Read,QueryKind::Locking => $executor->select($query->sqlId, []),
        };

        return is_int($rows) ? $rows : array_map(static fn (#[\SensitiveParameter] \stdClass $row): array => get_object_vars($row), $rows);
    }

    /** @param array<string,mixed> $parameters */
    private function compile(string $id, #[\SensitiveParameter] array $parameters): CompiledQuery
    {
        $schema = $this->compiler->getDefinition($id)->parameters;

        return $this->compiler->compile($id, (new CaseParameters)->normalize($schema, $parameters));
    }

    /** @return list<array<array-key,mixed>> */
    private function assertionRows(#[\SensitiveParameter] CompiledQuery $query, ?string $connection): array
    {
        $rows = [];
        $overflow = false;
        (new Executor(new PreparedCaseCompiler($query), $this->connections, $connection))->each($query->sqlId, [], static function (#[\SensitiveParameter] \stdClass $row) use (&$rows, &$overflow): bool {
            if (count($rows) >= 1000) {
                $overflow = true;

                return false;
            }
            $rows[] = get_object_vars($row);

            return true;
        }, fetchSize: 128);
        if ($overflow) {
            throw new SqlException(ErrorCode::Limit);
        }

        return $rows;
    }

    /** @param list<array<array-key,mixed>> $actual
     * @param  list<array<array-key,mixed>>  $expected
     */
    private function sameRows(#[\SensitiveParameter] array $actual, #[\SensitiveParameter] array $expected, string $order): bool
    {
        if ($order === 'unordered') {
            sort($actual);
            sort($expected);
        }

        return $actual === $expected;
    }

    /** @return list<string> */
    private function tokens(string $sql): array
    {
        return array_values(array_map(static fn ($token): string => $token->rawText, array_filter((new PgLexer)->tokenize($sql), static fn ($token): bool => ! $token->isTrivia())));
    }
}
