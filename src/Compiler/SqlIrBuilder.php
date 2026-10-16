<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Compiler;

use Hft\TwoWaySql\Compiler\Evaluator\ResolvedBind;
use Hft\TwoWaySql\Compiler\IR\BindSlot;
use Hft\TwoWaySql\Compiler\IR\Operator;
use Hft\TwoWaySql\Compiler\IR\SqlPiece;
use Hft\TwoWaySql\Compiler\IR\Text;
use Hft\TwoWaySql\Compiler\IR\Trivia;
use Hft\TwoWaySql\Compiler\Metadata\Identifier;
use Hft\TwoWaySql\Compiler\Sort\SortRenderer;
use Hft\TwoWaySql\Contracts\ValueEncoder;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Parameter\BoundValue;
use Hft\TwoWaySql\Parameter\Patch\PatchSchema;
use Hft\TwoWaySql\Template\Ast\AssignmentsNode;
use Hft\TwoWaySql\Template\Ast\BindNode;
use Hft\TwoWaySql\Template\Ast\ColumnsNode;
use Hft\TwoWaySql\Template\Ast\Node;
use Hft\TwoWaySql\Template\Ast\SortNode;
use Hft\TwoWaySql\Template\Ast\SqlNode;
use Hft\TwoWaySql\Template\Lexer\TokenKind;
use Hft\TwoWaySql\Template\QueryDefinition;

/** @phpstan-import-type Spec from \Hft\TwoWaySql\Parameter\ParameterSchema */
final readonly class SqlIrBuilder
{
    public function __construct(private ValueEncoder $encoder, private int $maxList = 10000, private int $maxBindings = 10000, private int $maxEncodedBytes = 33554432) {}

    /** @param list<Node> $nodes
     * @param  array<string, mixed>  $parameters
     * @return list<SqlPiece>
     */
    public function build(array $nodes, QueryDefinition $definition, #[\SensitiveParameter] array $parameters): array
    {
        $values = $definition->parameters->validateStructure($parameters);
        $pieces = [];
        $occurrence = 0;
        $encodedBytes = 0;
        foreach ($nodes as $node) {
            if ($node instanceof SqlNode) {
                $token = $node->token;
                $pieces[] = match ($token->kind) {
                    TokenKind::Operator => new Operator($token->rawText, $token->span),
                    TokenKind::Whitespace, TokenKind::Comment => new Trivia($token->rawText, $token->span, $token->kind === TokenKind::Comment),
                    TokenKind::Bind, TokenKind::Directive => throw new SqlException(ErrorCode::Syntax, $token->span),
                    default => new Text($token->rawText, $token->span),
                };
            } elseif ($node instanceof SortNode) {
                array_push($pieces, ...(new SortRenderer)->render($node, $definition->sorts, $values[$node->path] ?? null));
            } elseif ($node instanceof ColumnsNode) {
                foreach ($definition->projections->columns($node->path, $values[$node->path] ?? null) as $index => $column) {
                    $text = ($index > 0 ? ', ' : '').implode('.', array_map(Identifier::quote(...), $column['column']));
                    $pieces[] = new Text($text.(isset($column['alias']) ? ' AS '.Identifier::quote($column['alias']) : ''), $node->span);
                }
            } elseif ($node instanceof AssignmentsNode) {
                $patch = new PatchSchema($definition->parameters->definitions[$node->path]['fields'] ?? []);
                $first = true;
                foreach ($patch->values($values[$node->path] ?? null) as $field => $value) {
                    $spec = $patch->fields[$field];
                    $pieces[] = new Text(($first ? '' : ', ').Identifier::quote($spec['column']).' = ', $node->span);
                    $pieces[] = $this->binding($value, $spec, new BindNode($node->path.'.'.$field, false, $node->span), $occurrence, $encodedBytes);
                    $first = false;
                }
            } elseif ($node instanceof BindNode || $node instanceof ResolvedBind) {
                if ($node instanceof ResolvedBind) {
                    $value = $node->value();
                    $spec = $node->spec;
                    $node = $node->source;
                } else {
                    if (! array_key_exists($node->path, $values)) {
                        throw new SqlException(ErrorCode::ParameterMissing, $node->span);
                    }
                    $value = $values[$node->path];
                    $spec = $definition->parameters->definitions[$node->path];
                }
                if ($node->list) {
                    if (! is_array($value) || ! array_is_list($value)) {
                        throw new SqlException(ErrorCode::ParameterType, $node->span);
                    }
                    if ($value === []) {
                        throw new SqlException(ErrorCode::EmptyList, $node->span);
                    }
                    if (count($value) > $this->maxList || $occurrence + count($value) > $this->maxBindings) {
                        throw new SqlException(ErrorCode::Limit, $node->span);
                    }
                    $pieces[] = new Text('(', $node->span);
                    foreach ($value as $index => $element) {
                        if ($index > 0) {
                            $pieces[] = new Text(', ', $node->span);
                        }
                        $pieces[] = $this->binding($element, ['type' => substr($spec['type'], 5, -1)], $node, $occurrence, $encodedBytes);
                    }
                    $pieces[] = new Text(')', $node->span);
                } else {
                    $pieces[] = $this->binding($value, $spec, $node, $occurrence, $encodedBytes);
                }
            } else {
                throw new SqlException(ErrorCode::Syntax);
            }
        }

        return $pieces;
    }

    /** @param Spec $spec */
    private function binding(#[\SensitiveParameter] mixed $value, array $spec, BindNode $node, int &$occurrence, int &$encodedBytes): BindSlot
    {
        if ($occurrence >= $this->maxBindings) {
            throw new SqlException(ErrorCode::Limit, $node->span);
        }

        $wire = $this->encoder->encode($value, $spec);
        if ($encodedBytes + strlen((string) $wire) > $this->maxEncodedBytes) {
            throw new SqlException(ErrorCode::Limit, $node->span);
        }
        $encodedBytes += strlen((string) $wire);

        return new BindSlot(new BoundValue($spec['type'], $wire, $node->path, $occurrence++), $node->span);
    }
}
