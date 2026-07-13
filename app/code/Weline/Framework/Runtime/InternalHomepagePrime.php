<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;

/** Unspoofable predicate shared by the anonymous homepage-prime call sites. */
final class InternalHomepagePrime
{
    public static function isCurrentRequest(): bool
    {
        if ((string)self::serverValue('WLS_INTERNAL_WARMUP', '') !== '1'
            || (string)self::serverValue('WLS_INTERNAL_DYNAMIC_WARMUP', '') !== '1'
            || (string)self::serverValue('WLS_INTERNAL_HOMEPAGE_PRIME', '') !== '1'
            || (string)self::serverValue('HTTP_X_WLS_FPC_PRIME', '') !== '1'
            || (string)self::serverValue('HTTP_X_WLS_INTERNAL_REQUEST', '') !== 'homepage-fpc-prime'
        ) {
            return false;
        }

        $method = \strtoupper(\trim((string)self::serverValue('REQUEST_METHOD', 'GET')));
        return $method === 'GET';
    }

    public static function hasServerOnlyMarker(): bool
    {
        return (string)self::serverValue('WLS_INTERNAL_WARMUP', '') === '1';
    }

    /** @return array<string, string> Safe startup diagnostics; contains no cookie/token values. */
    public static function diagnostics(): array
    {
        $keys = [
            'WLS_INTERNAL_WARMUP',
            'WLS_INTERNAL_DYNAMIC_WARMUP',
            'WLS_INTERNAL_HOMEPAGE_PRIME',
            'HTTP_X_WLS_FPC_PRIME',
            'HTTP_X_WLS_INTERNAL_REQUEST',
            'REQUEST_METHOD',
            'REQUEST_URI',
            'WELINE_ORIGIN_REQUEST_URI',
        ];
        $result = [];
        foreach ($keys as $key) {
            $contextServer = Context::getCurrent()?->server();
            $result['context.' . $key] = \substr((string)(\is_array($contextServer)
                ? ($contextServer[$key] ?? '')
                : ''), 0, 128);
            $result['global.' . $key] = \substr((string)($_SERVER[$key] ?? ''), 0, 128);
        }

        return $result;
    }

    /**
     * Startup warmup runs before the public accept loop and therefore may run
     * on the Worker main thread rather than in a request Fiber. WelineEnv
     * intentionally refuses its persistent main-thread fallback, so this exact
     * server-only predicate must read the active Context directly first.
     */
    private static function serverValue(string $key, mixed $default = null): mixed
    {
        $contextServer = Context::getCurrent()?->server();
        if (\is_array($contextServer) && \array_key_exists($key, $contextServer)) {
            return $contextServer[$key];
        }

        $value = WelineEnv::server($key, null);
        if ($value !== null) {
            return $value;
        }

        return $_SERVER[$key] ?? $default;
    }
}
