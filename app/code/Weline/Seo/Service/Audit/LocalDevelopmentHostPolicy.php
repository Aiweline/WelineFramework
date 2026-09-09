<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Audit;

/**
 * Decides when SEO server-side crawlers may relax HTTPS certificate verification.
 *
 * Local WLS default host is {hash}.test.weline.com (ends with .com, not .test).
 */
final class LocalDevelopmentHostPolicy
{
    public static function shouldRelaxHttpsTls(string $url): bool
    {
        if (strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return false;
        }

        $host = strtolower(trim((string)parse_url($url, PHP_URL_HOST), '[]'));
        if ($host === '') {
            return false;
        }

        return self::isLocalDevelopmentHost($host);
    }

    public static function isLocalDevelopmentHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '') {
            return false;
        }

        if (
            $host === 'localhost'
            || $host === 'host.docker.internal'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test.weline.com')
            || str_ends_with($host, '.weline.test')
        ) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
