<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Codegen;

use Hft\TwoWaySql\Codegen\Definition\TypeNames;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use stdClass;

/** @internal */
final readonly class InspectionPlan
{
    /** @param array<string,string> $cases
     * @param  array<string,string>  $exclusions
     */
    private function __construct(public array $cases, public array $exclusions) {}

    /** @param list<string> $required */
    public static function load(?string $path, ?string $against, array $required): self
    {
        if ($required === [] || ($path === null) === ($against === null)) {
            throw new SqlException(ErrorCode::Generation);
        }
        if ($against !== null) {
            if (! TypeNames::generatedClassName($against)) {
                throw new SqlException(ErrorCode::Generation);
            }

            return new self(array_fill_keys($required, $against), []);
        }
        if ($path === null || str_contains($path, '://') || ! is_file($path) || is_link($path)) {
            throw new SqlException(ErrorCode::Generation);
        }
        $json = file_get_contents($path, false, null, 0, 1048577);
        if ($json === false || strlen($json) > 1048576) {
            throw new SqlException(ErrorCode::Limit);
        }
        $data = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
        if (! $data instanceof stdClass || ($data->schema_version ?? null) !== '1.0'
            || array_diff(array_keys(get_object_vars($data)), ['schema_version', 'cases', 'exclusions']) !== []
            || ! ($data->cases ?? null) instanceof stdClass || ! ($data->exclusions ?? null) instanceof stdClass) {
            throw new SqlException(ErrorCode::Generation);
        }
        $cases = self::entries($data->cases, true);
        $exclusions = self::entries($data->exclusions, false);
        $ids = array_merge(array_keys($cases), array_keys($exclusions));
        if ($cases === [] || count($ids) !== count(array_unique($ids)) || array_diff($required, $ids) !== [] || array_diff($ids, $required) !== []) {
            throw new SqlException(ErrorCode::Generation);
        }

        return new self($cases, $exclusions);
    }

    /** @return array<string,string> */
    private static function entries(stdClass $object, bool $dto): array
    {
        $result = [];
        // PHP converts numeric JSON object property names to integer array keys.
        /** @var array<array-key,mixed> $entries */
        $entries = get_object_vars($object);
        foreach ($entries as $id => $value) {
            if (! is_string($id) || ! is_string($value) || trim($value) === '' || strlen($value) > 1024
                || ($dto && ! TypeNames::generatedClassName($value))) {
                throw new SqlException(ErrorCode::Generation);
            }
            $result[$id] = $value;
        }

        return $result;
    }
}
