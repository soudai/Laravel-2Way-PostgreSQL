<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Compiler\Emitter;

use Hft\TwoWaySql\Compiler\CompiledQuery;
use Hft\TwoWaySql\Compiler\IR\BindSlot;
use Hft\TwoWaySql\Compiler\IR\Operator;
use Hft\TwoWaySql\Compiler\IR\SqlPiece;
use Hft\TwoWaySql\Compiler\IR\Text;
use Hft\TwoWaySql\Compiler\IR\Trivia;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Template\Lexer\StatementShape;
use Hft\TwoWaySql\Template\QueryDefinition;
use Hft\TwoWaySql\Template\QueryKind;

final readonly class PdoPgsqlEmitter
{
    public function __construct(private int $maxSqlBytes = 8388608, private int $maxBindings = 10000, private int $maxEncodedBytes = 33554432) {}

    /** @param list<SqlPiece> $pieces */
    public function emit(QueryDefinition $definition, array $pieces): CompiledQuery
    {
        $parts = [];
        $bindings = [];
        $bytes = 0;
        $encodedBytes = 0;
        $started = false;
        $terminated = false;
        $first = '';
        foreach ($pieces as $piece) {
            if (! $piece instanceof Trivia) {
                if ($terminated) {
                    throw new SqlException(ErrorCode::Syntax);
                }
                if (! $started) {
                    if (! $piece instanceof Text || ! in_array(strtoupper($piece->text), ['SELECT', 'WITH', 'INSERT', 'UPDATE', 'DELETE', 'MERGE'], true)) {
                        throw new SqlException(ErrorCode::Unsupported);
                    }
                    $started = true;
                    $first = strtoupper($piece->text);
                }
                if ($piece instanceof Text && $piece->text === ';') {
                    $terminated = true;
                }
            }
            if ($piece instanceof BindSlot) {
                if (count($bindings) >= $this->maxBindings) {
                    throw new SqlException(ErrorCode::Limit, $piece->span);
                }
                $value = $piece->value->value();
                $encodedBytes += strlen((string) $value);
                if ($encodedBytes > $this->maxEncodedBytes) {
                    throw new SqlException(ErrorCode::Limit, $piece->span);
                }
                $bindings[] = $value;
                $text = '?';
            } elseif ($piece instanceof Operator) {
                $text = str_replace('?', '??', $piece->text);
            } elseif ($piece instanceof Trivia) {
                if ($piece->comment) {
                    preg_match_all('/\r\n|\r|\n/', $piece->text, $newlines);
                    $text = ' '.implode('', $newlines[0]);
                } else {
                    $text = $piece->text;
                }
            } elseif ($piece instanceof Text) {
                $text = $piece->text;
            } else {
                throw new SqlException(ErrorCode::Syntax);
            }
            if ($bytes + strlen($text) > $this->maxSqlBytes) {
                throw new SqlException(ErrorCode::Limit);
            }
            $bytes += strlen($text);
            $parts[] = $text;
        }
        if (! $started) {
            throw new SqlException(ErrorCode::Syntax);
        }
        $sql = implode('', $parts);
        if (in_array($first, ['MERGE', 'WITH', 'INSERT'], true)) {
            $shape = StatementShape::inspect($sql);
            if ($shape->verb === 'MERGE' && $definition->kind !== ($shape->returning ? QueryKind::WriteReturning : QueryKind::Write)) {
                throw new SqlException(ErrorCode::QueryKind);
            }
            if ($shape->doSelect) {
                if ($shape->verb !== 'INSERT' || ! $shape->returning || $definition->kind !== QueryKind::WriteReturning) {
                    throw new SqlException(ErrorCode::QueryKind);
                }
                if ($definition->minimumPostgres < 19) {
                    throw new SqlException(ErrorCode::Compatibility);
                }
            }
        }

        return new CompiledQuery($definition->id, $sql, $bindings, $definition->kind, hash('sha256', $definition->definitionHash.$definition->sourceHash.$sql), ['sourceHash' => $definition->sourceHash, 'bindingCount' => count($bindings), 'sqlBytes' => $bytes, 'encodedBytes' => $encodedBytes], $definition->minimumPostgres);
    }
}
