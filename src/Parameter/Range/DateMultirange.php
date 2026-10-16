<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Parameter\Range;

final readonly class DateMultirange
{
    /** @param list<DateRange> $ranges */
    private function __construct(#[\SensitiveParameter] private array $ranges) {}

    /** @param list<DateRange> $ranges */
    public static function of(#[\SensitiveParameter] array $ranges): self
    {
        $ranges = MultirangeText::checkedRanges($ranges, DateRange::class);
        MultirangeText::encode($ranges);

        return new self($ranges);
    }

    public static function fromPostgres(#[\SensitiveParameter] string $wire, int $maxBytes = 8388608, int $maxRanges = 10000): self
    {
        $ranges = [];
        foreach (MultirangeText::decode($wire, $maxBytes, $maxRanges) as $part) {
            $ranges[] = DateRange::fromPostgres($part);
        }

        return self::of($ranges);
    }

    /** @return list<DateRange> */
    public function ranges(): array
    {
        return $this->ranges;
    }

    public function toPostgres(): string
    {
        return MultirangeText::encode($this->ranges);
    }

    /** @return array{ranges:int} */
    public function __debugInfo(): array
    {
        return ['ranges' => count($this->ranges)];
    }
}
