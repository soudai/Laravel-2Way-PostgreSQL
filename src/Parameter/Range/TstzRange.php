<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Parameter\Range;

use DateTimeImmutable;
use DateTimeInterface;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Mapping\ScalarConversion;
use Hft\TwoWaySql\Parameter\Codec\BuiltinEncoder;

final readonly class TstzRange
{
    private function __construct(#[\SensitiveParameter] private ?DateTimeImmutable $lower, #[\SensitiveParameter] private ?DateTimeImmutable $upper, public bool $lowerInclusive, public bool $upperInclusive, private bool $empty) {}

    public static function between(#[\SensitiveParameter] ?DateTimeInterface $lower, #[\SensitiveParameter] ?DateTimeInterface $upper, bool $lowerInclusive = true, bool $upperInclusive = false): self
    {
        foreach ([$lower, $upper] as $bound) {
            if ($bound !== null) {
                if ($bound->getOffset() % 60 !== 0) {
                    throw new SqlException(ErrorCode::ParameterType);
                }
                (new BuiltinEncoder)->encode($bound, ['type' => 'timestamptz']);
            }
        }
        if ($lower !== null && $upper !== null) {
            if ($lower > $upper) {
                throw new SqlException(ErrorCode::ParameterType);
            }
            if ($lower == $upper && (! $lowerInclusive || ! $upperInclusive)) {
                return self::empty();
            }
        }

        return new self($lower === null ? null : DateTimeImmutable::createFromInterface($lower), $upper === null ? null : DateTimeImmutable::createFromInterface($upper), $lower !== null && $lowerInclusive, $upper !== null && $upperInclusive, false);
    }

    public static function empty(): self
    {
        return new self(null, null, false, false, true);
    }

    public static function fromPostgres(#[\SensitiveParameter] string $wire): self
    {
        $data = RangeText::decode($wire);
        try {
            return $data['empty'] ? self::empty() : self::between($data['lower'] === null ? null : ScalarConversion::datetime($data['lower']), $data['upper'] === null ? null : ScalarConversion::datetime($data['upper']), $data['lower_inclusive'], $data['upper_inclusive']);
        } catch (\Throwable) {
            throw new SqlException(ErrorCode::ParameterType);
        }
    }

    public function lower(): ?DateTimeImmutable
    {
        return $this->lower;
    }

    public function upper(): ?DateTimeImmutable
    {
        return $this->upper;
    }

    public function isEmpty(): bool
    {
        return $this->empty;
    }

    public function toPostgres(): string
    {
        return RangeText::encode($this->lower?->format('Y-m-d\TH:i:s.uP'), $this->upper?->format('Y-m-d\TH:i:s.uP'), $this->lowerInclusive, $this->upperInclusive, $this->empty);
    }

    /** @return array{empty:bool,lower_inclusive:bool,upper_inclusive:bool} */
    public function __debugInfo(): array
    {
        return ['empty' => $this->empty, 'lower_inclusive' => $this->lowerInclusive, 'upper_inclusive' => $this->upperInclusive];
    }
}
