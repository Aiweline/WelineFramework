<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

/**
 * Edge origin mode for ManagedNginx: WLS Workers (proxy) vs PHP-FPM (fastcgi).
 */
final class ManagedNginxOriginMode
{
    public const WLS = 'wls';
    public const FPM = 'fpm';

    public static function normalize(mixed $raw): string
    {
        $value = \strtolower(\trim((string)$raw));
        if ($value === self::FPM || $value === 'php-fpm' || $value === 'php_fpm') {
            return self::FPM;
        }

        return self::WLS;
    }

    public static function isFpm(mixed $raw): bool
    {
        return self::normalize($raw) === self::FPM;
    }
}
