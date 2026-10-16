<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Compiler\Trace;

use Hft\TwoWaySql\Template\Ast\BranchNode;
use Hft\TwoWaySql\Template\Ast\ClauseNode;
use Hft\TwoWaySql\Template\Ast\LoopNode;
use Hft\TwoWaySql\Template\Ast\Node;
use Hft\TwoWaySql\Template\Source\SourceSpan;

/** @internal
 * @phpstan-type Arm array{id:string,kind:string,line:int,column:int,hits:int}
 * @phpstan-type Report array{sqlId:string,sourceHash:string,arms:list<Arm>,truncated:bool,compilations:int}
 */
final class CoverageSession
{
    /** @var array<string, Arm> */
    private array $arms = [];

    private bool $truncated = false;

    public function __construct(private readonly string $sqlId, private readonly string $sourceHash, private readonly int $maxArms) {}

    /** @param list<Node> $nodes */
    public function catalog(array $nodes): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof BranchNode) {
                $hasElse = false;
                foreach ($node->arms as $index => $arm) {
                    $hasElse = $hasElse || $arm->condition === null;
                    $this->add($node->span, 'arm:'.$index, $arm->condition === null ? 'else' : ($index === 0 ? 'if' : 'elseif'), $arm->span);
                }
                if (! $hasElse) {
                    $this->add($node->span, 'none', 'none', $node->span);
                }
                foreach ($node->arms as $arm) {
                    $this->catalog($arm->nodes);
                }
            } elseif ($node instanceof LoopNode) {
                foreach (['zero', 'one', 'many'] as $bucket) {
                    $this->add($node->span, 'loop.'.$bucket, 'loop.'.$bucket, $node->span);
                }
                $this->catalog($node->nodes);
            } elseif ($node instanceof ClauseNode) {
                $this->catalog($node->nodes);
            }
        }
    }

    public function branch(SourceSpan $span, ?int $arm): void
    {
        $this->hit($span, $arm === null ? 'none' : 'arm:'.$arm);
    }

    public function loop(SourceSpan $span, int $count): void
    {
        $this->hit($span, 'loop.'.match ($count) {
            0 => 'zero',1 => 'one',default => 'many'
        });
    }

    private function add(SourceSpan $span, string $slot, string $kind, SourceSpan $location): void
    {
        if (count($this->arms) >= $this->maxArms) {
            $this->truncated = true;

            return;
        }
        $id = $this->id($span, $slot);
        $this->arms[$id] = ['id' => $id, 'kind' => $kind, 'line' => $location->line, 'column' => $location->column, 'hits' => 0];
    }

    private function hit(SourceSpan $span, string $slot): void
    {
        $id = $this->id($span, $slot);
        if (isset($this->arms[$id]) && $this->arms[$id]['hits'] < PHP_INT_MAX) {
            $this->arms[$id]['hits']++;
        }
    }

    private function id(SourceSpan $span, string $slot): string
    {
        return hash('sha256', $this->sourceHash.':'.$span->offset.':'.$slot);
    }

    /** @return list<string> */
    public function visitedIds(): array
    {
        return array_keys(array_filter($this->arms, static fn (array $arm): bool => $arm['hits'] > 0));
    }

    /** @return Report */
    public function report(): array
    {
        return ['sqlId' => $this->sqlId, 'sourceHash' => $this->sourceHash, 'arms' => array_values($this->arms), 'truncated' => $this->truncated, 'compilations' => 1];
    }
}
