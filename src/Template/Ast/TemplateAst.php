<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Template\Ast;

final readonly class TemplateAst
{
    /** @param list<Node> $nodes
     * @param  list<string>  $references
     */
    public function __construct(
        public array $nodes,
        public string $sourceHash,
        public array $references,
        public string $grammarVersion = 'canonical-v1',
    ) {}
}
