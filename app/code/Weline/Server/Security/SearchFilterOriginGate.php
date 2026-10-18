<?php

declare(strict_types=1);

namespace Weline\Server\Security;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Server\Service\MemoryStateFacade;

/**
 * Origin-only rate gate for storefront search + listing filter traffic.
 *
 * Counts signed QueryBin ops (search / filteredListingPage) and HTML Extra
 * variant SSR (allowlisted query present). Edge FPC HIT does not reach this.
 * Exceeding 5 requests / 10s per client IP → shared_ban (600s default).
 *
 * Does NOT honor attack_detector ip_whitelist (local must ban too). Ban identity
 * is the shopper client IP after real_ip; loopback still follows
 * {@see GlobalRateLimiter::isNonBanableClientIp()} unless opt-in.
 */
final class SearchFilterOriginGate
{
    public const SCOPE = 'search_filter_origin';
    public const WINDOW_SECONDS = 10;
    public const MAX_REQUESTS = 5;
    public const BAN_SECONDS = 600;

    private static ?GlobalRateLimiter $limiter = null;

    /**
     * @return array{allowed:bool,blocked:bool,reason:string,ip:string}
     */
    public static function checkClientIp(string $clientIp): array
    {
        $ip = GlobalRateLimiter::normalizeClientIp($clientIp);
        if ($ip === '') {
            return ['allowed' => true, 'blocked' => false, 'reason' => '', 'ip' => ''];
        }

        $limiter = self::limiter();
        if ($limiter->isBanned($ip)) {
            return [
                'allowed' => false,
                'blocked' => true,
                'reason' => 'search_filter_origin_banned',
                'ip' => $ip,
            ];
        }

        $window = self::windowSeconds();
        $max = self::maxRequests();
        if ($limiter->allow(self::SCOPE, $ip, $max, $window)) {
            return ['allowed' => true, 'blocked' => false, 'reason' => '', 'ip' => $ip];
        }

        $banSeconds = self::banSeconds();
        $limiter->ban($ip, $banSeconds);
        try {
            AttackDetector::getInstance()->manualBlock($ip, $banSeconds);
        } catch (\Throwable) {
            // Observability only; shared_ban is authoritative for WLS Forbidden.
        }

        return [
            'allowed' => false,
            'blocked' => true,
            'reason' => 'search_filter_origin_rate:' . $max . '/' . $window . 's',
            'ip' => $ip,
        ];
    }

    /**
     * HTML Extra variant SSR: consume budget or terminate with 429.
     * Call only when allowlisted query params are present (not path-only shells).
     */
    public static function assertHtmlExtraVariantOrTerminate(Request $request): void
    {
        $ip = '';
        if (\method_exists($request, 'getClientIp')) {
            $ip = (string)$request->getClientIp();
        }
        if ($ip === '') {
            $ip = (string)$request->getServer('REMOTE_ADDR');
        }
        $verdict = self::checkClientIp($ip);
        if ($verdict['allowed']) {
            return;
        }
        $response = Response::fromContent(
            'Too Many Requests',
            429,
            'text/plain; charset=UTF-8',
        );
        $response->setHeader('Retry-After', (string)self::banSeconds());
        $response->setHeader('Cache-Control', 'no-store');
        throw new ResponseTerminateException($response);
    }

    /**
     * True when QueryBin call should consume the search/filter origin budget.
     */
    public static function isCountedBinQuery(string $provider, string $operation): bool
    {
        $provider = \strtolower(\trim($provider));
        $operation = \trim($operation);
        if ($operation === 'filteredListingPage'
            && ($provider === 'product_storefront' || $provider === 'product' || \str_contains($provider, 'product'))
        ) {
            return true;
        }
        if ($operation === 'search'
            && ($provider === 'search' || \str_contains($provider, 'search'))
        ) {
            return true;
        }

        return false;
    }

    public static function windowSeconds(): int
    {
        return self::positiveEnvInt('wls.search_filter_origin.window', self::WINDOW_SECONDS);
    }

    public static function maxRequests(): int
    {
        return self::positiveEnvInt('wls.search_filter_origin.max', self::MAX_REQUESTS);
    }

    public static function banSeconds(): int
    {
        return self::positiveEnvInt('wls.search_filter_origin.ban', self::BAN_SECONDS);
    }

    private static function positiveEnvInt(string $key, int $default): int
    {
        try {
            $raw = Env::get($key, $default);
        } catch (\Throwable) {
            $raw = $default;
        }
        $n = (int)$raw;

        return $n > 0 ? $n : $default;
    }

    private static function limiter(): GlobalRateLimiter
    {
        if (self::$limiter instanceof GlobalRateLimiter) {
            return self::$limiter;
        }
        $instanceName = \trim((string)(\getenv('WLS_INSTANCE') ?: \getenv('WLS_INSTANCE_NAME') ?: ''));
        if ($instanceName === '') {
            try {
                $instanceName = \trim((string)Env::get('wls.instance', Env::get('wls.instance_name', 'default')));
            } catch (\Throwable) {
                $instanceName = 'default';
            }
        }
        if ($instanceName === '') {
            $instanceName = 'default';
        }
        $state = null;
        try {
            $state = new MemoryStateFacade([
                'consumer_code' => $instanceName . ':search-filter-gate:' . (string)(\getmypid() ?: 0),
                'pool_profile' => 'worker_policy',
                'prefer_direct_connect' => true,
                'fail_fast_on_unhealthy' => true,
                'connect_timeout' => 0.02,
                'timeout' => 0.02,
                'acquire_timeout' => 0.005,
                'pool_size' => 1,
            ]);
        } catch (\Throwable) {
            $state = null;
        }
        self::$limiter = new GlobalRateLimiter($state, 1, $instanceName);

        return self::$limiter;
    }

    /** @internal tests */
    public static function resetLimiterForTests(): void
    {
        self::$limiter = null;
    }
}
