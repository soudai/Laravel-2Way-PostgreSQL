<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\StaticAnalysis;

use Hft\TwoWaySql\Attributes\SqlType;
use Hft\TwoWaySql\Codegen\Definition\ResultSchema;
use Hft\TwoWaySql\Contracts\Compiler;
use Hft\TwoWaySql\Execution\QueryOperation;
use Hft\TwoWaySql\Laravel\Executor\Executor;
use Hft\TwoWaySql\Laravel\Facades\TwoWaySql;
use Hft\TwoWaySql\Laravel\TwoWaySqlManager;
use Hft\TwoWaySql\Template\QueryKind;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/** @implements Rule<Expr> */
final class CallRule implements Rule
{
    public function __construct(private ContractCatalog $catalog, private ParameterCheck $parameters, private ReflectionProvider $reflection, private TypeStringResolver $types) {}

    public function getNodeType(): string
    {
        return Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        // PHPStan presents a nullsafe call as a narrowed MethodCall as well.
        if ((! $node instanceof MethodCall && ! $node instanceof StaticCall) || ! $node->name instanceof Identifier || $node->isFirstClassCallable()) {
            return [];
        }
        if ($node instanceof StaticCall) {
            if (! $node->class instanceof Name || $scope->resolveName($node->class) !== TwoWaySql::class) {
                return [];
            }
        } else {
            $receiver = $scope->getType($node->var);
            if (! (new ObjectType(Executor::class))->isSuperTypeOf($receiver)->yes() && ! (new ObjectType(TwoWaySqlManager::class))->isSuperTypeOf($receiver)->yes() && ! (new ObjectType(Compiler::class))->isSuperTypeOf($receiver)->yes()) {
                return [];
            }
        }
        $method = $node->name->name;
        $operation = QueryOperation::forMethod($method);
        if ($operation === null) {
            return [];
        }
        $dto = $method !== $operation->value;
        $arguments = $node->getArgs();
        $id = $this->argument($arguments, 'id', $dto ? 1 : 0);
        if ($id === null || $id->unpack || ($ids = $scope->getType($id->value)->getConstantStrings()) === []) {
            return [$this->error('dynamicQuery', 'Query ID is not a known literal; SQL contracts cannot be verified.')];
        }
        $errors = [];
        foreach ($ids as $literal) {
            $query = $this->catalog->query($literal->getValue());
            if ($query === null) {
                $errors[] = $this->error('unknownQuery', 'Query ID is absent from the generated manifest.');

                continue;
            }
            if ($query->stale()) {
                $errors[] = $this->error('staleSql', 'SQL source changed; regenerate and review the declared contracts.');
            }
            if (! $operation->allows(QueryKind::from($query->contract->kind))) {
                $errors[] = $this->error('queryKind', 'Execution mode does not match the declared query kind.');
            }
            $arg = $this->argument($arguments, $method === 'batch' ? 'parameterSets' : 'parameters', $dto ? 2 : 1);
            $actual = $arg !== null ? $scope->getType($arg->value) : ConstantArrayTypeBuilder::createEmpty()->getArray();
            if ($method === 'batch') {
                $actual = $actual->getIterableValueType();
            }
            array_push($errors, ...$this->parameters->check($query->parameters, $actual));
            if ($dto) {
                $class = $this->argument($arguments, 'class', 0);
                if ($class === null || ! $this->result($scope->getType($class->value), $query->contract->result)) {
                    $errors[] = $this->error('resultType', 'DTO constructor does not match the declared result columns, or cannot be verified.');
                }
            }
        }

        return $errors;
    }

    /** @param array<Arg> $arguments */
    private function argument(array $arguments, string $name, int $position): ?Arg
    {
        foreach ($arguments as $index => $argument) {
            if ($argument->name?->name === $name || ($argument->name === null && $index === $position)) {
                return $argument;
            }
        }

        return null;
    }

    private function result(Type $class, ResultSchema $schema): bool
    {
        $strings = $class->getConstantStrings();
        if ($strings === [] || $schema->columns === []) {
            return false;
        }
        foreach ($strings as $string) {
            if (! $this->reflection->hasClass($string->getValue())) {
                return false;
            }
            $reflection = $this->reflection->getClass($string->getValue());
            if (! $reflection->hasConstructor() || $reflection->isAbstract() || $reflection->isInterface()
                || $reflection->getName() === Model::class
                || $reflection->isSubclassOf(Model::class)) {
                return false;
            }
            $constructor = $reflection->getConstructor();
            $parameters = $constructor->getVariants()[0]->getParameters();
            if (! $constructor->isPublic() || count($parameters) !== count($schema->columns)) {
                return false;
            }
            foreach ($parameters as $parameter) {
                $column = $schema->columns[$parameter->getName()] ?? null;
                if ($column === null || ($column['type'] === 'float' && ! isset($column['codec'])) || $parameter->isVariadic() || $parameter->passedByReference()->yes() || ! $this->types->resolve(ResultSchema::phpType($column))->equals($parameter->getNativeType())) {
                    return false;
                }
                if (isset($column['doc']) && ! $this->types->resolve(($column['nullable'] ? '?' : '').$column['doc'])->equals($parameter->getType())) {
                    return false;
                }
                $attributes = array_values(array_filter($parameter->getAttributes(), static fn ($attribute): bool => $attribute->getName() === SqlType::class));
                $codec = $column['codec'] ?? null;
                if ($codec === null ? $attributes !== [] : (count($attributes) !== 1 || ! ($attributes[0]->getArgumentTypes()['id'] ?? new NullType)->equals(new ConstantStringType($codec)))) {
                    return false;
                }
            }
        }

        return true;
    }

    private function error(string $id, string $message): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)->identifier('twoway.'.$id)->build();
    }
}
