<?php
declare(strict_types=1);

namespace Weline\Server\Console\Server\Benchmark;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\DynamicWarmup\HotPathDiscoveryService;

final class FirstRender extends CommandAbstract
{
    public const ALIASES = ['server:benchmark:first_render'];

    public function execute(array $args = [], array $data = [])
    {
        if (isset($args['h']) || isset($args['help'])) {
            echo $this->help();
            return;
        }

        $mode = \strtolower(\trim((string)($args['mode'] ?? 'dynamic')));
        if ($mode !== 'dynamic') {
            $this->printer->error('Only --mode=dynamic is supported for first-render benchmark.');
            exit(1);
        }

        $serverConfig = $this->detectRunningServer($args);
        if (!$serverConfig) {
            exit(1);
        }

        $targetMs = (float)($args['target-ms'] ?? $args['target_ms'] ?? Env::get('wls.worker.dynamic_target_ms', 70) ?: 70);
        $targetMs = \max(1.0, $targetMs);
        $maxPaths = (int)($args['max'] ?? $args['max-paths'] ?? $args['max_paths'] ?? Env::get('wls.worker.dynamic_hot_path_max', 32) ?: 32);
        $paths = $this->resolveFirstRenderPaths($args, $maxPaths);
        if ($paths === []) {
            $this->printer->error('No dynamic first-render paths resolved.');
            exit(1);
        }

        $host = (string)($serverConfig['host'] ?? '127.0.0.1');
        $port = (int)($serverConfig['port'] ?? 0);
        $ssl = (bool)($serverConfig['ssl'] ?? false) || isset($args['ssl']) || isset($args['s']);
        $scheme = $ssl ? 'https' : 'http';
        $failFast = isset($args['fail-fast']) || isset($args['fail_fast']);

        $this->printer->note('WLS dynamic first-render benchmark');
        $this->printer->note('Target: max(total_ms) < ' . $targetMs . 'ms, FPC must not be HIT');

        $results = [];
        $failed = false;
        foreach ($paths as $path) {
            $url = $scheme . '://' . $host . ':' . $port . $path;
            $probe = $this->probeDynamicFirstRender($url, $host);
            $probe['path'] = $path;
            $probe['pass'] = $probe['ok']
                && $probe['total_ms'] < $targetMs
                && \strtoupper((string)$probe['fpc']) !== 'HIT';
            $results[] = $probe;

            $line = \sprintf(
                '%s %.2fms status=%s fpc=%s controller=%s %s',
                $probe['pass'] ? '[PASS]' : '[FAIL]',
                $probe['total_ms'],
                (string)$probe['status'],
                (string)$probe['fpc'],
                (string)$probe['controller_cache'],
                $path
            );
            $probe['pass'] ? $this->printer->success($line) : $this->printer->error($line);

            if (!$probe['pass']) {
                $failed = true;
                if ($failFast) {
                    break;
                }
            }
        }

        $max = 0.0;
        foreach ($results as $result) {
            $max = \max($max, (float)$result['total_ms']);
        }

        $this->printer->note('Max total_ms: ' . \sprintf('%.2f', $max));
        if ($failed) {
            exit(1);
        }
    }

