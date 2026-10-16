<?php

declare(strict_types=1);

namespace Hft\TwoWaySql\Laravel\Diagnostics;

use Hft\TwoWaySql\Exception\ErrorCode;
use Hft\TwoWaySql\Exception\SqlException;

/** @internal Discards every target, tag, and expression; quotes cannot manufacture feedback. */
final class AdviceFeedback
{
    /** @return list<list<string>> */
    public static function summarize(#[\SensitiveParameter] string $text): array
    {
        if (strlen($text) > 65536) {
            throw new SqlException(ErrorCode::Limit);
        }
        $flags = ['matched' => 'matched', 'partially matched' => 'partially_matched', 'not matched' => 'not_matched', 'inapplicable' => 'inapplicable', 'conflicting' => 'conflicting', 'failed' => 'failed'];
        $quoted = false;
        $entries = [];
        for ($index = 0; $index < strlen($text); $index++) {
            if ($text[$index] === '"') {
                if ($quoted && ($text[$index + 1] ?? '') === '"') {
                    $index++;
                } else {
                    $quoted = ! $quoted;
                }
            } elseif (! $quoted && substr($text, $index, 2) === '/*') {
                $end = strpos($text, '*/', $index + 2);
                if ($end === false) {
                    throw new SqlException(ErrorCode::Execution);
                }
                if (count($entries) >= 256) {
                    throw new SqlException(ErrorCode::Limit);
                }
                $entry = [];
                foreach (explode(',', substr($text, $index + 2, $end - $index - 2)) as $flag) {
                    $entry[] = $flags[trim($flag)] ?? 'unknown';
                }
                $entries[] = array_values(array_unique($entry));
                $index = $end + 1;
            }
        }
        if ($quoted) {
            throw new SqlException(ErrorCode::Execution);
        }

        return $entries;
    }
}
