<?php

declare(strict_types=1);

namespace Weline\Frontend\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\ScopeRateLimiter;
use Weline\Framework\Runtime\RequestAuthority;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Hard gate for Scope hole-punch: POST + HTTPS + same-origin + path shape + IP quota.
 *
 * Rate limit uses Framework ScopeRateLimiter (shared atomic store) with IP subject;
 * unavailable store fail-closes. Does not invent a local file counter.
 */
final class ScopeHolePunchSecurityGate
{
    public const ERROR_METHOD = 'method_not_allowed';
    public const ERROR_HTTPS = 'https_required';
    public const ERROR_ORIGIN = 'origin_required';
    public const ERROR_PATH = 'path_invalid';
    public const ERROR_RATE = 'rate_limited';
    public const ERROR_RATE_UNAVAILABLE = 'rate_limit_unavailable';

    private const MAX_PATH_BYTES = 2048;
    private const RATE_BUCKET = 'scope-hole-punch';
    private const DEFAULT_LIMIT = 30;
    private const DEFAULT_WINDOW = 60;

    public function __construct(
        private readonly ScopeRateLimiter $rateLimiter,
    ) {
    }

    /**
     * @return array{ok:true,path:string,client_ip:string}|array{ok:false,error:string,status:int,retry_after?:int}
     */
    public function assertTransport(Request $request): array
    {
        $method = \strtoupper(\trim((string)$request->getMethod()));
        if ($method !== 'POST') {
            return ['ok' => false, 'error' => self::ERROR_METHOD, 'status' => 405];
        }

        if (!$this->isHttpsRequest($request)) {
            return ['ok' => false, 'error' => self::ERROR_HTTPS, 'status' => 403];
        }

        if (!$this->isSameOrigin($request)) {
            return ['ok' => false, 'error' => self::ERROR_ORIGIN, 'status' => 403];
        }

        $path = $this->extractPagePath($request);
        $normalized = $this->normalizePagePath($path);
        if ($normalized === null) {
            return ['ok' => false, 'error' => self::ERROR_PATH, 'status' => 400];
        }

        return [
            'ok' => true,
            'path' => $normalized,
            'client_ip' => $this->clientIp($request),
        ];
    }

    /**
     * After channel Scope is known — consume IP quota for this Scope bucket.
     *
     * Prefers shared ScopeRateLimiter; if the shared atomic store is unavailable
     * (e.g. non-WLS FPM without redis), degrade to a host-local sliding window
     * under var/cache — still hard-enforced, never skip. Multi-node must set
     * security.scope_rate_limit.driver=redis so workers share the same counters.
     *
     * @return array{ok:true}|array{ok:false,error:string,status:int,retry_after?:int}
     */
    public function assertRateLimit(ScopeIdentity $scope, string $clientIp): array
    {
        $limit = (int)Env::get('security.scope_hole_punch.limit', self::DEFAULT_LIMIT);
        $window = (int)Env::get('security.scope_hole_punch.window', self::DEFAULT_WINDOW);
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }
        if ($window < 1) {
            $window = self::DEFAULT_WINDOW;
        }
        $subject = $clientIp !== '' ? $clientIp : 'unknown';

