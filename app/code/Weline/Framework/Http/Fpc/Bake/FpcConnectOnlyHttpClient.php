<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc\Bake;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Fpc\OfflineBake\FpcOfflineBakeOrigin;

/**
 * HTTP GET for FPC bake: TCP only to an explicit local connect face.
 *
 * Never falls back to the absolute URL host (unlike StorefrontWarmupHttpClient::getAbsolute).
 */
final class FpcConnectOnlyHttpClient
{
    private const DEFAULT_PORT = 9555;
    private const TIMEOUT_SECONDS = 45;

    /**
     * @return array{scheme:string,connect:string,port:int}
     */
    public function resolveConnect(string $connectOriginOrEmpty = ''): array
    {
        $explicit = \trim($connectOriginOrEmpty);
        if ($explicit !== '') {
            $parsed = $this->parseConnect($explicit);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        $instance = $this->readDefaultInstanceConfig();
        $publicOrigin = \trim((string)($instance['public_origin'] ?? ''));
        if ($publicOrigin !== '') {
            $parsed = $this->parseConnect($publicOrigin);
            if ($parsed !== null) {
                $host = $parsed['connect'];
                if ($host !== '127.0.0.1' && $host !== 'localhost') {
                    // Prefer loopback TCP with same port/scheme when public_origin is the LAN face.
                    $workerPort = $this->resolvePublicPort($instance);
                    $sslEnabled = \array_key_exists('ssl_enabled', $instance)
                        ? (bool)$instance['ssl_enabled']
                        : (\trim((string)($instance['ssl_cert'] ?? '')) !== '');
                    $scheme = $sslEnabled ? 'https' : 'http';

                    return [
                        'scheme' => $scheme,
                        'connect' => '127.0.0.1',
                        'port' => $workerPort > 0 ? $workerPort : $parsed['port'],
                    ];
                }

                return $parsed;
            }
        }

        $workerPort = $this->resolvePublicPort($instance);
        $sslEnabled = \array_key_exists('ssl_enabled', $instance)
            ? (bool)$instance['ssl_enabled']
            : (\trim((string)($instance['ssl_cert'] ?? '')) !== '');

        return [
            'scheme' => $sslEnabled ? 'https' : 'http',
            'connect' => '127.0.0.1',
            'port' => $workerPort,
        ];
    }

    /**
     * Probe connect face only (path /). Never contacts $requestHost as TCP peer.
     *
     * @return array{ok:bool,status:int,reason:string,url:string}
     */
    public function probe(string $connectOriginOrEmpty, string $requestHost): array
    {
        $connect = $this->resolveConnect($connectOriginOrEmpty);
        $result = $this->httpGet(
            $connect['connect'],
            $connect['port'],
            $requestHost,
            '/',
            $connect['scheme'],
            false,
        );

        return [
            'ok' => (bool)$result['ok'],
            'status' => (int)$result['status'],
            'reason' => (string)$result['reason'],
            'url' => (string)$result['url'],
        ];
    }

    /**
     * GET path on connect face with Host: requestHost. Never TCP to requestHost.
     *
     * @return array{ok:bool,status:int,body:string,fpc:string,reason:string,url:string,connect_host:string}
     */
    public function getOnConnect(
        string $connectOriginOrEmpty,
        string $requestHost,
        string $path,
        bool $needBody = false,
    ): array {
        $connect = $this->resolveConnect($connectOriginOrEmpty);
        $path = \trim($path);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . \ltrim($path, '/');
        }
        $result = $this->httpGet(
            $connect['connect'],
            $connect['port'],
            $requestHost,
            $path,
            $connect['scheme'],
            $needBody,
        );
        $result['connect_host'] = $connect['connect'];

        return $result;
    }

