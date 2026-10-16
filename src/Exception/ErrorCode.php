<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Exception;

enum ErrorCode: string
{
    case Syntax = 'TW_SYNTAX';
    case Unsupported = 'TW_UNSUPPORTED';
    case Sample = 'TW_SAMPLE';
    case ParameterMissing = 'TW_PARAMETER_MISSING';
    case ParameterExtra = 'TW_PARAMETER_EXTRA';
    case ParameterType = 'TW_PARAMETER_TYPE';
    case EmptyList = 'TW_EMPTY_LIST';
    case EmptySet = 'TW_EMPTY_SET';
    case Limit = 'TW_LIMIT';
    case TemplatePath = 'TW_TEMPLATE_PATH';
    case QueryKind = 'TW_QUERY_KIND';
    case Connection = 'TW_CONNECTION';
    case TransactionRequired = 'TW_TRANSACTION_REQUIRED';
    case Cardinality = 'TW_CARDINALITY';
    case Execution = 'TW_EXECUTION';
    case Mapping = 'TW_MAPPING';
    case Sort = 'TW_SORT';
    case AffectedRows = 'TW_AFFECTED_ROWS';
    case Batch = 'TW_BATCH';
    case Generation = 'TW_GENERATION';
    case Compatibility = 'TW_COMPATIBILITY';
}
