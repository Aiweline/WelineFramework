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
use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\Runtime\HttpProtocolCapabilityProbe;
use Weline\Server\Service\Runtime\RuntimeEndpointMetadata;
use Weline\Server\Service\Runtime\RuntimeSelection;
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
            return 1;
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
        $httpVersion = $this->normalizeHttpVersion($args['http-version'] ?? $args['http_version'] ?? $args['http'] ?? 'auto');
        if (!$ssl && $tlsVersion !== 'auto') {
            $this->printer->error(__('--tls-version 仅适用于 HTTPS 压测，请同时使用 --ssl 或选择 HTTPS 实例。'));
            return 1;
        }
        try {
            $acceptEncoding = $this->normalizeAcceptEncoding(
                $args['accept-encoding'] ?? $args['accept_encoding'] ?? 'identity'
            );
            $this->curlHttpVersionOption($httpVersion);
        } catch (\Throwable $exception) {
            $this->printer->error($exception->getMessage());
            return 1;
        }
        // keep-alive 会让 Dispatcher/direct 都按 TCP 连接粘滞到某个 Worker；验证连接级分流时可禁用复用
        $noKeepAlive = isset($args['no-keepalive']) || isset($args['no_keepalive']) || isset($args['spread']);
        $physicalConnectionsRaw = $args['physical-connections'] ?? $args['physical_connections'] ?? null;
        $physicalConnections = null;
        if ($physicalConnectionsRaw !== null) {
            $normalizedPhysicalConnections = \trim((string)$physicalConnectionsRaw);
            if ($normalizedPhysicalConnections === '' || !\ctype_digit($normalizedPhysicalConnections)) {
                $this->printer->error(__('--physical-connections 必须是正整数。'));
                return 1;
            }
            $physicalConnections = (int)$normalizedPhysicalConnections;
            if ($physicalConnections < 1 || $physicalConnections > \max(1, $concurrency)) {
                $this->printer->error(__('--physical-connections 必须在 1 到并发数 %{1} 之间。', [$concurrency]));
                return 1;
            }
            if ($noKeepAlive) {
                $this->printer->error(__('--physical-connections 不能与 --no-keepalive 同时使用；fresh 模式每个请求都必须新建连接。'));
                return 1;
            }
        }
        // 命中 Worker 统计：支持自定义响应头（逗号分隔），默认自动探测常见 WLS 头
        $workerHeader = (string)($args['worker-header'] ?? $args['worker_header'] ?? '');
        $workerBalanceThreshold = (float)($args['worker-balance-threshold'] ?? $args['worker_balance_threshold'] ?? 1.5);
        if ($workerBalanceThreshold < 1.0) {
            $workerBalanceThreshold = 1.0;
        }
        try {
            $minSuccessQps = $this->normalizeGateThreshold(
                $this->resolveBenchmarkOptionValue(
                    $args,
                    ['min-success-qps', 'min_success_qps', 'min-qps', 'min_qps'],
                    0
                ),
                '--min-success-qps'
            );
            $maxErrorRate = $this->normalizeGateThreshold(
                $this->resolveBenchmarkOptionValue($args, ['max-error-rate', 'max_error_rate'], 0),
                '--max-error-rate',
                100.0
            );
            $maxP95Ms = $this->normalizeGateThreshold(
                $this->resolveBenchmarkOptionValue($args, ['max-p95-ms', 'max_p95_ms'], 0),
                '--max-p95-ms'
            );
            $maxTlsP95Ms = $this->normalizeGateThreshold(
                $this->resolveBenchmarkOptionValue(
                    $args,
                    [
                        'max-tls-p95-ms',
                        'max_tls_p95_ms',
                        'max-tls-handshake-p95-ms',
                        'max_tls_handshake_p95_ms',
                    ],
                    0
                ),
                '--max-tls-p95-ms'
            );
        } catch (\InvalidArgumentException $exception) {
            $this->printer->error($exception->getMessage());
            return 1;
        }
        if (!$ssl && $maxTlsP95Ms > 0.0) {
            $this->printer->error(__('--max-tls-p95-ms 仅适用于 HTTPS 压测。'));
            return 1;
        }
        $benchmarkContext = $this->buildBenchmarkContext(
            $serverConfig,
            $concurrency,
            $totalRequests,
            $noKeepAlive,
            $ssl,
            $tlsVersion,
            $httpVersion,
        );
        $benchmarkContext['accept_encoding_requested'] = $acceptEncoding['requested'];
        $benchmarkContext['accept_encoding_curl'] = $acceptEncoding['curl'];
        $benchmarkContext['quality_gate_thresholds'] = [
            'min_success_qps' => $minSuccessQps,
            'max_error_rate_percent' => $maxErrorRate,
            'max_p95_ms' => $maxP95Ms,
            'max_tls_handshake_p95_ms' => $maxTlsP95Ms,
            'worker_balance_max_min_ratio' => $workerBalanceThreshold,
        ];
        $effectiveHttpVersion = $this->resolveEffectiveHttpVersion(
            $httpVersion,
            $ssl,
            (array)($benchmarkContext['http_protocol_capabilities'] ?? [])
        );
        $benchmarkContext['http_version_effective'] = $effectiveHttpVersion;
        $benchmarkContext['http_version_auto_strategy'] = $httpVersion === 'auto'
            ? 'use verified h3 when HTTPS client and WLS QUIC support are ready; otherwise default to h2 and fall back to h1.1'
            : 'explicit';
        if ($physicalConnections !== null && !\in_array($effectiveHttpVersion, ['2', '3'], true)) {
            $this->printer->error(__('--physical-connections 仅用于 HTTP/2 或 HTTP/3 多路复用压测。'));
            return 1;
        }
        $benchmarkContext['physical_connections_requested'] = $physicalConnections;
        try {
            $this->assertRequestedHttpVersionIsRunnable(
                $httpVersion,
                $ssl,
                (array)($benchmarkContext['http_protocol_capabilities'] ?? [])
            );
        } catch (\Throwable $exception) {
            $this->printer->error($exception->getMessage());
            return 1;
        }
        
        // 修复 Git Bash 路径转换问题（如 /_wls/health 被转成 C:/Program Files/Git/_wls/health）
        $scheme = $ssl ? 'https' : 'http';
        $targetUrlHost = $this->formatTargetUrlHost($authorityHost);
        $targetUrl = "{$scheme}://{$targetUrlHost}:{$port}{$path}";

        // 先复用原有快速端口门禁，离线目标不得进入最长 15 秒的 cURL 探针。
        $socket = @\fsockopen($host, $port, $errno, $errstr, 5);
        if (!$socket) {
            $this->printer->error(__('无法连接到服务器 %{1}:%{2}', [$host, $port]));
            $this->printer->note(__('请先启动服务器：php bin/w server:start'));
            return 1;
        }
        \fclose($socket);

        $benchmarkContext['content_encoding_probe'] = $this->probeContentEncoding(
            $targetUrl,
            $ssl,
            $tlsVersion,
            $effectiveHttpVersion,
            $acceptEncoding,
            $benchmarkContext,
        );
        
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
        $displayHttpVersion = $httpVersion === 'auto'
            ? 'auto -> ' . (string)($benchmarkContext['http_version_effective'] ?? 'auto')
            : $httpVersion;
        $this->printer->note(\sprintf('║  HTTP 版本：%-49s║', $displayHttpVersion));
        $contentEncodingProbe = (array)($benchmarkContext['content_encoding_probe'] ?? []);
        $contentEncodingDisplay = $acceptEncoding['requested'] . ' -> '
            . (string)($contentEncodingProbe['content_encoding'] ?? 'unknown');
        $this->printer->note(\sprintf('║  内容编码：%-49s║', $contentEncodingDisplay));
        $runtimeMetadata = \is_array($serverConfig['runtime_metadata'] ?? null)
            ? $serverConfig['runtime_metadata']
            : [];
        $runtimeSelectionData = $runtimeMetadata['runtime_selection'] ?? null;
        if (\is_array($runtimeSelectionData)) {
            $runtimeSelection = RuntimeSelection::fromArray($runtimeSelectionData);
            $runtimeLine = $runtimeSelection->effectiveTopology->value
                . ' / ' . $runtimeSelection->listenerMode
                . ' / ' . $runtimeSelection->eventLoopDriver
                . ' / ' . $runtimeSelection->sslEngine;
            $this->printer->note(\sprintf('║  ' . __('实际运行时：') . '%-47s║', $runtimeLine));
        }
        $this->printer->note('╚══════════════════════════════════════════════════════════════╝');
        if (!empty($contentEncodingProbe['error'])) {
            $this->printer->warning(__('内容编码探针失败：%{1}', [(string)$contentEncodingProbe['error']]));
        } else {
            $this->printer->note(__(
                '内容编码探针：Content-Encoding=%{encoding}，Vary=%{vary}，wire body=%{wire}B，logical body=%{logical}B',
                [
                    'encoding' => (string)($contentEncodingProbe['content_encoding'] ?? 'identity'),
                    'vary' => (string)($contentEncodingProbe['vary'] ?? ''),
                    'wire' => (string)($contentEncodingProbe['wire_body_bytes'] ?? 0),
                    'logical' => (string)($contentEncodingProbe['logical_body_bytes'] ?? 0),
                ]
            ));
        }
        echo "\n";
        
        $this->printer->success(__('服务器连接成功，开始压测...'));
        echo "\n";
        
        // 直接运行压测（传入是否 HTTPS）
        return $this->runBenchmark(
            $targetUrl,
            $concurrency,
            $totalRequests,
            $ssl,
            $noKeepAlive,
            $workerHeader,
            $workerBalanceThreshold,
            $tlsVersion,
            (string)$benchmarkContext['http_version_effective'],
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
            return $this->resolveManualPortTarget($manager, $runningInstances, $args);
        }

        if (\count($runningInstances) === 1) {
            $name = (string)\array_key_first($runningInstances);
            $info = $manager->getInstanceInfoWithIpcTimeout($name, false, 0.5);
            if ($info === null || !$this->ensureBenchmarkInstanceReady($info, $name)) {
                return null;
            }
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
        $info = $manager->getInstanceInfoWithIpcTimeout($instanceName, false, 0.5);
        if (!\is_array($raw) || $info === null) {
            $this->printer->error(__('实例 [%{1}] 不存在', [$instanceName]));
            return null;
        }
        if (!$this->ensureBenchmarkInstanceReady($info, $instanceName)) {
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

    private function ensureBenchmarkInstanceReady(ServerInstanceInfo $info, string $instanceName): bool
    {
        if (!$info->isMasterRunning()) {
            $this->printer->error(__('实例 [%{1}] 未运行，已拒绝将端口占用者归因为该实例。', [$instanceName]));
            return false;
        }

        $expectedWorkerCount = \max(1, (int)$info->workerCount);
        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        $runtimeStats = $manager->getRuntimeStatsForInstance($info, true);
        $runtimeDesiredWorkers = (int)($runtimeStats['desired_workers'] ?? 0);
        if ($runtimeDesiredWorkers > 0) {
            $expectedWorkerCount = $runtimeDesiredWorkers;
        }
        $runningWorkerCount = \max(0, (int)($runtimeStats['workers'] ?? 0));
        $stoppedWorkers = [];
        if ($runningWorkerCount <= 0) {
            foreach ($info->getWorkers() as $service) {
                if ($service->isRunning()) {
                    $runningWorkerCount++;
                    continue;
                }
                $stoppedWorkers[] = $service->displayName !== ''
                    ? $service->displayName
                    : $service->role . '#' . (string)$service->instanceId;
            }
        }

        // Direct/shared-FD exposes one public endpoint for all Workers. A rolling
        // surge or stale instance record may keep stopped historical Worker rows
        // around, and old launches can miss managed-process identity checks even
        // while the public endpoint is healthy. Prefer exact Worker process
        // evidence; fall back to the public health endpoint before refusing a
        // benchmark run.
        if ($runningWorkerCount < $expectedWorkerCount) {
            if ($this->probeBenchmarkHealthEndpoint($info)) {
                $this->printer->note(__('实例 [%{1}] 的公开 health endpoint 已健康；Worker 进程索引为 %{2}/%{3}，本次压测以当前 endpoint schema 运行时元数据和 HTTP 结果为准。', [
                    $instanceName,
                    $runningWorkerCount,
                    $expectedWorkerCount,
                ]));
                return true;
            }

            $this->printer->error(__('实例 [%{1}] 未达到压测就绪状态：运行 Worker %{2}/%{3}。', [
                $instanceName,
                $runningWorkerCount,
                $expectedWorkerCount,
            ]));
            if (!empty($stoppedWorkers)) {
                $this->printer->note(__('已停止 Worker：%{1}', [\implode(', ', $stoppedWorkers)]));
            }
            $this->printer->note(__('请先恢复 Worker，再重新压测；可执行：php bin/w server:restart %{1} -r', [$instanceName]));
            return false;
        }

        return true;
    }

    private function probeBenchmarkHealthEndpoint(ServerInstanceInfo $info): bool
    {
        if ($info->port <= 0 || $info->port > 65535) {
            return false;
        }
        $scheme = $info->sslEnabled ? 'https' : 'http';
        $host = $this->formatTargetUrlHost($this->normalizeConnectHost($info->host !== '' ? $info->host : '127.0.0.1'));
        $url = $scheme . '://' . $host . ':' . $info->port . '/_wls/health';

        if (\function_exists('curl_init')) {
            $ch = \curl_init($url);
            if ($ch === false) {
                return false;
            }
            \curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_NOBODY => false,
                CURLOPT_TIMEOUT_MS => 1500,
                CURLOPT_CONNECTTIMEOUT_MS => 800,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            ]);
            $body = \curl_exec($ch);
            $status = (int)\curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            \curl_close($ch);
            return $status === 200 && $this->isBenchmarkHealthBody($body);
        }

        $context = \stream_context_create([
            'http' => ['timeout' => 1.5, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $body = @\file_get_contents($url, false, $context);
        return $this->isBenchmarkHealthBody($body);
    }

    private function isBenchmarkHealthBody(mixed $body): bool
    {
        if (!\is_string($body)) {
            return false;
        }
        $trimmed = \trim($body);
        return $trimmed === 'OK' || \str_contains($trimmed, '"status":"healthy"');
    }

    /**
     * @param array<string, array<string, mixed>> $runningInstances
     */
    private function resolveManualPortTarget(
        ServerInstanceManager $manager,
        array $runningInstances,
        array $args,
    ): ?array
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
            $info = $manager->getInstanceInfoWithIpcTimeout($name, false, 0.0);
            if ($info === null) {
                $this->printer->error(__('实例 [%{1}] 状态不可读，已拒绝开始压测。', [$name]));
                return null;
            }
            if (!$this->ensureBenchmarkInstanceReady($info, $name)) {
                return null;
            }
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
        $invalidCount = 0;
        $invalidSamples = [];
        foreach ($manager->listPersistedInstanceNames() as $name) {
            $name = (string)$name;
            $raw = $manager->getRawInstanceData($name);
            if (!\is_array($raw)) {
                continue;
            }
            try {
                $info = $manager->getInstanceInfoWithIpcTimeout($name, false, 0.0);
            } catch (\RuntimeException $exception) {
                $invalidCount++;
                if (\count($invalidSamples) < 3) {
                    $invalidSamples[] = $name . ': ' . $exception->getMessage();
                }
                continue;
            }
            if ($info === null || !$info->isMasterRunning()) {
                continue;
            }
            $target = $this->buildInstanceTarget($name, $raw);
            if ($target !== null) {
                $targets[$name] = $target;
            }
        }

        if ($invalidCount > 0) {
            $sampleText = $invalidSamples === [] ? '' : ('；样例：' . \implode(' | ', $invalidSamples));
            $this->printer->warning(__('已跳过 %{1} 个无效 WLS 实例记录%{2}', [$invalidCount, $sampleText]));
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

        try {
            $runtimeMetadata = $this->extractRuntimeMetadata($endpoint);
        } catch (\RuntimeException) {
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
            'runtime_metadata' => $runtimeMetadata,
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
        string $httpVersion = 'auto',
        array $benchmarkContext = []
    ): int
    {
        $results = [];
        $requestLatencies = [];
        $errors = 0;
        $errorDetails = [];
        $statusCodes = [];
        $workerHits = [];
        $cacheSources = [];
        $httpVersionHits = [];
        $newConnectionCount = 0;
        $connectionReuseEligible = 0;
        $knownConnectedHandles = [];
        $connectTimeSamples = [];
        $tlsAppConnectTimeSamples = [];
        $tlsHandshakeTimeSamples = [];
        
        // 检查 curl 扩展
        if (!\function_exists('curl_multi_init')) {
            $this->printer->error(__('需要 curl 扩展支持'));
            return 1;
        }
        $benchmarkContext['worker_runtime_before'] = $this->captureWorkerRuntimeSnapshot($benchmarkContext);
        $startTime = \microtime(true);
        
        $effectiveHttpVersion = (string)($benchmarkContext['http_version_effective'] ?? $httpVersion);
        $curlHttpVersion = ($httpVersion === 'auto' && $effectiveHttpVersion !== '')
            ? $effectiveHttpVersion
            : $httpVersion;

        // 基础选项
        $baseOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTP_VERSION => $this->curlHttpVersionOption($curlHttpVersion),
            CURLOPT_ENCODING => (string)($benchmarkContext['accept_encoding_curl'] ?? 'identity'),
            CURLOPT_USERAGENT => 'Weline-Server-Benchmark/2.0',
        ];
        if ($noKeepAlive) {
            // 分流压测模式：每个请求尽量新建连接，让 Dispatcher 在“连接级”重新选择 Worker
            $baseOpts[CURLOPT_FORBID_REUSE] = true;
            $baseOpts[CURLOPT_FRESH_CONNECT] = true;
            $baseOpts[CURLOPT_TCP_KEEPALIVE] = 0;
            $baseOpts[CURLOPT_HTTPHEADER] = [
                'Connection: close',
                'X-WLS-Benchmark-Worker: 1',
            ];
        } else {
            // 性能压测模式：启用连接复用（Keep-Alive）
            $baseOpts[CURLOPT_FORBID_REUSE] = false;      // 允许连接复用
            $baseOpts[CURLOPT_FRESH_CONNECT] = false;     // 不强制新连接
            $baseOpts[CURLOPT_TCP_KEEPALIVE] = 1;         // 启用 TCP Keep-Alive
            $baseOpts[CURLOPT_TCP_KEEPIDLE] = 60;         // Keep-Alive 空闲时间
            $baseOpts[CURLOPT_TCP_KEEPINTVL] = 30;        // Keep-Alive 间隔
            $baseOpts[CURLOPT_HTTPHEADER] = [
                'Connection: keep-alive',
                'Keep-Alive: timeout=60, max=1000',
                'X-WLS-Benchmark-Worker: 1',
            ];
        }
        if ($ssl) {
            $baseOpts[CURLOPT_SSL_VERIFYPEER] = false;
            $baseOpts[CURLOPT_SSL_VERIFYHOST] = 0;
            $curlSslVersion = $this->curlSslVersionOption($tlsVersion);
            if ($curlSslVersion !== null) {
                $baseOpts[CURLOPT_SSLVERSION] = $curlSslVersion;
            }
        }
        $baseOpts = $this->applyBenchmarkEndpointCurlOptions(
            $baseOpts,
            $url,
            $ssl,
            $benchmarkContext,
        );
        
        $requestedPhysicalConnections = (int)($benchmarkContext['physical_connections_requested'] ?? 0);
        $sslSessionShareSupported = \defined('CURL_LOCK_DATA_SSL_SESSION');
        $connectionShareEnabled = !$noKeepAlive;
        $sslSessionShareEnabled = $ssl && $connectionShareEnabled && $sslSessionShareSupported;
        $clientMultiplexOptionEnabled = \defined('CURLPIPE_MULTIPLEX');
        $effectiveHttpVersion = (string)($benchmarkContext['http_version_effective'] ?? $httpVersion);
        $wlsAdapters = (array)($benchmarkContext['http_protocol_capabilities']['wls_adapters'] ?? []);
        if ($effectiveHttpVersion === '3') {
            $http3Runtime = (array)($wlsAdapters['http3'] ?? []);
            $http3Capabilities = (array)($http3Runtime['adapter_capabilities'] ?? []);
            $multiplexMaxConcurrentStreams = (bool)($http3Capabilities['http3_stream_multiplexing'] ?? false)
                ? 64
                : 0;
            $multiplexCapabilityVerified = $clientMultiplexOptionEnabled
                && (bool)($http3Runtime['runtime_verified'] ?? false)
                && (bool)($http3Capabilities['http3_stream_multiplexing'] ?? false)
                && $multiplexMaxConcurrentStreams > 1;
        } else {
            $http2Runtime = (array)($wlsAdapters['http2'] ?? []);
            $multiplexMaxConcurrentStreams = (int)($http2Runtime['max_concurrent_streams'] ?? 0);
            $multiplexCapabilityVerified = $clientMultiplexOptionEnabled
                && (bool)($http2Runtime['multiplexing_verified'] ?? false)
                && $multiplexMaxConcurrentStreams > 1;
        }
        $multiplexRequested = $connectionShareEnabled
            && $multiplexCapabilityVerified
            && \in_array($effectiveHttpVersion, ['2', '3'], true);
        $physicalConnectionLimit = $concurrency;
        $multiplexReadyWorkerTarget = 0;
        if ($multiplexRequested) {
            $multiplexReadyWorkerTarget = \min(
                $concurrency,
                \max(1, (int)($benchmarkContext['worker_count'] ?? 0))
            );
            if ($requestedPhysicalConnections > 0) {
                $physicalConnectionLimit = \min($concurrency, $requestedPhysicalConnections);
            } else {
                $streamCapacityConnectionTarget = (int)\ceil(
                    $concurrency / \max(1, $multiplexMaxConcurrentStreams)
                );
                $physicalConnectionLimit = \min(
                    $concurrency,
                    \max($multiplexReadyWorkerTarget, $streamCapacityConnectionTarget)
                );
            }
        }
        $curlMaxConcurrentStreamsSupported = \defined('CURLMOPT_MAX_CONCURRENT_STREAMS');
        $multiplexStreamLimit = $multiplexRequested
            ? \min(
                $multiplexMaxConcurrentStreams,
                \max(1, (int)\ceil($concurrency / \max(1, $physicalConnectionLimit)))
            )
            : 0;
        $pipeWaitSupported = \defined('CURLOPT_PIPEWAIT');
        $pipeWaitEnabled = $multiplexRequested && $pipeWaitSupported;
        if ($pipeWaitEnabled) {
            $baseOpts[\CURLOPT_PIPEWAIT] = true;
        }
        $benchmarkContext['connection_share_enabled'] = $connectionShareEnabled;
        $benchmarkContext['ssl_session_share_supported'] = $sslSessionShareSupported;
        $benchmarkContext['ssl_session_share_enabled'] = $sslSessionShareEnabled;
        $benchmarkContext['curl_multiplex_option_enabled'] = $clientMultiplexOptionEnabled;
        $benchmarkContext['curl_pipewait_supported'] = $pipeWaitSupported;
        $benchmarkContext['curl_pipewait_enabled'] = $pipeWaitEnabled;
        $benchmarkContext['curl_max_concurrent_streams_supported'] = $curlMaxConcurrentStreamsSupported;
        $benchmarkContext['http_multiplex_capability_verified'] = $multiplexCapabilityVerified;
        $benchmarkContext['http_multiplex_requested'] = $multiplexRequested;
        $benchmarkContext['http_multiplex_enabled'] = false;
        $benchmarkContext['http_multiplex_max_concurrent_streams'] = $multiplexMaxConcurrentStreams;
        $benchmarkContext['multiplex_stream_limit'] = $multiplexStreamLimit;
        $benchmarkContext['multiplex_ready_worker_target'] = $multiplexReadyWorkerTarget;
        $explicitPhysicalConnectionLanes = $multiplexRequested && $requestedPhysicalConnections > 0;
        $physicalConnectionLaneCount = $explicitPhysicalConnectionLanes
            ? $physicalConnectionLimit
            : 1;
        $benchmarkContext['physical_connection_limit'] = $physicalConnectionLimit;
        $benchmarkContext['physical_connection_lanes_requested'] = $explicitPhysicalConnectionLanes
            ? $requestedPhysicalConnections
            : null;
        $benchmarkContext['physical_connection_lanes_created'] = $physicalConnectionLaneCount;
        $benchmarkContext['connection_model'] = $explicitPhysicalConnectionLanes
            ? 'isolated-multiplex-lanes-with-per-lane-connect-cache'
            : ($multiplexRequested
                ? 'multiplexed-streams-over-bounded-physical-connections'
                : ($noKeepAlive ? 'fresh-connection-per-request' : 'parallel-keepalive-connections'));
        if ($multiplexRequested) {
            $this->printer->note(__(
                '多路复用连接模型：逻辑并发 %{1}，物理连接目标 %{2}，READY Worker 目标 %{3}，每连接 Stream 目标 %{4}/服务端上限 %{5}，MAX_CONCURRENT_STREAMS=%{6}，PIPEWAIT=%{7}',
                [
                    $concurrency,
                    $physicalConnectionLimit,
                    $multiplexReadyWorkerTarget,
                    $multiplexStreamLimit,
                    $multiplexMaxConcurrentStreams,
                    $curlMaxConcurrentStreamsSupported ? 'on' : 'unsupported',
                    $pipeWaitEnabled ? 'on' : 'off',
                ]
            ));
        }
        $benchmarkContext['reuse_profile'] = $noKeepAlive
            ? ($ssl ? 'fresh-tls-full-handshake' : 'fresh-connection')
            : ($ssl
                ? (($multiplexRequested ? 'http' . $effectiveHttpVersion . '-multiplex+' : '') . 'keep-alive+tls-connection-reuse')
                : 'keep-alive+http-connection-reuse');
        
        // 显式物理连接目标使用完全隔离的 multi/connect-cache lane。
        // 同一 lane 内的 easy handles 仍复用连接并进行 H2/H3 多路复用，
        // 不同 lane 之间绝不共享连接缓存，避免 libcurl 把 N 个目标折叠为 1 条连接。
        $multiHandles = [];
        $shareHandles = [];
        for ($laneId = 0; $laneId < $physicalConnectionLaneCount; $laneId++) {
            $laneMultiHandle = \curl_multi_init();
            $multiHandles[$laneId] = $laneMultiHandle;

            $laneShareHandle = null;
            if ($connectionShareEnabled) {
                $laneShareHandle = \curl_share_init();
                \curl_share_setopt($laneShareHandle, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
                \curl_share_setopt($laneShareHandle, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
                if ($sslSessionShareEnabled) {
                    \curl_share_setopt($laneShareHandle, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
                }
            }
            $shareHandles[$laneId] = $laneShareHandle;

            if ($clientMultiplexOptionEnabled) {
                \curl_multi_setopt($laneMultiHandle, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
            }
            if ($multiplexRequested && $curlMaxConcurrentStreamsSupported) {
                \curl_multi_setopt(
                    $laneMultiHandle,
                    (int)\constant('CURLMOPT_MAX_CONCURRENT_STREAMS'),
                    $multiplexStreamLimit
                );
            }

            $lanePhysicalConnectionLimit = $explicitPhysicalConnectionLanes
                ? 1
                : $physicalConnectionLimit;
            if (\defined('CURLMOPT_MAX_HOST_CONNECTIONS')) {
                \curl_multi_setopt(
                    $laneMultiHandle,
                    \CURLMOPT_MAX_HOST_CONNECTIONS,
                    $lanePhysicalConnectionLimit
                );
            }
            if (\defined('CURLMOPT_MAX_TOTAL_CONNECTIONS')) {
                \curl_multi_setopt(
                    $laneMultiHandle,
                    \CURLMOPT_MAX_TOTAL_CONNECTIONS,
                    $lanePhysicalConnectionLimit
                );
            }
        }
        
        // 创建固定数量的 easy handle；显式 lane 模式按 round-robin 绑定，
        // 保证 totalRequests >= lane 数时每个 lane 都会实际发起连接。
        $handlePool = [];
        $handleLanes = [];
        $activeHandles = [];  // key => ['handle', 'start', 'poolIndex', 'laneId', 'performed']
        $headerBuffers = [];  // key => raw header text
        $completed = 0;
        $requestsSent = 0;
        $laneNewConnectionCounts = \array_fill(0, $physicalConnectionLaneCount, 0);
        $multiplexTransferIntervals = [];
        $liveMultiplexConnectionObservations = [];
        $liveMultiplexLanePeaks = \array_fill(0, $physicalConnectionLaneCount, 0);

        $batchSize = \min($concurrency, $totalRequests);

        for ($i = 0; $i < $batchSize; $i++) {
            $laneId = $explicitPhysicalConnectionLanes
                ? ($i % $physicalConnectionLaneCount)
                : 0;
            $ch = \curl_init();
            \curl_setopt_array($ch, $baseOpts);
            \curl_setopt($ch, CURLOPT_URL, $url);
            $laneShareHandle = $shareHandles[$laneId] ?? null;
            if ($laneShareHandle !== null) {
                \curl_setopt($ch, CURLOPT_SHARE, $laneShareHandle);
            }
            \curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($chRef, string $line) use (&$headerBuffers): int {
                $headerBuffers[(int)$chRef] = ($headerBuffers[(int)$chRef] ?? '') . $line;
                return \strlen($line);
            });
            $handlePool[$i] = $ch;
            $handleLanes[$i] = $laneId;
        }

        for ($i = 0; $i < $batchSize; $i++) {
            $ch = $handlePool[$i];
            $laneId = $handleLanes[$i];
            $handleStartedAt = \microtime(true);
            \curl_multi_add_handle($multiHandles[$laneId], $ch);
            $activeHandles[(int)$ch] = [
                'handle' => $ch,
                'start' => $handleStartedAt,
                'poolIndex' => $i,
                'laneId' => $laneId,
                'performed' => false,
            ];
            $requestsSent++;
        }

        $runningByLane = \array_fill(0, $physicalConnectionLaneCount, 0);
        $selectLaneCursor = 0;
        $lastProgressReportAt = \microtime(true);
        $progressReportInterval = 0.5;
        $reportProgress = function (bool $force = false) use (
            &$lastProgressReportAt,
            &$completed,
            &$requestsSent,
            &$activeHandles,
            $totalRequests,
            $startTime,
            $progressReportInterval,
        ): void {
            $now = \microtime(true);
            if (!$force && ($now - $lastProgressReportAt) < $progressReportInterval) {
                return;
            }

            $progressPercent = $totalRequests > 0
                ? \min(100, ($completed / $totalRequests) * 100)
                : 0;
            $elapsedSeconds = \max($now - $startTime, 0.001);
            $liveQps = $completed / $elapsedSeconds;
            $this->printer->note(__('进度：%{1}%（完成 %{2}/%{3}，活动请求 %{4}，已发送 %{5}/%{3}，耗时 %{6}s，实时 QPS %{7}）', [
                \number_format($progressPercent, 1),
                \number_format($completed),
                \number_format($totalRequests),
                \number_format(\count($activeHandles)),
                \number_format($requestsSent),
                \number_format($elapsedSeconds, 1),
                \number_format($liveQps, 1),
            ]));
            $lastProgressReportAt = $now;
            \flush();
        };
        
        if ($noKeepAlive) {
            $this->printer->note(__('压测模式：禁用 keep-alive（更利于分流验证），并发连接数=%{1}', [$batchSize]));
        } elseif ($explicitPhysicalConnectionLanes) {
            $this->printer->note(__(
                '压测模式：%{1} 个隔离多路复用 lane，%{2} 个逻辑 easy handle；lane 内复用、lane 间连接缓存隔离。',
                [$physicalConnectionLaneCount, $batchSize]
            ));
        } else {
            $this->printer->note(__('压测模式：启用 keep-alive（性能模式），使用 %{1} 个逻辑 easy handle...', [$batchSize]));
        }
        $reportProgress(true);
        
        do {
            $running = 0;
            foreach ($multiHandles as $laneId => $laneMultiHandle) {
                $laneHandlesBeforeExec = [];
                foreach ($activeHandles as $activeKey => $activeHandle) {
                    if ((int)($activeHandle['laneId'] ?? -1) === $laneId) {
                        $laneHandlesBeforeExec[] = $activeKey;
                    }
                }
                $laneRunning = 0;
                do {
                    $status = \curl_multi_exec($laneMultiHandle, $laneRunning);
                } while ($status == CURLM_CALL_MULTI_PERFORM);
                foreach ($laneHandlesBeforeExec as $activeKey) {
                    if (isset($activeHandles[$activeKey])) {
                        $activeHandles[$activeKey]['performed'] = true;
                    }
                }
                $runningByLane[$laneId] = $laneRunning;
                $running += $laneRunning;

                while ($info = \curl_multi_info_read($laneMultiHandle)) {
                    $ch = $info['handle'];
                    $key = (int)$ch;
                
                    if (isset($activeHandles[$key])) {
                        $infoReadAt = \microtime(true);
                        $elapsed = ($infoReadAt - $activeHandles[$key]['start']) * 1000; // ms
                        $poolIndex = $activeHandles[$key]['poolIndex'];

                        if ($info['result'] === CURLE_OK) {
                            $transferInfo = \curl_getinfo($ch);
                            $totalTimeUs = \defined('CURLINFO_TOTAL_TIME_T')
                                ? (int)\curl_getinfo($ch, \CURLINFO_TOTAL_TIME_T)
                                : (int)\round((float)($transferInfo['total_time'] ?? 0.0) * 1000000);
                            $totalTimeSeconds = (float)$totalTimeUs / 1000000;
                            if ($totalTimeSeconds > 0.0) {
                                $elapsed = $totalTimeSeconds * 1000;
                            }
                            $httpCode = (int)($transferInfo['http_code'] ?? \curl_getinfo($ch, CURLINFO_HTTP_CODE));
                            $negotiatedHttpVersion = $this->curlHttpVersionName((int)($transferInfo['http_version'] ?? \curl_getinfo($ch, CURLINFO_HTTP_VERSION)));
                            $httpVersionHits[$negotiatedHttpVersion] = ($httpVersionHits[$negotiatedHttpVersion] ?? 0) + 1;
                            if ($multiplexRequested
                                && \in_array($negotiatedHttpVersion, ['2', '3'], true)
                                && $totalTimeUs > 0
                            ) {
                                $preTransferUs = \defined('CURLINFO_PRETRANSFER_TIME_T')
                                    ? (int)\curl_getinfo($ch, \CURLINFO_PRETRANSFER_TIME_T)
                                    : (int)\round((float)\curl_getinfo($ch, \CURLINFO_PRETRANSFER_TIME) * 1000000);
                                $localIp = (string)\curl_getinfo($ch, \CURLINFO_LOCAL_IP);
                                $localPort = (int)\curl_getinfo($ch, \CURLINFO_LOCAL_PORT);
                                $primaryIp = (string)\curl_getinfo($ch, \CURLINFO_PRIMARY_IP);
                                $primaryPort = (int)\curl_getinfo($ch, \CURLINFO_PRIMARY_PORT);
                                if ($preTransferUs > 0 && $totalTimeUs > $preTransferUs
                                    && $localPort > 0 && $primaryPort > 0
                                ) {
                                    $connectionId = \defined('CURLINFO_CONN_ID')
                                        ? (int)\curl_getinfo($ch, \CURLINFO_CONN_ID)
                                        : -1;
                                    $connectionKey = $connectionId >= 0
                                        ? $laneId . ':curl-connection-id:' . $connectionId
                                        : $laneId . ':' . $localIp . ':' . $localPort
                                            . '->' . $primaryIp . ':' . $primaryPort;
                                    if (!isset($multiplexTransferIntervals[$connectionKey])) {
                                        $multiplexTransferIntervals[$connectionKey] = [
                                            'lane_id' => $laneId,
                                            'local_ip' => $localIp,
                                            'local_port' => $localPort,
                                            'primary_ip' => $primaryIp,
                                            'primary_port' => $primaryPort,
                                            'connection_id' => $connectionId >= 0 ? $connectionId : null,
                                            'protocol' => $negotiatedHttpVersion,
                                            'intervals' => [],
                                        ];
                                    }
                                    $handleStartUs = (int)\round((float)$activeHandles[$key]['start'] * 1000000);
                                    $multiplexTransferIntervals[$connectionKey]['intervals'][] = [
                                        'start_us' => $handleStartUs + $preTransferUs,
                                        'end_us' => $handleStartUs + $totalTimeUs,
                                    ];
                                }
                            }
                            $statusCodes[(string)$httpCode] = ($statusCodes[(string)$httpCode] ?? 0) + 1;
                            $numConnectInfoSupported = \defined('CURLINFO_NUM_CONNECTS');
                            $numConnects = $numConnectInfoSupported
                                ? (int)\curl_getinfo($ch, \CURLINFO_NUM_CONNECTS)
                                : (int)($transferInfo['num_connects'] ?? 0);
                            if (!$numConnectInfoSupported && $numConnects <= 0 && !isset($knownConnectedHandles[$key])) {
                                // Compatibility fallback only when the runtime truly lacks NUM_CONNECTS.
                                // A supported NUM_CONNECTS value of zero is authoritative connection reuse,
                                // including another easy handle multiplexed onto an established H2/H3 connection.
                                $numConnects = 1;
                            }
                            if ($numConnects > 0) {
                                $newConnectionCount += $numConnects;
                                $laneNewConnectionCounts[$laneId] += $numConnects;
                                $knownConnectedHandles[$key] = true;
                            }
                            $connectionReuseEligible++;
                            $connectTimeMs = \defined('CURLINFO_CONNECT_TIME_T')
                                ? ((float)\curl_getinfo($ch, \CURLINFO_CONNECT_TIME_T) / 1000)
                                : ((float)($transferInfo['connect_time'] ?? 0.0) * 1000);
                            if ($connectTimeMs > 0) {
                                $connectTimeSamples[] = $connectTimeMs;
                            }
                            $tlsAppConnectMs = \defined('CURLINFO_APPCONNECT_TIME_T')
                                ? ((float)\curl_getinfo($ch, \CURLINFO_APPCONNECT_TIME_T) / 1000)
                                : ((float)($transferInfo['appconnect_time'] ?? 0.0) * 1000);
                            if ($ssl && $tlsAppConnectMs > 0) {
                                $tlsAppConnectTimeSamples[] = $tlsAppConnectMs;
                                // APPCONNECT is measured from transfer start and includes
                                // TCP connect. Subtract CONNECT to report crypto/TLS only.
                                $tlsHandshakeMs = \max(0.0, $tlsAppConnectMs - $connectTimeMs);
                                if ($tlsHandshakeMs > 0.0) {
                                    if ($numConnects > 0) {
                                        $tlsHandshakeTimeSamples[] = $tlsHandshakeMs;
                                    }
                                }
                            }
                            if ($httpCode >= 200 && $httpCode < 400) {
                                $results[] = $elapsed;
                                $headers = $this->parseResponseHeaders($headerBuffers[$key] ?? '');
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

                        $requestLatencies[] = $elapsed;
                        $completed++;

                        // easy handle 始终留在自己的 lane，避免跨 lane 污染连接缓存。
                        \curl_multi_remove_handle($laneMultiHandle, $ch);
                        unset($activeHandles[$key]);
                        $headerBuffers[$key] = '';

                        if ($requestsSent < $totalRequests) {
                            $handleStartedAt = \microtime(true);
                            \curl_multi_add_handle($laneMultiHandle, $ch);
                            $activeHandles[(int)$ch] = [
                                'handle' => $ch,
                                'start' => $handleStartedAt,
                                'poolIndex' => $poolIndex,
                                'laneId' => $laneId,
                                'performed' => false,
                            ];
                            $requestsSent++;
                        }

                        // 首次完成、最终完成立即反馈；中间按时间节流，避免高 QPS 时刷屏。
                        $reportProgress($completed === 1 || $completed >= $totalRequests);
                    }
                }

            }

            if ($running > 0) {
                $readyDescriptors = 0;
                foreach ($multiHandles as $laneId => $laneMultiHandle) {
                    if (($runningByLane[$laneId] ?? 0) <= 0) {
                        continue;
                    }
                    $selected = \curl_multi_select($laneMultiHandle, 0.0);
                    if ($selected > 0) {
                        $readyDescriptors += $selected;
                    }
                }

                // 所有 lane 都无立即可读事件时，仅阻塞一个轮转 lane 1ms，
                // 避免 N 个 lane 逐个等待造成 N 倍尾延迟。
                if ($readyDescriptors === 0) {
                    for ($offset = 0; $offset < $physicalConnectionLaneCount; $offset++) {
                        $laneId = ($selectLaneCursor + $offset) % $physicalConnectionLaneCount;
                        if (($runningByLane[$laneId] ?? 0) <= 0) {
                            continue;
                        }
                        \curl_multi_select($multiHandles[$laneId], 0.001);
                        $selectLaneCursor = ($laneId + 1) % $physicalConnectionLaneCount;
                        break;
                    }
                }
            }
            $reportProgress();

        } while ($running > 0 || \count($activeHandles) > 0);
        
        // 清理 handle 池和共享句柄
        foreach ($handlePool as $ch) {
            \curl_close($ch);
        }
        foreach ($multiHandles as $laneMultiHandle) {
            \curl_multi_close($laneMultiHandle);
        }
        foreach ($shareHandles as $laneShareHandle) {
            if ($laneShareHandle !== null) {
                \curl_share_close($laneShareHandle);
            }
        }
        
        $endTime = \microtime(true);
        $totalTime = $endTime - $startTime;
        $reusedRequestEstimate = \max(0, $connectionReuseEligible - $newConnectionCount);
        $benchmarkContext['curl_new_connections'] = $newConnectionCount;
        $observedPhysicalConnectionLanes = \count(\array_filter(
            $laneNewConnectionCounts,
            static fn (int $count): bool => $count > 0
        ));
        $physicalConnectionTargetValid = !$explicitPhysicalConnectionLanes
            || (
                $physicalConnectionLaneCount === $requestedPhysicalConnections
                && $observedPhysicalConnectionLanes >= $requestedPhysicalConnections
            );
        $benchmarkContext['physical_connections_observed'] = $newConnectionCount;
        $benchmarkContext['physical_connection_count_source'] = \defined('CURLINFO_NUM_CONNECTS')
            ? 'CURLINFO_NUM_CONNECTS'
            : 'per-handle-compatibility-fallback';
        $benchmarkContext['physical_connection_lanes_actual'] = $observedPhysicalConnectionLanes;
        $benchmarkContext['physical_connection_lane_new_connections'] = $laneNewConnectionCounts;
        $benchmarkContext['physical_connection_target_valid'] = $physicalConnectionTargetValid;
        $benchmarkContext['curl_connection_reuse_eligible'] = $connectionReuseEligible;
        $benchmarkContext['curl_reused_request_estimate'] = $reusedRequestEstimate;
        $benchmarkContext['curl_connection_reuse_ratio'] = $connectionReuseEligible > 0
            ? \round($reusedRequestEstimate / $connectionReuseEligible, 6)
            : null;
        $benchmarkContext['curl_connect_time_ms'] = $this->summarizeTimingSamples($connectTimeSamples);
        $benchmarkContext['curl_tls_appconnect_time_ms'] = $this->summarizeTimingSamples($tlsAppConnectTimeSamples);
        $benchmarkContext['curl_tls_handshake_time_ms'] = $this->summarizeTimingSamples($tlsHandshakeTimeSamples);
        if (!empty($httpVersionHits)) {
            \arsort($httpVersionHits);
            $benchmarkContext['http_version_negotiated'] = (string)\array_key_first($httpVersionHits);
            $benchmarkContext['http_version_hits'] = $httpVersionHits;
            $requestedHttpVersion = (string)($benchmarkContext['http_version_requested'] ?? $httpVersion);
            if ($requestedHttpVersion !== 'auto' && !isset($httpVersionHits[$requestedHttpVersion])) {
                $mismatchedSuccesses = \count($results);
                if ($mismatchedSuccesses > 0) {
                    $errors += $mismatchedSuccesses;
                    $results = [];
                    $detail = 'protocol_mismatch:requested=' . $requestedHttpVersion . ':negotiated=' . (string)$benchmarkContext['http_version_negotiated'];
                    $errorDetails[$detail] = ($errorDetails[$detail] ?? 0) + $mismatchedSuccesses;
                }
            }
        }
        $actualMultiplexProtocolHits = (int)($httpVersionHits['2'] ?? 0)
            + (int)($httpVersionHits['3'] ?? 0);
        foreach ($multiplexTransferIntervals as $connectionKey => $connection) {
            $events = [];
            foreach ((array)($connection['intervals'] ?? []) as $interval) {
                $intervalStartUs = (int)($interval['start_us'] ?? 0);
                $intervalEndUs = (int)($interval['end_us'] ?? 0);
                if ($intervalStartUs <= 0 || $intervalEndUs <= $intervalStartUs) {
                    continue;
                }
                $events[] = [$intervalStartUs, 1];
                $events[] = [$intervalEndUs, -1];
            }
            \usort($events, static function (array $left, array $right): int {
                $timeOrder = ((int)$left[0]) <=> ((int)$right[0]);
                return $timeOrder !== 0 ? $timeOrder : ((int)$left[1] <=> (int)$right[1]);
            });
            $concurrent = 0;
            $peak = 0;
            $peakAtUs = null;
            foreach ($events as [$eventAtUs, $delta]) {
                $concurrent += (int)$delta;
                if ($concurrent > $peak) {
                    $peak = $concurrent;
                    $peakAtUs = (int)$eventAtUs;
                }
            }
            if ($events === []) {
                continue;
            }
            $laneId = (int)($connection['lane_id'] ?? 0);
            unset($connection['intervals']);
            $liveMultiplexConnectionObservations[$connectionKey] = $connection + [
                'concurrent_streams' => $peak,
                'peak_concurrent_streams' => $peak,
                'sample_count' => (int)(\count($events) / 2),
                'peak_observed_at_ms' => $peakAtUs !== null
                    ? \round(($peakAtUs / 1000000 - $startTime) * 1000, 3)
                    : null,
            ];
            $liveMultiplexLanePeaks[$laneId] = \max(
                (int)($liveMultiplexLanePeaks[$laneId] ?? 0),
                $peak
            );
        }
        $multiplexPeakConcurrentStreams = $liveMultiplexLanePeaks === []
            ? 0
            : (int)\max($liveMultiplexLanePeaks);
        $httpMultiplexObserved = $multiplexRequested
            && $actualMultiplexProtocolHits > 0
            && $multiplexPeakConcurrentStreams >= 2
            && $newConnectionCount > 0
            && $connectionReuseEligible > $newConnectionCount
            && \count($results) > $newConnectionCount
            && \count($liveMultiplexConnectionObservations) > 0;
        $benchmarkContext['http_multiplex_enabled'] = $httpMultiplexObserved;
        $benchmarkContext['http_multiplex_observation'] = [
            'observed' => $httpMultiplexObserved,
            'negotiated_protocol' => (string)($benchmarkContext['http_version_negotiated'] ?? ''),
            'negotiated_multiplex_protocol_hits' => $actualMultiplexProtocolHits,
            'completed_successes' => \count($results),
            'connection_reuse_eligible' => $connectionReuseEligible,
            'new_connections' => $newConnectionCount,
            'peak_concurrent_streams' => $multiplexPeakConcurrentStreams,
            'lane_peak_concurrent_streams' => $liveMultiplexLanePeaks,
            'connections' => $liveMultiplexConnectionObservations,
            'measurement_source' => \defined('CURLINFO_CONN_ID')
                ? 'completed transfer PRETRANSFER-to-TOTAL interval overlap grouped by CURLINFO_CONN_ID'
                : 'completed transfer PRETRANSFER-to-TOTAL interval overlap grouped by local+primary connection tuple',
            'stream_ids_observed' => false,
            'evidence' => $httpMultiplexObserved
                ? 'at least two completed H2/H3 transfers overlapped after PRETRANSFER on the same connection identity'
                : 'no same-connection overlapping H2/H3 transfer intervals were observed',
        ];
        
        if ($explicitPhysicalConnectionLanes) {
            $this->printer->note(__(
                '物理连接 lane：请求 %{1}，创建 %{2}，实际建连 %{3}，累计新连接 %{4}。',
                [
                    $requestedPhysicalConnections,
                    $physicalConnectionLaneCount,
                    $observedPhysicalConnectionLanes,
                    $newConnectionCount,
                ]
            ));
            if (!$physicalConnectionTargetValid) {
                $this->printer->error(__(
                    '物理连接 lane 未达到请求值（请求 %{1}，实际 %{2}），本次基准无效。',
                    [$requestedPhysicalConnections, $observedPhysicalConnectionLanes]
                ));
            }
        }

        $benchmarkContext['worker_runtime_after'] = $this->captureWorkerRuntimeSnapshot($benchmarkContext);
        $workerBalance = $this->buildWorkerBalance(
            $workerHits,
            \count($results),
            $workerBalanceThreshold,
            $benchmarkContext
        );
        $benchmarkContext['worker_balance'] = $workerBalance;
        $qualityGate = $this->evaluateQualityGate(
            $results,
            $errors,
            $totalTime,
            $totalRequests,
            $requestLatencies,
            $workerBalance,
            $benchmarkContext
        );
        $benchmarkContext['quality_gate'] = $qualityGate;
        $benchmarkContext['benchmark_valid'] = (bool)$qualityGate['passed'];
        $benchmarkContext['benchmark_invalid_reasons'] = (array)$qualityGate['failure_reasons'];

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
            $cacheSources,
            $requestLatencies
        );

        return (bool)$qualityGate['passed'] ? 0 : 1;
    }
    
    /**
     * @param list<float> $samples
     * @return array{count:int,avg:float,min:float,max:float,p95:float,p99:float}
     */
    private function summarizeTimingSamples(array $samples): array
    {
        $count = \count($samples);
        if ($count === 0) {
            return ['count' => 0, 'avg' => 0.0, 'min' => 0.0, 'max' => 0.0, 'p95' => 0.0, 'p99' => 0.0];
        }

        \sort($samples);
        $p95Index = \min((int)\floor($count * 0.95), $count - 1);
        $p99Index = \min((int)\floor($count * 0.99), $count - 1);

        return [
            'count' => $count,
            'avg' => \round(\array_sum($samples) / $count, 3),
            'min' => \round((float)\min($samples), 3),
            'max' => \round((float)\max($samples), 3),
            'p95' => \round((float)$samples[$p95Index], 3),
            'p99' => \round((float)$samples[$p99Index], 3),
        ];
    }

    private function normalizeGateThreshold(mixed $value, string $option, ?float $maximum = null): float
    {
        // CommandAbstract's legacy argv parser represents an explicit numeric
        // zero as boolean false. Preserve that valid threshold value.
        $normalized = $value === false || $value === null ? '0' : \trim((string)$value);
        if ($normalized === '' || !\is_numeric($normalized)) {
            throw new \InvalidArgumentException(__('%{1} 必须是非负数字。', [$option]));
        }
        $threshold = (float)$normalized;
        if (!\is_finite($threshold) || $threshold < 0.0 || ($maximum !== null && $threshold > $maximum)) {
            $range = $maximum !== null ? '0-' . (string)$maximum : '>= 0';
            throw new \InvalidArgumentException(__('%{1} 必须在 %{2} 范围内。', [$option, $range]));
        }

        return $threshold;
    }

    /**
     * CommandAbstract keeps the original numeric argv entries alongside parsed
     * keys. Recover an explicit zero that the legacy parser classifies as an
     * empty next token and therefore stores as boolean true.
     *
     * @param list<string> $names
     */
    private function resolveBenchmarkOptionValue(array $args, array $names, mixed $default): mixed
    {
        foreach ($names as $name) {
            if (!\array_key_exists($name, $args)) {
                continue;
            }
            $parsed = $args[$name];
            if (\is_array($parsed)) {
                foreach (\array_reverse($parsed) as $candidate) {
                    if ($candidate !== true && \is_scalar($candidate)) {
                        return $candidate;
                    }
                }
            }
            if ($parsed !== true) {
                return $parsed;
            }
            foreach ($args as $index => $token) {
                if (!\is_int($index) || !\is_string($token)) {
                    continue;
                }
                if ($token === '--' . $name || $token === '-' . $name) {
                    $next = $args[$index + 1] ?? null;
                    if (\is_scalar($next) && !\str_starts_with((string)$next, '-')) {
                        return $next;
                    }
                }
            }

            return $parsed;
        }

        return $default;
    }

    /**
     * @param array<string,mixed> $benchmarkContext
     * @return array<string,mixed>
     */
    private function captureWorkerRuntimeSnapshot(array $benchmarkContext): array
    {
        $attribution = (string)($benchmarkContext['target_attribution'] ?? '');
        $required = \in_array($attribution, [
            'explicit_instance',
            'single_running_instance',
            'unique_live_endpoint_match',
        ], true);
        $instanceName = \trim((string)($benchmarkContext['instance_name'] ?? ''));
        if (!$required || $instanceName === '') {
            return [
                'required' => false,
                'captured' => false,
                'healthy' => null,
                'reason' => 'target_not_attributed_to_one_live_wls_instance',
            ];
        }

        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        $info = $manager->getInstanceInfoWithIpcTimeout($instanceName, false, 0.5);
        if ($info === null) {
            return [
                'required' => true,
                'captured' => false,
                'healthy' => false,
                'instance_name' => $instanceName,
                'reason' => 'instance_runtime_snapshot_unavailable',
            ];
        }

        $contextWorkerCount = (int)($benchmarkContext['worker_count'] ?? 0);
        $expectedWorkers = \max(1, $contextWorkerCount > 0 ? $contextWorkerCount : $info->workerCount);
        $canonicalSlots = [];
        for ($slot = 1; $slot <= $expectedWorkers; $slot++) {
            $canonicalSlots['worker#' . (string)$slot] = true;
        }
        $workers = [];
        $readyFingerprint = [];
        $duplicateSlots = [];
        $unexpectedReadySlots = [];
        $activeCanonicalSlots = [];
        foreach ($info->getWorkers() as $service) {
            $slotId = \trim((string)($service->metadata['slot_id'] ?? ''));
            if ($slotId === '') {
                $slotId = 'worker#' . (string)$service->instanceId;
            }
            $runningRealtime = $service->state === ServiceInstance::STATE_READY
                && $service->pid > 0
                && Processer::processExists($service->pid);
            $recordKey = $slotId;
            if (isset($workers[$recordKey])) {
                $recordKey .= '@pid' . (string)$service->pid . '-g' . (string)($service->metadata['generation'] ?? $service->epoch);
            }
            $worker = [
                'slot_id' => $slotId,
                'instance_id' => $service->instanceId,
                'pid' => $service->pid,
                'root_pid' => $service->rootPid,
                'launcher_pid' => $service->launcherPid,
                'state' => $service->state,
                'lease_id' => $service->launchId !== ''
                    ? $service->launchId
                    : (string)($service->metadata['lease_id'] ?? ''),
                'generation' => (int)($service->metadata['generation'] ?? $service->epoch),
                'running_realtime' => $runningRealtime,
                'canonical' => isset($canonicalSlots[$slotId]),
            ];
            $workers[$recordKey] = $worker;
            if (!$runningRealtime) {
                continue;
            }
            if (!isset($canonicalSlots[$slotId])) {
                $unexpectedReadySlots[$slotId] = true;
                continue;
            }
            if (isset($activeCanonicalSlots[$slotId])) {
                $duplicateSlots[$slotId] = true;
                $fingerprintKey = $slotId . '@pid' . (string)$service->pid;
            } else {
                $activeCanonicalSlots[$slotId] = true;
                $fingerprintKey = $slotId;
            }
            $readyFingerprint[$fingerprintKey] = [
                    'pid' => $service->pid,
                    'root_pid' => $service->rootPid,
                    'launcher_pid' => $service->launcherPid,
                    'lease_id' => $worker['lease_id'],
                    'generation' => $worker['generation'],
            ];
        }
        \ksort($workers);
        \ksort($readyFingerprint);
        $missingCanonicalSlots = \array_keys(\array_diff_key($canonicalSlots, $activeCanonicalSlots));
        $masterRunning = $info->isMasterRunning();
        $healthy = $masterRunning
            && $duplicateSlots === []
            && $unexpectedReadySlots === []
            && $missingCanonicalSlots === []
            && \count($readyFingerprint) === $expectedWorkers;

        return [
            'required' => true,
            'captured' => true,
            'healthy' => $healthy,
            'instance_name' => $instanceName,
            'master_pid' => $info->masterPid,
            'master_running' => $masterRunning,
            'expected_workers' => $expectedWorkers,
            'ready_workers' => \count($readyFingerprint),
            'duplicate_slots' => \array_keys($duplicateSlots),
            'unexpected_ready_slots' => \array_keys($unexpectedReadySlots),
            'missing_canonical_slots' => $missingCanonicalSlots,
            'ready_fingerprint' => $readyFingerprint,
            'workers' => $workers,
            'reason' => $healthy ? 'master_and_all_canonical_workers_ready' : 'master_or_worker_ready_contract_failed',
        ];
    }

    /**
     * @param array<string,int> $workerHits
     * @param array<string,mixed> $benchmarkContext
     * @return array<string,mixed>
     */
    private function buildWorkerBalance(
        array $workerHits,
        int $successCount,
        float $threshold,
        array $benchmarkContext
    ): array {
        $expectedWorkers = \max(0, (int)($benchmarkContext['worker_count'] ?? 0));
        $observedWorkers = \count($workerHits);
        $missingWorkers = \max(0, $expectedWorkers - $observedWorkers);
        $extraWorkers = $expectedWorkers > 0 ? \max(0, $observedWorkers - $expectedWorkers) : 0;
        $attributedSuccesses = (int)\array_sum($workerHits);
        $unattributedSuccesses = \max(0, $successCount - $attributedSuccesses);
        $max = $workerHits !== [] ? (int)\max($workerHits) : 0;
        $min = $workerHits !== [] && $missingWorkers === 0 && $extraWorkers === 0
            ? (int)\min($workerHits)
            : 0;
        $spreadRatio = $min > 0 ? $max / $min : INF;
        $evaluated = (bool)($benchmarkContext['fresh_connection'] ?? false) && $expectedWorkers > 0;
        $balanced = $evaluated
            ? $missingWorkers === 0
                && $extraWorkers === 0
                && $unattributedSuccesses === 0
                && $min > 0
                && $spreadRatio <= $threshold
            : null;

        return [
            'threshold' => \round($threshold, 3),
            'expected_workers' => $expectedWorkers,
            'observed_workers' => $observedWorkers,
            'missing_workers' => $missingWorkers,
            'extra_workers' => $extraWorkers,
            'attributed_successes' => $attributedSuccesses,
            'unattributed_successes' => $unattributedSuccesses,
            'max' => $max,
            'min' => $min,
            'spread_ratio' => \is_finite($spreadRatio) ? \round($spreadRatio, 3) : null,
            'evaluated' => $evaluated,
            'balanced' => $balanced,
        ];
    }

    /**
     * @param list<float> $results
     * @param list<float> $requestLatencies
     * @param array<string,mixed> $workerBalance
     * @param array<string,mixed> $benchmarkContext
     * @return array{passed:bool,checks:array<string,array<string,mixed>>,thresholds:array<string,mixed>,failure_reasons:list<string>}
     */
    private function evaluateQualityGate(
        array $results,
        int $errors,
        float $totalTime,
        int $totalRequests,
        array $requestLatencies,
        array $workerBalance,
        array $benchmarkContext
    ): array {
        $thresholds = \is_array($benchmarkContext['quality_gate_thresholds'] ?? null)
            ? $benchmarkContext['quality_gate_thresholds']
            : [];
        $minSuccessQps = (float)($thresholds['min_success_qps'] ?? 0.0);
        $maxErrorRate = (float)($thresholds['max_error_rate_percent'] ?? 0.0);
        $maxP95Ms = (float)($thresholds['max_p95_ms'] ?? 0.0);
        $maxTlsP95Ms = (float)($thresholds['max_tls_handshake_p95_ms'] ?? 0.0);
        $successCount = \count($results);
        $completed = $successCount + $errors;
        $successQps = $totalTime > 0.0 ? $successCount / $totalTime : 0.0;
        $errorRate = $completed > 0 ? ($errors / $completed) * 100 : 100.0;
        $latencySummary = $this->summarizeTimingSamples($requestLatencies !== [] ? $requestLatencies : $results);
        $tlsSummary = \is_array($benchmarkContext['curl_tls_handshake_time_ms'] ?? null)
            ? $benchmarkContext['curl_tls_handshake_time_ms']
            : [];
        $checks = [];
        $failureReasons = [];
        $record = static function (
            string $name,
            bool $evaluated,
            bool $passed,
            mixed $actual,
            mixed $threshold,
            string $failureReason
        ) use (&$checks, &$failureReasons): void {
            $checks[$name] = [
                'evaluated' => $evaluated,
                'passed' => !$evaluated || $passed,
                'actual' => $actual,
                'threshold' => $threshold,
            ];
            if ($evaluated && !$passed) {
                $checks[$name]['failure_reason'] = $failureReason;
                $failureReasons[] = $failureReason;
            }
        };

        $record('request_completion', true, $completed === $totalRequests, $completed, $totalRequests, 'request_completion_mismatch');
        $record('error_rate', true, $errorRate <= $maxErrorRate, \round($errorRate, 4), $maxErrorRate, 'error_rate_above_threshold');
        $record(
            'physical_connection_target',
            true,
            (bool)($benchmarkContext['physical_connection_target_valid'] ?? true),
            (int)($benchmarkContext['physical_connection_lanes_actual'] ?? 0),
            $benchmarkContext['physical_connection_lanes_requested'] ?? null,
            'physical_connection_lanes_below_requested'
        );

        $expectedProtocol = (string)($benchmarkContext['http_version_effective'] ?? '');
        $requestedProtocol = (string)($benchmarkContext['http_version_requested'] ?? '');
        $protocolHits = (array)($benchmarkContext['http_version_hits'] ?? []);
        $protocolEvaluated = \in_array($expectedProtocol, ['1.1', '2', '3'], true);
        $allowedProtocols = $requestedProtocol === 'auto'
            ? \array_values(\array_unique([$expectedProtocol, '1.1']))
            : [$expectedProtocol];
        $unexpectedProtocols = [];
        foreach ($protocolHits as $protocol => $count) {
            if ((int)$count > 0 && !\in_array((string)$protocol, $allowedProtocols, true)) {
                $unexpectedProtocols[(string)$protocol] = (int)$count;
            }
        }
        $allowedProtocolHits = 0;
        foreach ($allowedProtocols as $allowedProtocol) {
            $allowedProtocolHits += (int)($protocolHits[$allowedProtocol] ?? 0);
        }
        $protocolPassed = $allowedProtocolHits > 0 && $unexpectedProtocols === [];
        $record(
            'http_protocol',
            $protocolEvaluated,
            $protocolPassed,
            ['allowed_hits' => $allowedProtocolHits, 'unexpected' => $unexpectedProtocols],
            $allowedProtocols,
            'http_protocol_negotiation_mismatch'
        );

        $record('min_success_qps', $minSuccessQps > 0.0, $successQps >= $minSuccessQps, \round($successQps, 3), $minSuccessQps, 'success_qps_below_threshold');
        $record('max_p95_ms', $maxP95Ms > 0.0, (int)($latencySummary['count'] ?? 0) > 0 && (float)($latencySummary['p95'] ?? 0.0) <= $maxP95Ms, $latencySummary['p95'] ?? null, $maxP95Ms, 'latency_p95_above_threshold');
        $tlsSampleCount = (int)($tlsSummary['count'] ?? 0);
        $requiredTlsSamples = (bool)($benchmarkContext['fresh_tls'] ?? false)
            ? \max(1, $successCount)
            : \max(1, (int)($benchmarkContext['curl_new_connections'] ?? 0));
        $tlsSamplesComplete = $tlsSampleCount >= $requiredTlsSamples;
        $record(
            'max_tls_handshake_p95_ms',
            $maxTlsP95Ms > 0.0,
            $tlsSamplesComplete && (float)($tlsSummary['p95'] ?? 0.0) <= $maxTlsP95Ms,
            ['p95_ms' => $tlsSampleCount > 0 ? ($tlsSummary['p95'] ?? null) : null, 'samples' => $tlsSampleCount],
            ['p95_ms' => $maxTlsP95Ms, 'min_samples' => $requiredTlsSamples],
            $tlsSamplesComplete ? 'tls_handshake_p95_above_threshold' : 'tls_handshake_samples_missing'
        );

        $before = \is_array($benchmarkContext['worker_runtime_before'] ?? null)
            ? $benchmarkContext['worker_runtime_before']
            : [];
        $after = \is_array($benchmarkContext['worker_runtime_after'] ?? null)
            ? $benchmarkContext['worker_runtime_after']
            : [];
        $workerRuntimeRequired = (bool)($before['required'] ?? false)
            || (bool)($after['required'] ?? false);
        $workerRuntimeStable = (bool)($before['captured'] ?? false)
            && (bool)($after['captured'] ?? false)
            && (bool)($before['healthy'] ?? false)
            && (bool)($after['healthy'] ?? false)
            && (int)($before['master_pid'] ?? 0) === (int)($after['master_pid'] ?? -1)
            && (array)($before['ready_fingerprint'] ?? []) === (array)($after['ready_fingerprint'] ?? []);
        $record(
            'worker_runtime_stability',
            $workerRuntimeRequired,
            $workerRuntimeStable,
            [
                'before_master_pid' => $before['master_pid'] ?? null,
                'after_master_pid' => $after['master_pid'] ?? null,
                'before_ready_workers' => $before['ready_workers'] ?? null,
                'after_ready_workers' => $after['ready_workers'] ?? null,
            ],
            'same_master_and_ready_worker_fingerprint',
            'worker_runtime_changed_or_not_ready'
        );
        $record(
            'fresh_worker_balance',
            (bool)($workerBalance['evaluated'] ?? false),
            ($workerBalance['balanced'] ?? false) === true,
            $workerBalance,
            $workerBalance['threshold'] ?? null,
            'fresh_worker_distribution_failed'
        );

        $actualMultiplexProtocolHits = (int)($protocolHits['2'] ?? 0)
            + (int)($protocolHits['3'] ?? 0);
        $multiplexEvaluated = (bool)($benchmarkContext['keep_alive'] ?? false)
            && (bool)($benchmarkContext['http_multiplex_requested'] ?? false)
            && $actualMultiplexProtocolHits > 0;
        $record(
            'http_multiplex',
            $multiplexEvaluated,
            (bool)($benchmarkContext['http_multiplex_enabled'] ?? false),
            (bool)($benchmarkContext['http_multiplex_enabled'] ?? false),
            true,
            'http_multiplex_not_enabled'
        );

        return [
            'passed' => $failureReasons === [],
            'checks' => $checks,
            'thresholds' => $thresholds,
            'failure_reasons' => \array_values(\array_unique($failureReasons)),
        ];
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
        array $cacheSources = [],
        array $requestLatencies = []
    ): void
    {
        $successCount = \count($results);
        $totalCompleted = $successCount + $errors;
        $latencySamples = !empty($requestLatencies) ? $requestLatencies : $results;
        
        if (!empty($latencySamples)) {
            \sort($latencySamples);
            
            $avgTime = \array_sum($latencySamples) / \count($latencySamples);
            $minTime = \min($latencySamples);
            $maxTime = \max($latencySamples);
            $medianTime = $latencySamples[(int)(\count($latencySamples) / 2)];
            $p95Index = \min((int)(\count($latencySamples) * 0.95), \count($latencySamples) - 1);
            $p99Index = \min((int)(\count($latencySamples) * 0.99), \count($latencySamples) - 1);
            $p95Time = $latencySamples[$p95Index];
            $p99Time = $latencySamples[$p99Index];
        } else {
            $avgTime = $minTime = $maxTime = $medianTime = $p95Time = $p99Time = 0;
        }
        
        $qps = $totalTime > 0 ? $totalCompleted / $totalTime : 0;
        $successQps = $totalTime > 0 ? $successCount / $totalTime : 0;
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
        $this->printer->note(__('完成 QPS：%{1}', [\round($qps, 2)]));
        if ($errors > 0) {
            $this->printer->note(__('成功 QPS：%{1}', [\round($successQps, 2)]));
        }
        $reuseRatio = $benchmarkContext['curl_connection_reuse_ratio'] ?? null;
        if ($reuseRatio !== null) {
            $this->printer->note(__('连接复用估算：新建连接 %{1}/可复用请求 %{2}，复用率 %{3}%', [
                (string)($benchmarkContext['curl_new_connections'] ?? 0),
                (string)($benchmarkContext['curl_connection_reuse_eligible'] ?? 0),
                \round(((float)$reuseRatio) * 100, 2),
            ]));
        }
        if (!empty($benchmarkContext['curl_tls_handshake_time_ms']['count'] ?? 0)) {
            $this->printer->note(__('TLS 握手样本：%{1} 次，P95 %{2}ms', [
                (string)$benchmarkContext['curl_tls_handshake_time_ms']['count'],
                (string)$benchmarkContext['curl_tls_handshake_time_ms']['p95'],
            ]));
        }
        $negotiatedHttpVersion = (string)($benchmarkContext['http_version_negotiated'] ?? '');
        if ($negotiatedHttpVersion !== '') {
            $this->printer->note(__('HTTP 实际协商：%{1}', [$negotiatedHttpVersion]));
        }
        $httpVersionHitSummary = [];
        foreach ((array)($benchmarkContext['http_version_hits'] ?? []) as $version => $count) {
            $httpVersionHitSummary[] = (string)$version . '=' . (string)$count;
        }
        if ($httpVersionHitSummary !== []) {
            $this->printer->note(__('HTTP 协议命中：%{1}', [\implode(', ', $httpVersionHitSummary)]));
        }
        
        echo "\n";
        $this->printer->setup(__('延迟统计（全部已完成请求，毫秒）'));
        echo "\n";
        $this->printer->note(__('平均：%{1}', [\round($avgTime, 3)]));
        $this->printer->note(__('最小：%{1}', [\round($minTime, 3)]));
        $this->printer->note(__('最大：%{1}', [\round($maxTime, 3)]));
        $this->printer->note(__('中位数：%{1}', [\round($medianTime, 3)]));
        $this->printer->note(__('P95：%{1}', [\round($p95Time, 3)]));
        $this->printer->note(__('P99：%{1}', [\round($p99Time, 3)]));
        $workerBalance = \is_array($benchmarkContext['worker_balance'] ?? null)
            ? $benchmarkContext['worker_balance']
            : $this->buildWorkerBalance($workerHits, $successCount, $workerBalanceThreshold, $benchmarkContext);
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
        }
        echo "\n";
        if (!(bool)($workerBalance['evaluated'] ?? false)) {
            $this->printer->note(__('持久连接会粘滞到已选 Worker；本次仅记录分布，不执行 fresh-connection 均衡门禁。'));
        } elseif (($workerBalance['balanced'] ?? false) === true) {
            $this->printer->success(__('分流均衡门禁：PASS（max/min=%{1}，阈值=%{2}）', [
                (string)($workerBalance['spread_ratio'] ?? 'n/a'),
                (string)($workerBalance['threshold'] ?? 'n/a'),
            ]));
        } else {
            $this->printer->error(__('分流均衡门禁：FAIL（预期 %{1}，命中 %{2}，缺失 %{3}，额外 %{4}，未归因成功请求 %{5}，max/min=%{6}）', [
                (string)($workerBalance['expected_workers'] ?? 0),
                (string)($workerBalance['observed_workers'] ?? 0),
                (string)($workerBalance['missing_workers'] ?? 0),
                (string)($workerBalance['extra_workers'] ?? 0),
                (string)($workerBalance['unattributed_successes'] ?? 0),
                (string)($workerBalance['spread_ratio'] ?? 'n/a'),
            ]));
        }

        $qualityGate = \is_array($benchmarkContext['quality_gate'] ?? null)
            ? $benchmarkContext['quality_gate']
            : $this->evaluateQualityGate(
                $results,
                $errors,
                $totalTime,
                $totalRequests,
                $requestLatencies,
                $workerBalance,
                $benchmarkContext
            );
        if ((bool)($qualityGate['passed'] ?? false)) {
            $this->printer->success(__('质量门禁：PASS'));
        } else {
            $this->printer->error(__('质量门禁：FAIL（%{1}）', [
                \implode(', ', (array)($qualityGate['failure_reasons'] ?? ['unknown'])),
            ]));
        }
        
        echo "\n";
        
        // 保存报告
        $tlsEvidenceIntegrationSha256 = '';
        $tlsEvidenceVerifierSha256 = '';
        try {
            $tlsEvidenceStore = new \Weline\Server\Service\Runtime\TlsSessionResumptionEvidenceStore();
            $tlsEvidenceIntegrationSha256 = $tlsEvidenceStore->integrationSha256();
            $tlsEvidenceVerifierSha256 = $tlsEvidenceStore->verifierSha256();
        } catch (\Throwable) {
            // Ordinary benchmarks remain usable when the optional evidence
            // binder is unavailable; evidence publication will fail closed.
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
            'reuse_profile' => $benchmarkContext['reuse_profile'] ?? null,
            'connection_share_enabled' => (bool)($benchmarkContext['connection_share_enabled'] ?? false),
            'ssl_session_share_supported' => (bool)($benchmarkContext['ssl_session_share_supported'] ?? false),
            'ssl_session_share_enabled' => (bool)($benchmarkContext['ssl_session_share_enabled'] ?? false),
            'curl_new_connections' => (int)($benchmarkContext['curl_new_connections'] ?? 0),
            'curl_connection_reuse_eligible' => (int)($benchmarkContext['curl_connection_reuse_eligible'] ?? 0),
            'curl_reused_request_estimate' => (int)($benchmarkContext['curl_reused_request_estimate'] ?? 0),
            'curl_connection_reuse_ratio' => $benchmarkContext['curl_connection_reuse_ratio'] ?? null,
            'curl_connect_time_ms' => (array)($benchmarkContext['curl_connect_time_ms'] ?? []),
            'curl_tls_appconnect_time_ms' => (array)($benchmarkContext['curl_tls_appconnect_time_ms'] ?? []),
            'curl_tls_handshake_time_ms' => (array)($benchmarkContext['curl_tls_handshake_time_ms'] ?? []),
            'curl_multiplex_option_enabled' => (bool)($benchmarkContext['curl_multiplex_option_enabled'] ?? false),
            'curl_pipewait_supported' => (bool)($benchmarkContext['curl_pipewait_supported'] ?? false),
            'curl_pipewait_enabled' => (bool)($benchmarkContext['curl_pipewait_enabled'] ?? false),
            'curl_max_concurrent_streams_supported' => (bool)($benchmarkContext['curl_max_concurrent_streams_supported'] ?? false),
            'http_multiplex_capability_verified' => (bool)($benchmarkContext['http_multiplex_capability_verified'] ?? false),
            'http_multiplex_requested' => (bool)($benchmarkContext['http_multiplex_requested'] ?? false),
            'http_multiplex_enabled' => (bool)($benchmarkContext['http_multiplex_enabled'] ?? false),
            'http_multiplex_observation' => (array)($benchmarkContext['http_multiplex_observation'] ?? []),
            'http_multiplex_max_concurrent_streams' => (int)($benchmarkContext['http_multiplex_max_concurrent_streams'] ?? 0),
            'multiplex_stream_limit' => (int)($benchmarkContext['multiplex_stream_limit'] ?? 0),
            'multiplex_ready_worker_target' => (int)($benchmarkContext['multiplex_ready_worker_target'] ?? 0),
            'physical_connections_requested' => $benchmarkContext['physical_connections_requested'] ?? null,
            'physical_connection_limit' => (int)($benchmarkContext['physical_connection_limit'] ?? 0),
            'physical_connections_observed' => (int)($benchmarkContext['physical_connections_observed'] ?? 0),
            'physical_connection_count_source' => $benchmarkContext['physical_connection_count_source'] ?? null,
            'physical_connection_lanes_requested' => $benchmarkContext['physical_connection_lanes_requested'] ?? null,
            'physical_connection_lanes_created' => (int)($benchmarkContext['physical_connection_lanes_created'] ?? 0),
            'physical_connection_lanes_actual' => (int)($benchmarkContext['physical_connection_lanes_actual'] ?? 0),
            'physical_connection_lane_new_connections' => (array)($benchmarkContext['physical_connection_lane_new_connections'] ?? []),
            'physical_connection_target_valid' => (bool)($benchmarkContext['physical_connection_target_valid'] ?? true),
            'benchmark_valid' => (bool)($benchmarkContext['benchmark_valid'] ?? true),
            'benchmark_invalid_reasons' => (array)($benchmarkContext['benchmark_invalid_reasons'] ?? []),
            'connection_model' => $benchmarkContext['connection_model'] ?? null,
            'http_version_requested' => $benchmarkContext['http_version_requested'] ?? null,
            'http_version_effective' => $benchmarkContext['http_version_effective'] ?? null,
            'http_version_auto_strategy' => $benchmarkContext['http_version_auto_strategy'] ?? null,
            'http_version_forced' => (bool)($benchmarkContext['http_version_forced'] ?? false),
            'http_version_negotiated' => $benchmarkContext['http_version_negotiated'] ?? null,
            'http_version_hits' => (array)($benchmarkContext['http_version_hits'] ?? []),
            'accept_encoding_requested' => (string)($benchmarkContext['accept_encoding_requested'] ?? 'identity'),
            'accept_encoding_curl' => (string)($benchmarkContext['accept_encoding_curl'] ?? 'identity'),
            'content_encoding_probe' => (array)($benchmarkContext['content_encoding_probe'] ?? []),
            'http_default_target' => $benchmarkContext['http_default_target'] ?? null,
            'http_default_effective' => $benchmarkContext['http_default_effective'] ?? null,
            'http_default_fallback' => (array)($benchmarkContext['http_default_fallback'] ?? []),
            'http3_data_plane_enabled' => (bool)($benchmarkContext['http3_data_plane_enabled'] ?? false),
            'http3_data_plane_reason' => $benchmarkContext['http3_data_plane_reason'] ?? null,
            'http_protocol_capabilities' => (array)($benchmarkContext['http_protocol_capabilities'] ?? []),
            'instance_name' => (string)($benchmarkContext['instance_name'] ?? ''),
            'instance' => (string)($benchmarkContext['instance_name'] ?? ''),
            'target_attribution' => (string)($benchmarkContext['target_attribution'] ?? 'unattributed'),
            'runtime_metadata_source' => $benchmarkContext['runtime_metadata_source'] ?? null,
            'endpoint_schema_version' => $benchmarkContext['endpoint_schema_version'] ?? null,
            'runtime_selection' => $benchmarkContext['runtime_selection'] ?? null,
            'worker_count' => (int)($benchmarkContext['worker_count'] ?? 0),
            'architecture' => $benchmarkContext['architecture'] ?? null,
            'arch' => $benchmarkContext['architecture'] ?? null,
            'php_version' => $benchmarkContext['php_version'] ?? null,
            'event_extension_version' => $benchmarkContext['event_extension_version'] ?? null,
            'policy_digest' => $benchmarkContext['policy_digest'] ?? null,
            'container_registry_digest' => $benchmarkContext['container_registry_digest'] ?? null,
            'tls_evidence_integration_sha256' => $tlsEvidenceIntegrationSha256,
            'tls_evidence_verifier_sha256' => $tlsEvidenceVerifierSha256,
            'success_count' => $successCount,
            'error_count' => $errors,
            'error_rate' => \round($errorRate, 2),
            'total_time_seconds' => \round($totalTime, 3),
            'qps' => \round($qps, 2),
            'success_qps' => \round($successQps, 2),
            'latency_scope' => 'all_completed_requests',
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
            'worker_runtime_before' => (array)($benchmarkContext['worker_runtime_before'] ?? []),
            'worker_runtime_after' => (array)($benchmarkContext['worker_runtime_after'] ?? []),
            'quality_gate' => $qualityGate,
            'cache_source' => $cacheSource !== '' ? $cacheSource : null,
            'cache_sources' => $cacheSources,
            'status_codes' => $statusCodes,
            'error_details' => $errorDetails,
            'benchmark_client' => (array)($benchmarkContext['benchmark_client'] ?? []),
        ];
        
        $reportDir = BP . 'var/log/wls';
        if (!\is_dir($reportDir)) {
            @\mkdir($reportDir, 0755, true);
        }
        $reportFile = $this->buildReportFilePath($reportDir, $targetUrl);
        $this->persistBenchmarkReport($reportFile, $report);
    }

    protected function persistBenchmarkReport(string $reportFile, array $report): bool
    {
        try {
            $json = \json_encode(
                $this->normalizeBenchmarkReportValue($report),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            $this->printer->warning(__('报告 JSON 编码失败，未保存：%{1}', [$exception->getMessage()]));
            return false;
        }

        if ($json === '') {
            $this->printer->warning(__('报告 JSON 内容为空，未保存：%{1}', [$reportFile]));
            return false;
        }

        $reportDir = \dirname($reportFile);
        if (!\is_dir($reportDir) && !@\mkdir($reportDir, 0755, true) && !\is_dir($reportDir)) {
            $this->printer->warning(__('报告目录创建失败，未保存：%{1}', [$reportDir]));
            return false;
        }

        $tmpFile = $reportFile . '.tmp.' . (string)\getmypid() . '.' . \bin2hex(\random_bytes(4));
        $bytes = @\file_put_contents($tmpFile, $json, LOCK_EX);
        if ($bytes === false || $bytes <= 0) {
            @\unlink($tmpFile);
            $this->printer->warning(__('报告写入失败，未保存：%{1}', [$reportFile]));
            return false;
        }

        if (!@\rename($tmpFile, $reportFile)) {
            @\unlink($tmpFile);
            $this->printer->warning(__('报告发布失败，未保存：%{1}', [$reportFile]));
            return false;
        }

        $this->printer->note(__('报告已保存：%{1}', [$reportFile]));
        return true;
    }

    protected function normalizeBenchmarkReportValue(mixed $value): mixed
    {
        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalizeBenchmarkReportValue($item);
            }
            return $value;
        }

        if (\is_float($value) && (!\is_finite($value) || \is_nan($value))) {
            return null;
        }

        return $value;
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
                '--instance <name>' => __('精确指定运行中的 WLS 实例，并归因当前 endpoint schema 运行时元数据'),
                '-p, --port <port>' => __('指定端口（可选，默认自动探测）'),
                '--host <ip>' => __('指定主机（可选，默认 127.0.0.1；-h 保留给全局帮助）'),
                '-s, --ssl' => __('指定端口为 HTTPS（与 -p 合用；自动探测时根据实例配置）'),
                '--tls-version <auto|1.2|1.3>' => __('强制 HTTPS 压测使用指定 TLS 版本（默认 auto）'),
                '--http-version <auto|1.1|2|3>' => __('强制压测请求使用指定 HTTP 版本；auto 在 HTTPS QUIC 数据面就绪时使用 HTTP/3，否则默认 HTTP/2 并自动回退 HTTP/1.1'),
                '--physical-connections <n>' => __('HTTP/2/3 物理连接目标；默认按 READY Worker 数和服务端 Stream 容量自动计算，设为 1 可测单连接多路复用'),
                '--accept-encoding <auto|br,gzip|gzip|identity>' => __('请求内容编码；默认 identity 保持旧基线，auto 模拟浏览器并启用 cURL 支持的全部压缩'),
                '--no-keepalive, --spread' => __('禁用 keep-alive/连接复用（更利于验证连接级分流；HTTPS 时亦是 fresh TLS）'),
                '--worker-header <name>' => __('命中 Worker 统计使用的响应头（逗号分隔；默认自动探测 X-WLS-Worker-PID/Id/Port）'),
                '--worker-balance-threshold <ratio>' => __('fresh-connection 分流 max/min 硬门禁（默认 1.5）'),
                '--min-success-qps <n>' => __('成功 QPS 下限；0 表示不设置性能下限（--min-qps 为别名）'),
                '--max-error-rate <percent>' => __('错误率上限百分比（默认 0）'),
                '--max-p95-ms <ms>' => __('全部完成请求 P95 上限；0 表示禁用'),
                '--max-tls-p95-ms <ms>' => __('TLS 握手 P95 上限；0 表示禁用，启用后无握手样本亦失败'),
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('基本压测（自动探测）') => 'php bin/w server:benchmark',
                __('指定 WLS 实例') => 'php bin/w server:benchmark --instance api-server',
                __('浏览器压缩首页') => 'php bin/w server:benchmark --instance api-server --path / --accept-encoding auto',
                __('高并发') => 'php bin/w server:benchmark -c 500 -n 50000',
                __('分流验证（禁用 keep-alive）') => 'php bin/w server:benchmark -c 500 -n 50000 --no-keepalive',
                __('统计 Worker 分布') => 'php bin/w server:benchmark -p 9503 --ssl --path /_wls/health --worker-header X-WLS-Worker-Port',
                __('分流倾斜阈值检查') => 'php bin/w server:benchmark -p 9503 --ssl --path /_wls/health --worker-balance-threshold 1.3',
                __('指定端口') => 'php bin/w server:benchmark -p 9000',
                __('指定 HTTPS 端口') => 'php bin/w server:benchmark -p 9443 --ssl',
                __('HTTP/2 协商验证') => 'php bin/w server:benchmark -p 9443 --ssl --http-version 2',
                __('HTTP/2 单物理连接多路复用') => 'php bin/w server:benchmark -p 9443 --ssl --http-version 2 --physical-connections 1',
                __('HTTP/3 协商验证') => 'php bin/w server:benchmark -p 9443 --ssl --http-version 3 --accept-encoding auto',
                __('TLS 1.3 fresh connection') => 'php bin/w server:benchmark -p 9443 --ssl --tls-version 1.3 --no-keepalive',
            ]
        );
    }

    /**
     * @return array{requested:string,curl:string}
     */
    private function normalizeAcceptEncoding(mixed $value): array
    {
        $normalized = \strtolower((string)$value);
        $normalized = \preg_replace('/\s+/', '', $normalized) ?? '';
        if ($normalized === '') {
            $normalized = 'identity';
        }

        return match ($normalized) {
            'auto' => ['requested' => 'auto', 'curl' => ''],
            'identity' => ['requested' => 'identity', 'curl' => 'identity'],
            'gzip' => ['requested' => 'gzip', 'curl' => 'gzip'],
            'br,gzip', 'gzip,br' => ['requested' => 'br,gzip', 'curl' => 'br,gzip'],
            default => throw new \InvalidArgumentException((string)__(
                '--accept-encoding 仅允许：auto、br,gzip、gzip、identity。'
            )),
        };
    }

    /**
     * Probe one real representation before the measured run. This warms the
     * selected FPC encoding variant and records wire bytes without pretending
     * that cURL's decoded body length is network transfer size.
     *
     * @param array{requested:string,curl:string} $acceptEncoding
     * @return array<string,mixed>
     */
    private function probeContentEncoding(
        string $url,
        bool $ssl,
        string $tlsVersion,
        string $httpVersion,
        array $acceptEncoding,
        array $benchmarkContext,
    ): array {
        if (!\function_exists('curl_init')) {
            return ['error' => (string)__('当前 PHP 未安装 curl 扩展。')];
        }

        $headers = [];
        $handle = \curl_init($url);
        if ($handle === false) {
            return ['error' => (string)__('无法初始化内容编码 cURL 探针。')];
        }

        $options = [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT => 15,
            \CURLOPT_CONNECTTIMEOUT => 5,
            \CURLOPT_HTTP_VERSION => $this->curlHttpVersionOption($httpVersion),
            \CURLOPT_ENCODING => $acceptEncoding['curl'],
            \CURLOPT_USERAGENT => 'Weline-Server-Benchmark-Encoding-Probe/1.0',
            \CURLOPT_HTTPHEADER => ['X-WLS-Benchmark-Worker: 1'],
            \CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $length = \strlen($line);
                $trimmed = \trim($line);
                if ($trimmed === '') {
                    return $length;
                }
                if (\str_starts_with(\strtoupper($trimmed), 'HTTP/')) {
                    $headers = [];
                    return $length;
                }
                $separator = \strpos($trimmed, ':');
                if ($separator !== false) {
                    $name = \strtolower(\trim(\substr($trimmed, 0, $separator)));
                    $value = \trim(\substr($trimmed, $separator + 1));
                    if ($name !== '') {
                        $headers[$name] = isset($headers[$name])
                            ? $headers[$name] . ', ' . $value
                            : $value;
                    }
                }
                return $length;
            },
        ];
        if ($ssl) {
            $options[\CURLOPT_SSL_VERIFYPEER] = false;
            $options[\CURLOPT_SSL_VERIFYHOST] = 0;
            if ($tlsVersion === '1.3' && \defined('CURL_SSLVERSION_TLSv1_3')) {
                $sslVersion = \CURL_SSLVERSION_TLSv1_3;
                if (\defined('CURL_SSLVERSION_MAX_TLSv1_3')) {
                    $sslVersion |= \CURL_SSLVERSION_MAX_TLSv1_3;
                }
                $options[\CURLOPT_SSLVERSION] = $sslVersion;
            } elseif ($tlsVersion === '1.2' && \defined('CURL_SSLVERSION_TLSv1_2')) {
                $sslVersion = \CURL_SSLVERSION_TLSv1_2;
                if (\defined('CURL_SSLVERSION_MAX_TLSv1_2')) {
                    $sslVersion |= \CURL_SSLVERSION_MAX_TLSv1_2;
                }
                $options[\CURLOPT_SSLVERSION] = $sslVersion;
            }
        }

        $options = $this->applyBenchmarkEndpointCurlOptions(
            $options,
            $url,
            $ssl,
            $benchmarkContext,
        );
        \curl_setopt_array($handle, $options);
        $body = \curl_exec($handle);
        if ($body === false) {
            $error = \curl_error($handle);
            \curl_close($handle);
            return [
                'request_accept_encoding' => $acceptEncoding['requested'],
                'error' => $error !== '' ? $error : (string)__('内容编码探针失败。'),
            ];
        }

        $wireBytes = \defined('CURLINFO_SIZE_DOWNLOAD_T')
            ? (int)\curl_getinfo($handle, \CURLINFO_SIZE_DOWNLOAD_T)
            : (int)\round((float)\curl_getinfo($handle, \CURLINFO_SIZE_DOWNLOAD));
        $status = (int)\curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        $httpVersionId = \defined('CURLINFO_HTTP_VERSION')
            ? (int)\curl_getinfo($handle, \CURLINFO_HTTP_VERSION)
            : null;
        \curl_close($handle);

        $logicalBytes = \strlen((string)$body);
        $contentLength = isset($headers['content-length']) && \ctype_digit($headers['content-length'])
            ? (int)$headers['content-length']
            : null;

        return [
            'request_accept_encoding' => $acceptEncoding['requested'],
            'content_encoding' => \strtolower((string)($headers['content-encoding'] ?? 'identity')),
            'vary' => (string)($headers['vary'] ?? ''),
            'content_length_header' => $contentLength,
            'wire_body_bytes' => $wireBytes,
            'logical_body_bytes' => $logicalBytes,
            'wire_to_logical_ratio' => $logicalBytes > 0 ? \round($wireBytes / $logicalBytes, 6) : null,
            'http_status' => $status,
            'curl_http_version_id' => $httpVersionId,
            'measurement' => 'single_unmeasured_warmup_probe',
        ];
    }

    /**
     * Apply transport-only options for a locally managed WLS endpoint.
     *
     * The URL authority remains unchanged so TLS SNI and HTTP Host keep using the
     * public instance name. Only the TCP destination is resolved to the local bind
     * address. Unattributed non-loopback targets retain the caller's proxy policy.
     *
     * @param array<int, mixed> $options
     * @param array<string, mixed> $benchmarkContext
     * @return array<int, mixed>
     */
    private function applyBenchmarkEndpointCurlOptions(
        array $options,
        string $url,
        bool $ssl,
        array $benchmarkContext,
    ): array {
        $connectHost = \trim((string)($benchmarkContext['connect_host'] ?? ''));
        $authorityHost = \trim((string)(\parse_url($url, PHP_URL_HOST) ?? ''));
        $targetPort = (int)(\parse_url($url, PHP_URL_PORT) ?? ($ssl ? 443 : 80));
        $targetAttribution = (string)($benchmarkContext['target_attribution'] ?? '');
        $managedLocalTarget = $this->isLoopbackHost($connectHost)
            || \in_array($targetAttribution, [
                'explicit_instance',
                'single_running_instance',
                'unique_live_endpoint_match',
            ], true);

        if (!$managedLocalTarget) {
            return $options;
        }

        $options[\CURLOPT_NOPROXY] = '*';
        $options[\CURLOPT_PROXY] = '';

        if ($connectHost !== ''
            && $authorityHost !== ''
            && \strcasecmp(\trim($connectHost, '[]'), \trim($authorityHost, '[]')) !== 0
        ) {
            $resolveAddress = \trim($connectHost, '[]');
            if (\str_contains($resolveAddress, ':')) {
                $resolveAddress = '[' . $resolveAddress . ']';
            }
            $options[\CURLOPT_RESOLVE] = [
                \trim($authorityHost, '[]') . ':' . $targetPort . ':' . $resolveAddress,
            ];
        }

        return $options;
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
        string $httpVersion,
    ): array
    {
        $runtime = isset($serverConfig['runtime_metadata']) && \is_array($serverConfig['runtime_metadata'])
            ? $serverConfig['runtime_metadata']
            : [];
        $runtimeSelection = \is_array($runtime['runtime_selection'] ?? null)
            ? RuntimeSelection::fromArray($runtime['runtime_selection'])->toArray()
            : null;
        $curl = \function_exists('curl_version') ? (array)\curl_version() : [];
        $httpProtocolCapabilities = (new HttpProtocolCapabilityProbe())->snapshot();
        $httpDefaultPolicy = \is_array($httpProtocolCapabilities['default_policy'] ?? null)
            ? $httpProtocolCapabilities['default_policy']
            : [];
        $wlsAdapters = \is_array($httpProtocolCapabilities['wls_adapters'] ?? null)
            ? $httpProtocolCapabilities['wls_adapters']
            : [];
        $http2Runtime = \is_array($wlsAdapters['http2'] ?? null) ? $wlsAdapters['http2'] : [];
        $clientMultiplexOptionEnabled = \defined('CURLPIPE_MULTIPLEX');
        $multiplexMaxConcurrentStreams = (int)($http2Runtime['max_concurrent_streams'] ?? 0);
        $multiplexVerified = $clientMultiplexOptionEnabled
            && (bool)($http2Runtime['multiplexing_verified'] ?? false)
            && $multiplexMaxConcurrentStreams > 1;

        return [
            'requested_requests' => $totalRequests,
            'concurrency' => $concurrency,
            'active_connections' => \min($concurrency, $totalRequests),
            'keep_alive' => !$noKeepAlive,
            'fresh_connection' => $noKeepAlive,
            'fresh_tls' => $ssl && $noKeepAlive,
            'tls_version' => $ssl ? $tlsVersion : null,
            'reuse_profile' => $noKeepAlive
                ? ($ssl ? 'fresh-tls-full-handshake' : 'fresh-connection')
                : ($ssl
                    ? (($multiplexVerified ? 'http2-multiplex+' : '') . 'keep-alive+tls-connection-reuse')
                    : 'keep-alive+http-connection-reuse'),
            'connection_share_enabled' => !$noKeepAlive,
            'ssl_session_share_supported' => \defined('CURL_LOCK_DATA_SSL_SESSION'),
            'ssl_session_share_enabled' => $ssl && !$noKeepAlive && \defined('CURL_LOCK_DATA_SSL_SESSION'),
            'curl_multiplex_option_enabled' => $clientMultiplexOptionEnabled,
            'curl_pipewait_supported' => \defined('CURLOPT_PIPEWAIT'),
            'curl_pipewait_enabled' => false,
            'curl_max_concurrent_streams_supported' => \defined('CURLMOPT_MAX_CONCURRENT_STREAMS'),
            'http_multiplex_capability_verified' => $multiplexVerified,
            'http_multiplex_requested' => false,
            'http_multiplex_enabled' => false,
            'http_multiplex_max_concurrent_streams' => $multiplexMaxConcurrentStreams,
            'multiplex_stream_limit' => 0,
            'multiplex_ready_worker_target' => 0,
            'physical_connections_requested' => null,
            'physical_connection_limit' => $concurrency,
            'physical_connections_observed' => 0,
            'physical_connection_count_source' => null,
            'connection_model' => $noKeepAlive ? 'fresh-connection-per-request' : 'parallel-keepalive-connections',
            'http_version_requested' => $httpVersion,
            'http_version_effective' => $httpVersion,
            'http_version_forced' => $httpVersion !== 'auto',
            'http_version_negotiated' => null,
            'http_version_hits' => [],
            'http_default_target' => (string)($httpDefaultPolicy['target_preferred'] ?? 'http/2'),
            'http_default_effective' => (string)($httpDefaultPolicy['effective_preferred'] ?? 'http/1.1'),
            'http_default_fallback' => (array)($httpDefaultPolicy['fallback'] ?? ['http/1.1']),
            'http3_data_plane_enabled' => (bool)($wlsAdapters['http3']['enabled'] ?? false),
            'http3_data_plane_reason' => (string)($wlsAdapters['http3']['reason'] ?? ''),
            'http_protocol_capabilities' => $httpProtocolCapabilities,
            'instance_name' => (string)($serverConfig['instance'] ?? ''),
            'target_attribution' => (string)($serverConfig['target_attribution'] ?? 'unattributed'),
            'connect_host' => (string)($serverConfig['host'] ?? ''),
            'authority_host' => (string)($serverConfig['authority_host'] ?? $serverConfig['host'] ?? ''),
            'runtime_metadata_source' => $runtime['metadata_source'] ?? null,
            'endpoint_schema_version' => $runtime['endpoint_schema_version'] ?? null,
            'runtime_selection' => $runtimeSelection,
            'worker_count' => (int)($serverConfig['worker_count'] ?? 0),
            'architecture' => $runtime['architecture'] ?? null,
            'php_version' => $runtime['php_version'] ?? null,
            'event_extension_version' => $runtime['event_extension_version'] ?? null,
            'policy_digest' => $runtime['policy_digest'] ?? null,
            'container_registry_digest' => $runtime['container_registry_digest'] ?? null,
            'benchmark_client' => [
                'os' => \PHP_OS_FAMILY,
                'architecture' => (string)\php_uname('m'),
                'php_version' => \PHP_VERSION,
                'event_extension_loaded' => \extension_loaded('event'),
                'event_extension_version' => \extension_loaded('event') ? (\phpversion('event') ?: null) : null,
                'curl_version' => $curl['version'] ?? null,
                'ssl_version' => $curl['ssl_version'] ?? null,
                'http2_supported' => (bool)($httpProtocolCapabilities['curl_client']['http2_constant'] ?? false),
                'http3_supported' => (bool)($httpProtocolCapabilities['curl_client']['http3_constant'] ?? false),
                'ssl_session_share_supported' => \defined('CURL_LOCK_DATA_SSL_SESSION'),
                'http_multiplex_supported' => \defined('CURLPIPE_MULTIPLEX'),
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

    private function normalizeHttpVersion(mixed $value): string
    {
        $value = \strtolower(\trim((string)$value));
        $value = \str_replace(['http/', 'http', 'h', '_'], ['', '', '', '.'], $value);
        $value = \trim($value, '.');
        if ($value === '' || $value === 'auto') {
            return 'auto';
        }
        if (\in_array($value, ['1', '1.1', '11'], true)) {
            return '1.1';
        }
        if (\in_array($value, ['2', '2.0', '20'], true)) {
            return '2';
        }
        if (\in_array($value, ['3', '3.0', '30'], true)) {
            return '3';
        }

        throw new \InvalidArgumentException('--http-version must be auto, 1.1, 2, or 3.');
    }

    /** @param array<string,mixed> $capabilities */
    private function assertRequestedHttpVersionIsRunnable(string $requested, bool $ssl, array $capabilities): void
    {
        if ($requested === 'auto' || $requested === '1.1') {
            return;
        }

        $edge = \is_array($capabilities['edge'] ?? null) ? $capabilities['edge'] : [];
        $edgeName = (string)($edge['adapter'] ?? ($capabilities['default_policy']['edge_adapter'] ?? ''));
        if ($edgeName === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX
            && \in_array($requested, ['2', '3'], true)
        ) {
            throw new \RuntimeException(
                'wls.edge.adapter=nginx：请对 Nginx 边缘压测 HTTP/' . $requested
                . '，或设置 wls.edge.adapter=wls 后再对 WLS 端口使用 --http-version '
                . $requested . '。'
            );
        }

        if ($requested !== '3') {
            return;
        }
        if (!$ssl) {
            throw new \RuntimeException('HTTP/3 requires HTTPS/QUIC; use --ssl or target an HTTPS WLS instance.');
        }

        $curl = (array)($capabilities['curl_client'] ?? []);
        $adapters = (array)($capabilities['wls_adapters'] ?? []);
        $curlHttp3 = (bool)($curl['http3_constant'] ?? false) && (bool)($curl['http3_feature'] ?? false);
        if (!$curlHttp3) {
            throw new \RuntimeException('The current PHP cURL/libcurl build cannot negotiate HTTP/3. Use --http-version auto to fall back to HTTP/2/1.1.');
        }

        $serverHttp3 = (bool)($adapters['http3']['enabled'] ?? false);
        if (!$serverHttp3) {
            $reason = (string)($adapters['http3']['reason'] ?? 'WLS HTTP/3 data plane is unavailable.');
            throw new \RuntimeException('WLS cannot serve HTTP/3 on this instance yet: ' . $reason . ' Use --http-version auto for HTTP/2 fallback.');
        }
    }

    /** @param array<string,mixed> $capabilities */
    private function resolveEffectiveHttpVersion(string $requested, bool $ssl, array $capabilities): string
    {
        if ($requested !== 'auto') {
            return $requested;
        }

        $curl = (array)($capabilities['curl_client'] ?? []);
        $adapters = (array)($capabilities['wls_adapters'] ?? []);

        $curlHttp3 = (bool)($curl['http3_only_constant'] ?? false)
            && (bool)($curl['http3_feature'] ?? false);
        $serverHttp3 = (bool)($adapters['http3']['enabled'] ?? false)
            && (bool)($adapters['http3']['runtime_verified'] ?? false);
        if ($ssl && $curlHttp3 && $serverHttp3) {
            return '3';
        }

        $curlHttp2 = (bool)($curl['http2_constant'] ?? false) && (bool)($curl['http2_feature'] ?? false);
        $serverHttp2 = (bool)($adapters['http2']['enabled'] ?? false);
        if ($curlHttp2 && $serverHttp2) {
            return '2';
        }

        return '1.1';
    }


    private function curlHttpVersionOption(string $httpVersion): int
    {
        return match ($httpVersion) {
            'auto' => \defined('CURL_HTTP_VERSION_NONE') ? (int)\constant('CURL_HTTP_VERSION_NONE') : 0,
            '1.1' => CURL_HTTP_VERSION_1_1,
            '2' => $this->requireCurlHttpVersionConstant('CURL_HTTP_VERSION_2_0', 'HTTP/2'),
            // A protocol verification run must never silently count HTTP/2
            // fallback as HTTP/3 success. CURL_HTTP_VERSION_3 permits fallback;
            // 3ONLY makes the benchmark an exact transport assertion.
            '3' => $this->requireCurlHttpVersionConstant('CURL_HTTP_VERSION_3ONLY', 'HTTP/3-only'),
            default => throw new \InvalidArgumentException('--http-version must be auto, 1.1, 2, or 3.'),
        };
    }

    private function requireCurlHttpVersionConstant(string $constant, string $label): int
    {
        if (!\defined($constant)) {
            throw new \RuntimeException('The current PHP cURL/libcurl build cannot request ' . $label . '.');
        }

        return (int)\constant($constant);
    }

    private function curlHttpVersionName(int $curlInfoVersion): string
    {
        $known = [
            \defined('CURL_HTTP_VERSION_1_0') ? (int)\constant('CURL_HTTP_VERSION_1_0') : -10 => '1.0',
            \defined('CURL_HTTP_VERSION_1_1') ? (int)\constant('CURL_HTTP_VERSION_1_1') : -11 => '1.1',
            \defined('CURL_HTTP_VERSION_2_0') ? (int)\constant('CURL_HTTP_VERSION_2_0') : -20 => '2',
            \defined('CURL_HTTP_VERSION_3') ? (int)\constant('CURL_HTTP_VERSION_3') : -30 => '3',
        ];

        return $known[$curlInfoVersion] ?? ('unknown:' . $curlInfoVersion);
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
