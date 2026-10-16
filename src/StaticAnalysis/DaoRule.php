<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\StaticAnalysis;

use Hft\TwoWaySql\Attributes\SqlQuery;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\NullType;

/** @implements Rule<InClassMethodNode> */
final class DaoRule implements Rule
{
    public function __construct(private ContractCatalog $catalog, private TypeStringResolver $types) {}

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection()->getName();
        $method = $node->getMethodReflection();
        if (! $this->catalog->isDao($class) || $method->getName() === '__construct') {
            return [];
        }
        $definition = $this->catalog->method($class, $method->getName());
        $signature = $definition === null || $method->isStatic() || $method->returnsByReference()->yes();
        $variant = $method->getVariants()[0];
        if ($definition !== null) {
            $signature = $signature || count($variant->getParameters()) !== count($definition->arguments)
                || ! $this->types->resolve($definition->returnType)->equals($variant->getNativeReturnType())
                || ! $this->types->resolve($definition->returnDoc)->equals($variant->getReturnType());
            foreach ($variant->getParameters() as $index => $parameter) {
                $argument = $definition->arguments[$index] ?? null;
                $signature = $signature || $argument === null || $parameter->getName() !== $argument->name || $parameter->isVariadic() || $parameter->isOptional() || $parameter->passedByReference()->yes()
                    || ! $this->types->resolve($argument->type)->equals($parameter->getNativeType());
                if ($argument?->doc !== null) {
                    $signature = $signature || ! $this->types->resolve($argument->doc)->equals($parameter->getType());
                }
            }
        }
        $errors = $signature ? [RuleErrorBuilder::message('DAO signature differs from its generated contract; regenerate after reviewing the declaration.')->identifier('twoway.daoSignature')->build()] : [];
        if ($definition !== null && $this->catalog->isInterface($class)) {
            $attributes = [];
            foreach ($node->getOriginalNode()->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    if ($scope->resolveName($attribute->name) === SqlQuery::class) {
                        $attributes[] = $attribute;
                    }
                }
            }
            $arguments = [];
            if (count($attributes) === 1) {
                foreach ($attributes[0]->args as $index => $argument) {
                    $key = $argument->name->name ?? (['id', 'mode', 'result'][$index] ?? 'invalid');
                    $arguments[$key] = $scope->getType($argument->value);
                }
            }
            if (! ($arguments['id'] ?? new NullType)->equals(new ConstantStringType($definition->query->id))
                || ! ($arguments['mode'] ?? new ConstantStringType('select'))->equals(new ConstantStringType($definition->mode))
                || ! ($arguments['result'] ?? new NullType)->equals($definition->resultClass === null ? new NullType : new ConstantStringType($definition->resultClass))) {
                $errors[] = RuleErrorBuilder::message('SqlQuery attribute differs from its generated contract.')->identifier('twoway.daoAttribute')->build();
            }
        }

        return $errors;
    }
}
