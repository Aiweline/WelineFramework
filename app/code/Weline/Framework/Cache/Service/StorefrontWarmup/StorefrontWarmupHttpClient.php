<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Service\StorefrontWarmup;

use Weline\Framework\App\Env;

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
                    $push($scheme, $host, $port, $host);
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
        $requestHost = $publicHost !== '' ? $publicHost : '127.0.0.1';
        if ($sslEnabled && $workerPort !== 443 && $workerPort !== 80) {
            $requestHost .= ':' . $workerPort;
        }
        $push($loopbackScheme, '127.0.0.1', $workerPort, $requestHost);

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
            if ($last['ok']) {
                return $last;
            }
        }

        return $last;
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
            if ($last['ok']) {
                return $last;
            }
        }

        // Fallback: direct absolute fetch (production / foreign host).
        return $this->httpGet($host, $port, $requestHost, $path, $scheme, $needBody);
    }

    /**
     * @return array{ok:bool,status:int,body:string,fpc:string,reason:string,url:string}
     */
    private function httpGet(
        string $connectHost,
        int $port,
        string $requestHost,
        string $path,
        string $scheme,
        bool $needBody,
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
        $url = $scheme . '://' . $authority . $path;
        if (!\function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'fpc' => '', 'reason' => 'curl_missing', 'url' => $url];
        }
        $ch = \curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'fpc' => '', 'reason' => 'curl_init', 'url' => $url];
        }

        $headers = [];
        \curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS => 3,
            \CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            \CURLOPT_CONNECTTIMEOUT => 5,
            \CURLOPT_SSL_VERIFYPEER => false,
            \CURLOPT_SSL_VERIFYHOST => 0,
            \CURLOPT_HTTPHEADER => [
                'Host: ' . $requestHost,
                'Accept: text/html,application/xml,text/xml,*/*',
                'User-Agent: WelineStorefrontWarmup/1.0',
            ],
            \CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $trim = \trim($line);
                if ($trim !== '' && \str_contains($trim, ':')) {
                    [$name, $value] = \array_map('trim', \explode(':', $trim, 2));
                    $headers[\strtolower($name)] = $value;
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
            return ['ok' => false, 'status' => $status, 'body' => '', 'fpc' => '', 'reason' => 'curl:' . $error, 'url' => $url];
        }
        if ($status < 200 || $status >= 400) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'fpc' => '', 'reason' => 'http_' . $status, 'url' => $url];
        }
        if (!\is_string($body)) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'fpc' => '', 'reason' => 'empty_body', 'url' => $url];
        }
        // Sitemap/XML may be short; HTML FPC paths still need substance.
        if (!$needBody && \strlen($body) < 80) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'fpc' => '', 'reason' => 'empty_body', 'url' => $url];
        }

        $fpc = (string)($headers['x-weline-fpc'] ?? $headers['x-wls-fpc-status'] ?? '');

        return [
            'ok' => true,
            'status' => $status,
            'body' => $needBody ? $body : '',
            'fpc' => $fpc,
            'reason' => '',
            'url' => $url,
        ];
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
}
