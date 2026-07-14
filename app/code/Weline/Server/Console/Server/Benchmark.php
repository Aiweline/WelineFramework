<?php
declare(strict_types=1);

/**
 * Weline Server - 压测命令
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\Runtime\RuntimeEndpointMetadata;
use Weline\Server\Service\ServerInstanceManager;

/**
 * server:benchmark - 运行压力测试
 */
class Benchmark extends CommandAbstract
{
    private const DEFAULT_BENCHMARK_PATH = '/_wls/health';

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // 自动探测服务器配置
        $serverConfig = $this->detectRunningServer($args);
        
        if (!$serverConfig) {
            return;
        }
        
        $host = $serverConfig['host'];
        $authorityHost = (string)($serverConfig['authority_host'] ?? $host);
        $port = $serverConfig['port'];
        $instanceName = $serverConfig['instance'];
        $workerCount = $serverConfig['worker_count'];
        $ssl = (bool)($serverConfig['ssl'] ?? false);
        
        // 压测参数（仅核心参数需要用户指定）
        $concurrency = (int) ($args['concurrency'] ?? $args['c'] ?? 100);
        $totalRequests = (int) ($args['requests'] ?? $args['n'] ?? 10000);
        $path = $this->resolveBenchmarkPath($args);
        $tlsVersion = $this->normalizeTlsVersion($args['tls-version'] ?? $args['tls_version'] ?? 'auto');
        $httpVersion = $this->normalizeHttpVersion($args['http-version'] ?? $args['http_version'] ?? 'auto');
        $acceptEncoding = $this->normalizeAcceptEncoding(
            $args['accept-encoding'] ?? $args['accept_encoding'] ?? 'gzip'
        );
        if (!$ssl && $tlsVersion !== 'auto') {
            $this->printer->error(__('--tls-version 仅适用于 HTTPS 压测，请同时使用 --ssl 或选择 HTTPS 实例。'));
            return;
        }
        if (!$ssl && \in_array($httpVersion, ['2', '3'], true)) {
            $this->printer->error(__('--http-version 2/3 仅适用于 HTTPS WLS；明文端点请选择 auto 或 1.1。'));
            return;
        }
        $unsupportedHttpVersionReason = $this->unsupportedHttpVersionReason($httpVersion);
        if ($unsupportedHttpVersionReason !== null) {
            $this->printer->error($unsupportedHttpVersionReason);
            return;
        }
        // keep-alive 会让 Dispatcher/direct 都按 TCP 连接粘滞到某个 Worker；验证连接级分流时可禁用复用
        $noKeepAlive = isset($args['no-keepalive']) || isset($args['no_keepalive']) || isset($args['spread']);
        // 命中 Worker 统计：支持自定义响应头（逗号分隔），默认自动探测常见 WLS 头
        $workerHeader = (string)($args['worker-header'] ?? $args['worker_header'] ?? '');
        $workerBalanceThreshold = (float)($args['worker-balance-threshold'] ?? $args['worker_balance_threshold'] ?? 1.5);
        if ($workerBalanceThreshold < 1.0) {
            $workerBalanceThreshold = 1.0;
        }
        $benchmarkContext = $this->buildBenchmarkContext(
            $serverConfig,
            $concurrency,
            $totalRequests,
            $noKeepAlive,
            $ssl,
            $tlsVersion,
        );
        $benchmarkContext['requested_http_version'] = $httpVersion;
        $benchmarkContext['accept_encoding'] = $acceptEncoding;
        
        // 修复 Git Bash 路径转换问题（如 /_wls/health 被转成 C:/Program Files/Git/_wls/health）
        $scheme = $ssl ? 'https' : 'http';
        $targetUrlHost = $this->formatTargetUrlHost($authorityHost);
        $targetUrl = "{$scheme}://{$targetUrlHost}:{$port}{$path}";
        
        $this->printer->note(__('Weline Server 压力测试'));
        echo "\n";
        if (!isset($args['path']) || \trim((string)$args['path']) === '') {
            $this->printer->note(__('未指定 --path，默认使用轻量端点 %{1} 测 WLS 吞吐；压业务页请显式传 --path /xxx', [self::DEFAULT_BENCHMARK_PATH]));
            echo "\n";
        }
        
        if (!\in_array($path, ['/_wls/health', '/__wls_health'], true)
            && !\str_starts_with($path, '/.well-known/acme-challenge/')
        ) {
            $this->printer->warning(__(
                '业务路径压测会执行完整安全策略；X-WLS-Benchmark-Worker 仅用于 Worker 归因，不绕过 Origin Token、封禁、限流或攻击规则。高压前请仅为专用测试源 IP 显式配置 whitelist CIDR。',
            ));
            echo "\n";
        }

        // 显示探测到的服务器信息
        $this->printer->note('╔══════════════════════════════════════════════════════════════╗');
        $this->printer->note('║                     压测目标                                   ║');
        $this->printer->note('╠══════════════════════════════════════════════════════════════╣');
        $this->printer->note(\sprintf('║  实例名称：%-50s║', $instanceName));
        $this->printer->note(\sprintf('║  目标地址：%-50s║', $targetUrl));
        $this->printer->note(\sprintf('║  Worker 数：%-49s║', $workerCount));
        $this->printer->note(\sprintf('║  并发数：%-52s║', $concurrency));
        $this->printer->note(\sprintf('║  总请求数：%-50s║', $totalRequests));
        if ($ssl) {
            $this->printer->note(\sprintf('║  TLS 版本：%-50s║', $tlsVersion));
        }
        $this->printer->note(\sprintf('║  HTTP 协商：%-49s║', $httpVersion));
        $this->printer->note(\sprintf('║  内容编码：%-49s║', $acceptEncoding));
        $runtimeMetadata = \is_array($serverConfig['runtime_metadata'] ?? null)
            ? $serverConfig['runtime_metadata']
            : [];
        $runtimeTopology = (string)($runtimeMetadata['effective_topology'] ?? '');
        if ($runtimeTopology !== '') {
            $runtimeLine = $runtimeTopology
                . ' / ' . ((string)($runtimeMetadata['listener_strategy'] ?? '-') ?: '-')
                . ' / ' . ((string)($runtimeMetadata['event_loop_driver'] ?? '-') ?: '-')
                . ' / ' . ((string)($runtimeMetadata['ssl_engine'] ?? '-') ?: '-');
            $this->printer->note(\sprintf('║  ' . __('实际运行时：') . '%-47s║', $runtimeLine));
        }
        $this->printer->note('╚══════════════════════════════════════════════════════════════╝');
        echo "\n";
        
        // 检查服务器是否运行
        $socket = @\fsockopen($host, $port, $errno, $errstr, 5);
        if (!$socket) {
            $this->printer->error(__('无法连接到服务器 %{1}:%{2}', [$host, $port]));
            $this->printer->note(__('请先启动服务器：php bin/w server:start'));
            return;
        }
        \fclose($socket);
        
        $this->printer->success(__('服务器连接成功，开始压测...'));
        echo "\n";
        
