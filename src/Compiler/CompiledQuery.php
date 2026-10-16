<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Compiler;

use Hft\TwoWaySql\Template\QueryKind;

final readonly class CompiledQuery
{
    /** @param list<int|string|null> $values
     * @param  array<string, int|string|list<string>>  $diagnostics
     */
    public function __construct(
        public string $sqlId,
        private string $pdoSql,
        #[\SensitiveParameter] private array $values,
        public QueryKind $kind,
        public string $hash,
        public array $diagnostics = [],
        public int $minimumPostgres = 18,
    ) {}

    public function sql(): string
    {
        return $this->pdoSql;
    }

    /** @return list<int|string|null> */
    public function bindings(): array
    {
        return $this->values;
    }

    /** @return array{sqlId:string, kind:string, hash:string, bindings:int} */
    public function __debugInfo(): array
    {
        return ['sqlId' => $this->sqlId, 'kind' => $this->kind->value, 'hash' => $this->hash, 'bindings' => count($this->values)];
    }

    /** @return array{sqlId:string, kind:string, hash:string, bindings:int} */
    public function __serialize(): array
    {
        return $this->__debugInfo();
    }
}
