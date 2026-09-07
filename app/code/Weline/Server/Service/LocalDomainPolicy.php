<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;

final class LocalDomainPolicy
{
    /** Default local/dev project root (public TLD; Google OAuth / console friendly). */
    public const TEST_ROOT_DOMAIN = 'test.weline.com';

    /** Previous default; still managed so existing hosts/certs keep working. */
    public const LEGACY_WELINE_TEST_ROOT_DOMAIN = 'weline.test';

    public const LEGACY_LOCAL_TEST_ROOT_DOMAIN = 'local.test';
    public const LOOPBACK_ROOT_DOMAIN = 'weline.localhost';

    public const TEST_WILDCARD_DOMAIN = '*.test.weline.com';
    public const LEGACY_WELINE_TEST_WILDCARD_DOMAIN = '*.weline.test';
    public const LEGACY_LOCAL_TEST_WILDCARD_DOMAIN = '*.local.test';
    public const LOOPBACK_WILDCARD_DOMAIN = '*.weline.localhost';

    private const LOCAL_ROOT_DOMAINS = [
        self::TEST_ROOT_DOMAIN,
        self::LEGACY_WELINE_TEST_ROOT_DOMAIN,
        self::LEGACY_LOCAL_TEST_ROOT_DOMAIN,
        self::LOOPBACK_ROOT_DOMAIN,
    ];

    private const LOCAL_WILDCARD_DOMAINS = [
        self::TEST_WILDCARD_DOMAIN,
        self::LEGACY_WELINE_TEST_WILDCARD_DOMAIN,
        self::LEGACY_LOCAL_TEST_WILDCARD_DOMAIN,
        self::LOOPBACK_WILDCARD_DOMAIN,
    ];

    /** Roots that need an explicit 127.0.0.1 hosts entry (not *.localhost). */
    private const HOSTS_ENTRY_ROOT_DOMAINS = [
        self::TEST_ROOT_DOMAIN,
        self::LEGACY_WELINE_TEST_ROOT_DOMAIN,
        self::LEGACY_LOCAL_TEST_ROOT_DOMAIN,
    ];

    public static function isDevelopmentMode(?string $deployMode = null): bool
    {
        $deployMode = $deployMode !== null ? $deployMode : (string) (Env::system('deploy') ?? 'prod');
        $deployMode = \strtolower(\trim($deployMode));

        return \in_array($deployMode, ['dev', 'development', 'local', 'test'], true);
    }

    public static function currentRootDomain(?string $deployMode = null): string
    {
        return self::isDevelopmentMode($deployMode)
            ? self::TEST_ROOT_DOMAIN
            : self::LOOPBACK_ROOT_DOMAIN;
    }

    public static function currentWildcardDomain(?string $deployMode = null): string
    {
        return '*.' . self::currentRootDomain($deployMode);
    }

    public static function buildProjectHost(string $shortHash, ?string $deployMode = null): string
    {
        $shortHash = \substr(\preg_replace('/[^0-9a-f]/i', '', \strtolower($shortHash)) ?? '', 0, 8);
        if ($shortHash === '') {
            $shortHash = '00000000';
        }

        return 'p' . $shortHash . '.' . self::currentRootDomain($deployMode);
    }

    public static function normalizeDomain(string $domain): string
    {
        $domain = \strtolower(\trim($domain));
        if ($domain === '') {
            return '';
        }

        if (\str_contains($domain, '://')) {
            $parsedHost = (string) (\parse_url($domain, \PHP_URL_HOST) ?? '');
            if ($parsedHost !== '') {
                $domain = $parsedHost;
            }
        }

        if (\str_contains($domain, ':') && !\str_contains($domain, ']')) {
            [$domain] = \explode(':', $domain, 2);
        }

        return \trim($domain);
    }

    public static function resolveRootDomain(string $domain): ?string
    {
        $domain = self::normalizeDomain($domain);
        if ($domain === '') {
            return null;
        }

        if (\str_starts_with($domain, '*.')) {
            $domain = \substr($domain, 2);
        }

        // Longer roots first so test.weline.com wins over accidental shorter suffixes.
        $roots = self::LOCAL_ROOT_DOMAINS;
        \usort($roots, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        foreach ($roots as $rootDomain) {
            if ($domain === $rootDomain || \str_ends_with($domain, '.' . $rootDomain)) {
                return $rootDomain;
            }
        }

        return null;
    }

    public static function resolveWildcardDomain(string $domain): ?string
    {
        $rootDomain = self::resolveRootDomain($domain);
        return $rootDomain === null ? null : '*.' . $rootDomain;
    }

    public static function isManagedLocalDomain(string $domain): bool
    {
        return self::resolveRootDomain($domain) !== null;
    }

    public static function isManagedWildcardDomain(string $domain): bool
    {
        return \in_array(self::normalizeDomain($domain), self::LOCAL_WILDCARD_DOMAINS, true);
    }

    public static function isManagedSingleLabelSubdomain(string $domain): bool
    {
        $domain = self::normalizeDomain($domain);
        $rootDomain = self::resolveRootDomain($domain);
        if ($domain === '' || $rootDomain === null || $domain === $rootDomain) {
            return false;
        }

        $pattern = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.' . \preg_quote($rootDomain, '/') . '$/i';
        return (bool) \preg_match($pattern, $domain);
    }

    public static function isStandardProjectHost(string $domain): bool
    {
        $domain = self::normalizeDomain($domain);
        if ($domain === '') {
            return false;
        }

        return (bool) \preg_match(
            '/^p[0-9a-f]{8}\.(?:test\.weline\.com|weline\.test|local\.test|weline\.localhost)$/i',
            $domain
        );
    }

    public static function requiresHostsEntry(string $domain): bool
    {
        $rootDomain = self::resolveRootDomain($domain);
        return $rootDomain !== null && \in_array($rootDomain, self::HOSTS_ENTRY_ROOT_DOMAINS, true);
    }

    public static function resolvesViaLoopbackSuffix(string $domain): bool
    {
        return self::resolveRootDomain($domain) === self::LOOPBACK_ROOT_DOMAIN;
    }
}
