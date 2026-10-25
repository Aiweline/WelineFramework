<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Service\StorefrontWarmup;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Fpc\FpcPierceStamp;

/**
 * HTTP GET warmer for storefront / sitemap documents (no WLS Fiber dependency).
 */
final class StorefrontWarmupHttpClient
{
    private const DEFAULT_PORT = 9555;
    private const TIMEOUT_SECONDS = 45;

    /**
     * @return list<array{scheme:string,connect:string,port:int,host:string}>
     */
    public function resolveTargets(): array
    {
        $targets = [];
        $seen = [];
        $push = static function (string $scheme, string $connect, int $port, string $host) use (&$targets, &$seen): void {
            $scheme = \strtolower($scheme) === 'http' ? 'http' : 'https';
            $connect = \trim($connect);
            $host = \trim($host);
            if ($connect === '' || $host === '' || $port <= 0) {
                return;
            }
            $key = $scheme . '|' . $connect . '|' . $port . '|' . $host;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $targets[] = [
                'scheme' => $scheme,
                'connect' => $connect,
                'port' => $port,
                'host' => $host,
            ];
        };

        $instance = $this->readDefaultInstanceConfig();
        $publicOrigin = \trim((string)($instance['public_origin'] ?? ''));
        if ($publicOrigin !== '') {
            $parts = \parse_url($publicOrigin);
            if (\is_array($parts)) {
                $host = \trim((string)($parts['host'] ?? ''));
                if ($host !== '' && $host !== '127.0.0.1' && $host !== 'localhost') {
                    $scheme = \strtolower((string)($parts['scheme'] ?? 'https'));
                    if ($scheme !== 'http' && $scheme !== 'https') {
                        $scheme = 'https';
                    }
                    $port = (int)($parts['port'] ?? ($scheme === 'http' ? 80 : 443));
                    // Worker 监听口不是公网可达源：直连会绕过托管 Nginx，
                    // 让渲染/FPC 发布带上 :9555（canonical/hreflang/站内链接全被污染）。
                    if (!$this->isWorkerListenPort($port)) {
                        $push($scheme, $host, $port, $host);
                    }
                }
            }
        }

        $publicHost = \trim((string)($instance['public_host'] ?? ''));
        if ($publicHost !== '' && $publicHost !== '0.0.0.0' && $publicHost !== '::'
            && $publicHost !== '127.0.0.1' && $publicHost !== 'localhost'
        ) {
            $push('https', $publicHost, 443, $publicHost);
        }

        $workerPort = $this->resolvePublicPort($instance);
        $sslEnabled = \array_key_exists('ssl_enabled', $instance)
            ? (bool)$instance['ssl_enabled']
            : (\trim((string)($instance['ssl_cert'] ?? '')) !== '');
        $loopbackScheme = $sslEnabled ? 'https' : 'http';
        // The loopback Worker target must present the public Host WITHOUT the
        // Worker listen port. Worker host parsing (Url::parser →
        // WlsRequest::getBaseHost) treats a public Host carrying the listen port
        // as a public non-default port under edge_adapter=wls, bakes it into
        // canonical/hreflang/storefront links and publishes that into html-disk.
        // A bare public Host on the loopback hop renders the clean origin.
        $push($loopbackScheme, '127.0.0.1', $workerPort, $publicHost !== '' ? $publicHost : '127.0.0.1');

        return $targets;
    }

    /**
     * @return array{ok:bool,status:int,body:string,fpc:string,reason:string,url:string}
     */
    public function get(string $path, bool $needBody = false): array
    {
        $path = \trim($path);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . \ltrim($path, '/');
        }
        $last = ['ok' => false, 'status' => 0, 'body' => '', 'fpc' => '', 'reason' => 'no_target', 'url' => ''];
        foreach ($this->resolveTargets() as $target) {
            $last = $this->httpGet(
                $target['connect'],
                $target['port'],
                $target['host'],
                $path,
                $target['scheme'],
                $needBody,
            );
            if (!$last['ok']) {
                continue;
            }
            // needBody (staging republish): pierce alone can still HIT html-disk on
            // public/loopback; refuse HIT and try the next target for a fresh origin body.
            if ($needBody && self::isFpcHit((string)($last['fpc'] ?? ''))) {
                $last['ok'] = false;
                $last['reason'] = 'fpc_hit_refuse_body';
                continue;
            }

            return $last;
        }

