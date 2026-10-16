<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Codegen\Schema;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Parameter\ParameterSchema;
use Hft\TwoWaySql\Parameter\Patch\PatchSchema;
use Hft\TwoWaySql\Types\TypeCatalog;
use Hft\TwoWaySql\Types\TypeDescriptor;

/** @phpstan-import-type Spec from ParameterSchema
 * @phpstan-type Field array{type:string, doc:string, optional?:bool}
 */
final class ParameterShape
{
    public function __construct(private readonly TypeCatalog $types = new TypeCatalog) {}

    /** @param array<string, Spec> $definitions
     * @return array<string, Field>
     */
    public function fields(array $definitions): array
    {
        $groups = [];
        foreach ($definitions as $path => $spec) {
            $parts = explode('.', $path, 2);
            $groups[$parts[0]][$parts[1] ?? ''] = $spec;
        }
        $fields = [];
        foreach ($groups as $name => $group) {
            $fields[$name] = isset($group['']) ? $this->field($group['']) : ['type' => 'array', 'doc' => $this->shape($this->fields($group))];
        }

        return $fields;
    }

    /** @param array<string, Field> $fields */
    public function shape(array $fields): string
    {
        $parts = [];
        foreach ($fields as $name => $field) {
            $parts[] = $name.(($field['optional'] ?? false) ? '?' : '').':'.$field['doc'];
        }

        return 'array{'.implode(',', $parts).'}';
    }

    /** @param Spec $spec
     * @return Field
     */
    private function field(array $spec): array
    {
        $type = $spec['type'];
        $native = match ($type) {
            'int64' => 'int', 'text', 'uuid', 'decimal', 'date', 'timestamp' => 'string',
            'bool' => 'bool', 'timestamptz' => '\\DateTimeInterface', 'jsonb' => 'mixed',
            'daterange' => '\\Hft\\TwoWaySql\\Parameter\\Range\\DateRange',
            'tstzrange' => '\\Hft\\TwoWaySql\\Parameter\\Range\\TstzRange',
            default => str_starts_with($type, 'codec:') ? 'mixed' : 'array',
        };
        $doc = $native;
        if (str_starts_with($type, 'codec:') && ($descriptor = $this->types->find(substr($type, 6))) !== null) {
            if (($spec['nullable'] ?? false) && ! $descriptor->nullable) {
                throw new SqlException(ErrorCode::Generation);
            }
            $native = TypeDescriptor::phpType($descriptor->inputType);
            $doc = $descriptor->inputDoc ?? ($native === 'array' ? 'array<array-key,mixed>' : $native);
        }
        if ($type === 'patch<record>') {
            $fields = [];
            foreach ((new PatchSchema($spec['fields'] ?? []))->fields as $name => $item) {
                if (! $item['protected']) {
                    $fields[$name] = $this->field($item) + ['optional' => true];
                }
            }
            $doc = $this->shape($fields);
        } elseif ($type === 'list<record>') {
            $doc = 'list<'.$this->shape($this->fields((new ParameterSchema($spec['fields'] ?? []))->definitions)).'>';
        } elseif (preg_match('/\A(?:list|pg_array)<(int64|text|uuid|bool|decimal)>\z/D', $type, $match)) {
            $element = match ($match[1]) {
                'int64' => 'int', 'bool' => 'bool', default => 'string'
            };
            $doc = 'list<'.(($spec['elements_nullable'] ?? false) ? '?' : '').$element.'>';
        } elseif ($native === 'array' && ! str_starts_with($type, 'codec:')) {
            throw new SqlException(ErrorCode::Generation);
        }
        $nullable = ($spec['nullable'] ?? false) && $native !== 'mixed' ? '?' : '';

        return ['type' => $nullable.$native, 'doc' => $nullable.$doc];
    }
}
