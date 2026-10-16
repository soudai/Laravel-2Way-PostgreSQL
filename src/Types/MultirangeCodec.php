<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Types;

use Hft\TwoWaySql\Contracts\ColumnConverter;
use Hft\TwoWaySql\Contracts\ParameterCodec;
use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\MappingException;
use Hft\TwoWaySql\Exception\SqlException;
use Hft\TwoWaySql\Parameter\Range\DateMultirange;
use Hft\TwoWaySql\Parameter\Range\MultirangeText;
use Hft\TwoWaySql\Parameter\Range\TstzMultirange;

final readonly class MultirangeCodec implements ColumnConverter, ParameterCodec
{
    public function __construct(private string $type, private int $maxBytes = 8388608, private int $maxRanges = 10000)
    {
        MultirangeText::limits($maxBytes, $maxRanges);
        if (! in_array($type, ['date', 'tstz'], true)) {
            throw new SqlException(ErrorCode::ParameterType);
        }
    }

    public function encode(#[\SensitiveParameter] mixed $value): string
    {
        if (($this->type === 'date' && $value instanceof DateMultirange) || ($this->type === 'tstz' && $value instanceof TstzMultirange)) {
            return MultirangeText::encode($value->ranges(), $this->maxBytes, $this->maxRanges);
        }
        throw new SqlException(ErrorCode::ParameterType);
    }

    public function convert(#[\SensitiveParameter] mixed $value): DateMultirange|TstzMultirange
    {
        try {
            if (! is_string($value)) {
                throw new MappingException;
            }

            return $this->type === 'date' ? DateMultirange::fromPostgres($value, $this->maxBytes, $this->maxRanges) : TstzMultirange::fromPostgres($value, $this->maxBytes, $this->maxRanges);
        } catch (\Throwable) {
            throw new MappingException;
        }
    }
}