    public function tip(): string
    {
        return 'Benchmark first dynamic render without accepting FPC hits.';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:benchmark:first-render',
            $this->tip(),
            [
                '--mode=dynamic' => 'Run dynamic controller-chain benchmark. This is the only supported mode.',
                '--paths=auto|/a,/b' => 'Use auto-discovered hot paths or an explicit comma-separated path list.',
                '--target-ms=70' => 'Fail when any path reaches or exceeds this total wall time.',
                '--fail-fast' => 'Stop on the first failed path.',
                '-p, --port=<port>' => 'Target an explicit WLS port.',
                '--ssl' => 'Use HTTPS when the server config was not auto-detected as SSL.',
            ],
            [],
            [
                'Auto paths' => 'php bin/w server:benchmark:first-render --mode=dynamic --paths=auto --target-ms=70 --fail-fast',
                'Explicit path' => 'php bin/w server:benchmark:first-render --mode=dynamic --paths=/ --target-ms=70',
            ],
            'php bin/w server:benchmark:first-render --mode=dynamic --paths=auto --target-ms=70 [--fail-fast]'
        );
    }

    /**
     * @return list<string>
     */
    public function resolveFirstRenderPaths(array $args, int $maxPaths = 32): array
    {
        $raw = $args['paths'] ?? $args['path'] ?? 'auto';
        $discovery = ObjectManager::getInstance(HotPathDiscoveryService::class);
        if (!$discovery instanceof HotPathDiscoveryService) {
            $discovery = new HotPathDiscoveryService();
        }

        if (\is_array($raw)) {
            $paths = [];
            foreach ($raw as $path) {
                if (\is_scalar($path)) {
                    $paths[] = (string)$path;
                }
            }
            return $discovery->normalizeRankAndLimit($paths, $maxPaths);
        }

        $raw = \trim((string)$raw);
        if ($raw === '' || \strtolower($raw) === 'auto') {
            return $discovery->discover($maxPaths);
        }

        $paths = \preg_split('/\s*,\s*/', $raw, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        return $discovery->normalizeRankAndLimit($paths, $maxPaths);
    }

    /**
     * @return array{host:string,port:int,instance:string,worker_count:int,ssl?:bool}|null
     */
    private function detectRunningServer(array $args): ?array
    {
        if (isset($args['port']) || isset($args['p'])) {
            $port = (int)($args['port'] ?? $args['p']);
            if ($port <= 0) {
                $this->printer->error('Invalid --port value.');
                return null;
            }

            return [
                'host' => (string)($args['host'] ?? $args['h'] ?? '127.0.0.1'),
                'port' => $port,
                'instance' => 'manual',
                'worker_count' => 1,
                'ssl' => isset($args['ssl']) || isset($args['s']),
            ];
        }

        $instances = $this->runningInstances();
        if ($instances !== []) {
            $name = \array_key_first($instances);
            $instance = $instances[$name];
            return [
                'host' => (string)$instance['host'],
                'port' => (int)$instance['port'],
                'instance' => (string)$name,
                'worker_count' => (int)($instance['worker_count'] ?? $instance['count'] ?? 1),
                'ssl' => (bool)($instance['ssl_enabled'] ?? $instance['ssl'] ?? false),
            ];
        }

        $envConfig = Env::getInstance()->getConfig();
        $wls = \is_array($envConfig['wls'] ?? null) ? $envConfig['wls'] : [];
        if (isset($wls['port'])) {
            $host = (string)($wls['host'] ?? '127.0.0.1');
            $port = (int)$wls['port'];
            if ($this->canConnect($host, $port, 1.0)) {
                return [
                    'host' => $host,
                    'port' => $port,
                    'instance' => 'env',
                    'worker_count' => (int)($wls['worker_count'] ?? 1),
                    'ssl' => (bool)($wls['ssl_enabled'] ?? false),
                ];
            }
        }

        if ($this->canConnect('127.0.0.1', 9981, 1.0)) {
            return [
                'host' => '127.0.0.1',
                'port' => 9981,
                'instance' => 'default',
                'worker_count' => 1,
                'ssl' => false,
            ];
        }

        $this->printer->error('No running WLS server detected. Use -p <port> or start the server first.');
        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function runningInstances(): array
    {
        $instanceDir = Env::VAR_DIR . 'server' . DS . 'instances';
        if (!\is_dir($instanceDir)) {
            return [];
        }

        $instances = [];
        foreach (\glob($instanceDir . DS . '*.json') ?: [] as $file) {
            $data = @\json_decode((string)@\file_get_contents($file), true);
            if (!\is_array($data) || !isset($data['host'], $data['port'])) {
                continue;
            }

            $host = (string)$data['host'];
            $port = (int)$data['port'];
            if (!$this->canConnect($host, $port, 1.0)) {
                continue;
            }

            $data['worker_count'] = $data['worker_count'] ?? $data['count'] ?? 1;
            $instances[\basename((string)$file, '.json')] = $data;
        }

        return $instances;
    }

    private function canConnect(string $host, int $port, float $timeout): bool
    {
        if ($host === '' || $port <= 0) {
            return false;
        }

        $socket = @\fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$socket) {
            return false;
        }

        \fclose($socket);
        return true;
    }

    /**
     * @return array{ok: bool, total_ms: float, status: int|string, fpc: string, controller_cache: string}
     */
    private function probeDynamicFirstRender(string $url, string $host): array
    {
        if (\function_exists('curl_init')) {
            return $this->probeWithCurl($url, $host);
        }

        return $this->probeWithStreams($url, $host);
    }

    /**
     * @return array{ok: bool, total_ms: float, status: int|string, fpc: string, controller_cache: string}
     */
    private function probeWithCurl(string $url, string $host): array
    {
        $ch = \curl_init($url);
        \curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_HEADER => true,
            \CURLOPT_NOBODY => false,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_CONNECTTIMEOUT_MS => 2000,
            \CURLOPT_TIMEOUT_MS => 15000,
            \CURLOPT_SSL_VERIFYHOST => false,
            \CURLOPT_SSL_VERIFYPEER => false,
            \CURLOPT_HTTPHEADER => [
                'Host: ' . $host,
                'Accept: text/html,application/xhtml+xml',
                'Accept-Encoding: identity',
                'Connection: close',
                'X-WLS-Dynamic-Benchmark: 1',
                'X-WLS-FPC-Bypass: 1',
            ],
        ]);

        $startedAtNanoseconds = \hrtime(true);
        $raw = \curl_exec($ch);
        $elapsedMs = \round(\max(
            0.0,
            (\hrtime(true) - $startedAtNanoseconds) / 1_000_000.0
        ), 2);
        $status = \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        $headerSize = \curl_getinfo($ch, \CURLINFO_HEADER_SIZE);
        $error = \curl_error($ch);
        \curl_close($ch);

        $headers = \is_string($raw) ? \substr($raw, 0, (int)$headerSize) : '';
        if ($error !== '') {
            return [
                'ok' => false,
                'total_ms' => $elapsedMs,
                'status' => 'curl:' . $error,
                'fpc' => 'UNKNOWN',
                'controller_cache' => 'unknown',
            ];
        }

        return [
            'ok' => $status >= 200 && $status < 400,
            'total_ms' => $this->headerFloat($headers, 'X-WLS-First-Render-Total-Ms')
                ?: $this->headerFloat($headers, 'X-WLS-Performance-Total')
                ?: $elapsedMs,
            'status' => $status,
            'fpc' => $this->headerValue($headers, 'X-WLS-FPC-Status')
                ?: $this->headerValue($headers, 'X-Weline-FPC')
                ?: 'MISS',
            'controller_cache' => $this->headerValue($headers, 'X-WLS-Controller-Cache')
                ?: $this->headerValue($headers, 'X-WLS-Category-View-Cache')
                ?: $this->headerValue($headers, 'X-WLS-Product-View-Cache')
                ?: 'unknown',
        ];
    }

    /**
     * @return array{ok: bool, total_ms: float, status: int|string, fpc: string, controller_cache: string}
     */
    private function probeWithStreams(string $url, string $host): array
    {
        $context = \stream_context_create([
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
                'timeout' => 15,
                'header' => \implode("\r\n", [
                    'Host: ' . $host,
                    'Accept: text/html,application/xhtml+xml',
                    'Accept-Encoding: identity',
                    'Connection: close',
                    'X-WLS-Dynamic-Benchmark: 1',
                    'X-WLS-FPC-Bypass: 1',
                ]),
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $startedAtNanoseconds = \hrtime(true);
        $body = @\file_get_contents($url, false, $context);
        $elapsedMs = \round(\max(
            0.0,
            (\hrtime(true) - $startedAtNanoseconds) / 1_000_000.0
        ), 2);
        $headers = isset($http_response_header) && \is_array($http_response_header)
            ? \implode("\r\n", $http_response_header)
            : '';
        $status = $this->statusFromHeaders($headers);

        return [
            'ok' => $body !== false && $status >= 200 && $status < 400,
            'total_ms' => $this->headerFloat($headers, 'X-WLS-First-Render-Total-Ms')
                ?: $this->headerFloat($headers, 'X-WLS-Performance-Total')
                ?: $elapsedMs,
            'status' => $status ?: 'stream-error',
            'fpc' => $this->headerValue($headers, 'X-WLS-FPC-Status')
                ?: $this->headerValue($headers, 'X-Weline-FPC')
                ?: 'MISS',
            'controller_cache' => $this->headerValue($headers, 'X-WLS-Controller-Cache')
                ?: $this->headerValue($headers, 'X-WLS-Category-View-Cache')
                ?: $this->headerValue($headers, 'X-WLS-Product-View-Cache')
                ?: 'unknown',
        ];
    }

    private function headerValue(string $headers, string $name): string
    {
        if (\preg_match('/^' . \preg_quote($name, '/') . ':\s*(.+)$/im', $headers, $matches)) {
            return \trim((string)$matches[1]);
        }

        return '';
    }

    private function headerFloat(string $headers, string $name): float
    {
        $value = $this->headerValue($headers, $name);
        return \is_numeric($value) ? (float)$value : 0.0;
    }

    private function statusFromHeaders(string $headers): int
    {
        if (\preg_match('/^HTTP\/\S+\s+(\d{3})/im', $headers, $matches)) {
            return (int)$matches[1];
        }

        return 0;
    }
}
