<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

use Weline\Framework\App\Env;

/**
 * Install-scoped FPC master switch + opt-in auto pierce for generated URLs.
 *
 * FPC enable maps to cache.status.frontend_cache ∧ router_cache (no third gate).
 * Auto-pierce defaults OFF (even in DEV) so FPC stays hittable; admins may
 * explicitly persist wls.fpc.dev_auto_pierce_enabled for local acceptance.
 */
final class FpcMode
{
    public const ENV_AUTO_PIERCE = 'wls.fpc.dev_auto_pierce_enabled';

    /** @var list<string> */
    public const FPC_CACHE_IDENTITIES = ['frontend_cache', 'router_cache'];

    public static function isFullPageCacheEnabled(): bool
    {
        try {
            return (bool)Env::get('cache.status.router_cache', 1)
                && (bool)Env::get('cache.status.frontend_cache', 1);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Persist FPC master switch onto the same cache.status keys Coordinator already gates on.
     *
     * @return array{ok:bool, enabled:bool, message:string}
     */
    public static function setFullPageCacheEnabled(bool $enabled): array
    {
        $cacheEnv = (array)Env::getInstance()->getConfig('cache');
        $cacheEnv['pools'] = \is_array($cacheEnv['pools'] ?? null) ? $cacheEnv['pools'] : [];
        $cacheEnv['status'] = \is_array($cacheEnv['status'] ?? null) ? $cacheEnv['status'] : [];

        foreach (self::FPC_CACHE_IDENTITIES as $identity) {
            if (!isset($cacheEnv['pools'][$identity]) || !\is_array($cacheEnv['pools'][$identity])) {
                $cacheEnv['pools'][$identity] = [];
            }
            $cacheEnv['pools'][$identity]['enabled'] = $enabled;
            $cacheEnv['status'][$identity] = $enabled ? 1 : 0;
        }

        if (!Env::getInstance()->setConfig('cache', $cacheEnv)) {
            return [
                'ok' => false,
                'enabled' => self::isFullPageCacheEnabled(),
                'message' => 'env_write_failed',
            ];
        }

        return [
            'ok' => true,
            'enabled' => $enabled,
            'message' => $enabled ? 'fpc_enabled' : 'fpc_disabled',
        ];
    }

    /**
     * When env key is absent: always false (opt-in only; preserves FPC hits).
     */
    public static function isDevAutoPierceEnabled(): bool
    {
        try {
            $raw = Env::get(self::ENV_AUTO_PIERCE, null);
        } catch (\Throwable) {
            return false;
        }
        if ($raw === null) {
            return false;
        }

        return self::truthy($raw);
    }

    /**
     * Whether the auto-pierce flag was explicitly persisted (vs absent default-off).
     */
    public static function isDevAutoPierceExplicit(): bool
    {
        try {
            return Env::get(self::ENV_AUTO_PIERCE, null) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{ok:bool, enabled:bool, message:string}
     */
    public static function setDevAutoPierceEnabled(bool $enabled): array
    {
        if (!Env::set(self::ENV_AUTO_PIERCE, $enabled)) {
            return [
                'ok' => false,
                'enabled' => self::isDevAutoPierceEnabled(),
                'message' => 'env_write_failed',
            ];
        }

        return [
            'ok' => true,
            'enabled' => $enabled,
            'message' => $enabled ? 'auto_pierce_enabled' : 'auto_pierce_disabled',
        ];
    }

    /**
     * Append install pierce query when auto-pierce is on and the URL lacks it.
     */
    public static function appendPierceQueryIfEnabled(string $url): string
    {
        if ($url === '' || !self::isDevAutoPierceEnabled()) {
            return $url;
        }
        if (\preg_match('#^(mailto|tel|javascript|data):#i', $url) === 1) {
            return $url;
        }
        $needle = FpcPierceStamp::QUERY_NAME . '=';
        if (\str_contains($url, $needle)) {
            return $url;
        }

        try {
            $suffix = FpcPierceStamp::buildQuerySuffix();
        } catch (\Throwable) {
            return $url;
        }
        if ($suffix === '') {
            return $url;
        }

        return \str_contains($url, '?') ? ($url . '&' . $suffix) : ($url . '?' . $suffix);
    }

    /**
     * @return array{
     *   fpc_enabled:bool,
     *   auto_pierce_enabled:bool,
     *   auto_pierce_explicit:bool,
     *   auto_pierce_defaults_off:bool,
     *   pierce:array{secret_masked:string,cookie_name:string,query_name:string,query_suffix:string}
     * }
     */
    public static function status(): array
    {
        $secret = '';
        $masked = '';
        $suffix = '';
        try {
            $secret = FpcPierceStamp::ensureSecret();
            $suffix = FpcPierceStamp::buildQuerySuffix($secret);
            $masked = self::maskSecret($secret);
        } catch (\Throwable) {
            // Panel still renders toggles when secret IO fails.
        }

        return [
            'fpc_enabled' => self::isFullPageCacheEnabled(),
            'auto_pierce_enabled' => self::isDevAutoPierceEnabled(),
            'auto_pierce_explicit' => self::isDevAutoPierceExplicit(),
            // Absent env → off (kept as a stable UI hint; not tied to DEV anymore).
            'auto_pierce_defaults_off' => true,
            'pierce' => [
                'secret_masked' => $masked,
                'cookie_name' => FpcPierceStamp::COOKIE_NAME,
                'query_name' => FpcPierceStamp::QUERY_NAME,
                'query_suffix' => $suffix,
            ],
        ];
    }

    private static function truthy(mixed $raw): bool
    {
        if (\is_bool($raw)) {
            return $raw;
        }
        if (\is_int($raw) || \is_float($raw)) {
            return (int)$raw !== 0;
        }
        $s = \strtolower(\trim((string)$raw));

        return !\in_array($s, ['', '0', 'false', 'off', 'no', 'null'], true);
    }

    private static function maskSecret(string $secret): string
    {
        $len = \strlen($secret);
        if ($len <= 12) {
            return \str_repeat('*', $len);
        }

        return \substr($secret, 0, 6) . \str_repeat('*', $len - 10) . \substr($secret, -4);
    }
}
