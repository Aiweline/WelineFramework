<?php

declare(strict_types=1);

namespace Weline\Compare\Service;

/**
 * Extracts comparable numeric magnitudes from storefront specification text.
 */
final class CompareNumericValueParser
{
    public function parse(string $value): ?float
    {
        $text = trim(mb_strtolower(str_replace([',', '，'], '', $value)));
        if ($text === '' || $text === '—') {
            return null;
        }

        if (!preg_match('/(\d+(?:\.\d+)?)/u', $text, $match)) {
            return null;
        }

        $number = (float)$match[1];
        if (preg_match('/tb|terabyte/u', $text)) {
            return $number * 1024.0;
        }
        if (preg_match('/gb|gigabyte/u', $text) || (preg_match('/\bg\b/u', $text) && !preg_match('/kg/u', $text))) {
            return $number;
        }
        if (preg_match('/mb|megabyte/u', $text)) {
            return $number / 1024.0;
        }

        return $number;
    }
}
