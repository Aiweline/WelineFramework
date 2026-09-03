<?php

declare(strict_types=1);

namespace Weline\I18n\Helper;

/**
 * Creates a collision-resistant DOM namespace for one rendered switcher.
 *
 * A switcher can be rendered by independent Hook/Template contexts in the same
 * response, so request-local or process-local counters are not a safe identity.
 */
final class SwitcherInstanceId
{
    private const NONCE_BYTES = 12;

    public static function create(string $prefix): string
    {
        $prefix = trim($prefix);
        if ($prefix === '' || preg_match('/^[A-Za-z][A-Za-z0-9_:.-]*$/D', $prefix) !== 1) {
            throw new \InvalidArgumentException('Switcher DOM id prefix is invalid.');
        }

        return $prefix . '-' . bin2hex(random_bytes(self::NONCE_BYTES));
    }
}
