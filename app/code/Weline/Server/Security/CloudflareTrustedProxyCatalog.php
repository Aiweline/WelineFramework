<?php

declare(strict_types=1);

namespace Weline\Server\Security;

/**
 * Published Cloudflare edge CIDRs used as WLS trusted-proxy hops.
 *
 * Orange-cloud origins see CF edges as the Nginx `$remote_addr`. Managed Nginx
 * appends that edge onto `X-Forwarded-For`, so WLS must peel CF hops or the
 * edge IP becomes the client identity and `shared_ban` 403s whole POPs.
 *
 * Source: https://www.cloudflare.com/ips-v4 and /ips-v6 (fetched 2026-09-27).
 */
final class CloudflareTrustedProxyCatalog
{
    /**
     * @return list<string>
     */
    public static function cidrs(): array
    {
        return [
            // IPv4
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
            // IPv6
            '2400:cb00::/32',
            '2606:4700::/32',
            '2803:f800::/32',
            '2405:b500::/32',
            '2405:8100::/32',
            '2a06:98c0::/29',
            '2c0f:f248::/32',
        ];
    }

    public static function contains(string $ip): bool
    {
        $identity = new CanonicalClientIdentity();
        $normalized = $identity->normalizeIp($ip);
        if ($normalized === '') {
            return false;
        }

        return $identity->matchesAny($normalized, self::cidrs());
    }
}