        try {
            if (!$this->rateLimiter->allow($scope, self::RATE_BUCKET, $limit, $window, $subject)) {
                return [
                    'ok' => false,
                    'error' => self::ERROR_RATE,
                    'status' => 429,
                    'retry_after' => $window,
                ];
            }

            return ['ok' => true];
        } catch (\Throwable $e) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning(
                    '[ScopeHolePunch] shared rate limiter unavailable, using local window: {msg}',
                    ['msg' => $e->getMessage()],
                    'security',
                );
            }

            return $this->assertLocalIpWindow($scope, $subject, $limit, $window);
        }
    }

    /**
     * @return array{ok:true}|array{ok:false,error:string,status:int,retry_after?:int}
     */
    private function assertLocalIpWindow(
        ScopeIdentity $scope,
        string $subject,
        int $limit,
        int $window,
    ): array {
        $dir = BP . 'var' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR
            . 'frontend_worker' . \DIRECTORY_SEPARATOR . 'scope_hole_punch' . \DIRECTORY_SEPARATOR;
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return [
                'ok' => false,
                'error' => self::ERROR_RATE_UNAVAILABLE,
                'status' => 503,
            ];
        }
        $key = \hash('sha256', self::RATE_BUCKET . '|' . $scope->canonicalKey() . '|' . $subject);
        $path = $dir . $key . '.json';
        $now = \microtime(true);
        $cutoff = $now - $window;
        $timestamps = [];
        $raw = @\file_get_contents($path);
        if (\is_string($raw) && $raw !== '') {
            try {
                $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = null;
            }
            if (\is_array($decoded)) {
                foreach ($decoded as $value) {
                    if (\is_numeric($value) && (float)$value >= $cutoff) {
                        $timestamps[] = (float)$value;
                    }
                }
            }
        }
        if (\count($timestamps) >= $limit) {
            return [
                'ok' => false,
                'error' => self::ERROR_RATE,
                'status' => 429,
                'retry_after' => $window,
            ];
        }
        $timestamps[] = $now;
        try {
            @\file_put_contents($path, \json_encode($timestamps, \JSON_THROW_ON_ERROR), \LOCK_EX);
        } catch (\JsonException) {
            return [
                'ok' => false,
                'error' => self::ERROR_RATE_UNAVAILABLE,
                'status' => 503,
            ];
        }

        return ['ok' => true];
    }

    public function normalizePagePath(string $raw): ?string
    {
        $raw = \trim($raw);
        if ($raw === '') {
            return '/';
        }
        if (\preg_match('#^https?://#i', $raw) === 1) {
            $parts = \parse_url($raw);
            if (!\is_array($parts)) {
                return null;
            }
            $raw = (string)($parts['path'] ?? '/');
        }
        if ($raw === '' || $raw[0] !== '/') {
            $raw = '/' . $raw;
        }
        $q = \strpos($raw, '?');
        if ($q !== false) {
            $raw = \substr($raw, 0, $q);
        }
        $h = \strpos($raw, '#');
        if ($h !== false) {
            $raw = \substr($raw, 0, $h);
        }
        if ($raw === '') {
            $raw = '/';
        }
        if (\strlen($raw) > self::MAX_PATH_BYTES || \str_contains($raw, "\0")) {
            return null;
        }
        if (\str_contains($raw, '..')) {
            return null;
        }
        // Storefront path characters only (no spaces / control / backslash).
        if (\preg_match('#\A/(?:[A-Za-z0-9._~%-]+(?:/[A-Za-z0-9._~%-]+)*)?/?\z#D', $raw) !== 1) {
            return null;
        }

        return $raw;
    }

    private function extractPagePath(Request $request): string
    {
        $bodyParams = $request->getBodyParams(true);
        if (\is_array($bodyParams)) {
            $fromBody = (string)($bodyParams['path'] ?? $bodyParams['page_path'] ?? $bodyParams['pathname'] ?? '');
            if ($fromBody !== '') {
                return $fromBody;
            }
        }
        $fromParam = (string)$request->getParam('path', '');
        if ($fromParam !== '') {
            return $fromParam;
        }
        $referer = (string)$request->getServer('HTTP_REFERER', '');

        return $referer;
    }

    private function isHttpsRequest(Request $request): bool
    {
        $https = \strtolower((string)$request->getServer('HTTPS', ''));
        if ($https === 'on' || $https === '1') {
            return true;
        }
        $proto = \strtolower(\trim((string)$request->getServer('HTTP_X_FORWARDED_PROTO', '')));
        if (\str_contains($proto, ',')) {
            $proto = \trim(\explode(',', $proto, 2)[0]);
        }
        if ($proto === 'https') {
            return true;
        }
        $scheme = \strtolower(\trim((string)WelineEnv::getRequestScheme()));

        return $scheme === 'https';
    }

    private function isSameOrigin(Request $request): bool
    {
        $authority = RequestAuthority::current();
        if ($authority === '') {
            return false;
        }
        $scheme = \strtolower(\trim((string)WelineEnv::getRequestScheme()));
        if (!\in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'https';
        }
        $expected = $scheme . '://' . $authority;

        $origin = \trim((string)$request->getServer('HTTP_ORIGIN', ''));
        if ($origin !== '') {
            return $this->originMatchesExpected($origin, $expected);
        }

        // Browser fetch without Origin is unusual for credentialed POST; require
        // Sec-Fetch-Site=same-origin. Missing both → fail-closed.
        $fetchSite = \strtolower(\trim((string)$request->getServer('HTTP_SEC_FETCH_SITE', '')));

        return $fetchSite === 'same-origin';
    }

    /**
     * Same-site Origin vs request authority.
     *
     * Worker listen ports (e.g. :9555) 308 to managed Nginx apex (no port). After
     * that hop, browsers may still send Origin with the listen port while
     * RequestAuthority is apex — exact string equality falsely 403s hole-punch and
     * breaks QueryBin account.current → login↔account loops.
     *
     * Host+scheme must match. Ports may differ only when one side is the scheme
     * default (443/80) and the other is a non-default same-host listen port.
     */
    public function originMatchesExpected(string $origin, string $expected): bool
    {
        $left = $this->parseOriginParts($origin);
        $right = $this->parseOriginParts($expected);
        if ($left === null || $right === null) {
            return false;
        }
        if ($left['scheme'] !== $right['scheme']
            || !\hash_equals($left['host'], $right['host'])
        ) {
            return false;
        }

        $leftPort = $this->effectiveOriginPort($left['scheme'], $left['port']);
        $rightPort = $this->effectiveOriginPort($right['scheme'], $right['port']);
        if ($leftPort === $rightPort) {
            return true;
        }

        $default = $left['scheme'] === 'https' ? 443 : 80;

        // Apex ↔ Worker listen on the same host only (one side scheme-default).
        return ($leftPort === $default) !== ($rightPort === $default);
    }

    /**
     * @return array{scheme:string,host:string,port:int|null}|null
     */
    private function parseOriginParts(string $origin): ?array
    {
        $origin = \rtrim(\trim($origin), '/');
        if ($origin === '' || \preg_match('#^https?://#i', $origin) !== 1) {
            return null;
        }
        try {
            $parts = \parse_url($origin);
        } catch (\ValueError) {
            return null;
        }
        if (!\is_array($parts)) {
            return null;
        }
        $scheme = \strtolower(\trim((string)($parts['scheme'] ?? '')));
        $host = \strtolower(\trim((string)($parts['host'] ?? ''), '[]'));
        if (!\in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        // Reject userinfo / path / query / fragment — Origin must be scheme://host[:port].
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['path'])
            || isset($parts['query']) || isset($parts['fragment'])
        ) {
            // parse_url may omit path for bare origin; empty path is fine.
            if (isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment'])
            ) {
                return null;
            }
            $path = (string)($parts['path'] ?? '');
            if ($path !== '' && $path !== '/') {
                return null;
            }
        }
        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
        ];
    }

    private function effectiveOriginPort(string $scheme, ?int $port): int
    {
        if ($port !== null && $port > 0) {
            return $port;
        }

        return $scheme === 'https' ? 443 : 80;
    }

    private function clientIp(Request $request): string
    {
        try {
            $ip = \trim((string)$request->getServerBag()->getClientIp());
        } catch (\Throwable) {
            $ip = '';
        }
        if ($ip === '' || $ip === '0.0.0.0') {
            $ip = \trim(WelineEnv::getClientIp());
        }
        if ($ip === '' || $ip === '0.0.0.0') {
            return 'unknown';
        }

        return $ip;
    }
}
