<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Compiler\Evaluator;

use Hft\TwoWaySql\Template\Ast\BindNode;
use Hft\TwoWaySql\Template\Ast\Node;

/** Per-compilation value; never stored in TemplateAst or its cache.
 * @phpstan-import-type Spec from \Hft\TwoWaySql\Parameter\ParameterSchema
 */
final readonly class ResolvedBind implements Node
{
    /** @param Spec $spec */
    public function __construct(public BindNode $source, public array $spec, #[\SensitiveParameter] private mixed $value) {}

    public function value(): mixed
    {
        return $this->value;
    }

    /** @return array{path:string} */
    public function __debugInfo(): array
    {
        return ['path' => $this->source->path];
    }

    /** @return array{path:string} */
    public function __serialize(): array
    {
        return $this->__debugInfo();
    }
}