        return $last;
    }

    /**
     * Visitor-path GET for a priority home: no pierce/bypass headers, so a MISS
     * renders and publishes shared html-disk + homepage bag, while a warm receipt
     * stays a cheap HIT. Used to seed the first real visitor's path without
     * marking the response no-store.
     *
     * @return array{ok:bool,status:int,body:string,fpc:string,reason:string,url:string}
     */
    public function getVisitor(string $path): array
    {
        $path = \trim($path);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . \ltrim($path, '/');
        }
        $last = ['ok' => false, 'status' => 0, 'body' => '', 'fpc' => '', 'reason' => 'no_target', 'url' => ''];
        foreach ($this->resolveTargets() as $target) {
            $last = $this->requestTarget($target, $path, [
                'pierce' => false,
                'bypass' => false,
                'return_body' => false,
            ]);
            if ($last['ok']) {
                return $last;
            }
        }

        return $last;
    }

    /**
     * Parallel path warm via curl_multi (falls back to serial get()).
     *
     * @param list<string> $paths
     * @return list<array{ok:bool,status:int,body:string,fpc:string,reason:string,url:string}>
     */
    public function getMany(array $paths, bool $needBody = false): array
    {
        $paths = \array_values($paths);
        if ($paths === []) {
            return [];
        }
        if (\count($paths) === 1 || !\function_exists('curl_multi_init') || !\function_exists('curl_init')) {
            $out = [];
            foreach ($paths as $path) {
                $out[] = $this->get($path, $needBody);
            }

            return $out;
        }

        $targets = $this->resolveTargets();
        if ($targets === []) {
            $empty = [];
            foreach ($paths as $path) {
                $empty[] = [
                    'ok' => false,
                    'status' => 0,
                    'body' => '',
                    'fpc' => '',
                    'reason' => 'no_target',
                    'url' => $path,
                ];
            }

            return $empty;
        }

        $target = $targets[0];
        $results = $this->httpGetManyOnTarget($target, $paths, $needBody);
        foreach ($results as $i => $row) {
            $ok = (bool)($row['ok'] ?? false);
            if (!$ok || ($needBody && self::isFpcHit((string)($row['fpc'] ?? '')))) {
                $results[$i] = $this->get($paths[$i], $needBody);
            }
        }

        return $results;
    }

    /**
     * @param array{scheme:string,connect:string,port:int,host:string} $target
     * @param list<string> $paths
     * @return list<array{ok:bool,status:int,body:string,fpc:string,reason:string,url:string}>
     */
    private function httpGetManyOnTarget(array $target, array $paths, bool $needBody): array
    {
        $mh = \curl_multi_init();
        if ($mh === false) {
            $out = [];
            foreach ($paths as $path) {
                $out[] = $this->get($path, $needBody);
            }

            return $out;
        }

        /** @var array<int, resource|\CurlHandle> $handles */
        $handles = [];
        $headerBags = [];
        foreach ($paths as $i => $path) {
            $path = \trim((string)$path);
            if ($path === '' || $path[0] !== '/') {
                $path = '/' . \ltrim($path, '/');
            }
            $path = self::appendPierceQuery($path);
            $scheme = \strtolower((string)($target['scheme'] ?? 'http')) === 'http' ? 'http' : 'https';
            $connectHost = \trim((string)($target['connect'] ?? ''));
            $port = (int)($target['port'] ?? 0);
            $requestHost = (string)($target['host'] ?? '');
            if (\str_contains($connectHost, ':') && !\str_starts_with($connectHost, '[') && \substr_count($connectHost, ':') > 1) {
                $connectHost = '[' . $connectHost . ']';
            }
            $authority = $connectHost;
            if (!(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
                $authority .= ':' . $port;
            }
            $url = $scheme . '://' . $authority . $path;
            $ch = \curl_init($url);
            if ($ch === false) {
                continue;
            }
            $headerBags[$i] = [];
            $requestHeaders = [
                'Host: ' . $requestHost,
                'Accept: text/html,application/xml,text/xml,*/*',
                'User-Agent: WelineStorefrontWarmup/1.0',
            ];
            if ($needBody) {
                $requestHeaders[] = 'x-wls-fpc-bypass: 1';
            }
            \curl_setopt_array($ch, [
                \CURLOPT_RETURNTRANSFER => true,
                \CURLOPT_FOLLOWLOCATION => true,
                \CURLOPT_MAXREDIRS => 3,
                \CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                \CURLOPT_CONNECTTIMEOUT => 5,
                \CURLOPT_SSL_VERIFYPEER => false,
                \CURLOPT_SSL_VERIFYHOST => 0,
                \CURLOPT_HTTPHEADER => $requestHeaders,
                \CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headerBags, $i): int {
                    $trim = \trim($line);
                    if ($trim !== '' && \str_contains($trim, ':')) {
                        [$name, $value] = \array_map('trim', \explode(':', $trim, 2));
                        $headerBags[$i][\strtolower($name)] = $value;
                    }

                    return \strlen($line);
                },
            ]);
            $handles[$i] = $ch;
            \curl_multi_add_handle($mh, $ch);
        }

        $running = null;
        do {
            $status = \curl_multi_exec($mh, $running);
            if ($running) {
                \curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === \CURLM_OK);

        $results = [];
        foreach ($paths as $i => $_path) {
            if (!isset($handles[$i])) {
                $results[$i] = [
                    'ok' => false,
                    'status' => 0,
                    'body' => '',
                    'fpc' => '',
                    'reason' => 'curl_init',
                    'url' => '',
                ];
                continue;
            }
            $ch = $handles[$i];
            $body = \curl_multi_getcontent($ch);
            $httpStatus = (int)\curl_getinfo($ch, \CURLINFO_HTTP_CODE);
            $errno = \curl_errno($ch);
            $error = (string)\curl_error($ch);
            $url = (string)\curl_getinfo($ch, \CURLINFO_EFFECTIVE_URL);
            \curl_multi_remove_handle($mh, $ch);
            \curl_close($ch);

            if ($errno !== 0) {
                $results[$i] = [
                    'ok' => false,
                    'status' => $httpStatus,
                    'body' => '',
                    'fpc' => '',
                    'reason' => 'curl:' . $error,
                    'url' => $url,
                ];
                continue;
            }
            if ($httpStatus < 200 || $httpStatus >= 400 || !\is_string($body)) {
                $results[$i] = [
                    'ok' => false,
                    'status' => $httpStatus,
                    'body' => '',
                    'fpc' => '',
                    'reason' => !\is_string($body) ? 'empty_body' : 'http_' . $httpStatus,
                    'url' => $url,
                ];
                continue;
            }
            if (!$needBody && \strlen($body) < 80) {
                $results[$i] = [
                    'ok' => false,
                    'status' => $httpStatus,
                    'body' => '',
                    'fpc' => '',
                    'reason' => 'empty_body',
                    'url' => $url,
                ];
                continue;
            }
            $headers = $headerBags[$i] ?? [];
            $fpc = (string)($headers['x-weline-fpc'] ?? $headers['x-wls-fpc-status'] ?? '');
            $results[$i] = [
                'ok' => true,
                'status' => $httpStatus,
                'body' => $needBody ? $body : '',
                'fpc' => $fpc,
                'reason' => '',
                'url' => $url,
            ];
        }
        \curl_multi_close($mh);

        \ksort($results);

        return \array_values($results);
    }

    /**
     * Warm an absolute http(s) URL or a storefront path (Changed offer queue).
     *
     * @return array{ok:bool,status:int,body:string,fpc:string,reason:string,url:string}
     */
    public function getAbsolute(string $url, bool $needBody = false): array
    {
        $url = \trim($url);
        if ($url === '') {
            return ['ok' => false, 'status' => 0, 'body' => '', 'fpc' => '', 'reason' => 'empty_url', 'url' => ''];
        }
        if (\preg_match('#^https?://#i', $url) !== 1) {
            return $this->get($url, $needBody);
        }
        $parts = \parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'fpc' => '', 'reason' => 'bad_url', 'url' => $url];
        }
        $scheme = \strtolower((string)($parts['scheme'] ?? 'https'));
        if ($scheme !== 'http' && $scheme !== 'https') {
            $scheme = 'https';
        }
        $host = \strtolower((string)$parts['host']);
        $port = (int)($parts['port'] ?? ($scheme === 'http' ? 80 : 443));
        $path = (string)($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if (!empty($parts['query'])) {
            $path .= '?' . (string)$parts['query'];
        }
        $requestHost = $host;
        if (!(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $requestHost .= ':' . $port;
        }

        // Prefer local WLS targets with Host: virtual-host (multi-site FPC keys).
        $last = ['ok' => false, 'status' => 0, 'body' => '', 'fpc' => '', 'reason' => 'no_target', 'url' => $url];
        foreach ($this->resolveTargets() as $target) {
            $last = $this->httpGet(
                $target['connect'],
                $target['port'],
                $requestHost,
                $path,
                $target['scheme'],
                $needBody,
            );
            if (!$last['ok']) {
                continue;
            }
            if ($needBody && self::isFpcHit((string)($last['fpc'] ?? ''))) {
                $last['ok'] = false;
                $last['reason'] = 'fpc_hit_refuse_body';
                continue;
            }

            return $last;
        }

        // Fallback: direct absolute fetch (production / foreign host).
        $last = $this->httpGet($host, $port, $requestHost, $path, $scheme, $needBody);
        if ($needBody && ($last['ok'] ?? false) && self::isFpcHit((string)($last['fpc'] ?? ''))) {
            $last['ok'] = false;
            $last['reason'] = 'fpc_hit_refuse_body';
        }

        return $last;
    }

    /**
     * Single-target HTTP GET with explicit visitor vs origin bake semantics.
     *
     * - Visitor/live assert: pierce=false, bypass=false, return_body=true (HIT accepted).
     * - Staging origin bake: pierce=true, bypass=true (refuse HIT at caller).
     *
     * @param array{scheme:string,connect:string,port:int,host:string} $target
     * @param array{pierce?:bool,bypass?:bool,return_body?:bool} $opts
     * @return array{ok:bool,status:int,body:string,fpc:string,reason:string,url:string,storefront_gen_cookie:string}
     */
    public function requestTarget(array $target, string $path, array $opts = []): array
    {
        $path = \trim($path);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . \ltrim($path, '/');
        }
        $pierce = (bool)($opts['pierce'] ?? true);
        $bypass = (bool)($opts['bypass'] ?? false);
        $returnBody = (bool)($opts['return_body'] ?? $bypass);

        return $this->httpGet(
            (string)($target['connect'] ?? ''),
            (int)($target['port'] ?? 0),
            (string)($target['host'] ?? ''),
            $path,
            (string)($target['scheme'] ?? 'http'),
            $returnBody,
            $pierce,
            $bypass,
        );
    }

    /**
     * @return array{ok:bool,status:int,body:string,fpc:string,reason:string,url:string,storefront_gen_cookie:string}
     */
    private function httpGet(
        string $connectHost,
        int $port,
        string $requestHost,
        string $path,
        string $scheme,
        bool $needBody,
        bool $pierce = true,
        bool $bypass = false,
    ): array {
        $scheme = \strtolower($scheme) === 'http' ? 'http' : 'https';
        $connectHost = \trim($connectHost);
        if (\str_contains($connectHost, ':') && !\str_starts_with($connectHost, '[') && \substr_count($connectHost, ':') > 1) {
            $connectHost = '[' . $connectHost . ']';
        }
        $authority = $connectHost;
        if (!(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $authority .= ':' . $port;
        }
        // Staging warm seeds hardlinks from live; without pierce, Nginx html-disk HIT
        // never reaches PHP and promote keeps stale leaves. Pierce alone is not enough
        // on some edges (still HIT); needBody also sends x-wls-fpc-bypass for origin HTML.
        // Post-promote visitor assert must NOT pierce/bypass — that is real access path.
        if ($pierce) {
            $path = self::appendPierceQuery($path);
        }
        $url = $scheme . '://' . $authority . $path;
        $empty = static fn (string $reason) => [
            'ok' => false,
            'status' => 0,
            'body' => '',
            'fpc' => '',
            'reason' => $reason,
            'url' => $url,
            'storefront_gen_cookie' => '',
        ];
        if (!\function_exists('curl_init')) {
            return $empty('curl_missing');
        }
        $ch = \curl_init($url);
        if ($ch === false) {
            return $empty('curl_init');
        }

        $requestHeaders = [
            'Host: ' . $requestHost,
            'Accept: text/html,application/xml,text/xml,*/*',
            'User-Agent: WelineStorefrontWarmup/1.0',
        ];
        // Legacy: needBody warm/republish implies origin bypass when caller did not
        // pass explicit bypass=false via requestTarget.
        if ($bypass || ($needBody && $pierce)) {
            // Republish/staging seed must capture freshly rendered HTML, not disk HIT.
            $requestHeaders[] = 'x-wls-fpc-bypass: 1';
        }

        $headers = [];
        $setCookies = [];
        \curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS => 3,
            \CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            \CURLOPT_CONNECTTIMEOUT => 5,
            \CURLOPT_SSL_VERIFYPEER => false,
            \CURLOPT_SSL_VERIFYHOST => 0,
            \CURLOPT_HTTPHEADER => $requestHeaders,
            \CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers, &$setCookies): int {
                $trim = \trim($line);
                if ($trim !== '' && \str_contains($trim, ':')) {
                    [$name, $value] = \array_map('trim', \explode(':', $trim, 2));
                    $lower = \strtolower($name);
                    if ($lower === 'set-cookie') {
                        $setCookies[] = $value;
                    } else {
                        $headers[$lower] = $value;
                    }
                }

                return \strlen($line);
            },
        ]);

        $body = \curl_exec($ch);
        $status = (int)\curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $errno = \curl_errno($ch);
        $error = (string)\curl_error($ch);
        \curl_close($ch);

        if ($errno !== 0) {
            return [
                'ok' => false,
                'status' => $status,
                'body' => '',
                'fpc' => '',
                'reason' => 'curl:' . $error,
                'url' => $url,
                'storefront_gen_cookie' => '',
            ];
        }
        if ($status < 200 || $status >= 400) {
            return [
                'ok' => false,
                'status' => $status,
                'body' => '',
                'fpc' => '',
                'reason' => 'http_' . $status,
                'url' => $url,
                'storefront_gen_cookie' => '',
            ];
        }
        if (!\is_string($body)) {
            return [
                'ok' => false,
                'status' => $status,
                'body' => '',
                'fpc' => '',
                'reason' => 'empty_body',
                'url' => $url,
                'storefront_gen_cookie' => '',
            ];
        }
        // Sitemap/XML may be short; HTML FPC paths still need substance.
        if (!$needBody && \strlen($body) < 80) {
            return [
                'ok' => false,
                'status' => $status,
                'body' => '',
                'fpc' => '',
                'reason' => 'empty_body',
                'url' => $url,
                'storefront_gen_cookie' => '',
            ];
        }

        $fpc = (string)($headers['x-weline-fpc'] ?? $headers['x-wls-fpc-status'] ?? '');
        $genCookie = '';
        foreach ($setCookies as $cookieLine) {
            if (\preg_match('/(?:^|;\s*)weline_storefront_gen=([^;\\s]+)/i', $cookieLine, $m) === 1
                || \preg_match('/^weline_storefront_gen=([^;\\s]+)/i', $cookieLine, $m) === 1
            ) {
                $genCookie = \trim((string)($m[1] ?? ''));
                if ($genCookie !== '') {
                    break;
                }
            }
        }

        return [
            'ok' => true,
            'status' => $status,
            'body' => $needBody ? $body : '',
            'fpc' => $fpc,
            'reason' => '',
            'url' => $url,
            'storefront_gen_cookie' => $genCookie,
        ];
    }

    /**
     * True when $port is the WLS Worker listen port. Such a "public_origin" is an
     * internal H1 upstream address (managed Nginx → 127.0.0.1:WLS_PORT), never a
     * browser-reachable origin: warming it renders/publishes URLs carrying the
     * Worker port into canonical/hreflang/storefront links.
     */
    private function isWorkerListenPort(int $port): bool
    {
        if ($port <= 0 || $port === 80 || $port === 443) {
            return false;
        }

        $raw = \getenv('WLS_PORT');
        if (!\is_string($raw) || $raw === '' || !\ctype_digit($raw)) {
            $raw = $_SERVER['WLS_PORT'] ?? $_SERVER['SERVER_PORT'] ?? '';
        }
        if (!\is_int($raw) && !(\is_string($raw) && $raw !== '' && \ctype_digit($raw))) {
            return false;
        }

        return (int)$raw === $port;
    }

    /**
     * @param array<string, mixed> $instance
     */
    private function resolvePublicPort(array $instance): int
    {
        foreach (['server.ssl_port', 'server.port', 'wls.ssl_port', 'wls.port'] as $key) {
            $raw = Env::get($key, null);
            if (\is_numeric($raw) && (int)$raw > 0) {
                return (int)$raw;
            }
        }
        foreach (['worker_port', 'port', 'ssl_port'] as $key) {
            $raw = $instance[$key] ?? null;
            if (\is_numeric($raw) && (int)$raw > 0) {
                return (int)$raw;
            }
        }

        return self::DEFAULT_PORT;
    }

    /**
     * @return array<string, mixed>
     */
    private function readDefaultInstanceConfig(): array
    {
        $path = (\defined('BP') ? (string)BP : '') . 'var/server/instances/default.json';
        if (!\is_file($path)) {
            return [];
        }
        try {
            $decoded = \json_decode((string)\file_get_contents($path), true);

            return \is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Attach install pierce stamp so edge html-disk try_files cannot short-circuit warm.
     */
    private static function appendPierceQuery(string $path): string
    {
        $path = \trim($path);
        if ($path === '') {
            $path = '/';
        }
        try {
            $suffix = FpcPierceStamp::buildQuerySuffix();
        } catch (\Throwable) {
            return $path;
        }
        if ($suffix === '' || \str_contains($path, FpcPierceStamp::QUERY_NAME . '=')) {
            return $path;
        }
        $join = \str_contains($path, '?') ? '&' : '?';

        return $path . $join . $suffix;
    }

    /**
     * True when X-Weline-Fpc (or alias) reports a disk/cache HIT usable as stale seed.
     */
    private static function isFpcHit(string $fpc): bool
    {
        $fpc = \strtoupper(\trim($fpc));
        if ($fpc === '') {
            return false;
        }

        return \str_contains($fpc, 'HIT');
    }
}
