<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\Contract\CacheWarmerInterface;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupOrchestrator;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Observer\WorkerBootstrapWarmup;

/**
 * Rebuilds storefront FPC for high-traffic entry paths after clear/start.
 *
 * Worker READY already primes homepage once; this warmer covers cache:clear /
 * cache:warm and expands to a small locale/path set so the first visitor is not
 * the cold SSR victim.
 *
 * Reachability rule (W6): prefer the public storefront face (public_origin /
 * public_host on 443). Never force HTTPS against a plain-HTTP worker bind
 * (ssl_enabled=false → https://127.0.0.1:worker_port resets). Loopback is a
 * fallback with matching scheme.
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
        // Single entry with Framework Orchestrator (phases A→B→C→D + ledger).
        /** @var StorefrontWarmupOrchestrator $orchestrator */
        $orchestrator = ObjectManager::getInstance(StorefrontWarmupOrchestrator::class);
        $report = $orchestrator->runAfterClear();
        $warmed = (int)($report['warmed'] ?? 0);
        $skipped = (int)($report['skipped'] ?? 0);
        $failed = (int)($report['failed'] ?? 0);

        return [
            'warmed' => $warmed,
            'skipped' => $skipped + $failed,
            'message' => 'orchestrator phase=' . (string)($report['phase'] ?? '')
                . ' reason=' . (string)($report['reason'] ?? '')
                . ' warmed=' . $warmed
                . ' skipped=' . $skipped
                . ' failed=' . $failed,
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

    /**
     * Ordered warm targets: public face first, then loopback with correct scheme.
     *
     * @param list<string> $hosts
     * @return list<array{scheme:string,connect:string,port:int,host:string}>
     */
    private function resolveWarmTargets(array $hosts, int $workerPort): array
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

        $publicHost = $this->resolvePublicHostname($hosts);
        $publicOrigin = $this->resolvePublicOrigin();
        if ($publicOrigin !== null) {
            $push($publicOrigin['scheme'], $publicOrigin['host'], $publicOrigin['port'], $publicOrigin['host']);
        } elseif ($publicHost !== '') {
            $push('https', $publicHost, 443, $publicHost);
        }

        $connectHost = $this->resolveConnectHost($hosts);
        $sslEnabled = $this->isWorkerSslEnabled();
        $loopbackScheme = $sslEnabled ? 'https' : 'http';
        // Public Host without :workerPort — :9555 on HTTP triggers canonical 308 to public HTTPS.
        $loopbackRequestHost = $publicHost !== '' ? $publicHost : $connectHost;
        if ($sslEnabled) {
            $loopbackRequestHost = $this->requestHostWithPort($loopbackRequestHost, $workerPort);
        }
        $push($loopbackScheme, $connectHost, $workerPort, $loopbackRequestHost);

        return $targets;
    }

    /**
     * @param list<string> $hosts
     */
    private function resolvePublicHostname(array $hosts): string
    {
        foreach ($this->discoverPublicHosts() as $host) {
            return $host;
        }
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

        return '';
    }

    /**
     * @return null|array{scheme:string,host:string,port:int}
     */
    private function resolvePublicOrigin(): ?array
    {
        $instance = $this->readDefaultInstanceConfig();
        $origin = \trim((string)($instance['public_origin'] ?? ''));
        if ($origin === '') {
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
        $host = \trim((string)($parts['host'] ?? ''));
        if ($host === '' || $host === '127.0.0.1' || $host === 'localhost') {
            return null;
        }
        $scheme = \strtolower((string)($parts['scheme'] ?? 'https'));
        if ($scheme !== 'http' && $scheme !== 'https') {
            $scheme = 'https';
        }
        $port = (int)($parts['port'] ?? ($scheme === 'http' ? 80 : 443));

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port];
    }

    private function isWorkerSslEnabled(): bool
    {
        $instance = $this->readDefaultInstanceConfig();
        if (\array_key_exists('ssl_enabled', $instance)) {
            return (bool)$instance['ssl_enabled'];
        }
        $cert = \trim((string)($instance['ssl_cert'] ?? ''));

        return $cert !== '';
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
        $path = VAR_DIR . 'server/instances/default.json';
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
    private function httpGet(
        string $connectHost,
        int $port,
        string $requestHost,
        string $path,
        string $scheme = 'https',
    ): array {
        $scheme = \strtolower($scheme) === 'http' ? 'http' : 'https';
        $connectHost = \trim($connectHost);
        if (\str_contains($connectHost, ':') && !\str_starts_with($connectHost, '[')) {
            // bare IPv6
            if (\substr_count($connectHost, ':') > 1) {
                $connectHost = '[' . $connectHost . ']';
            }
        }
        $authority = $connectHost;
        if (!(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $authority .= ':' . $port;
        }
        $url = $scheme . '://' . $authority . $path;
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
