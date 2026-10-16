<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\StaticAnalysis;

use Hft\TwoWaySql\Codegen\Schema\ParameterShape;
use Hft\TwoWaySql\Parameter\ParameterSchema;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

final class ParameterCheck
{
    public function __construct(private ContractCatalog $catalog, private TypeStringResolver $types) {}

    /** @return list<IdentifierRuleError> */
    public function check(ParameterSchema $schema, Type $actual): array
    {
        $classes = $actual->getObjectClassNames();
        if ($actual->isObject()->yes() && $classes !== []) {
            foreach ($classes as $class) {
                if ($this->catalog->parameters($class)?->definitions != $schema->definitions) {
                    return [$this->error('parameterType', 'Input object needs a matching declared parameter contract.')];
                }
            }

            return [];
        }
        $shape = new ParameterShape($this->catalog->types());

        return $this->compare($this->types->resolve($shape->shape($shape->fields($schema->definitions))), $actual);
    }

    /** @return list<IdentifierRuleError> */
    private function compare(Type $expected, Type $actual): array
    {
        if (TypeCombinator::containsNull($expected) && TypeCombinator::removeNull($expected)->isArray()->yes()) {
            if ($actual->isNull()->yes()) {
                return [];
            }
            $expected = TypeCombinator::removeNull($expected);
            $actual = TypeCombinator::removeNull($actual);
        }
        $shapes = $expected->getConstantArrays();
        if (count($shapes) === 1 && $expected->equals($shapes[0])) {
            $expected = $shapes[0];
            $arrays = $actual->getConstantArrays();
            if ($arrays === [] || ! $actual->isArray()->yes()) {
                return [$this->error('parameterType', 'Parameter shape cannot be verified; provide a declared shape.')];
            }
            $errors = [];
            foreach ($arrays as $array) {
                foreach ($expected->getKeyTypes() as $index => $key) {
                    if ($expected->isOptionalKey($index) && $array->hasOffsetValueType($key)->no()) {
                        continue;
                    }
                    if (! $array->hasOffsetValueType($key)->yes() && ! $expected->isOptionalKey($index)) {
                        $errors[] = $this->error('parameterMissing', 'A required parameter field is missing or optional.');
                    } else {
                        array_push($errors, ...$this->compare($expected->getOffsetValueType($key), $array->getOffsetValueType($key)));
                    }
                }
                foreach ($array->getKeyTypes() as $key) {
                    if ($expected->hasOffsetValueType($key)->no()) {
                        $errors[] = $this->error('parameterExtra', 'An undeclared parameter field is present.');
                    }
                }
            }

            return $errors;
        }
        if ($expected->isArray()->yes() && $actual->isArray()->yes() && ! $actual->isIterableAtLeastOnce()->no()) {
            $errors = $this->compare($expected->getIterableValueType(), $actual->getIterableValueType());
            if ($errors !== []) {
                return $errors;
            }
        }

        return $expected->isSuperTypeOf($actual)->yes() ? [] : [$this->error('parameterType', 'Parameter type or nullability differs from its declared contract.')];
    }

    private function error(string $id, string $message): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)->identifier('twoway.'.$id)->build();
    }
}
