<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

/**
 * Shell-standard freight failure modes (each Provider chooses via own SystemConfig).
 */
final class DropshipFreightPolicy
{
    /** Keep local Shipping amount; checkout continues. */
    public const ON_FAILURE_FALLBACK_LOCAL = 'fallback_local';

    /** Block checkout freeze / empty shipping methods. */
    public const ON_FAILURE_BLOCK_CHECKOUT = 'block_checkout';

    public static function normalize(?string $raw): string
    {
        $v = strtolower(trim((string)$raw));
        if ($v === self::ON_FAILURE_BLOCK_CHECKOUT) {
            return self::ON_FAILURE_BLOCK_CHECKOUT;
        }

        return self::ON_FAILURE_FALLBACK_LOCAL;
    }
}