        // 直接运行压测（传入是否 HTTPS）
        $this->runBenchmark(
            $targetUrl,
            $concurrency,
            $totalRequests,
            $ssl,
            $noKeepAlive,
            $workerHeader,
            $workerBalanceThreshold,
            $tlsVersion,
            $benchmarkContext
        );
    }
    
    /**
     * 修复 Git Bash 路径转换问题
     * 
     * Git Bash 会自动将 /path 转换为 C:/Program Files/Git/path
     * 此方法检测并还原为正确的 URL 路径
     */
    protected function fixGitBashPath(string $path): string
    {
        // 检测常见的 Git Bash 路径前缀
        $gitBashPrefixes = [
            'C:/Program Files/Git/',
            'C:\\Program Files\\Git\\',
            '/c/Program Files/Git/',
            'D:/Program Files/Git/',
            'D:\\Program Files\\Git\\',
            '/d/Program Files/Git/',
        ];
        
        foreach ($gitBashPrefixes as $prefix) {
            if (\stripos($path, $prefix) === 0) {
                // 提取原始路径并还原
                $originalPath = \substr($path, \strlen($prefix) - 1);
                // 确保以 / 开头
                if ($originalPath[0] !== '/') {
                    $originalPath = '/' . $originalPath;
                }
                // 将反斜杠转换为正斜杠
                $originalPath = \str_replace('\\', '/', $originalPath);
                
                return $originalPath;
            }
        }
        
        // 确保路径以 / 开头
        if (!empty($path) && $path[0] !== '/') {
            $path = '/' . $path;
        }
        
        return $path;
    }
    
    /**
     * 自动探测运行中的服务器
     */
    protected function resolveBenchmarkPath(array $args): string
    {
        $path = (string)($args['path'] ?? self::DEFAULT_BENCHMARK_PATH);
        $path = \trim($path);
        if ($path === '') {
            $path = self::DEFAULT_BENCHMARK_PATH;
        }

        return $this->fixGitBashPath($path);
    }
    /**
     * 自动探测运行中的服务器。
     */
    protected function detectRunningServer(array $args): ?array
    {
        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        $requestedInstance = \trim((string)($args['instance'] ?? ''));
        if ($requestedInstance !== '') {
            return $this->resolveNamedInstanceTarget($manager, $requestedInstance, $args);
        }

        $runningInstances = $this->collectRunningInstanceTargets($manager);

        // A manually selected port is always an authorized benchmark target,
        // but runtime metadata is attributed only after a unique host/port
        // match against live local WLS endpoint records.
        if (isset($args['port']) || isset($args['p'])) {
            return $this->resolveManualPortTarget($runningInstances, $args);
        }

        if (\count($runningInstances) === 1) {
            $name = (string)\array_key_first($runningInstances);
            $target = $runningInstances[$name];
            $target['target_attribution'] = 'single_running_instance';
            return $target;
        }

        if (\count($runningInstances) > 1) {
            $this->printer->error(__('检测到多个运行中的 WLS 实例，已拒绝自动选择，避免误压生产实例。'));
            $this->printer->note(__('请使用 --instance <name> 明确指定实例：%{1}', [
                \implode(', ', \array_keys($runningInstances)),
            ]));
            return null;
        }

        // The env fallback is intentionally un-attributed: configuration is
        // not proof that the process currently listening on that endpoint is
        // the configured WLS instance.
        $envConfig = Env::getInstance()->getConfig();
        $wls = \is_array($envConfig['wls'] ?? null) ? $envConfig['wls'] : [];
        $port = (int)($wls['port'] ?? 0);
        if ($port > 0 && $port <= 65535) {
            $this->printer->warning(__('未找到可验证的运行实例，仅使用 env 端点作为压测目标；报告不归因运行时元数据。'));
            return [
                'host' => $this->normalizeConnectHost((string)($wls['host'] ?? '127.0.0.1')),
                'port' => $port,
                'instance' => __('未归因的 env 端点'),
                'worker_count' => (int)($wls['worker_count'] ?? 1),
                'ssl' => (bool)($wls['ssl_enabled'] ?? false),
                'runtime_metadata' => [],
                'target_attribution' => 'env_config_unverified',
            ];
        }

        $this->printer->error(__('未检测到运行中的服务器'));
        $this->printer->note(__('请先启动服务器：php bin/w server:start'));
        echo "\n";
        $this->printer->note(__('或使用 --instance <name> / -p <port> 明确指定压测目标'));
        return null;
    }

    private function resolveNamedInstanceTarget(
        ServerInstanceManager $manager,
        string $instanceName,
        array $args,
    ): ?array {
        $raw = $manager->getRawInstanceData($instanceName);
        $info = $manager->getInstanceInfoWithIpcTimeout($instanceName, false, 0.0);
        if (!\is_array($raw) || $info === null) {
            $this->printer->error(__('实例 [%{1}] 不存在', [$instanceName]));
            return null;
        }
        if (!$info->isMasterRunning()) {
            $this->printer->error(__('实例 [%{1}] 未运行，已拒绝将端口占用者归因为该实例。', [$instanceName]));
            return null;
        }

        $target = $this->buildInstanceTarget($instanceName, $raw);
        if ($target === null) {
            $this->printer->error(__('实例 [%{1}] 缺少有效的 host/port endpoint。', [$instanceName]));
            return null;
        }

        if (isset($args['port']) || isset($args['p'])) {
            $requestedPort = (int)($args['port'] ?? $args['p']);
            if ($requestedPort !== (int)$target['port']) {
                $this->printer->error(__('--instance %{1} 的实际端口是 %{2}:%{3}，与手动端口 %{4} 冲突。', [
                    $instanceName,
                    $target['host'],
                    $target['port'],
                    $requestedPort,
                ]));
                return null;
            }
        }

        if (isset($args['host']) || isset($args['h'])) {
            $requestedHost = (string)($args['host'] ?? $args['h']);
            if (!$this->endpointHostMatchesTarget((string)($raw['host'] ?? ''), $requestedHost)) {
                $this->printer->error(__('--instance %{1} 的实际 host 是 %{2}，与手动 host %{3} 冲突。', [
                    $instanceName,
                    (string)($raw['host'] ?? ''),
                    $requestedHost,
                ]));
                return null;
            }
            $target['host'] = $this->normalizeConnectHost($requestedHost);
        }

        if ((isset($args['ssl']) || isset($args['s'])) && !(bool)$target['ssl']) {
            $this->printer->error(__('--instance %{1} 的 endpoint 不是 HTTPS，不能与 --ssl 同时使用。', [$instanceName]));
            return null;
        }

        $target['target_attribution'] = 'explicit_instance';
        return $target;
    }

    /**
     * @param array<string, array<string, mixed>> $runningInstances
     */
    private function resolveManualPortTarget(array $runningInstances, array $args): ?array
    {
        $port = (int)($args['port'] ?? $args['p'] ?? 0);
        if ($port < 1 || $port > 65535) {
            $this->printer->error(__('压测端口必须在 1-65535 范围内。'));
            return null;
        }

        $host = $this->normalizeConnectHost((string)($args['host'] ?? $args['h'] ?? '127.0.0.1'));
        $sslRequested = isset($args['ssl']) || isset($args['s']);
        $matches = [];
        foreach ($runningInstances as $name => $target) {
            $endpointHost = (string)($target['endpoint_host'] ?? $target['host'] ?? '');
            if ((int)($target['port'] ?? 0) !== $port
                || !$this->endpointHostMatchesTarget($endpointHost, $host)
                || ($sslRequested && !(bool)($target['ssl'] ?? false))) {
                continue;
            }
            $matches[$name] = $target;
        }

        if (\count($matches) === 1) {
            $name = (string)\array_key_first($matches);
            $target = $matches[$name];
            $target['host'] = $host;
            $target['ssl'] = $sslRequested ? true : (bool)$target['ssl'];
            $target['target_attribution'] = 'unique_live_endpoint_match';
            $this->printer->note(__('手动端口唯一匹配到运行实例 [%{1}]，报告将使用该实例的 schema v%{2} 运行时元数据。', [
                $name,
                (string)($target['runtime_metadata']['endpoint_schema_version'] ?? 0),
            ]));
            return $target;
        }

        if (\count($matches) > 1) {
            $this->printer->warning(__('手动端口匹配到多个实例记录（%{1}）；压测可继续，但报告不归因任何 WLS 运行时。', [
                \implode(', ', \array_keys($matches)),
            ]));
            $this->printer->note(__('请使用 --instance <name> 消除歧义。'));
        } else {
            $this->printer->note(__('手动 host/port 未唯一匹配运行中的 WLS endpoint；报告仅记录目标地址，不伪造拓扑或策略元数据。'));
        }

        return [
            'host' => $host,
            'port' => $port,
            'instance' => __('手动指定（未归因）'),
            'worker_count' => 0,
            'ssl' => $sslRequested,
            'runtime_metadata' => [],
            'target_attribution' => \count($matches) > 1 ? 'ambiguous_endpoint_match' : 'manual_unattributed',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function collectRunningInstanceTargets(ServerInstanceManager $manager): array
    {
        $targets = [];
        foreach ($manager->listPersistedInstanceNames() as $name) {
            $name = (string)$name;
            $raw = $manager->getRawInstanceData($name);
            if (!\is_array($raw)) {
                continue;
            }
            $info = $manager->getInstanceInfoWithIpcTimeout($name, false, 0.0);
            if ($info === null || !$info->isMasterRunning()) {
                continue;
            }
            $target = $this->buildInstanceTarget($name, $raw);
            if ($target !== null) {
                $targets[$name] = $target;
            }
        }

        \ksort($targets);
        return $targets;
    }

    /**
     * @param array<string, mixed> $endpoint
     * @return array<string, mixed>|null
     */
    private function buildInstanceTarget(string $name, array $endpoint): ?array
    {
        $endpointHost = \trim((string)($endpoint['host'] ?? ''));
        $port = (int)($endpoint['port'] ?? $endpoint['main_port'] ?? 0);
        if ($endpointHost === '' || $port < 1 || $port > 65535) {
            return null;
        }

        return [
            'host' => $this->normalizeConnectHost($endpointHost),
            'endpoint_host' => $endpointHost,
            'authority_host' => $this->normalizeAuthorityHost(
                (string)($endpoint['public_host'] ?? $endpoint['ssl_domain'] ?? ''),
                $endpointHost,
            ),
            'port' => $port,
            'instance' => $name,
            'worker_count' => (int)($endpoint['count'] ?? $endpoint['worker_count'] ?? 0),
            'ssl' => (bool)($endpoint['ssl_enabled'] ?? false),
            'runtime_metadata' => $this->extractRuntimeMetadata($endpoint),
        ];
    }

    private function endpointHostMatchesTarget(string $endpointHost, string $targetHost): bool
    {
        $endpointHost = \strtolower(\trim($endpointHost, "[] \t\n\r\0\x0B"));
        $targetHost = \strtolower(\trim($targetHost, "[] \t\n\r\0\x0B"));
        if ($endpointHost === '' || $targetHost === '') {
            return false;
        }
        if ($endpointHost === $targetHost) {
            return true;
        }

        $endpointWildcard = \in_array($endpointHost, ['0.0.0.0', '::', '*'], true);
        if ($endpointWildcard) {
            return $this->isLoopbackHost($targetHost);
        }

        return $this->isLoopbackHost($endpointHost) && $this->isLoopbackHost($targetHost);
    }

    private function isLoopbackHost(string $host): bool
    {
        $host = \strtolower(\trim($host, '[]'));
        return $host === 'localhost'
            || $host === '::1'
            || \str_starts_with($host, '127.');
    }

    private function normalizeConnectHost(string $host): string
    {
        $host = \trim($host, "[] \t\n\r\0\x0B");
        if ($host === '' || $host === '0.0.0.0' || $host === '*') {
            return '127.0.0.1';
        }
        if ($host === '::') {
            return '::1';
        }

        return $host;
    }

    private function formatTargetUrlHost(string $host): string
    {
        $host = $this->normalizeConnectHost($host);
        return \str_contains($host, ':') ? '[' . $host . ']' : $host;
    }

    private function normalizeAuthorityHost(string $authorityHost, string $fallback): string
    {
        $authorityHost = \trim($authorityHost);
        if ($authorityHost !== '' && \str_contains($authorityHost, '://')) {
            $authorityHost = (string)(\parse_url($authorityHost, PHP_URL_HOST) ?? '');
        } else {
            $authorityHost = \trim($authorityHost, "[] \t\n\r\0\x0B");
            if (\substr_count($authorityHost, ':') === 1
                && \preg_match('/^([^:]+):[0-9]+$/D', $authorityHost, $matches) === 1
            ) {
                $authorityHost = (string)$matches[1];
            }
        }
        $authorityHost = \strtolower(\rtrim($authorityHost, '.'));
        if ($authorityHost === ''
            || (!\filter_var($authorityHost, FILTER_VALIDATE_IP)
                && \preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $authorityHost) !== 1)
        ) {
            return $this->normalizeConnectHost($fallback);
        }

        return $authorityHost;
    }
    
    /**
     * 运行压测
     *
     * @param string $url 完整目标 URL（含 http/https）
     * @param int $concurrency 并发数
     * @param int $totalRequests 总请求数
     * @param bool $ssl 是否 HTTPS（用于设置 SSL 验证选项，本地自签证书可跳过验证）
     */
    protected function runBenchmark(
        string $url,
        int $concurrency,
        int $totalRequests,
        bool $ssl = false,
        bool $noKeepAlive = false,
        string $workerHeader = '',
        float $workerBalanceThreshold = 1.5,
        string $tlsVersion = 'auto',
        array $benchmarkContext = []
    ): void
    {
        $results = [];
        $errors = 0;
        $errorDetails = [];
        $statusCodes = [];
        $workerHits = [];
        $cacheSources = [];
        $httpVersionHits = [];
        $contentEncodingHits = [];
        $startedAtNanoseconds = \hrtime(true);
        
        // 检查 curl 扩展
        if (!\function_exists('curl_multi_init')) {
            $this->printer->error(__('需要 curl 扩展支持'));
            return;
        }
        
        // 基础选项
        $requestedHttpVersion = (string)($benchmarkContext['requested_http_version'] ?? 'auto');
        $acceptEncoding = (string)($benchmarkContext['accept_encoding'] ?? 'gzip');
        $baseOpts = [
            // Benchmark only needs status and response headers. Copying every
            // response body into a PHP string makes the client the bottleneck
            // for large FPC pages and reports a false server-side QPS ceiling.
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static fn($handle, string $chunk): int => \strlen($chunk),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTP_VERSION => $this->curlHttpVersionOption($requestedHttpVersion, $ssl),
            CURLOPT_USERAGENT => 'Weline-Server-Benchmark/2.0',
        ];
        $clientHeaders = ['X-WLS-Benchmark-Worker: 1'];
        if ($acceptEncoding !== 'identity') {
            // Set the wire header directly instead of CURLOPT_ENCODING: the
            // latter decompresses in the PHP client and contaminates latency.
            $clientHeaders[] = 'Accept-Encoding: ' . $acceptEncoding;
        }
        if ($noKeepAlive) {
            // 分流压测模式：每个请求尽量新建连接，让 Dispatcher 在“连接级”重新选择 Worker
            $baseOpts[CURLOPT_FORBID_REUSE] = true;
            $baseOpts[CURLOPT_FRESH_CONNECT] = true;
            $baseOpts[CURLOPT_TCP_KEEPALIVE] = 0;
            $baseOpts[CURLOPT_HTTPHEADER] = \array_merge(['Connection: close'], $clientHeaders);
        } else {
            // 性能压测模式：启用连接复用（Keep-Alive）
            $baseOpts[CURLOPT_FORBID_REUSE] = false;      // 允许连接复用
            $baseOpts[CURLOPT_FRESH_CONNECT] = false;     // 不强制新连接
            $baseOpts[CURLOPT_TCP_KEEPALIVE] = 1;         // 启用 TCP Keep-Alive
            $baseOpts[CURLOPT_TCP_KEEPIDLE] = 60;         // Keep-Alive 空闲时间
            $baseOpts[CURLOPT_TCP_KEEPINTVL] = 30;        // Keep-Alive 间隔
            // HTTP/1.1 persistence and HTTP/2/3 multiplexing are defaults.
            // Do not inject hop-by-hop Connection headers into H2/H3 requests.
            $baseOpts[CURLOPT_HTTPHEADER] = $clientHeaders;
        }
        if ($ssl) {
            $baseOpts[CURLOPT_SSL_VERIFYPEER] = false;
            $baseOpts[CURLOPT_SSL_VERIFYHOST] = 0;
            $curlSslVersion = $this->curlSslVersionOption($tlsVersion);
            if ($curlSslVersion !== null) {
                $baseOpts[CURLOPT_SSLVERSION] = $curlSslVersion;
            }
        }
        $connectHost = \trim((string)($benchmarkContext['connect_host'] ?? ''));
        $authorityHost = \trim((string)(\parse_url($url, PHP_URL_HOST) ?? ''));
        $targetPort = (int)(\parse_url($url, PHP_URL_PORT) ?? ($ssl ? 443 : 80));
        if ($connectHost !== ''
            && $authorityHost !== ''
            && \strcasecmp(\trim($connectHost, '[]'), \trim($authorityHost, '[]')) !== 0
        ) {
            $resolveAddress = \trim($connectHost, '[]');
            if (\str_contains($resolveAddress, ':')) {
                $resolveAddress = '[' . $resolveAddress . ']';
            }
            $baseOpts[CURLOPT_RESOLVE] = [
                \trim($authorityHost, '[]') . ':' . $targetPort . ':' . $resolveAddress,
            ];
        }
        
        $mh = \curl_multi_init();
        
        // 创建共享句柄，用于连接池复用（禁用 keep-alive 时不启用共享池）
        $sh = null;
        if (!$noKeepAlive) {
            $sh = \curl_share_init();
            \curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
            \curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
            if (\defined('CURL_LOCK_DATA_SSL_SESSION')) {
                \curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
            }
        }
        
        // 设置 curl_multi 管道化/复用（HTTP/1.1 管道化，HTTP/2 多路复用）
        if (\defined('CURLPIPE_MULTIPLEX')) {
            \curl_multi_setopt($mh, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
        }
        // 限制每个主机的最大连接数，促进连接复用
        if (\defined('CURLMOPT_MAX_HOST_CONNECTIONS')) {
            \curl_multi_setopt($mh, CURLMOPT_MAX_HOST_CONNECTIONS, $concurrency);
        }
        
        // 创建固定数量的 curl handle 用于复用
        $handlePool = [];
        $activeHandles = [];  // key => ['handle' => $ch, 'started_at_nanoseconds' => int, 'poolIndex' => index]
        $headerBuffers = [];  // key => raw header text
        $completed = 0;
        $requestsSent = 0;
        
        $batchSize = \min($concurrency, $totalRequests);
        
        // 初始化 handle 池（绑定共享句柄）
        for ($i = 0; $i < $batchSize; $i++) {
            $ch = \curl_init();
            \curl_setopt_array($ch, $baseOpts);
            \curl_setopt($ch, CURLOPT_URL, $url);
            if ($sh !== null) {
                \curl_setopt($ch, CURLOPT_SHARE, $sh);  // 共享连接池
            }
            \curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($chRef, string $line) use (&$headerBuffers): int {
                $headerBuffers[(int)$chRef] = ($headerBuffers[(int)$chRef] ?? '') . $line;
                return \strlen($line);
            });
            $handlePool[$i] = $ch;
        }
        
        // 添加初始批次请求
        for ($i = 0; $i < $batchSize; $i++) {
            $ch = $handlePool[$i];
            \curl_multi_add_handle($mh, $ch);
            $activeHandles[(int)$ch] = [
                'handle' => $ch,
                'started_at_nanoseconds' => \hrtime(true),
                'poolIndex' => $i,
            ];
            $requestsSent++;
        }
        
        $running = null;
        $lastProgress = 0;
        
        $this->printer->note($noKeepAlive
            ? __('压测模式：禁用 keep-alive（更利于分流验证），并发连接数=%{1}', [$batchSize])
            : __('压测模式：启用 keep-alive（性能模式），使用 %{1} 个持久连接...', [$batchSize]));
        
        do {
            // 执行请求
            do {
                $status = \curl_multi_exec($mh, $running);
            } while ($status == CURLM_CALL_MULTI_PERFORM);
            
            // 检查完成的请求
            while ($info = \curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $key = (int)$ch;
                
                if (isset($activeHandles[$key])) {
                    $elapsed = \max(
                        0.0,
                        (\hrtime(true) - $activeHandles[$key]['started_at_nanoseconds']) / 1_000_000.0
                    );
                    $poolIndex = $activeHandles[$key]['poolIndex'];
                    
                    if ($info['result'] === CURLE_OK) {
                        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $actualHttpVersion = $this->curlHttpVersionLabel(
                            (int)\curl_getinfo($ch, CURLINFO_HTTP_VERSION)
                        );
                        $httpVersionHits[$actualHttpVersion] = ($httpVersionHits[$actualHttpVersion] ?? 0) + 1;
                        $statusCodes[(string)$httpCode] = ($statusCodes[(string)$httpCode] ?? 0) + 1;
                        if ($httpCode >= 200 && $httpCode < 400) {
                            $results[] = $elapsed;
                            $headers = $this->parseResponseHeaders($headerBuffers[$key] ?? '');
                            $contentEncoding = \strtolower(\trim((string)($headers['content-encoding'] ?? 'identity')));
                            $contentEncoding = $contentEncoding !== '' ? $contentEncoding : 'identity';
                            $contentEncodingHits[$contentEncoding] = ($contentEncodingHits[$contentEncoding] ?? 0) + 1;
                            $workerMarker = $this->extractWorkerMarker($headers, $workerHeader);
                            if ($workerMarker !== '') {
                                $workerHits[$workerMarker] = ($workerHits[$workerMarker] ?? 0) + 1;
                            }
                            $cacheSource = $this->extractCacheSource($headers);
                            if ($cacheSource !== '') {
                                $cacheSources[$cacheSource] = ($cacheSources[$cacheSource] ?? 0) + 1;
                            }
                        } else {
                            $errors++;
                            $detail = 'http:' . (string)$httpCode;
                            $errorDetails[$detail] = ($errorDetails[$detail] ?? 0) + 1;
                        }
                    } else {
                        $errors++;
                        $errno = \curl_errno($ch);
                        $message = \curl_error($ch);
                        $detail = 'curl:' . (string)$errno . ':' . ($message !== '' ? $message : \curl_strerror($errno));
                        $errorDetails[$detail] = ($errorDetails[$detail] ?? 0) + 1;
                    }
                    
                    $completed++;
                    
                    // 显示进度
                    $progress = (int)($completed / $totalRequests * 100);
                    if ($progress >= $lastProgress + 10) {
                        $this->printer->note(__('进度：%{1}% (%{2}/%{3})', [$progress, $completed, $totalRequests]));
                        $lastProgress = $progress;
                    }
                    
                    // 从 multi handle 移除
                    \curl_multi_remove_handle($mh, $ch);
                    unset($activeHandles[$key]);
                    $headerBuffers[$key] = '';
                    
                    // 如果还有请求要发送，复用同一个 handle（共享连接池会自动复用连接）
                    if ($requestsSent < $totalRequests) {
                        // 重新添加到 multi handle（共享句柄会复用连接）
                        \curl_multi_add_handle($mh, $ch);
                        $activeHandles[(int)$ch] = [
                            'handle' => $ch,
                            'started_at_nanoseconds' => \hrtime(true),
                            'poolIndex' => $poolIndex,
                        ];
                        $requestsSent++;
                    }
                }
            }
            
            // 等待活动
            if ($running > 0) {
                \curl_multi_select($mh, 0.01);
            }
            
        } while ($running > 0 || \count($activeHandles) > 0);
        
        // 清理 handle 池和共享句柄
        foreach ($handlePool as $ch) {
            \curl_close($ch);
        }
        \curl_multi_close($mh);
        if ($sh !== null) {
            \curl_share_close($sh);
        }
        
        $totalTime = \max(
            0.0,
            (\hrtime(true) - $startedAtNanoseconds) / 1_000_000_000.0
        );
        $benchmarkContext['actual_http_versions'] = $httpVersionHits;
        $benchmarkContext['response_content_encodings'] = $contentEncodingHits;
        $benchmarkContext['benchmark_client']['response_body_mode'] = 'discard';
        
        // 生成报告
        $this->generateReport(
            $results,
            $errors,
            $totalTime,
            $totalRequests,
            $url,
            $workerHits,
            $workerBalanceThreshold,
            $errorDetails,
            $statusCodes,
            $benchmarkContext,
            $cacheSources
        );
    }
    
    /**
     * 生成报告
     */
    protected function generateReport(
        array $results,
        int $errors,
        float $totalTime,
        int $totalRequests,
        string $targetUrl,
        array $workerHits = [],
        float $workerBalanceThreshold = 1.5,
        array $errorDetails = [],
        array $statusCodes = [],
        array $benchmarkContext = [],
        array $cacheSources = []
    ): void
    {
        $successCount = \count($results);
        $totalCompleted = $successCount + $errors;
        
        if (!empty($results)) {
            \sort($results);
            
            $avgTime = \array_sum($results) / \count($results);
            $minTime = \min($results);
            $maxTime = \max($results);
            $medianTime = $results[(int)(\count($results) / 2)];
            $p95Index = \min((int)(\count($results) * 0.95), \count($results) - 1);
            $p99Index = \min((int)(\count($results) * 0.99), \count($results) - 1);
            $p95Time = $results[$p95Index];
            $p99Time = $results[$p99Index];
        } else {
            $avgTime = $minTime = $maxTime = $medianTime = $p95Time = $p99Time = 0;
        }
        
        $qps = $totalTime > 0 ? $successCount / $totalTime : 0;
        $errorRate = $totalCompleted > 0 ? ($errors / $totalCompleted) * 100 : 0;
        if (!empty($errorDetails)) {
            \arsort($errorDetails);
        }
        if (!empty($cacheSources)) {
            \arsort($cacheSources);
        }
        $cacheSource = (string)(\array_key_first($cacheSources) ?? '');
        
        echo "\n";
        $this->printer->setup(__('压测结果报告'));
        echo "\n";
        
        $this->printer->note(__('总请求数：%{1}', [$totalCompleted]));
        $this->printer->success(__('成功请求：%{1}', [$successCount]));
        if ($errors > 0) {
            $this->printer->error(__('失败请求：%{1}', [$errors]));
        } else {
            $this->printer->note(__('失败请求：%{1}', [$errors]));
        }
        $this->printer->note(__('错误率：%{1}%', [\round($errorRate, 2)]));
        
        echo "\n";
        $this->printer->note(__('总耗时：%{1} 秒', [\round($totalTime, 3)]));
        $this->printer->success(__('QPS：%{1}', [\round($qps, 2)]));
        
        echo "\n";
        $this->printer->setup(__('延迟统计（毫秒）'));
        echo "\n";
        $this->printer->note(__('平均：%{1}', [\round($avgTime, 3)]));
        $this->printer->note(__('最小：%{1}', [\round($minTime, 3)]));
        $this->printer->note(__('最大：%{1}', [\round($maxTime, 3)]));
        $this->printer->note(__('中位数：%{1}', [\round($medianTime, 3)]));
        $this->printer->note(__('P95：%{1}', [\round($p95Time, 3)]));
        $this->printer->note(__('P99：%{1}', [\round($p99Time, 3)]));
        $workerBalance = null;
        if (!empty($workerHits)) {
            \arsort($workerHits);
            echo "\n";
            $this->printer->setup(__('Worker 命中分布'));
            echo "\n";
            $sum = \array_sum($workerHits);
            foreach ($workerHits as $worker => $count) {
                $ratio = $sum > 0 ? \round($count * 100 / $sum, 2) : 0.0;
                $this->printer->note(__('%{1}：%{2} (%{3}%)', [$worker, $count, $ratio]));
            }

            $max = (int)\max($workerHits);
            $min = (int)\min($workerHits);
            $spreadRatio = $min > 0 ? $max / $min : INF;
            $balanceEvaluated = (bool)($benchmarkContext['fresh_connection'] ?? false);
            $balanced = $balanceEvaluated ? $spreadRatio <= $workerBalanceThreshold : null;
            $workerBalance = [
                'threshold' => \round($workerBalanceThreshold, 3),
                'max' => $max,
                'min' => $min,
                'spread_ratio' => \is_finite($spreadRatio) ? \round($spreadRatio, 3) : INF,
                'evaluated' => $balanceEvaluated,
                'balanced' => $balanced,
            ];
            echo "\n";
            if (!$balanceEvaluated) {
                $this->printer->note(__('持久连接会粘滞到已选 Worker；本次仅记录分布，不执行 fresh-connection 均衡门禁。'));
            } elseif ($balanced) {
                $this->printer->success(__('分流均衡检查：OK（max/min=%{1}，阈值=%{2}）', [
                    (string)$workerBalance['spread_ratio'],
                    (string)$workerBalance['threshold'],
                ]));
            } else {
                $this->printer->warning(__('分流均衡检查：WARN（max/min=%{1}，阈值=%{2}）', [
                    (string)$workerBalance['spread_ratio'],
                    (string)$workerBalance['threshold'],
                ]));
            }
        }
        
        echo "\n";
        
        // 保存报告
        $protocolHits = (array)($benchmarkContext['actual_http_versions'] ?? []);
        if ($protocolHits !== []) {
            \arsort($protocolHits);
            $this->printer->setup(__('实际 HTTP 协议'));
            echo "\n";
            foreach ($protocolHits as $protocol => $count) {
                $this->printer->note(__('%{1}：%{2}', [$protocol, $count]));
            }
            echo "\n";
        }

        $report = [
            'report_schema_version' => 4,
            'generated_at' => \date(DATE_ATOM),
            'target_url' => $targetUrl,
            'total_requests' => $totalCompleted,
            'requested_requests' => (int)($benchmarkContext['requested_requests'] ?? $totalRequests),
            'requests' => (int)($benchmarkContext['requested_requests'] ?? $totalRequests),
            'concurrency' => (int)($benchmarkContext['concurrency'] ?? 0),
            'active_connections' => (int)($benchmarkContext['active_connections'] ?? 0),
            'keep_alive' => (bool)($benchmarkContext['keep_alive'] ?? true),
            'keepalive' => (bool)($benchmarkContext['keep_alive'] ?? true),
            'fresh_connection' => (bool)($benchmarkContext['fresh_connection'] ?? false),
            'fresh_tls' => (bool)($benchmarkContext['fresh_tls'] ?? false),
            'tls_version' => $benchmarkContext['tls_version'] ?? null,
            'requested_http_version' => $benchmarkContext['requested_http_version'] ?? 'auto',
            'actual_http_versions' => $protocolHits,
            'accept_encoding' => $benchmarkContext['accept_encoding'] ?? 'identity',
            'response_content_encodings' => (array)($benchmarkContext['response_content_encodings'] ?? []),
            'instance_name' => (string)($benchmarkContext['instance_name'] ?? ''),
            'instance' => (string)($benchmarkContext['instance_name'] ?? ''),
            'target_attribution' => (string)($benchmarkContext['target_attribution'] ?? 'unattributed'),
            'runtime_metadata_source' => $benchmarkContext['runtime_metadata_source'] ?? null,
            'endpoint_schema_version' => $benchmarkContext['endpoint_schema_version'] ?? null,
            'runtime_selection_valid' => $benchmarkContext['runtime_selection_valid'] ?? null,
            'runtime_selection' => $benchmarkContext['runtime_selection'] ?? null,
            'requested_topology' => $benchmarkContext['requested_topology'] ?? null,
            'effective_topology' => $benchmarkContext['runtime_topology'] ?? null,
            'runtime_topology' => $benchmarkContext['runtime_topology'] ?? null,
            'topology' => $benchmarkContext['runtime_topology'] ?? null,
            'topology_source' => $benchmarkContext['topology_source'] ?? null,
            'topology_reason' => $benchmarkContext['topology_reason'] ?? null,
            'topology_reason_codes' => (array)($benchmarkContext['topology_reason_codes'] ?? []),
            'listener_strategy' => $benchmarkContext['listener_strategy'] ?? null,
            'worker_count' => (int)($benchmarkContext['worker_count'] ?? 0),
            'os' => $benchmarkContext['os'] ?? null,
            'architecture' => $benchmarkContext['architecture'] ?? null,
            'arch' => $benchmarkContext['architecture'] ?? null,
            'php_version' => $benchmarkContext['php_version'] ?? null,
            'event_loop_driver' => $benchmarkContext['event_loop_driver'] ?? null,
            'event_extension_version' => $benchmarkContext['event_extension_version'] ?? null,
            'ssl_engine' => $benchmarkContext['ssl_engine'] ?? null,
            'policy_compatible' => $benchmarkContext['policy_compatible'] ?? null,
            'policy_digest' => $benchmarkContext['policy_digest'] ?? null,
            'success_count' => $successCount,
            'error_count' => $errors,
            'error_rate' => \round($errorRate, 2),
            'total_time_seconds' => \round($totalTime, 3),
            'qps' => \round($qps, 2),
            'latency_ms' => [
                'avg' => \round($avgTime, 3),
                'min' => \round($minTime, 3),
                'max' => \round($maxTime, 3),
                'median' => \round($medianTime, 3),
                'p95' => \round($p95Time, 3),
                'p99' => \round($p99Time, 3),
            ],
            'worker_hits' => $workerHits,
            'worker_balance' => $workerBalance,
            'cache_source' => $cacheSource !== '' ? $cacheSource : null,
            'cache_sources' => $cacheSources,
            'status_codes' => $statusCodes,
            'error_details' => $errorDetails,
            'runtime_environment' => (array)($benchmarkContext['runtime_environment'] ?? []),
            'benchmark_client' => (array)($benchmarkContext['benchmark_client'] ?? []),
        ];
        
        $reportDir = BP . 'var/log/wls';
        if (!\is_dir($reportDir)) {
            @\mkdir($reportDir, 0755, true);
        }
        $reportFile = $this->buildReportFilePath($reportDir, $targetUrl);
        \file_put_contents($reportFile, \json_encode($report, JSON_PRETTY_PRINT));
        $this->printer->note(__('报告已保存：%{1}', [$reportFile]));
    }
    
    protected function buildReportFilePath(string $reportDir, string $targetUrl, ?float $now = null): string
    {
        $now ??= \microtime(true);
        $seconds = (int)$now;
        $micros = (int)\round(($now - $seconds) * 1000000);
        if ($micros >= 1000000) {
            $seconds++;
            $micros = 0;
        }

        $path = \parse_url($targetUrl, \PHP_URL_PATH);
        $pathSlug = \is_string($path) ? \trim($path, '/') : '';
        $pathSlug = \preg_replace('/[^A-Za-z0-9]+/', '-', $pathSlug) ?? '';
        $pathSlug = \strtolower(\trim($pathSlug, '-')) ?: 'root';

        $baseFile = \rtrim($reportDir, '/\\')
            . '/benchmark_report_'
            . \date('Ymd_His', $seconds)
            . '_'
            . \str_pad((string)$micros, 6, '0', \STR_PAD_LEFT)
            . '_'
            . $pathSlug
            . '_pid'
            . (string)\getmypid();

        $reportFile = $baseFile . '.json';
        $suffix = 1;
        while (\is_file($reportFile)) {
            $reportFile = $baseFile . '_' . (string)$suffix . '.json';
            $suffix++;
        }

        return $reportFile;
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('对 Weline Server 进行压力测试');
    }
    
    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:benchmark',
            __('自动探测运行中的服务器并进行压力测试'),
            [
                '-c, --concurrency <n>' => __('并发数（默认：100）'),
                '-n, --requests <n>' => __('总请求数（默认：10000）'),
                '--path <path>' => __('请求路径（默认：/_wls/health）'),
                '--instance <name>' => __('精确指定运行中的 WLS 实例，并归因 schema v3 运行时元数据'),
                '-p, --port <port>' => __('指定端口（可选，默认自动探测）'),
                '-h, --host <ip>' => __('指定主机（可选，默认 127.0.0.1）'),
                '-s, --ssl' => __('指定端口为 HTTPS（与 -p 合用；自动探测时根据实例配置）'),
                '--tls-version <auto|1.2|1.3>' => __('强制 HTTPS 压测使用指定 TLS 版本（默认 auto）'),
                '--http-version <auto|1.1|2|3>' => __('HTTP 协议：auto 默认经 ALPN 协商 H2 并自动回退 H1；可显式验证 H3'),
                '--accept-encoding <gzip|identity>' => __('响应内容编码（默认 gzip，模拟生产浏览器且不在压测客户端解压）'),
                '--no-keepalive, --spread' => __('禁用 keep-alive/连接复用（更利于验证连接级分流；HTTPS 时亦是 fresh TLS）'),
                '--worker-header <name>' => __('命中 Worker 统计使用的响应头（可逗号分隔；默认自动探测 X-WLS-Worker-PID/Id/Port）'),
                '--worker-balance-threshold <ratio>' => __('分流倾斜阈值，按 max/min 判定（默认 1.5，超过则 WARN）'),
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('基本压测（自动探测）') => 'php bin/w server:benchmark',
                __('指定 WLS 实例') => 'php bin/w server:benchmark --instance api-server',
                __('高并发') => 'php bin/w server:benchmark -c 500 -n 50000',
                __('分流验证（禁用 keep-alive）') => 'php bin/w server:benchmark -c 500 -n 50000 --no-keepalive',
                __('统计 Worker 分布') => 'php bin/w server:benchmark -p 9503 --ssl --path /_wls/health --worker-header X-WLS-Worker-Port',
                __('分流倾斜阈值检查') => 'php bin/w server:benchmark -p 9503 --ssl --path /_wls/health --worker-balance-threshold 1.3',
                __('指定端口') => 'php bin/w server:benchmark -p 9000',
                __('指定 HTTPS 端口') => 'php bin/w server:benchmark -p 9443 --ssl',
                __('TLS 1.3 fresh connection') => 'php bin/w server:benchmark -p 9443 --ssl --tls-version 1.3 --no-keepalive',
                __('HTTP/2 首页 FPC') => 'php bin/w server:benchmark --instance api-server --path / --http-version 2',
                __('HTTP/3 首页 FPC') => 'php bin/w server:benchmark --instance api-server --path / --http-version 3',
            ]
        );
    }

    private function normalizeHttpVersion(mixed $value): string
    {
        $value = \strtolower(\trim((string)$value));
        return match ($value) {
            '', 'auto', 'default', 'negotiate' => 'auto',
            '1', '1.1', 'h1', 'http1', 'http/1.1' => '1.1',
            '2', '2.0', 'h2', 'http2', 'http/2', 'http/2.0' => '2',
            '3', '3.0', 'h3', 'http3', 'http/3', 'http/3.0' => '3',
            default => throw new \InvalidArgumentException(
                '--http-version must be auto, 1.1, 2, or 3.'
            ),
        };
    }

    /**
     * Reject explicit protocols that the benchmark client cannot emit.
     *
     * The WLS endpoint may support HTTP/3 through the native protocol edge
     * while the PHP/libcurl bundled on the current platform does not. Running
     * the full request count in that situation only produces CURLE_NOT_BUILT_IN
     * and can be mistaken for a server regression.
     */
    private function unsupportedHttpVersionReason(string $httpVersion): ?string
    {
        if (!\in_array($httpVersion, ['2', '3'], true) || !\function_exists('curl_version')) {
            return null;
        }

        $curl = (array)\curl_version();
        $features = \is_array($curl['feature_list'] ?? null) ? $curl['feature_list'] : [];
        $featureName = $httpVersion === '3' ? 'HTTP3' : 'HTTP2';
        if (($features[$featureName] ?? false) === true) {
            return null;
        }

        $protocol = $httpVersion === '3' ? 'HTTP/3' : 'HTTP/2';
        $curlVersion = (string)($curl['version'] ?? 'unknown');
        return __(
            '当前 PHP/libcurl 压测客户端不支持 %{protocol}（libcurl %{version}）；这是客户端能力限制，不代表 WLS 服务端不支持。请使用具备该协议能力的 curl/浏览器/QUIC 客户端验证。',
            ['protocol' => $protocol, 'version' => $curlVersion],
        );
    }

    private function normalizeAcceptEncoding(mixed $value): string
    {
        $value = \strtolower(\trim((string)$value));
        return match ($value) {
            '', 'gzip' => 'gzip',
            'identity', 'none', 'off' => 'identity',
            default => throw new \InvalidArgumentException(
                '--accept-encoding must be gzip or identity.'
            ),
        };
    }

    private function curlHttpVersionOption(string $httpVersion, bool $ssl): int
    {
        return match ($httpVersion) {
            '1.1' => CURL_HTTP_VERSION_1_1,
            '2' => $ssl && \defined('CURL_HTTP_VERSION_2TLS')
                ? CURL_HTTP_VERSION_2TLS
                : throw new \RuntimeException('The current PHP cURL build cannot negotiate HTTP/2 over TLS.'),
            '3' => $ssl && \defined('CURL_HTTP_VERSION_3')
                ? CURL_HTTP_VERSION_3
                : throw new \RuntimeException('The current PHP cURL build cannot request HTTP/3.'),
            default => CURL_HTTP_VERSION_NONE,
        };
    }

    private function curlHttpVersionLabel(int $version): string
    {
        return match ($version) {
            CURL_HTTP_VERSION_1_0 => 'HTTP/1.0',
            CURL_HTTP_VERSION_1_1 => 'HTTP/1.1',
            CURL_HTTP_VERSION_2_0 => 'HTTP/2',
            \defined('CURL_HTTP_VERSION_3') ? CURL_HTTP_VERSION_3 : -1 => 'HTTP/3',
            default => 'unknown:' . $version,
        };
    }

    /**
     * Build immutable, non-sensitive metadata for one benchmark report.
     */
    private function buildBenchmarkContext(
        array $serverConfig,
        int $concurrency,
        int $totalRequests,
        bool $noKeepAlive,
        bool $ssl,
        string $tlsVersion,
    ): array
    {
        $runtime = isset($serverConfig['runtime_metadata']) && \is_array($serverConfig['runtime_metadata'])
            ? $serverConfig['runtime_metadata']
            : [];
        $targetAttribution = (string)($serverConfig['target_attribution'] ?? 'unattributed');
        $instanceName = (string)($serverConfig['instance'] ?? '');
        if ($instanceName !== '' && \in_array($targetAttribution, [
            'explicit_instance',
            'single_running_instance',
            'unique_live_endpoint_match',
        ], true)) {
            try {
                /** @var ServerInstanceManager $manager */
                $manager = ObjectManager::getInstance(ServerInstanceManager::class);
                $status = $manager->getMasterIpcStatusResult($instanceName, 1.0);
                $live = \is_array($status['data'] ?? null) ? $status['data'] : [];
                $livePolicyDigest = \strtolower(\trim((string)($live['policy_digest'] ?? '')));
                if (!empty($status['success']) && \preg_match('/^[a-f0-9]{64}$/D', $livePolicyDigest) === 1) {
                    $runtime['policy_digest'] = $livePolicyDigest;
                    $runtime['policy_state'] = (string)($live['policy_state'] ?? 'unknown');
                    $metadataSource = \trim((string)($runtime['metadata_source'] ?? 'endpoint'));
                    $runtime['metadata_source'] = ($metadataSource !== '' ? $metadataSource : 'endpoint')
                        . '+master_ipc';
                }
            } catch (\Throwable) {
                // Endpoint metadata remains a safe fallback when live IPC is transiently unavailable.
            }
        }
        $curl = \function_exists('curl_version') ? (array)\curl_version() : [];

        return [
            'requested_requests' => $totalRequests,
            'concurrency' => $concurrency,
            'active_connections' => \min($concurrency, $totalRequests),
            'keep_alive' => !$noKeepAlive,
            'fresh_connection' => $noKeepAlive,
            'fresh_tls' => $ssl && $noKeepAlive,
            'tls_version' => $ssl ? $tlsVersion : null,
            'instance_name' => $instanceName,
            'target_attribution' => $targetAttribution,
            'connect_host' => (string)($serverConfig['host'] ?? ''),
            'authority_host' => (string)($serverConfig['authority_host'] ?? $serverConfig['host'] ?? ''),
            'runtime_metadata_source' => $runtime['metadata_source'] ?? null,
            'endpoint_schema_version' => $runtime['endpoint_schema_version'] ?? null,
            'runtime_selection_valid' => $runtime['runtime_selection_valid'] ?? null,
            'runtime_selection' => $runtime['runtime_selection'] ?? null,
            'requested_topology' => $runtime['requested_topology'] ?? null,
            'runtime_topology' => $runtime['effective_topology'] ?? $runtime['topology'] ?? null,
            'topology_source' => $runtime['topology_source'] ?? null,
            'topology_reason' => $runtime['topology_reason'] ?? null,
            'topology_reason_codes' => (array)($runtime['topology_reason_codes'] ?? []),
            'listener_strategy' => $runtime['listener_strategy'] ?? null,
            'worker_count' => (int)($serverConfig['worker_count'] ?? 0),
            'os' => $runtime['os'] ?? null,
            'architecture' => $runtime['architecture'] ?? null,
            'php_version' => $runtime['php_version'] ?? null,
            'event_loop_driver' => $runtime['event_loop_driver'] ?? null,
            'event_extension_version' => $runtime['event_extension_version'] ?? null,
            'ssl_engine' => $runtime['ssl_engine'] ?? null,
            'policy_compatible' => $runtime['policy_compatible'] ?? null,
            'policy_digest' => $runtime['policy_digest'] ?? null,
            'runtime_environment' => $runtime,
            'benchmark_client' => [
                'os' => \PHP_OS_FAMILY,
                'architecture' => (string)\php_uname('m'),
                'php_version' => \PHP_VERSION,
                'event_extension_loaded' => \extension_loaded('event'),
                'event_extension_version' => \extension_loaded('event') ? (\phpversion('event') ?: null) : null,
                'curl_version' => $curl['version'] ?? null,
                'ssl_version' => $curl['ssl_version'] ?? null,
            ],
        ];
    }

    private function normalizeTlsVersion(mixed $value): string
    {
        $value = \strtolower(\trim((string)$value));
        $value = \str_replace(['tlsv', 'tls', '_'], ['', '', '.'], $value);
        $value = \trim($value, '.');
        if ($value === '' || $value === 'auto') {
            return 'auto';
        }
        if (\in_array($value, ['1.2', '12'], true)) {
            return '1.2';
        }
        if (\in_array($value, ['1.3', '13'], true)) {
            return '1.3';
        }

        throw new \InvalidArgumentException('--tls-version must be auto, 1.2, or 1.3.');
    }

    private function curlSslVersionOption(string $tlsVersion): ?int
    {
        if ($tlsVersion === 'auto') {
            return null;
        }

        $constantSuffix = $tlsVersion === '1.3' ? 'TLSv1_3' : 'TLSv1_2';
        $minimumConstant = 'CURL_SSLVERSION_' . $constantSuffix;
        $maximumConstant = 'CURL_SSLVERSION_MAX_' . $constantSuffix;
        if (!\defined($minimumConstant) || !\defined($maximumConstant)) {
            throw new \RuntimeException(
                'The current PHP cURL/libcurl build cannot pin TLS ' . $tlsVersion . '.'
            );
        }

        return (int)\constant($minimumConstant) | (int)\constant($maximumConstant);
    }

    /**
     * Extract only report-safe runtime fields from instance/env configuration.
     */
    private function extractRuntimeMetadata(array $data): array
    {
        return RuntimeEndpointMetadata::fromEndpoint($data)->toArray();
    }

    private function extractCacheSource(array $headers): string
    {
        $fpc = \trim((string)($headers['x-wls-performance-fpc-source'] ?? ''));
        if ($fpc !== '') {
            return 'fpc:' . \strtolower($fpc);
        }
        $static = \trim((string)($headers['x-wls-cache'] ?? ''));
        if ($static !== '') {
            return 'static:' . \strtolower($static);
        }
        return '';
    }

    private function parseResponseHeaders(string $rawHeaders): array
    {
        if ($rawHeaders === '') {
            return [];
        }
        // 多次重定向/1xx 时只取最后一段响应头
        $blocks = \preg_split("/\r\n\r\n|\n\n/", \trim($rawHeaders));
        $lastBlock = (string)($blocks[\count($blocks) - 1] ?? '');
        $lines = \preg_split("/\r\n|\n/", $lastBlock) ?: [];
        $headers = [];
        foreach ($lines as $line) {
            $pos = \strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = \strtolower(\trim(\substr($line, 0, $pos)));
            $value = \trim(\substr($line, $pos + 1));
            if ($name === '') {
                continue;
            }
            $headers[$name] = $value;
        }
        return $headers;
    }

    private function extractWorkerMarker(array $headers, string $workerHeader): string
    {
        $candidates = [];
        if ($workerHeader !== '') {
            $candidates = \array_values(\array_filter(\array_map('trim', \explode(',', $workerHeader))));
        }
        if (empty($candidates)) {
            // Direct topology intentionally exposes one public port shared by
            // every Worker. Prefer process identity so the default report does
            // not collapse an actually balanced pool into a single bucket.
            $candidates = ['X-WLS-Worker-PID', 'X-WLS-Worker-Id', 'X-WLS-Worker-Port'];
        }
        foreach ($candidates as $headerName) {
            $key = \strtolower($headerName);
            if (!isset($headers[$key]) || $headers[$key] === '') {
                continue;
            }
            return $headerName . '=' . $headers[$key];
        }
        return '';
    }
}