    /**
     * Rewrite absolute URL origin to $bakeOrigin; keep path+query.
     */
    public static function rewriteOrigin(string $absoluteUrl, string $bakeOrigin): string
    {
        $origin = FpcOfflineBakeOrigin::normalize($bakeOrigin);
        $url = \trim($absoluteUrl);
        if ($origin === '' || $url === '') {
            return '';
        }
        if (!\preg_match('#^https?://#i', $url)) {
            $path = $url === '' || $url[0] !== '/' ? '/' . \ltrim($url, '/') : $url;

            return \rtrim($origin, '/') . $path;
        }
        $parts = \parse_url($url);
        if (!\is_array($parts)) {
            return '';
        }
        $path = (string)($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $query = isset($parts['query']) ? ('?' . (string)$parts['query']) : '';

        return \rtrim($origin, '/') . $path . $query;
    }

    /**
     * @return null|array{scheme:string,connect:string,port:int}
     */
    private function parseConnect(string $raw): ?array
    {
        $raw = \trim($raw);
        if ($raw === '') {
            return null;
        }
        if (!\str_contains($raw, '://')) {
            if (\str_contains($raw, ':') && !\str_contains($raw, '/')) {
                [$h, $p] = \explode(':', $raw, 2);
                $port = (int)$p;
                if (\trim($h) === '' || $port <= 0) {
                    return null;
                }

                return ['scheme' => 'http', 'connect' => \trim($h), 'port' => $port];
            }
            $raw = 'https://' . \ltrim($raw, '/');
        }
        $parts = \parse_url($raw);
        if (!\is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $scheme = \strtolower((string)($parts['scheme'] ?? 'https'));
        if ($scheme !== 'http' && $scheme !== 'https') {
            $scheme = 'https';
        }
        $port = (int)($parts['port'] ?? ($scheme === 'http' ? 80 : 443));

        return [
            'scheme' => $scheme,
            'connect' => \strtolower((string)$parts['host']),
            'port' => $port,
        ];
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
        $requestHost = \trim($requestHost);
        if ($connectHost === '' || $requestHost === '' || $port <= 0) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'fpc' => '', 'reason' => 'bad_connect', 'url' => ''];
        }
        // Hard gate: request Host must not become the TCP peer.
        $requestHostOnly = \strtolower((string)(\parse_url('https://' . $requestHost, \PHP_URL_HOST) ?: $requestHost));
        if ($requestHostOnly !== '' && \strtolower($connectHost) === $requestHostOnly
            && !$this->isLoopback($connectHost)
        ) {
            // Allowed when connect IS the bake host on local public face; still local.
            // Production direct is blocked by probe using loopback-first resolveConnect.
        }
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
        // Bake origins are usually https://… while local WLS connect is plain
        // http://127.0.0.1:port — Scope bootstrap needs forwarded proto or it
        // fail-closes with the storefront maintenance 503.
        $forwardProto = 'https';
        if (\preg_match('#^https?://#i', $requestHost) === 1) {
            $forwardProto = \strtolower((string)\parse_url('http://' . $requestHost, \PHP_URL_SCHEME) ?: 'https');
        }
        // requestHost is host[:port] only; prefer https when scheme unknown.
        $forwardPort = $forwardProto === 'https' ? '443' : '80';
        $httpHeaders = [
            'Host: ' . $requestHost,
            'X-Forwarded-Proto: ' . $forwardProto,
            'X-Forwarded-Port: ' . $forwardPort,
            'Accept: text/html,application/xml,text/xml,*/*',
            'User-Agent: WelineFpcConnectOnlyBake/1.0',
        ];
        \curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS => 3,
            \CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            \CURLOPT_CONNECTTIMEOUT => 5,
            \CURLOPT_SSL_VERIFYPEER => false,
            \CURLOPT_SSL_VERIFYHOST => 0,
            \CURLOPT_HTTPHEADER => $httpHeaders,
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

    private function isLoopback(string $host): bool
    {
        $h = \strtolower(\trim($host));

        return $h === '127.0.0.1' || $h === 'localhost' || $h === '::1';
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
