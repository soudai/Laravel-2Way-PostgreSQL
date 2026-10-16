<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Parameter\Range;

use DateTimeImmutable;
use DateTimeZone;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Parameter\Codec\BuiltinEncoder;

final readonly class DateRange
{
    private function __construct(#[\SensitiveParameter] private ?string $lower, #[\SensitiveParameter] private ?string $upper, public bool $lowerInclusive, public bool $upperInclusive, private bool $empty) {}

    public static function between(#[\SensitiveParameter] ?string $lower, #[\SensitiveParameter] ?string $upper, bool $lowerInclusive = true, bool $upperInclusive = false): self
    {
        foreach ([$lower, $upper] as $bound) {
            if ($bound !== null) {
                (new BuiltinEncoder)->encode($bound, ['type' => 'date']);
            }
        }
        if (($lower === '9999-12-31' && ! $lowerInclusive) || ($upper === '9999-12-31' && $upperInclusive)) {
            throw new SqlException(ErrorCode::ParameterType);
        }
        if ($lower !== null && $upper !== null) {
            if ($lower > $upper) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            if ($lower === $upper && (! $lowerInclusive || ! $upperInclusive)) {
                return self::empty();
            }
            if (! $lowerInclusive && ! $upperInclusive && (new DateTimeImmutable($lower, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d') === $upper) {
                return self::empty();
            }
        }

        return new self($lower, $upper, $lower !== null && $lowerInclusive, $upper !== null && $upperInclusive, false);
    }

    public static function empty(): self
    {
        return new self(null, null, false, false, true);
    }

    public static function fromPostgres(#[\SensitiveParameter] string $wire): self
    {
        $data = RangeText::decode($wire);

        return $data['empty'] ? self::empty() : self::between($data['lower'], $data['upper'], $data['lower_inclusive'], $data['upper_inclusive']);
    }

    public function lower(): ?string
    {
        return $this->lower;
    }

    public function upper(): ?string
    {
        return $this->upper;
    }

    public function isEmpty(): bool
    {
        return $this->empty;
    }

    public function toPostgres(): string
    {
        return RangeText::encode($this->lower, $this->upper, $this->lowerInclusive, $this->upperInclusive, $this->empty);
    }

    /** @return array{empty:bool,lower_inclusive:bool,upper_inclusive:bool} */
    public function __debugInfo(): array
    {
        return ['empty' => $this->empty, 'lower_inclusive' => $this->lowerInclusive, 'upper_inclusive' => $this->upperInclusive];
    }
}
