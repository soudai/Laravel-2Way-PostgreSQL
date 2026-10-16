<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Diagnostics;

/** @phpstan-type Metadata array{sqlId:string,sourceHash:string,queryHash:string,elapsedMicroseconds:int,rows:?int,errorCode:?string,sqlState:?string,branches:list<string>,branchesTruncated:bool} */
final readonly class ExecutionEvent
{
    /** @var list<string> */
    public array $branches;

    public bool $branchesTruncated;

    /** @param list<string> $branches */
    public function __construct(public string $sqlId, public string $sourceHash, public string $queryHash, public int $elapsedMicroseconds, public ?int $rows, public ?string $errorCode, public ?string $sqlState, array $branches = [])
    {
        $this->branches = array_slice($branches, 0, 1024);
        $this->branchesTruncated = count($branches) > 1024;
    }

    /** @return Metadata */
    public function metadata(): array
    {
        return ['sqlId' => $this->sqlId, 'sourceHash' => $this->sourceHash, 'queryHash' => $this->queryHash, 'elapsedMicroseconds' => $this->elapsedMicroseconds, 'rows' => $this->rows, 'errorCode' => $this->errorCode, 'sqlState' => $this->sqlState, 'branches' => $this->branches, 'branchesTruncated' => $this->branchesTruncated];
    }
}
