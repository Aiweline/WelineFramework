<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

/**
 * Normalize Offer / row requires_shipping scalars; detect "absent".
 */
final class RequiresShippingFlag
{
    public static function isAbsent(mixed $raw): bool
    {
        return $raw === null || $raw === '';
    }

    public static function coercePresent(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) || is_float($raw)) {
            return ((int)$raw) !== 0;
        }
        $s = strtolower(trim((string)$raw));
        if (in_array($s, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return true;
    }
}
