<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\Contract\CacheWarmerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Observer\WorkerBootstrapWarmup;

/**
 * Rebuilds storefront FPC for high-traffic entry paths after clear/start.
 *
 * Worker READY already primes homepage once; this warmer covers cache:clear /
 * cache:warm and expands to a small locale/path set so the first visitor is not
 * the cold SSR victim.
 */
final class StorefrontFpcWarmer implements CacheWarmerInterface
{
    private const DEFAULT_PORT = 9555;
    private const MAX_PATHS = 8;
    private const TIMEOUT_SECONDS = 45;

    public function getName(): string
    {
        return 'theme.storefront_fpc';
    }

    public function getTargetPool(): string
    {
        return 'fpc';
    }

    public function getPriority(): int
    {
        return 800;
    }

    public function canWarm(): bool
    {
        return \class_exists(WorkerBootstrapWarmup::class);
    }

    public function warm(): array
    {
        /** @var WorkerBootstrapWarmup $bootstrap */
        $bootstrap = ObjectManager::getInstance(WorkerBootstrapWarmup::class);
        $hosts = $bootstrap->getFpcWarmupHosts();
        $paths = $this->priorityPaths($bootstrap->getFpcWarmupPaths());
        $port = $this->resolvePublicPort();
        $connectHost = $this->resolveConnectHost($hosts);

        $warmed = 0;
        $skipped = 0;
        $notes = [];
        $requestHost = $this->pickRequestHost($hosts, $connectHost);
        $requestHost = $this->requestHostWithPort($requestHost, $port);

        foreach ($paths as $path) {
            // Prefer public hostname URL so TLS SNI/Host match the storefront face.
            $result = $this->httpGet($requestHost !== '' ? $requestHost : $connectHost, $port, $requestHost, $path);
            if (!$result['ok'] && $requestHost !== '' && $requestHost !== $connectHost) {
                $result = $this->httpGet($connectHost, $port, $requestHost, $path);
            }
            if ($result['ok']) {
                $warmed++;
                $notes[] = $path . '=' . $result['status'] . ':' . $result['fpc'];
            } else {
                $skipped++;
                $notes[] = $path . '=fail:' . $result['reason'];
            }
        }

        return [
            'warmed' => $warmed,
            'skipped' => $skipped,
            'message' => 'port=' . $port . ' host=' . $requestHost . ' ' . \implode(';', \array_slice($notes, 0, 6)),
        ];
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function priorityPaths(array $paths): array
    {
        // The public catalog entry is the most common anonymous first request
        // after a deploy. Prime it alongside locale roots so the first shopper
        // does not pay the full SSR/layout cost while the FPC is still empty.
        $forced = [
            '/',
            '/en_US/',
            '/zh_Hans_CN/',
            '/ar_SA/',
            '/en_US/products',
            '/zh_Hans_CN/products',
            '/ar_SA/products',
        ];
        $merged = [];
        foreach ([...$forced, ...$paths] as $path) {
            $path = \str_replace(["\r", "\n", "\t"], '', \trim((string)$path));
            if ($path === '') {
                continue;
            }
            if ($path[0] !== '/') {
                $path = '/' . $path;
            }
            if (!\str_ends_with($path, '/') && \preg_match('#^/(?:[a-z]{2}(?:_[A-Za-z]+){1,2})?$#', $path) === 1) {
                $path .= '/';
            }
            $merged[$path] = $path;
        }

        return \array_slice(\array_values($merged), 0, self::MAX_PATHS);
    }

    private function resolvePublicPort(): int
    {
        foreach (['server.ssl_port', 'server.port', 'wls.ssl_port', 'wls.port'] as $key) {
            $raw = Env::get($key, null);
            if (\is_numeric($raw) && (int)$raw > 0) {
                return (int)$raw;
            }
        }

        $instance = $this->readDefaultInstanceConfig();
        foreach (['worker_port', 'port', 'ssl_port'] as $key) {
            $raw = $instance[$key] ?? null;
            if (\is_numeric($raw) && (int)$raw > 0) {
                return (int)$raw;
            }
        }

        return self::DEFAULT_PORT;
    }

    /**
     * @param list<string> $hosts
     */
    private function resolveConnectHost(array $hosts): string
    {
        $instance = $this->readDefaultInstanceConfig();
        $bind = \trim((string)($instance['host'] ?? ''));
        if ($bind === '127.0.0.1' || $bind === 'localhost') {
            return '127.0.0.1';
        }

        foreach ($hosts as $host) {
            $host = \trim((string)$host);
            if ($host === '' || $host === '0.0.0.0' || $host === '::') {
                continue;
            }
            if ($host === '127.0.0.1' || $host === 'localhost') {
                return '127.0.0.1';
            }
        }

        return '127.0.0.1';
    }

    /**
     * @param list<string> $hosts
     */
    private function pickRequestHost(array $hosts, string $connectHost): string
    {
        foreach ($hosts as $host) {
            $host = \trim((string)$host);
            if ($host === '' || $host === '0.0.0.0' || $host === '::') {
                continue;
            }
            if ($host === '127.0.0.1' || $host === 'localhost') {
                continue;
            }

            return $host;
        }

        foreach ($this->discoverPublicHosts() as $host) {
            return $host;
        }

        return $connectHost;
    }

    private function requestHostWithPort(string $host, int $port): string
    {
        $host = trim($host);
        if ($host === '' || $port <= 0 || $port === 80 || $port === 443) {
            return $host;
        }
        if (str_starts_with($host, '[')) {
            if (preg_match('/\]:\d+$/', $host) === 1) {
                return $host;
            }
            return rtrim($host, ']') . ']:' . $port;
        }
        if (substr_count($host, ':') > 1) {
            return '[' . $host . ']:' . $port;
        }
        if (preg_match('/:\d+$/', $host) === 1) {
            return $host;
        }

        return $host . ':' . $port;
    }

    /**
     * @return list<string>
     */
    private function discoverPublicHosts(): array
    {
        $instance = $this->readDefaultInstanceConfig();
        $public = \trim((string)($instance['public_host'] ?? ''));
        if ($public !== '' && $public !== '0.0.0.0' && $public !== '::'
            && $public !== '127.0.0.1' && $public !== 'localhost'
        ) {
            return [$public];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function readDefaultInstanceConfig(): array
    {
        static $cached = null;
        if (\is_array($cached)) {
            return $cached;
        }
        $path = BP . 'var/server/instances/default.json';
        if (!\is_file($path)) {
            $cached = [];

            return $cached;
        }
        try {
            $decoded = \json_decode((string)\file_get_contents($path), true);
            $cached = \is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            $cached = [];
        }

        return $cached;
    }

    /**
     * @return array{ok:bool,status:int,fpc:string,reason:string}
     */
    private function httpGet(string $connectHost, int $port, string $requestHost, string $path): array
    {
        $url = 'https://' . $connectHost . ':' . $port . $path;
        if (!\function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'fpc' => '', 'reason' => 'curl_missing'];
        }

        $ch = \curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'fpc' => '', 'reason' => 'curl_init'];
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
                'Accept: text/html',
                'User-Agent: WelineStorefrontFpcWarmer/1.0',
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
            return ['ok' => false, 'status' => $status, 'fpc' => '', 'reason' => 'curl:' . $error];
        }
        if ($status < 200 || $status >= 400) {
            return ['ok' => false, 'status' => $status, 'fpc' => '', 'reason' => 'http_' . $status];
        }
        if (!\is_string($body) || \strlen($body) < 200) {
            return ['ok' => false, 'status' => $status, 'fpc' => '', 'reason' => 'empty_body'];
        }

        $fpc = (string)($headers['x-weline-fpc'] ?? $headers['x-wls-fpc-status'] ?? 'ok');

        return ['ok' => true, 'status' => $status, 'fpc' => $fpc, 'reason' => ''];
    }
}
