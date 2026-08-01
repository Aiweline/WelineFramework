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
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayStartupDecision;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;
use Weline\Server\Service\Runtime\HttpProtocolCapabilityProbe;
use Weline\Server\Service\Runtime\HttpProtocolSelection;
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

        $serverConfig['worker_count'] = $this->resolveRuntimeWorkerCount($serverConfig);
        
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
        // keep-alive 会让 Nginx 回源连接粘滞到已选 Direct Worker；验证 Nginx→Worker 连接级分流时可禁用复用
        $noKeepAlive = isset($args['no-keepalive']) || isset($args['no_keepalive']) || isset($args['spread']);
        $expectWorkerRuntimeChange = isset($args['expect-worker-runtime-change'])
            || isset($args['expect_worker_runtime_change'])
            || isset($args['expect-reload'])
            || isset($args['expect_reload']);
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
        $benchmarkContext['worker_runtime_expectation'] =
            $expectWorkerRuntimeChange ? 'changed' : 'stable';
        $benchmarkContext['quality_gate_thresholds'] = [
            'min_success_qps' => $minSuccessQps,
            'max_error_rate_percent' => $maxErrorRate,
            'max_p95_ms' => $maxP95Ms,
            'max_tls_handshake_p95_ms' => $maxTlsP95Ms,
            'worker_balance_max_min_ratio' => $workerBalanceThreshold,
        ];
        try {
            $effectiveHttpVersion = $this->resolveEffectiveHttpVersion(
                $httpVersion,
                $ssl,
                (array)($benchmarkContext['http_protocol_capabilities'] ?? []),
                (string)($benchmarkContext['benchmark_target_surface'] ?? 'unattributed_endpoint'),
            );
        } catch (\Throwable $exception) {
            $this->printer->error($exception->getMessage());
            return 1;
        }
        $benchmarkContext['http_version_effective'] = $effectiveHttpVersion;
        $benchmarkTargetSurface = (string)($benchmarkContext['benchmark_target_surface'] ?? '');
        $benchmarkContext['http_version_auto_strategy'] = $httpVersion !== 'auto'
            ? 'explicit'
            : match ($benchmarkTargetSurface) {
                'public_edge' => 'select only a Managed Nginx protocol with live runtime verification, then require the observed response version to match',
                'wls_endpoint' => (string)($serverConfig['edge_adapter'] ?? '') === 'wls'
                    ? 'select verified in-process pure WLS HTTP/2, then fall back to HTTP/1.1'
                    : 'use HTTP/1.1 for the explicitly selected internal Nginx backend',
                'public_edge_unbound', 'wls_endpoint_unbound', 'attributed_endpoint_unbound'
                    => 'fail closed because the attributed endpoint policy or owner binding is incomplete',
                default => 'unattributed endpoint: delegate negotiation to libcurl and report the observed version without Managed Nginx attribution',
            };
        if ($physicalConnections !== null && !\in_array($effectiveHttpVersion, ['2', '3'], true)) {
            $this->printer->error(__('--physical-connections 仅用于 HTTP/2 或 HTTP/3 多路复用压测。'));
            return 1;
        }
        $benchmarkContext['physical_connections_requested'] = $physicalConnections;
        try {
            $this->assertRequestedHttpVersionIsRunnable(
                $httpVersion,
                $ssl,
                (array)($benchmarkContext['http_protocol_capabilities'] ?? []),
                (string)($benchmarkContext['benchmark_target_surface'] ?? 'unattributed_endpoint'),
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
        if ((bool)($benchmarkContext['managed_nginx_generation_required'] ?? false)
            && !(bool)($benchmarkContext['content_encoding_probe']['nginx_generation_verified'] ?? false)
        ) {
            $this->printer->error(__('Managed Nginx 配置 generation 探针不匹配，已拒绝开始压测。'));
            return 1;
        }

        
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

        // A manually selected port is always an authorized benchmark target.
        // Runtime metadata is attributed only after a unique Managed Nginx
        // public-edge or explicit internal-backend identity match.
        if (isset($args['port']) || isset($args['p'])) {
            return $this->resolveManualPortTarget($manager, $runningInstances, $args);
        }

        if (\count($runningInstances) === 1) {
            $name = (string)\array_key_first($runningInstances);
            $info = $manager->getInstanceInfoWithIpcTimeout($name, false, 0.5);
            $target = $runningInstances[$name];
            if ($info === null
                || !$this->ensureBenchmarkInstanceReady($info, $name, $target)
            ) {
                return null;
            }
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

        $this->printer->error(__('未检测到通过 Managed Nginx owner 与公网端口身份门禁的运行实例。'));
        $this->printer->note(__('请先完成 WLS 与 Managed Nginx 启动；如只测内部回源，必须显式使用 -p <WLS端口>。'));
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
        $target = $this->buildInstanceTarget($instanceName, $raw);
        if ($target === null) {
            $this->printer->error(__(
                '实例 [%{1}] 没有通过其公网入口身份与协议策略门禁。',
                [$instanceName],
            ));
            return null;
        }
        if (!$this->ensureBenchmarkInstanceReady($info, $instanceName, $target)) {
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
     * @param array<string,mixed> $target
     */
    private function ensureBenchmarkInstanceReady(
        ServerInstanceInfo $info,
        string $instanceName,
        array $target = [],
    ): bool
    {
        if (!$info->isMasterRunning()) {
            $this->printer->error(__('实例 [%{1}] 未运行，已拒绝将端口占用者归因为该实例。', [$instanceName]));
            return false;
        }

        if ((string)($target['benchmark_carrier_role'] ?? '')
            === ControlMessage::ROLE_GATEWAY_BACKEND
        ) {
            $expected = \max(
                1,
                (int)($target['benchmark_expected_carriers'] ?? $info->workerCount),
            );
            $running = 0;
            foreach ($info->getServicesByRole(ControlMessage::ROLE_GATEWAY_BACKEND) as $service) {
                // getInstanceInfoWithIpcTimeout() is a fresh authenticated
                // Master snapshot. Gateway backend children intentionally
                // replace argv[0] with a per-slot process title, so the generic
                // CLI managed-process-name probe cannot be used as identity
                // evidence here. READY + current IPC client + PID is the
                // authoritative project-side lease; buildInstanceTarget()
                // separately proves the public route end to end.
                if ($service->state === ServiceInstance::STATE_READY
                    && $service->pid > 0
                    && $service->ipcClientId !== null
                ) {
                    $running++;
                }
            }
            if ($running < $expected) {
                $this->printer->error(__(
                    '实例 [%{1}] 未达到网关压测就绪状态：运行 Gateway Backend %{2}/%{3}。',
                    [$instanceName, $running, $expected],
                ));
                $this->printer->note(__(
                    '请先恢复项目网关后端池，再重新压测；可查看：php bin/w server:status %{1}',
                    [$instanceName],
                ));
                return false;
            }

            return true;
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

        // Direct/shared-FD exposes one backend endpoint for all Workers. A rolling
        // surge or stale instance record may keep stopped historical Worker rows
        // around, and old launches can miss managed-process identity checks even
        // while the backend endpoint is healthy. Prefer exact Worker process
        // evidence; fall back to the backend health endpoint before refusing a
        // benchmark run.
        if ($runningWorkerCount < $expectedWorkerCount) {
            if ($this->probeBenchmarkHealthEndpoint($info)) {
                $this->printer->note(__('实例 [%{1}] 的 WLS health endpoint 已健康；Worker 进程索引为 %{2}/%{3}，公网压测仍需通过当前边缘身份门禁。', [
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

    /**
     * Dynamic scale is a runtime-only control-plane operation. The persisted
     * endpoint remains restart configuration, so benchmark attribution must
     * resolve the current desired Worker count from authoritative IPC state.
     *
     * @param array<string,mixed> $serverConfig
     */
    protected function resolveRuntimeWorkerCount(array $serverConfig): int
    {
        if ((string)($serverConfig['benchmark_carrier_role'] ?? '')
            === ControlMessage::ROLE_GATEWAY_BACKEND
        ) {
            return \max(
                1,
                (int)($serverConfig['benchmark_expected_carriers']
                    ?? $serverConfig['worker_count']
                    ?? 1),
            );
        }
        $configuredWorkers = \max(0, (int)($serverConfig['worker_count'] ?? 0));
        $instanceName = \trim((string)($serverConfig['instance'] ?? ''));
        if ($instanceName === '') {
            return $configuredWorkers;
        }

        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        $info = $manager->getInstanceInfoWithIpcTimeout($instanceName, false, 0.5);
        if ($info === null) {
            return $configuredWorkers;
        }

        return $this->selectRuntimeWorkerCount(
            $configuredWorkers,
            (int)$info->workerCount,
            $manager->getRuntimeStatsForInstance($info, true),
        );
    }

    /**
     * @param array<string,mixed> $runtimeStats
     */
    protected function selectRuntimeWorkerCount(
        int $configuredWorkers,
        int $persistedWorkers,
        array $runtimeStats,
    ): int {
        $desiredWorkers = (int)($runtimeStats['desired_workers'] ?? 0);
        if ($desiredWorkers > 0) {
            return $desiredWorkers;
        }

        $readyWorkers = (int)($runtimeStats['workers'] ?? 0);
        if ($readyWorkers > 0) {
            return $readyWorkers;
        }

        if ($configuredWorkers > 0) {
            return $configuredWorkers;
        }

        return \max(1, $persistedWorkers);
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
        try {
            $authorityHost = $this->normalizeExplicitAuthorityHost(
                $args['authority-host'] ?? $args['authority_host'] ?? null,
            );
        } catch (\InvalidArgumentException $exception) {
            $this->printer->error($exception->getMessage());
            return null;
        }
        $sslRequested = isset($args['ssl']) || isset($args['s']);
        $publicMatches = [];
        $backendMatches = [];
        foreach ($runningInstances as $name => $target) {
            $managed = \is_array($target['managed_nginx'] ?? null) ? $target['managed_nginx'] : [];
            foreach ([
                [
                    'port' => (bool)($target['ssl'] ?? false) ? (int)($managed['listen_https'] ?? 0) : 0,
                    'ssl' => true,
                ],
                ['port' => (int)($managed['listen_http'] ?? 0), 'ssl' => false],
            ] as $publicEndpoint) {
                if ($publicEndpoint['port'] !== $port
                    || ($sslRequested && !$publicEndpoint['ssl'])
                    || !$this->endpointHostMatchesTarget((string)($target['host'] ?? ''), $host)
                ) {
                    continue;
                }
                $candidate = $target;
                $candidate['host'] = $host;
                $candidate['port'] = $port;
                $candidate['ssl'] = $publicEndpoint['ssl'];
                $candidate['target_surface'] = 'public_edge';
                $candidate['target_endpoint_role'] = 'managed_nginx_public';
                $publicMatches[$name] = $candidate;
                break;
            }

            $backend = \is_array($target['wls_backend'] ?? null) ? $target['wls_backend'] : [];
            if ((int)($backend['port'] ?? 0) === $port
                && $this->endpointHostMatchesTarget((string)($backend['host'] ?? ''), $host)
                && !$sslRequested
                && !(bool)($backend['ssl'] ?? false)
            ) {
                $candidate = $target;
                $candidate['host'] = $host;
                $candidate['port'] = $port;
                $candidate['ssl'] = false;
                $candidate['target_surface'] = 'wls_endpoint';
                $candidate['target_endpoint_role'] = 'internal_nginx_backend';
                $backendMatches[$name] = $candidate;
            }
        }
        if ($backendMatches === []) {
            foreach ($manager->listPersistedInstanceNames() as $persistedName) {
                $persistedName = (string)$persistedName;
                $endpoint = $manager->getRawInstanceData($persistedName);
                try {
                    $info = $manager->getInstanceInfoWithIpcTimeout($persistedName, false, 0.0);
                } catch (\RuntimeException) {
                    continue;
                }
                if (!\is_array($endpoint) || $info === null || !$info->isMasterRunning()) {
                    continue;
                }
                $backendHost = \trim((string)($endpoint['host'] ?? ''));
                $backendPort = (int)($endpoint['port'] ?? $endpoint['main_port'] ?? 0);
                $backendSsl = (bool)($endpoint['ssl_enabled'] ?? false);
                if ($backendPort !== $port
                    || !$this->endpointHostMatchesTarget($backendHost, $host)
                    || $sslRequested
                    || $backendSsl
                    || \strtolower(\trim((string)($endpoint['edge_adapter'] ?? ''))) !== 'nginx'
                ) {
                    continue;
                }
                try {
                    $runtimeMetadata = $this->extractRuntimeMetadata($endpoint);
                } catch (\RuntimeException) {
                    continue;
                }
                $backendMatches[$persistedName] = [
                    'host' => $host,
                    'endpoint_host' => $backendHost,
                    'authority_host' => $this->normalizeAuthorityHost(
                        (string)($endpoint['public_host'] ?? ''),
                        $backendHost,
                    ),
                    'port' => $backendPort,
                    'instance' => $persistedName,
                    'worker_count' => (int)($endpoint['count'] ?? $endpoint['worker_count'] ?? 0),
                    'ssl' => false,
                    'runtime_metadata' => $runtimeMetadata,
                    'edge_adapter' => 'nginx',
                    'http_protocol_selection' => \is_array($endpoint['http_protocol_selection'] ?? null)
                        ? $endpoint['http_protocol_selection']
                        : [],
                    'target_surface' => 'wls_endpoint',
                    'target_endpoint_role' => 'internal_nginx_backend',
                    'wls_backend' => [
                        'host' => $this->normalizeConnectHost($backendHost),
                        'port' => $backendPort,
                        'ssl' => false,
                    ],
                ];
            }
        }

        $matches = $publicMatches !== [] ? $publicMatches : $backendMatches;
        if (\count($matches) === 1) {
            $name = (string)\array_key_first($matches);
            $info = $manager->getInstanceInfoWithIpcTimeout($name, false, 0.0);
            if ($info === null) {
                $this->printer->error(__('实例 [%{1}] 状态不可读，已拒绝开始压测。', [$name]));
                return null;
            }
            $target = $matches[$name];
            if (!$this->ensureBenchmarkInstanceReady($info, $name, $target)) {
                return null;
            }
            $target['target_attribution'] = $publicMatches !== []
                ? 'unique_live_public_edge_match'
                : 'unique_live_backend_match';
            if ($authorityHost !== null) {
                $boundAuthority = $this->normalizeAuthorityHost(
                    (string)($target['authority_host'] ?? ''),
                    (string)($target['host'] ?? $host),
                );
                if ($publicMatches !== [] && \strcasecmp($authorityHost, $boundAuthority) !== 0) {
                    $this->printer->error(__(
                        '--authority-host 与已归因实例的公网 authority 不一致；请使用实例配置中的 %{1}。',
                        [$boundAuthority],
                    ));
                    return null;
                }
                $target['authority_host'] = $authorityHost;
                $target['explicit_transport_resolve'] = true;
            }
            $this->printer->note(__('手动端口唯一匹配到运行实例 [%{1}] 的 %{2}，报告将使用该实例的 schema v%{3} 运行时元数据。', [
                $name,
                $publicMatches !== [] ? __('Managed Nginx 公网入口') : __('内部 HTTP/1.1 回源'),
                (string)($target['runtime_metadata']['endpoint_schema_version'] ?? 0),
            ]));
            return $target;
        }

        if (\count($matches) > 1) {
            $this->printer->warning(__('手动端口匹配到多个实例记录（%{1}）；压测可继续，但报告不归因任何运行时。', [
                \implode(', ', \array_keys($matches)),
            ]));
            $this->printer->note(__('请使用 --instance <name> 消除歧义。'));
        } else {
            $this->printer->note(__('手动 host/port 未唯一匹配 Managed Nginx 公网入口或内部 WLS 回源；报告仅记录地址，不伪造运行时证据。'));
        }

        return [
            'host' => $host,
            'authority_host' => $authorityHost ?? $host,
            'port' => $port,
            'instance' => __('手动指定（未归因）'),
            'worker_count' => 0,
            'ssl' => $sslRequested,
            'runtime_metadata' => [],
            'target_surface' => 'unattributed_endpoint',
            'target_endpoint_role' => $authorityHost !== null ? 'manual_external_authority' : 'manual_endpoint',
            'target_attribution' => \count($matches) > 1 ? 'ambiguous_endpoint_match' : 'manual_unattributed',
            'explicit_transport_resolve' => $authorityHost !== null,
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

        $edgeAdapter = \strtolower(\trim((string)($endpoint['edge_adapter'] ?? '')));
        if (!\in_array($edgeAdapter, [
            \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX,
            \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS,
        ], true)) {
            return null;
        }
        $selectionData = \is_array($endpoint['http_protocol_selection'] ?? null)
            ? $endpoint['http_protocol_selection']
            : [];
        try {
            $selection = HttpProtocolSelection::fromArray($selectionData);
            $selection->assertCompatibleEdgeAdapter($edgeAdapter);
        } catch (\Throwable) {
            return null;
        }
        $gatewayRuntime = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        if ((string)($gatewayRuntime['mode'] ?? '') === 'gateway') {
            $publicTarget = $this->resolveHostGatewayPublicTarget(
                $name,
                $endpointHost,
                (string)($endpoint['public_host'] ?? $endpoint['ssl_domain'] ?? ''),
                $gatewayRuntime,
            );
            if ($publicTarget === null) {
                return null;
            }
            $joinBackendRequired = $edgeAdapter
                    === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS
                && \strtolower(\trim((string)($gatewayRuntime['requested_mode'] ?? '')))
                    === GatewayStartupDecision::MODE_AUTO;
            $carrierRole = $joinBackendRequired
                ? ControlMessage::ROLE_GATEWAY_BACKEND
                : ControlMessage::ROLE_WORKER;

            return [
                ...$publicTarget,
                'endpoint_host' => (string)$publicTarget['host'],
                'instance' => $name,
                'worker_count' => (int)($endpoint['count'] ?? $endpoint['worker_count'] ?? 0),
                'runtime_metadata' => $runtimeMetadata,
                'edge_adapter' => $edgeAdapter,
                'http_protocol_selection' => $selection->toArray(),
                'benchmark_carrier_role' => $carrierRole,
                'benchmark_expected_carriers' => \max(
                    1,
                    (int)($joinBackendRequired
                        ? ($gatewayRuntime['join_backend']['desired_count']
                            ?? $endpoint['count']
                            ?? $endpoint['worker_count']
                            ?? 1)
                        : ($endpoint['count']
                            ?? $endpoint['worker_count']
                            ?? 1)),
                ),
                'managed_nginx' => [],
                'wls_backend' => [
                    'host' => $this->normalizeConnectHost($endpointHost),
                    'port' => $port,
                    'ssl' => false,
                ],
            ];
        }
        if ($edgeAdapter === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS) {
            $connectHost = $this->normalizeConnectHost($endpointHost);
            $authorityHost = $this->normalizeAuthorityHost(
                (string)($endpoint['public_host'] ?? $endpoint['ssl_domain'] ?? ''),
                $connectHost,
            );
            $fallbackHttpsPort = (string)($gatewayRuntime['fallback_state'] ?? '')
                === 'DEGRADED_WLS'
                ? (int)($gatewayRuntime['public_https'] ?? 0)
                : 0;
            $sslEnabled = $fallbackHttpsPort > 0
                ? true
                : (bool)($endpoint['ssl_enabled'] ?? false);
            $publicPort = $fallbackHttpsPort >= 1 && $fallbackHttpsPort <= 65535
                ? $fallbackHttpsPort
                : $port;

            return [
                'host' => $connectHost,
                'authority_host' => $authorityHost,
                'port' => $publicPort,
                'ssl' => $sslEnabled,
                'target_surface' => 'wls_endpoint',
                'target_endpoint_role' => $fallbackHttpsPort > 0
                    ? 'gateway_fallback_public'
                    : 'pure_wls_public',
                'explicit_transport_resolve' => \strcasecmp($authorityHost, $connectHost) !== 0,
                'endpoint_host' => $connectHost,
                'instance' => $name,
                'worker_count' => (int)($endpoint['count'] ?? $endpoint['worker_count'] ?? 0),
                'runtime_metadata' => $runtimeMetadata,
                'edge_adapter' => $edgeAdapter,
                'http_protocol_selection' => $selection->toArray(),
                'managed_nginx' => [],
                'wls_backend' => [
                    'host' => $connectHost,
                    'port' => $publicPort,
                    'ssl' => $sslEnabled,
                ],
            ];
        }
        if ((bool)($endpoint['ssl_enabled'] ?? false)) {
            return null;
        }
        try {
            $managed = ManagedNginxService::fromEnv()->doctorSnapshot();
        } catch (\Throwable) {
            return null;
        }
        $publicTarget = $this->resolveManagedNginxPublicTarget(
            $managed,
            $name,
            $endpointHost,
            $port,
            (string)($endpoint['public_host'] ?? $endpoint['ssl_domain'] ?? ''),
        );
        if ($publicTarget === null) {
            return null;
        }

        return [
            ...$publicTarget,
            'endpoint_host' => (string)$publicTarget['host'],
            'instance' => $name,
            'worker_count' => (int)($endpoint['count'] ?? $endpoint['worker_count'] ?? 0),
            'runtime_metadata' => $runtimeMetadata,
            'edge_adapter' => $edgeAdapter,
            'http_protocol_selection' => $selection->toArray(),
            'managed_nginx' => $managed,
            'wls_backend' => [
                'host' => $this->normalizeConnectHost($endpointHost),
                'port' => $port,
                'ssl' => false,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $gatewayRuntime
     * @return array<string,mixed>|null
     */
    private function resolveHostGatewayPublicTarget(
        string $instanceName,
        string $backendHost,
        string $configuredAuthorityHost,
        array $gatewayRuntime,
    ): ?array {
        $connectHost = $this->normalizeConnectHost($backendHost);
        $authorityHost = $this->normalizeAuthorityHost($configuredAuthorityHost, $connectHost);
        if (!$this->isLoopbackHost($connectHost)
            || $authorityHost === $connectHost
            || \filter_var($authorityHost, FILTER_VALIDATE_IP) !== false
        ) {
            return null;
        }
        $projectUuid = \strtolower(\trim((string)($gatewayRuntime['project_uuid'] ?? '')));
        $gatewayInstance = \trim((string)($gatewayRuntime['instance_id'] ?? ''));
        $gatewayEpoch = \strtolower(\trim((string)($gatewayRuntime['epoch'] ?? '')));
        $launchId = \strtolower(\trim((string)($gatewayRuntime['launch_id'] ?? '')));
        $instanceGeneration = (int)($gatewayRuntime['instance_generation'] ?? 0);
        if (!\hash_equals(GatewayPaths::PROTOCOL, (string)($gatewayRuntime['protocol'] ?? ''))
            || \preg_match('/\A[a-f0-9-]{36}\z/D', $projectUuid) !== 1
            || !\hash_equals($instanceName, $gatewayInstance)
            || \preg_match('/\A[a-f0-9]{32}\z/D', $gatewayEpoch) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1
            || $instanceGeneration < 1
        ) {
            return null;
        }

        $status = $this->readHostGatewayStatus();
        $observation = $this->hostGatewayObservation(
            $status,
            $projectUuid,
            $instanceName,
            $instanceGeneration,
            $launchId,
            $gatewayEpoch,
            $authorityHost,
        );
        if ($observation === null) {
            return null;
        }
        $publicHttps = (int)$observation['public_https'];
        $protocols = $this->probeHostGatewayPublicProtocols(
            $connectHost,
            $authorityHost,
            $publicHttps,
            $observation,
        );
        if (!\in_array('http/1.1', $protocols, true)) {
            return null;
        }
        $observation['public_protocols'] = $protocols;

        return [
            'host' => $connectHost,
            'authority_host' => $authorityHost,
            'port' => $publicHttps,
            'ssl' => true,
            'target_surface' => 'public_edge',
            'target_endpoint_role' => 'host_gateway_public',
            'explicit_transport_resolve' => true,
            'host_gateway' => $observation,
        ];
    }

    /**
     * Read through the project-authenticated gateway endpoint. Kept protected
     * so the identity gates can be tested without a host-level service.
     *
     * @return array<string,mixed>
     */
    protected function readHostGatewayStatus(): array
    {
        return (new GatewayHostManager())->status(2.0);
    }

    /**
     * @param array<string,mixed> $status
     * @return array<string,mixed>|null
     */
    private function hostGatewayObservation(
        array $status,
        string $projectUuid,
        string $instanceName,
        int $instanceGeneration,
        string $launchId,
        string $gatewayEpoch,
        string $authorityHost,
    ): ?array {
        $statusProject = \strtolower(\trim((string)($status['project_uuid'] ?? '')));
        $statusEpoch = \strtolower(\trim((string)($status['epoch'] ?? '')));
        $publicHttps = (int)($status['public_https'] ?? 0);
        if (!(bool)($status['ok'] ?? false)
            || !(bool)($status['ready'] ?? false)
            || !(bool)($status['release_ready'] ?? false)
            || !(bool)($status['broker_ready'] ?? false)
            || !(bool)($status['supervisor_ready'] ?? false)
            || !(bool)($status['data_plane']['running'] ?? false)
            || !\hash_equals(GatewayPaths::PROTOCOL, (string)($status['protocol'] ?? ''))
            || (int)($status['protocol_min'] ?? 0) > 2
            || (int)($status['protocol_max'] ?? 0) < 2
            || !\hash_equals(GatewayPaths::IMPLEMENTATION_LEVEL, (string)($status['implementation_level'] ?? ''))
            || !\hash_equals(GatewayPaths::SECURITY_PROFILE, (string)($status['security_profile'] ?? ''))
            || !\hash_equals($projectUuid, $statusProject)
            || !\hash_equals($gatewayEpoch, $statusEpoch)
            || $publicHttps < 1
            || $publicHttps > 65535
        ) {
            return null;
        }

        $instanceActive = false;
        foreach ((array)($status['instances'] ?? []) as $instance) {
            if (!\is_array($instance)
                || !\hash_equals($instanceName, (string)($instance['instance_id'] ?? ''))
            ) {
                continue;
            }
            $instanceActive = (string)($instance['status'] ?? '') === 'ACTIVE'
                && (int)($instance['generation'] ?? 0) === $instanceGeneration;
            break;
        }
        if (!$instanceActive) {
            return null;
        }

        foreach ((array)($status['routes'] ?? []) as $route) {
            if (!\is_array($route)
                || (string)($route['status'] ?? '') !== 'ACTIVE'
                || !\hash_equals($projectUuid, \strtolower((string)($route['project_uuid'] ?? '')))
                || !$this->gatewayDomainCoversAuthority(
                    (string)($route['domain'] ?? ''),
                    $authorityHost,
                )
                || !\hash_equals(
                    $instanceName,
                    (string)($route['preferred_instance_id'] ?? $route['instance_id'] ?? ''),
                )
            ) {
                continue;
            }
            $identity = \is_array($route['backend_identity'] ?? null)
                ? $route['backend_identity']
                : [];
            $certificate = \is_array($route['certificate'] ?? null)
                ? $route['certificate']
                : [];
            $certificateDigest = \strtolower(\trim(
                (string)($certificate['snapshot_digest'] ?? ''),
            ));
            $routeId = \strtolower(\trim((string)($route['route_id'] ?? '')));
            if ((int)($identity['generation'] ?? 0) !== $instanceGeneration
                || !\hash_equals($launchId, \strtolower((string)($identity['launch_id'] ?? '')))
                || (int)($identity['master_epoch'] ?? 0) < 1
                || !(bool)($certificate['valid'] ?? false)
                || (int)($certificate['generation'] ?? 0) < 1
                || \preg_match('/\A[a-f0-9]{64}\z/D', $certificateDigest) !== 1
                || \preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                || (int)($route['route_generation'] ?? 0) < 1
            ) {
                continue;
            }

            return [
                'protocol' => GatewayPaths::PROTOCOL,
                'implementation_level' => GatewayPaths::IMPLEMENTATION_LEVEL,
                'security_profile' => GatewayPaths::SECURITY_PROFILE,
                'epoch' => $statusEpoch,
                'gateway_generation' => (int)($status['generation'] ?? 0),
                'project_uuid' => $projectUuid,
                'instance_id' => $instanceName,
                'instance_generation' => $instanceGeneration,
                'launch_id' => $launchId,
                'master_epoch' => (int)$identity['master_epoch'],
                'route_id' => $routeId,
                'route_generation' => (int)$route['route_generation'],
                'domain' => \strtolower((string)$route['domain']),
                'certificate_generation' => (int)$certificate['generation'],
                'certificate_snapshot_sha256' => $certificateDigest,
                'public_https' => $publicHttps,
            ];
        }

        return null;
    }

    private function gatewayDomainCoversAuthority(string $domain, string $authorityHost): bool
    {
        $domain = \strtolower(\rtrim(\trim($domain), '.'));
        $authorityHost = \strtolower(\rtrim(\trim($authorityHost), '.'));
        if ($domain === '' || $authorityHost === '') {
            return false;
        }
        if (!\str_starts_with($domain, '*.')) {
            return \hash_equals($domain, $authorityHost);
        }
        $suffix = \substr($domain, 1);
        return \str_ends_with($authorityHost, $suffix)
            && \substr_count($authorityHost, '.') === \substr_count($domain, '.');
    }

    /**
     * @param array<string,mixed> $observation
     * @return list<string>
     */
    protected function probeHostGatewayPublicProtocols(
        string $connectHost,
        string $authorityHost,
        int $httpsPort,
        array $observation,
    ): array {
        if (!\function_exists('curl_init')) {
            return [];
        }
        $candidates = [['http/1.1', \CURL_HTTP_VERSION_1_1]];
        $curl = (array)\curl_version();
        if (\defined('CURL_HTTP_VERSION_2_0')
            && \defined('CURL_VERSION_HTTP2')
            && (((int)($curl['features'] ?? 0) & (int)\constant('CURL_VERSION_HTTP2')) !== 0)
        ) {
            \array_unshift($candidates, ['http/2', (int)\constant('CURL_HTTP_VERSION_2_0')]);
        }
        $verified = [];
        foreach ($candidates as [$protocol, $curlVersion]) {
            $nonce = \bin2hex(\random_bytes(16));
            $url = 'https://' . $this->formatTargetUrlHost($authorityHost) . ':' . $httpsPort
                . '/__wls_gateway_sentinel?nonce=' . $nonce;
            $headers = [];
            $handle = \curl_init($url);
            if ($handle === false) {
                continue;
            }
            $resolveAddress = \trim($connectHost, '[]');
            if (\str_contains($resolveAddress, ':')) {
                $resolveAddress = '[' . $resolveAddress . ']';
            }
            \curl_setopt_array($handle, [
                \CURLOPT_RETURNTRANSFER => true,
                \CURLOPT_TIMEOUT_MS => 4000,
                \CURLOPT_CONNECTTIMEOUT_MS => 1000,
                \CURLOPT_SSL_VERIFYPEER => false,
                \CURLOPT_SSL_VERIFYHOST => 0,
                \CURLOPT_HTTP_VERSION => $curlVersion,
                \CURLOPT_NOPROXY => '*',
                \CURLOPT_PROXY => '',
                \CURLOPT_RESOLVE => [
                    \trim($authorityHost, '[]') . ':' . $httpsPort . ':' . $resolveAddress,
                ],
                \CURLOPT_HTTPHEADER => ['Cache-Control: no-store'],
                \CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                    $separator = \strpos($line, ':');
                    if ($separator !== false) {
                        $headers[\strtolower(\trim(\substr($line, 0, $separator)))]
                            = \trim(\substr($line, $separator + 1));
                    }
                    return \strlen($line);
                },
            ]);
            $body = \curl_exec($handle);
            $statusCode = (int)\curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
            $observed = $this->curlHttpVersionName(
                (int)\curl_getinfo($handle, \CURLINFO_HTTP_VERSION),
            );
            \curl_close($handle);
            $payload = \is_string($body) ? \json_decode($body, true) : null;
            if ($statusCode !== 200
                || $observed !== ($protocol === 'http/2' ? '2' : '1.1')
                || !\is_array($payload)
                || !\hash_equals(
                    (string)$observation['project_uuid'],
                    (string)($headers['x-wls-project-uuid'] ?? ''),
                )
                || !\hash_equals(
                    (string)$observation['instance_id'],
                    (string)($headers['x-wls-instance-id'] ?? ''),
                )
                || (int)$observation['instance_generation']
                    !== (int)($headers['x-wls-backend-generation'] ?? 0)
                || !\hash_equals($nonce, (string)($headers['x-wls-probe-nonce'] ?? ''))
                || !\hash_equals((string)$observation['instance_id'], (string)($payload['instance'] ?? ''))
                || !\hash_equals((string)$observation['launch_id'], (string)($payload['launch_id'] ?? ''))
                || (int)$observation['master_epoch'] !== (int)($payload['master_epoch'] ?? 0)
                || !\hash_equals($nonce, (string)($payload['nonce'] ?? ''))
            ) {
                continue;
            }
            $verified[] = $protocol;
        }

        return $verified;
    }

    /**
     * @param array<string,mixed> $managed
     * @return array<string,mixed>|null
     */
    private function resolveManagedNginxPublicTarget(
        array $managed,
        string $instanceName,
        string $backendHost,
        int $backendPort,
        string $configuredAuthorityHost,
    ): ?array {
        $ownerInstance = \trim((string)($managed['owner_instance'] ?? ''));
        $ownerBackendHost = \trim((string)($managed['owner_upstream_host'] ?? ''));
        $ownerBackendPort = (int)($managed['owner_upstream_port'] ?? 0);
        $configDigest = \strtolower(\trim((string)($managed['owner_config_sha256'] ?? '')));
        $upstreamDigest = \strtolower(\trim((string)($managed['owner_upstream_endpoint_sha256'] ?? '')));
        $publicProtocols = \is_array($managed['public_protocols'] ?? null)
            ? \array_values(\array_filter(\array_map('strval', $managed['public_protocols'])))
            : [];
        if (!(bool)($managed['managed'] ?? false)
            || !(bool)($managed['installed'] ?? false)
            || !(bool)($managed['running'] ?? false)
            || !(bool)($managed['runtime_owner_active'] ?? false)
            || !(bool)($managed['owner_ports_bound'] ?? false)
            || !(bool)($managed['install_identity_matches'] ?? false)
            || !(bool)($managed['binary_capabilities_ok'] ?? false)
            || (int)($managed['pid'] ?? 0) <= 0
            || \trim((string)($managed['owner_config_generation'] ?? '')) === ''
            || !\hash_equals($instanceName, $ownerInstance)
            || $ownerBackendPort !== $backendPort
            || !$this->endpointHostMatchesTarget($backendHost, $ownerBackendHost)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $configDigest) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $upstreamDigest) !== 1
            || $publicProtocols === []
        ) {
            return null;
        }

        $httpPort = (int)($managed['owner_listen_http'] ?? 0);
        $httpsPort = (int)($managed['owner_listen_https'] ?? 0);
        $certificateDigest = \strtolower(\trim((string)($managed['owner_ssl_certificate_sha256'] ?? '')));
        $tlsReadyForTarget = $httpsPort > 0
            && $httpsPort <= 65535
            && (bool)($managed['http_ssl_module'] ?? false)
            && \preg_match('/\A[a-f0-9]{64}\z/D', $certificateDigest) === 1;
        if (!$tlsReadyForTarget && ($httpPort < 1 || $httpPort > 65535)) {
            return null;
        }

        $serverNames = \is_array($managed['owner_server_names'] ?? null)
            ? \array_values(\array_filter(
                \array_map(
                    static fn(mixed $serverName): string => \trim((string)$serverName),
                    $managed['owner_server_names'],
                ),
                static fn(string $serverName): bool =>
                    $serverName !== ''
                    && $serverName !== '_'
                    && !\str_contains($serverName, '*'),
            ))
            : [];
        $authorityHost = $serverNames[0] ?? $configuredAuthorityHost;
        $connectHost = $this->normalizeConnectHost(
            $ownerBackendHost !== '' ? $ownerBackendHost : '127.0.0.1',
        );

        return [
            'host' => $connectHost,
            'authority_host' => $this->normalizeAuthorityHost($authorityHost, $connectHost),
            'port' => $tlsReadyForTarget ? $httpsPort : $httpPort,
            'ssl' => $tlsReadyForTarget,
            'target_surface' => 'public_edge',
            'target_endpoint_role' => 'managed_nginx_public',
        ];
    }

    /**
     * @param array<string,mixed> $expected
     * @param array<string,mixed> $current
     */
    private function managedNginxObservationMatches(array $expected, array $current): bool
    {
        if ($expected === []
            || $current === []
            || !(bool)($current['running'] ?? false)
            || (int)($expected['pid'] ?? 0) <= 0
            || (int)($expected['pid'] ?? 0) !== (int)($current['pid'] ?? 0)
        ) {
            return false;
        }
        $identityFields = [
            'owner_instance',
            'owner_upstream_host',
            'owner_upstream_port',
            'owner_config_generation',
            'owner_config_sha256',
            'owner_upstream_endpoint_sha256',
            'owner_ssl_certificate_sha256',
            'owner_ports_bound',
            'owner_listen_http',
            'owner_listen_https',
            'runtime_owner_active',
            'listen_http',
            'listen_https',
            'public_protocols',
        ];
        foreach ($identityFields as $field) {
            $expectedValue = $expected[$field] ?? null;
            $currentValue = $current[$field] ?? null;
            if (\is_array($expectedValue) || \is_array($currentValue)) {
                if ((array)$expectedValue !== (array)$currentValue) {
                    return false;
                }
                continue;
            }
            if ((string)$expectedValue !== (string)$currentValue) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $benchmarkContext
     */
    private function managedNginxOwnerStillMatchesBenchmarkContext(array $benchmarkContext): bool
    {
        if (!(bool)($benchmarkContext['managed_nginx_generation_required'] ?? false)) {
            return true;
        }
        $expected = \is_array($benchmarkContext['_managed_nginx_owner_observation'] ?? null)
            ? $benchmarkContext['_managed_nginx_owner_observation']
            : [];
        try {
            $current = ManagedNginxService::fromEnv()->doctorSnapshot();
        } catch (\Throwable) {
            return false;
        }

        return $this->managedNginxObservationMatches($expected, $current);
    }

    /**
     * @param array<string,mixed> $expected
     */
    private function hostGatewayObservationStillMatches(
        array $expected,
        string $connectHost,
        string $authorityHost,
    ): bool {
        if ($expected === []) {
            return false;
        }
        $current = $this->hostGatewayObservation(
            $this->readHostGatewayStatus(),
            (string)($expected['project_uuid'] ?? ''),
            (string)($expected['instance_id'] ?? ''),
            (int)($expected['instance_generation'] ?? 0),
            (string)($expected['launch_id'] ?? ''),
            (string)($expected['epoch'] ?? ''),
            $authorityHost,
        );
        if ($current === null) {
            return false;
        }
        foreach ([
            'protocol',
            'implementation_level',
            'security_profile',
            'epoch',
            'project_uuid',
            'instance_id',
            'instance_generation',
            'launch_id',
            'master_epoch',
            'route_id',
            'route_generation',
            'domain',
            'certificate_generation',
            'certificate_snapshot_sha256',
            'public_https',
        ] as $field) {
            if ((string)($expected[$field] ?? '') !== (string)($current[$field] ?? '')) {
                return false;
            }
        }
        $expectedProtocols = \is_array($expected['public_protocols'] ?? null)
            ? \array_values(\array_filter(\array_map('strval', $expected['public_protocols'])))
            : [];
        if (!\in_array('http/1.1', $expectedProtocols, true)) {
            return false;
        }
        $currentProtocols = $this->probeHostGatewayPublicProtocols(
            $connectHost,
            $authorityHost,
            (int)$current['public_https'],
            $current,
        );
        foreach ($expectedProtocols as $protocol) {
            if (!\in_array($protocol, $currentProtocols, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $benchmarkContext
     */
    private function hostGatewayStillMatchesBenchmarkContext(array $benchmarkContext): bool
    {
        if (!(bool)($benchmarkContext['host_gateway_identity_required'] ?? false)) {
            return true;
        }
        $expected = \is_array($benchmarkContext['_host_gateway_observation'] ?? null)
            ? $benchmarkContext['_host_gateway_observation']
            : [];
        try {
            return $this->hostGatewayObservationStillMatches(
                $expected,
                (string)($benchmarkContext['connect_host'] ?? ''),
                (string)($benchmarkContext['authority_host'] ?? ''),
            );
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $benchmarkContext
     * @param array<string,string> $headers
     */
    private function responseMatchesManagedNginxGeneration(
        array $benchmarkContext,
        array $headers,
    ): bool {
        if (!(bool)($benchmarkContext['managed_nginx_generation_required'] ?? false)) {
            return true;
        }
        $expected = \strtolower(\trim(
            (string)($benchmarkContext['managed_nginx_expected_generation'] ?? ''),
        ));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $expected) !== 1) {
            return false;
        }
        $observed = \array_values(\array_filter(
            \array_map(
                static fn(string $value): string => \strtolower(\trim($value)),
                \explode(',', (string)($headers['x-wls-nginx-config'] ?? '')),
            ),
            static fn(string $value): bool => $value !== '',
        ));
        return $observed !== []
            && \count(\array_unique($observed)) === 1
            && \hash_equals($expected, $observed[0]);
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

    private function normalizeExplicitAuthorityHost(mixed $value): ?string
    {
        $authorityHost = \trim((string)$value);
        if ($authorityHost === '') {
            return null;
        }
        $authorityHost = \strtolower(\rtrim(\trim($authorityHost, "[] \t\n\r\0\x0B"), '.'));
        if (\str_contains($authorityHost, '://')
            || \str_contains($authorityHost, '/')
            || (!\filter_var($authorityHost, FILTER_VALIDATE_IP)
                && \preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $authorityHost) !== 1)
        ) {
            throw new \InvalidArgumentException((string)__(
                '--authority-host 必须是不含 scheme、端口或路径的有效主机名/IP。',
            ));
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
        // The base reporter consumes exact summaries from benchmarkContext. If an
        // extension overrides the protected report hook, preserve its legacy sample arrays.
        $results = [];
        $requestLatencies = [];
        $generateReportMethod = new \ReflectionMethod($this, 'generateReport');
        $reportHookRequiresLegacySamples = $generateReportMethod->getDeclaringClass()->getName() !== self::class;
        $successCount = 0;
        $requestLatencyAccumulator = new BenchmarkExactTimingAccumulator();
        $connectTimeAccumulator = new BenchmarkExactTimingAccumulator();
        $tlsAppConnectTimeAccumulator = new BenchmarkExactTimingAccumulator();
        $tlsHandshakeTimeAccumulator = new BenchmarkExactTimingAccumulator();
        $errors = 0;
        $errorDetails = [];
        $statusCodes = [];
        $workerHits = [];
        $cacheSources = [];
        $httpVersionHits = [];
        $newConnectionCount = 0;
        $connectionReuseEligible = 0;
        $knownConnectedHandles = [];
        $managedNginxGenerationRequired =
            (bool)($benchmarkContext['managed_nginx_generation_required'] ?? false);
        $managedNginxGenerationVerifiedCount = 0;
        $managedNginxGenerationMismatchCount = 0;
        $managedNginxOwnerStableBefore = $this->managedNginxOwnerStillMatchesBenchmarkContext(
            $benchmarkContext,
        );
        $benchmarkContext['managed_nginx_owner_stable_before'] = $managedNginxOwnerStableBefore;
        if (!$managedNginxOwnerStableBefore) {
            $this->printer->error(__('Managed Nginx owner 在正式压测前已变化，已拒绝运行。'));
            return 1;
        }
        $hostGatewayStableBefore = $this->hostGatewayStillMatchesBenchmarkContext(
            $benchmarkContext,
        );
        $benchmarkContext['host_gateway_identity_stable_before'] = $hostGatewayStableBefore;
        if (!$hostGatewayStableBefore) {
            $this->printer->error(__('宿主级 WLS 2.0 网关身份或活动路由在正式压测前已变化，已拒绝运行。'));
            return 1;
        }
        
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
            CURLOPT_HTTP_VERSION => $this->curlHttpVersionOption(
                $curlHttpVersion,
                $httpVersion === 'auto',
            ),
            CURLOPT_ENCODING => (string)($benchmarkContext['accept_encoding_curl'] ?? 'identity'),
            CURLOPT_USERAGENT => 'Weline-Server-Benchmark/2.0',
        ];
        if ($noKeepAlive) {
            // 分流压测模式：每个请求尽量新建连接，让 Nginx 在回源连接级重新选择 Direct Worker
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
        $connectionReuseEnabled = !$noKeepAlive;
        // Each physical lane intentionally relies on its curl_multi connection
        // cache only. No CURLSH handle is attached, so the legacy explicit-share
        // report flags must stay false even when this libcurl supports CURLSH.
        $connectionShareEnabled = false;
        $sslSessionShareEnabled = false;
        $clientMultiplexOptionEnabled = \defined('CURLPIPE_MULTIPLEX');
        $effectiveHttpVersion = (string)($benchmarkContext['http_version_effective'] ?? $httpVersion);
        $multiplexMaxConcurrentStreams = (int)($benchmarkContext['http_multiplex_max_concurrent_streams'] ?? 0);
        $multiplexCapabilityVerified = $clientMultiplexOptionEnabled
            && (bool)($benchmarkContext['http_multiplex_capability_verified'] ?? false)
            && $multiplexMaxConcurrentStreams > 1;
        $multiplexSchedulingAuthorized = $clientMultiplexOptionEnabled
            && (bool)($benchmarkContext['http_multiplex_scheduling_authorized'] ?? $multiplexCapabilityVerified)
            && $multiplexMaxConcurrentStreams > 1;
        $multiplexRequested = $connectionReuseEnabled
            && $multiplexSchedulingAuthorized
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
        $benchmarkContext['curl_multi_cache_scope'] = 'per-physical-connection-lane';
        $benchmarkContext['curl_multi_connection_reuse_enabled'] = $connectionReuseEnabled;
        $benchmarkContext['curl_multi_tls_session_cache_enabled'] = $ssl;
        $benchmarkContext['curl_multi_tls_session_resumption_verified'] = false;
        $benchmarkContext['curl_multiplex_option_enabled'] = $clientMultiplexOptionEnabled;
        $benchmarkContext['curl_pipewait_supported'] = $pipeWaitSupported;
        $benchmarkContext['curl_pipewait_enabled'] = $pipeWaitEnabled;
        $benchmarkContext['curl_max_concurrent_streams_supported'] = $curlMaxConcurrentStreamsSupported;
        $benchmarkContext['http_multiplex_capability_verified'] = $multiplexCapabilityVerified;
        $benchmarkContext['http_multiplex_scheduling_authorized'] = $multiplexSchedulingAuthorized;
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
        // 同一 multi 内的 connection/DNS/TLS Session cache 由 libcurl 自动共享；
        // 不再叠加 share handle，避免 Windows libcurl 出现双重连接池状态。
        $multiHandles = [];
        for ($laneId = 0; $laneId < $physicalConnectionLaneCount; $laneId++) {
            $laneMultiHandle = \curl_multi_init();
            $multiHandles[$laneId] = $laneMultiHandle;

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
                                            'event_accumulator' => new BenchmarkExactEventPeakAccumulator(),
                                        ];
                                    }
                                    $handleStartUs = (int)\round((float)$activeHandles[$key]['start'] * 1000000);
                                    $multiplexTransferIntervals[$connectionKey]['event_accumulator']->addInterval(
                                        $handleStartUs + $preTransferUs,
                                        $handleStartUs + $totalTimeUs,
                                    );
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
                                $connectTimeAccumulator->add($connectTimeMs);
                            }
                            $tlsAppConnectMs = \defined('CURLINFO_APPCONNECT_TIME_T')
                                ? ((float)\curl_getinfo($ch, \CURLINFO_APPCONNECT_TIME_T) / 1000)
                                : ((float)($transferInfo['appconnect_time'] ?? 0.0) * 1000);
                            if ($ssl && $tlsAppConnectMs > 0) {
                                $tlsAppConnectTimeAccumulator->add($tlsAppConnectMs);
                                // APPCONNECT is measured from transfer start and includes
                                // TCP connect. Subtract CONNECT to report crypto/TLS only.
                                $tlsHandshakeMs = \max(0.0, $tlsAppConnectMs - $connectTimeMs);
                                if ($tlsHandshakeMs > 0.0) {
                                    if ($numConnects > 0) {
                                        $tlsHandshakeTimeAccumulator->add($tlsHandshakeMs);
                                    }
                                }
                            }
                            if ($httpCode >= 200 && $httpCode < 400) {
                                $headers = $this->parseResponseHeaders($headerBuffers[$key] ?? '');
                                if (!$this->responseMatchesManagedNginxGeneration($benchmarkContext, $headers)) {
                                    $errors++;
                                    $managedNginxGenerationMismatchCount++;
                                    $detail = 'managed_nginx_generation_mismatch';
                                    $errorDetails[$detail] = ($errorDetails[$detail] ?? 0) + 1;
                                } else {
                                    $successCount++;
                                    if ($reportHookRequiresLegacySamples) {
                                        $results[] = $elapsed;
                                    }
                                    if ($managedNginxGenerationRequired) {
                                        $managedNginxGenerationVerifiedCount++;
                                    }
                                    $workerMarker = $this->extractWorkerMarker($headers, $workerHeader);
                                    if ($workerMarker !== '') {
                                        $workerHits[$workerMarker] = ($workerHits[$workerMarker] ?? 0) + 1;
                                    }
                                    $cacheSource = $this->extractCacheSource($headers);
                                    if ($cacheSource !== '') {
                                        $cacheSources[$cacheSource] = ($cacheSources[$cacheSource] ?? 0) + 1;
                                    }
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

                        $requestLatencyAccumulator->add($elapsed);
                        if ($reportHookRequiresLegacySamples) {
                            $requestLatencies[] = $elapsed;
                        }
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
        
        // 清理 handle 池和 multi 连接池
        foreach ($handlePool as $ch) {
            \curl_close($ch);
        }
        foreach ($multiHandles as $laneMultiHandle) {
            \curl_multi_close($laneMultiHandle);
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
        $benchmarkContext['curl_connect_time_ms'] = $connectTimeAccumulator->summarize();
        $benchmarkContext['curl_tls_appconnect_time_ms'] = $tlsAppConnectTimeAccumulator->summarize();
        $benchmarkContext['curl_tls_handshake_time_ms'] = $tlsHandshakeTimeAccumulator->summarize();
        $benchmarkContext['benchmark_latency_summary'] = $requestLatencyAccumulator->summarize(true);
        if (!empty($httpVersionHits)) {
            \arsort($httpVersionHits);
            $benchmarkContext['http_version_negotiated'] = (string)\array_key_first($httpVersionHits);
            $benchmarkContext['http_version_hits'] = $httpVersionHits;
            $requestedHttpVersion = (string)($benchmarkContext['http_version_requested'] ?? $httpVersion);
            if ($requestedHttpVersion !== 'auto' && !isset($httpVersionHits[$requestedHttpVersion])) {
                $mismatchedSuccesses = $successCount;
                if ($mismatchedSuccesses > 0) {
                    $errors += $mismatchedSuccesses;
                    $successCount = 0;
                    $results = [];
                    $detail = 'protocol_mismatch:requested=' . $requestedHttpVersion . ':negotiated=' . (string)$benchmarkContext['http_version_negotiated'];
                    $errorDetails[$detail] = ($errorDetails[$detail] ?? 0) + $mismatchedSuccesses;
                }
            }
        }
        $actualMultiplexProtocolHits = (int)($httpVersionHits['2'] ?? 0)
            + (int)($httpVersionHits['3'] ?? 0);
        foreach ($multiplexTransferIntervals as $connectionKey => $connection) {
            $eventAccumulator = $connection['event_accumulator'] ?? null;
            if (!$eventAccumulator instanceof BenchmarkExactEventPeakAccumulator) {
                continue;
            }
            $eventSummary = $eventAccumulator->summarize();
            $sampleCount = (int)($eventSummary['sample_count'] ?? 0);
            if ($sampleCount <= 0) {
                continue;
            }
            $peak = (int)($eventSummary['peak_concurrent_streams'] ?? 0);
            $peakAtUs = \is_int($eventSummary['peak_observed_at_us'] ?? null)
                ? (int)$eventSummary['peak_observed_at_us']
                : null;
            $laneId = (int)($connection['lane_id'] ?? 0);
            unset($connection['event_accumulator']);
            $liveMultiplexConnectionObservations[$connectionKey] = $connection + [
                'concurrent_streams' => $peak,
                'peak_concurrent_streams' => $peak,
                'sample_count' => $sampleCount,
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
            && $successCount > $newConnectionCount
            && \count($liveMultiplexConnectionObservations) > 0;
        $benchmarkContext['http_multiplex_enabled'] = $httpMultiplexObserved;
        $benchmarkContext['http_multiplex_observation'] = [
            'observed' => $httpMultiplexObserved,
            'negotiated_protocol' => (string)($benchmarkContext['http_version_negotiated'] ?? ''),
            'negotiated_multiplex_protocol_hits' => $actualMultiplexProtocolHits,
            'completed_successes' => $successCount,
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

        $benchmarkContext['managed_nginx_generation_verified_count'] =
            $managedNginxGenerationVerifiedCount;
        $benchmarkContext['managed_nginx_generation_mismatch_count'] =
            $managedNginxGenerationMismatchCount;
        $benchmarkContext['managed_nginx_owner_stable_after'] =
            $this->managedNginxOwnerStillMatchesBenchmarkContext($benchmarkContext);
        if (!(bool)$benchmarkContext['managed_nginx_owner_stable_after']) {
            $this->printer->error(__('Managed Nginx owner 在压测期间发生变化，本次结果无效。'));
        }
        $benchmarkContext['host_gateway_identity_stable_after'] =
            $this->hostGatewayStillMatchesBenchmarkContext($benchmarkContext);
        if (!(bool)$benchmarkContext['host_gateway_identity_stable_after']) {
            $this->printer->error(__('宿主级 WLS 2.0 网关身份或活动路由在压测期间发生变化，本次结果无效。'));
        }

        $benchmarkContext['benchmark_success_count'] = $successCount;
        $benchmarkContext['worker_runtime_after'] = $this->captureWorkerRuntimeSnapshot($benchmarkContext);
        $workerBalance = $this->buildWorkerBalance(
            $workerHits,
            $successCount,
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
            'unique_live_public_edge_match',
            'unique_live_backend_match',
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
        $carrierRole = (string)($benchmarkContext['benchmark_carrier_role']
            ?? ControlMessage::ROLE_WORKER);
        if (!\in_array($carrierRole, [
            ControlMessage::ROLE_WORKER,
            ControlMessage::ROLE_GATEWAY_BACKEND,
        ], true)) {
            $carrierRole = ControlMessage::ROLE_WORKER;
        }
        if ($info !== null
            && $this->workerRuntimeIdentityEvidenceIncomplete($info, $carrierRole)
        ) {
            $retriedInfo = $manager->getInstanceInfoWithIpcTimeout($instanceName, false, 1.5);
            if ($retriedInfo !== null) {
                $info = $retriedInfo;
            }
        }
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
        $expectedWorkers = $carrierRole === ControlMessage::ROLE_GATEWAY_BACKEND
            ? \max(
                1,
                (int)($benchmarkContext['benchmark_expected_carriers']
                    ?? $contextWorkerCount
                    ?? 1),
            )
            : $this->selectRuntimeWorkerCount(
                $contextWorkerCount,
                (int)$info->workerCount,
                $manager->getRuntimeStatsForInstance($info, true),
            );
        $canonicalSlots = [];
        for ($slot = 1; $slot <= $expectedWorkers; $slot++) {
            $canonicalSlots[$carrierRole . '#' . (string)$slot] = true;
        }
        $workers = [];
        $readyFingerprint = [];
        $identitySources = [];
        $leaseComplete = true;
        $duplicateSlots = [];
        $unexpectedReadySlots = [];
        $activeCanonicalSlots = [];
        foreach ($info->getServicesByRole($carrierRole) as $service) {
            $slotId = \trim((string)($service->metadata['slot_id'] ?? ''));
            if ($slotId === '') {
                $slotId = $carrierRole . '#' . (string)$service->instanceId;
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
            $identitySource = \trim((string)($service->metadata['status_source'] ?? ''));
            $identitySources[$identitySource !== '' ? $identitySource : 'ipc'] = true;
            if ($worker['lease_id'] === '') {
                $leaseComplete = false;
            }
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
        $identitySourceNames = \array_keys($identitySources);
        \sort($identitySourceNames);
        $identitySource = $identitySourceNames === ['ipc'] ? 'ipc' : \implode('+', $identitySourceNames);
        $identityAuthoritative = $identitySource === 'ipc' && $leaseComplete;

        return [
            'required' => true,
            'captured' => true,
            'healthy' => $healthy,
            'instance_name' => $instanceName,
            'master_pid' => $info->masterPid,
            'master_running' => $masterRunning,
            'carrier_role' => $carrierRole,
            'expected_workers' => $expectedWorkers,
            'ready_workers' => \count($readyFingerprint),
            'identity_source' => $identitySource,
            'identity_authoritative' => $identityAuthoritative,
            'lease_complete' => $leaseComplete,
            'duplicate_slots' => \array_keys($duplicateSlots),
            'unexpected_ready_slots' => \array_keys($unexpectedReadySlots),
            'missing_canonical_slots' => $missingCanonicalSlots,
            'ready_fingerprint' => $readyFingerprint,
            'workers' => $workers,
            'reason' => $healthy
                ? 'master_and_all_canonical_carriers_ready'
                : 'master_or_carrier_ready_contract_failed',
        ];
    }
    private function workerRuntimeIdentityEvidenceIncomplete(
        ServerInstanceInfo $info,
        string $carrierRole = ControlMessage::ROLE_WORKER,
    ): bool
    {
        $workers = $info->getServicesByRole($carrierRole);
        if ($workers === []) {
            return true;
        }

        foreach ($workers as $service) {
            $statusSource = \trim((string)($service->metadata['status_source'] ?? ''));
            $leaseId = $service->launchId !== ''
                ? $service->launchId
                : (string)($service->metadata['lease_id'] ?? '');
            if (\in_array($statusSource, ['startup_events', 'port_owner', 'runtime_fallback'], true)
                || $leaseId === ''
            ) {
                return true;
            }
        }

        return false;
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
        $successCount = \array_key_exists('benchmark_success_count', $benchmarkContext)
            ? \max(0, (int)$benchmarkContext['benchmark_success_count'])
            : \count($results);
        $completed = $successCount + $errors;
        $successQps = $totalTime > 0.0 ? $successCount / $totalTime : 0.0;
        $errorRate = $completed > 0 ? ($errors / $completed) * 100 : 100.0;
        $latencySummary = \is_array($benchmarkContext['benchmark_latency_summary'] ?? null)
            ? $benchmarkContext['benchmark_latency_summary']
            : $this->summarizeTimingSamples($requestLatencies !== [] ? $requestLatencies : $results);
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

        $managedNginxGenerationRequired =
            (bool)($benchmarkContext['managed_nginx_generation_required'] ?? false);
        $generationVerifiedCount =
            (int)($benchmarkContext['managed_nginx_generation_verified_count'] ?? 0);
        $generationMismatchCount =
            (int)($benchmarkContext['managed_nginx_generation_mismatch_count'] ?? 0);
        $record(
            'managed_nginx_generation',
            $managedNginxGenerationRequired,
            $generationVerifiedCount > 0 && $generationMismatchCount === 0,
            [
                'verified_successes' => $generationVerifiedCount,
                'mismatches' => $generationMismatchCount,
            ],
            'every successful public-edge response must match the bound Nginx generation',
            'managed_nginx_generation_mismatch',
        );
        $record(
            'managed_nginx_owner_stability',
            $managedNginxGenerationRequired,
            (bool)($benchmarkContext['managed_nginx_owner_stable_before'] ?? false)
                && (bool)($benchmarkContext['managed_nginx_owner_stable_after'] ?? false),
            [
                'before' => (bool)($benchmarkContext['managed_nginx_owner_stable_before'] ?? false),
                'after' => (bool)($benchmarkContext['managed_nginx_owner_stable_after'] ?? false),
            ],
            'same Managed Nginx owner/PID/generation/ports before and after the measured run',
            'managed_nginx_owner_changed_during_benchmark',
        );
        $hostGatewayIdentityRequired =
            (bool)($benchmarkContext['host_gateway_identity_required'] ?? false);
        $record(
            'host_gateway_identity_stability',
            $hostGatewayIdentityRequired,
            (bool)($benchmarkContext['host_gateway_identity_stable_before'] ?? false)
                && (bool)($benchmarkContext['host_gateway_identity_stable_after'] ?? false),
            [
                'before' => (bool)($benchmarkContext['host_gateway_identity_stable_before'] ?? false),
                'after' => (bool)($benchmarkContext['host_gateway_identity_stable_after'] ?? false),
                'epoch' => (string)(
                    $benchmarkContext['_host_gateway_observation']['epoch'] ?? ''
                ),
                'route_generation' => (int)(
                    $benchmarkContext['_host_gateway_observation']['route_generation'] ?? 0
                ),
            ],
            'same signed gateway epoch and project route/certificate/backend identity before and after the measured run',
            'host_gateway_identity_changed_during_benchmark',
        );

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
        $allowedProtocols = [$expectedProtocol];
        if ($requestedProtocol === 'auto') {
            foreach ((array)($benchmarkContext['http_default_fallback'] ?? []) as $fallbackProtocol) {
                $normalizedFallback = \strtolower(\trim((string)$fallbackProtocol));
                $normalizedFallback = \str_replace(['http/', 'http'], '', $normalizedFallback);
                if (\in_array($normalizedFallback, ['1.1', '2', '3'], true)) {
                    $allowedProtocols[] = $normalizedFallback;
                }
            }
            $allowedProtocols = \array_values(\array_unique($allowedProtocols));
        }
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
        $workerRuntimeExpectation =
            (string)($benchmarkContext['worker_runtime_expectation'] ?? 'stable');
        $workerRuntimeChangeExpected = $workerRuntimeExpectation === 'changed';
        $workerRuntimeRequired = $workerRuntimeChangeExpected
            || (bool)($before['required'] ?? false)
            || (bool)($after['required'] ?? false);
        $workerRuntimeCaptured = (bool)($before['captured'] ?? false)
            && (bool)($after['captured'] ?? false);
        $workerRuntimeHealthy = $workerRuntimeCaptured
            && (bool)($before['healthy'] ?? false)
            && (bool)($after['healthy'] ?? false);
        $workerRuntimeIdentityComplete = $workerRuntimeHealthy
            && (bool)($before['identity_authoritative'] ?? false)
            && (bool)($after['identity_authoritative'] ?? false)
            && (bool)($before['lease_complete'] ?? false)
            && (bool)($after['lease_complete'] ?? false);
        $workerRuntimeStable = $workerRuntimeIdentityComplete
            && (int)($before['master_pid'] ?? 0) === (int)($after['master_pid'] ?? -1)
            && (array)($before['ready_fingerprint'] ?? []) === (array)($after['ready_fingerprint'] ?? []);
        $workerMasterStable = $workerRuntimeIdentityComplete
            && (int)($before['master_pid'] ?? 0) > 0
            && (int)($before['master_pid'] ?? 0) === (int)($after['master_pid'] ?? -1);
        $workerFingerprintChanged = $workerRuntimeIdentityComplete
            && (array)($before['ready_fingerprint'] ?? [])
                !== (array)($after['ready_fingerprint'] ?? []);
        $workerRuntimePassed = $workerRuntimeChangeExpected
            ? $workerMasterStable && $workerFingerprintChanged
            : $workerRuntimeStable;
        if (!$workerRuntimeCaptured || !$workerRuntimeHealthy) {
            $workerRuntimeFailureReason = 'worker_runtime_not_ready';
        } elseif (!$workerRuntimeIdentityComplete) {
            $workerRuntimeFailureReason = 'worker_runtime_identity_evidence_incomplete';
        } elseif ($workerRuntimeChangeExpected && !$workerMasterStable) {
            $workerRuntimeFailureReason = 'worker_master_changed_during_expected_reload';
        } elseif ($workerRuntimeChangeExpected) {
            $workerRuntimeFailureReason = 'worker_runtime_did_not_change';
        } else {
            $workerRuntimeFailureReason = 'worker_runtime_changed';
        }
        $record(
            'worker_runtime_stability',
            $workerRuntimeRequired,
            $workerRuntimePassed,
            [
                'expectation' => $workerRuntimeExpectation,
                'before_master_pid' => $before['master_pid'] ?? null,
                'after_master_pid' => $after['master_pid'] ?? null,
                'before_ready_workers' => $before['ready_workers'] ?? null,
                'after_ready_workers' => $after['ready_workers'] ?? null,
                'before_identity_source' => $before['identity_source'] ?? null,
                'after_identity_source' => $after['identity_source'] ?? null,
                'before_lease_complete' => $before['lease_complete'] ?? null,
                'after_lease_complete' => $after['lease_complete'] ?? null,
                'comparison_mode' => $workerRuntimeIdentityComplete
                    ? ($workerRuntimeChangeExpected
                        ? 'same_master_authoritative_ipc_fingerprint_change'
                        : 'authoritative_ipc_lease_fingerprint')
                    : 'fail_closed_incomplete_identity_evidence',
            ],
            $workerRuntimeChangeExpected
                ? 'same master with a changed healthy authoritative Worker fingerprint'
                : 'same_master_and_ready_worker_fingerprint',
            $workerRuntimeFailureReason
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
        $successCount = \array_key_exists('benchmark_success_count', $benchmarkContext)
            ? \max(0, (int)$benchmarkContext['benchmark_success_count'])
            : \count($results);
        $totalCompleted = $successCount + $errors;
        $precomputedLatencySummary = \is_array($benchmarkContext['benchmark_latency_summary'] ?? null)
            ? $benchmarkContext['benchmark_latency_summary']
            : null;
        if ($precomputedLatencySummary !== null) {
            $avgTime = (float)($precomputedLatencySummary['avg'] ?? 0.0);
            $minTime = (float)($precomputedLatencySummary['min'] ?? 0.0);
            $maxTime = (float)($precomputedLatencySummary['max'] ?? 0.0);
            $medianTime = (float)($precomputedLatencySummary['median'] ?? 0.0);
            $p95Time = (float)($precomputedLatencySummary['p95'] ?? 0.0);
            $p99Time = (float)($precomputedLatencySummary['p99'] ?? 0.0);
        } else {
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
            'curl_multi_cache_scope' => (string)($benchmarkContext['curl_multi_cache_scope'] ?? ''),
            'curl_multi_connection_reuse_enabled' =>
                (bool)($benchmarkContext['curl_multi_connection_reuse_enabled'] ?? false),
            'curl_multi_tls_session_cache_enabled' =>
                (bool)($benchmarkContext['curl_multi_tls_session_cache_enabled'] ?? false),
            'curl_multi_tls_session_resumption_verified' =>
                (bool)($benchmarkContext['curl_multi_tls_session_resumption_verified'] ?? false),
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
            'http_multiplex_scheduling_authorized' =>
                (bool)($benchmarkContext['http_multiplex_scheduling_authorized'] ?? false),
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
            'managed_nginx_generation_required' =>
                (bool)($benchmarkContext['managed_nginx_generation_required'] ?? false),
            'managed_nginx_generation_verified_count' =>
                (int)($benchmarkContext['managed_nginx_generation_verified_count'] ?? 0),
            'managed_nginx_generation_mismatch_count' =>
                (int)($benchmarkContext['managed_nginx_generation_mismatch_count'] ?? 0),
            'managed_nginx_owner_stable_before' => (bool)($benchmarkContext['managed_nginx_owner_stable_before'] ?? true),
            'managed_nginx_owner_stable_after' => (bool)($benchmarkContext['managed_nginx_owner_stable_after'] ?? true),
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
            'benchmark_target_surface' => (string)($benchmarkContext['benchmark_target_surface'] ?? 'unattributed_endpoint'),
            'target_endpoint_role' => (string)($benchmarkContext['target_endpoint_role'] ?? ''),
            'target_connect_host' => (string)($benchmarkContext['connect_host'] ?? ''),
            'target_authority_host' => (string)($benchmarkContext['authority_host'] ?? ''),
            'target_resolve_explicit' => (bool)($benchmarkContext['explicit_transport_resolve'] ?? false),
            'endpoint_policy_bound' => (bool)($benchmarkContext['endpoint_policy_bound'] ?? false),
            'endpoint_policy_source' => $benchmarkContext['endpoint_policy_source'] ?? null,
            'endpoint_edge_adapter' => $benchmarkContext['endpoint_edge_adapter'] ?? null,
            'endpoint_http_protocol_selection' => $benchmarkContext['endpoint_http_protocol_selection'] ?? null,
            'endpoint_policy_error' => $benchmarkContext['endpoint_policy_error'] ?? null,
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
            'worker_runtime_expectation' =>
                (string)($benchmarkContext['worker_runtime_expectation'] ?? 'stable'),
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
                '--authority-host <name>' => __('为跨主机压测指定 TLS SNI/HTTP Host；TCP 仍连接 --host 指定地址'),
                '-s, --ssl' => __('指定端口为 HTTPS（与 -p 合用；自动探测时根据实例配置）'),
                '--tls-version <auto|1.2|1.3>' => __('强制 HTTPS 压测使用指定 TLS 版本（默认 auto）'),
                '--http-version <auto|1.1|2|3>' => __('强制压测请求使用指定 HTTP 版本；auto 对 Managed Nginx 公网入口只选择已实测协议，内部回源固定 HTTP/1.1，未归因目标交由 cURL 协商'),
                '--physical-connections <n>' => __('HTTP/2/3 物理连接目标；默认按 READY Worker 数和服务端 Stream 容量自动计算，设为 1 可测单连接多路复用'),
                '--accept-encoding <auto|br,gzip|gzip|identity>' => __('请求内容编码；默认 identity 保持旧基线，auto 模拟浏览器并启用 cURL 支持的全部压缩'),
                '--no-keepalive, --spread' => __('禁用 keep-alive/连接复用（更利于验证连接级分流；HTTPS 时亦是 fresh TLS）'),
                '--worker-header <name>' => __('命中 Worker 统计使用的响应头（逗号分隔；默认自动探测 X-WLS-Worker-PID/Id/Port）'),
                '--worker-balance-threshold <ratio>' => __('fresh-connection 分流 max/min 硬门禁（默认 1.5）'),
                '--min-success-qps <n>' => __('成功 QPS 下限；0 表示不设置性能下限（--min-qps 为别名）'),
                '--max-error-rate <percent>' => __('错误率上限百分比（默认 0）'),
                '--max-p95-ms <ms>' => __('全部完成请求 P95 上限；0 表示禁用'),
                '--max-tls-p95-ms <ms>' => __('TLS 握手 P95 上限；0 表示禁用，启用后无握手样本亦失败'),
                '--expect-worker-runtime-change, --expect-reload' => __('预期压测期间发生滚动 reload：要求 Master 不变、前后 Worker 身份证据健康且代际指纹发生变化'),
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('基本压测（自动探测）') => 'php bin/w server:benchmark',
                __('指定 WLS 实例') => 'php bin/w server:benchmark --instance api-server',
                __('浏览器压缩首页') => 'php bin/w server:benchmark --instance api-server --path / --accept-encoding auto',
                __('高并发') => 'php bin/w server:benchmark -c 500 -n 50000',
                __('分流验证（禁用 keep-alive）') => 'php bin/w server:benchmark -c 500 -n 50000 --no-keepalive',
                __('统计 Worker 分布') => 'php bin/w server:benchmark --instance api-server --path /_wls/health --worker-header X-WLS-Worker-Port',
                __('分流倾斜阈值检查') => 'php bin/w server:benchmark --instance api-server --path /_wls/health --worker-balance-threshold 1.3',
                __('指定端口') => 'php bin/w server:benchmark -p 9000',
                __('指定 HTTPS 端口') => 'php bin/w server:benchmark -p 15443 --ssl',
                __('跨主机 HTTP/2 压测') => 'php bin/w server:benchmark --host 10.0.0.8 --authority-host app.weline.test -p 15443 --ssl --http-version 2 --physical-connections 3',
                __('HTTP/2 协商验证') => 'php bin/w server:benchmark -p 15443 --ssl --http-version 2',
                __('HTTP/2 单物理连接多路复用') => 'php bin/w server:benchmark -p 15443 --ssl --http-version 2 --physical-connections 1',
                __('HTTP/3 协商验证') => 'php bin/w server:benchmark -p 15443 --ssl --http-version 3 --accept-encoding auto',
                __('TLS 1.3 fresh connection') => 'php bin/w server:benchmark -p 15443 --ssl --tls-version 1.3 --no-keepalive',
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
            \CURLOPT_HTTP_VERSION => $this->curlHttpVersionOption(
                $httpVersion,
                (string)($benchmarkContext['http_version_requested'] ?? '') === 'auto',
            ),
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
        $generationRequired = (string)($benchmarkContext['benchmark_target_surface'] ?? '') === 'public_edge';
        $generationObserved = (string)($headers['x-wls-nginx-config'] ?? '');
        $generationVerified = $this->responseMatchesManagedNginxGeneration(
            $benchmarkContext,
            $headers,
        );

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
            'nginx_generation_required' => $generationRequired,
            'nginx_generation_observed' => $generationObserved,
            'nginx_generation_verified' => $generationVerified,
            'wire_to_logical_ratio' => $logicalBytes > 0 ? \round($wireBytes / $logicalBytes, 6) : null,
            'http_status' => $status,
            'curl_http_version_id' => $httpVersionId,
            'measurement' => 'single_unmeasured_warmup_probe',
        ];
    }

    /**
     * Apply transport-only resolve and proxy options for a benchmark endpoint.
     *
     * The URL authority remains unchanged so TLS SNI and HTTP Host keep using the
     * public instance name. Only the TCP destination is resolved to the explicit
     * connect host. Explicit routes and locally managed endpoints bypass ambient
     * proxies; other unattributed non-loopback targets retain the caller's policy.
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
        $explicitTransportResolve = (bool)($benchmarkContext['explicit_transport_resolve'] ?? false);
        $directTransportRoute = $explicitTransportResolve
            || $this->isLoopbackHost($connectHost)
            || \in_array($targetAttribution, [
                'explicit_instance',
                'single_running_instance',
                'unique_live_public_edge_match',
                'unique_live_backend_match',
            ], true);

        if (!$directTransportRoute) {
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
        $endpointEdgeAdapter = \strtolower(\trim((string)($serverConfig['edge_adapter'] ?? '')));
        $targetAttribution = (string)($serverConfig['target_attribution'] ?? 'unattributed');
        $targetAttributed = \in_array($targetAttribution, [
            'explicit_instance',
            'single_running_instance',
            'unique_live_public_edge_match',
            'unique_live_backend_match',
        ], true);
        $requestedTargetSurface = \strtolower(\trim((string)($serverConfig['target_surface'] ?? '')));
        $targetSurfaceValid = \in_array($requestedTargetSurface, ['public_edge', 'wls_endpoint'], true);
        $endpointSelection = null;
        $endpointPolicyError = null;
        $selectionData = $serverConfig['http_protocol_selection'] ?? null;
        if (\is_array($selectionData) && $selectionData !== []) {
            try {
                $endpointSelection = HttpProtocolSelection::fromArray($selectionData);
                $endpointSelection->assertCompatibleEdgeAdapter($endpointEdgeAdapter);
            } catch (\Throwable $exception) {
                $endpointPolicyError = $exception->getMessage();
                $endpointSelection = null;
            }
        } elseif ($targetAttributed) {
            $endpointPolicyError = 'persisted http_protocol_selection is missing';
        }
        $endpointEdgeValid = \in_array($endpointEdgeAdapter, [
            \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX,
            \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS,
        ], true);
        if ($targetAttributed && !$endpointEdgeValid) {
            $endpointPolicyError = 'persisted edge_adapter is not nginx or wls';
        }
        if ($targetAttributed && !$targetSurfaceValid) {
            $endpointPolicyError = 'benchmark target surface is missing or invalid';
        }
        $endpointPolicyBound = $targetAttributed
            && $endpointEdgeValid
            && $endpointSelection !== null
            && $targetSurfaceValid;
        $endpointPolicySource = $endpointPolicyBound
            ? 'persisted_endpoint'
            : ($targetAttributed ? 'persisted_endpoint_unbound' : 'unattributed_target');
        $targetManagedNginx = \is_array($serverConfig['managed_nginx'] ?? null)
            ? $serverConfig['managed_nginx']
            : [];
        $targetHostGateway = \is_array($serverConfig['host_gateway'] ?? null)
            ? $serverConfig['host_gateway']
            : [];
        if ($endpointPolicyBound
            && $endpointEdgeAdapter === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX
            && $requestedTargetSurface === 'public_edge'
            && $targetHostGateway === []
        ) {
            try {
                $currentManagedNginx = ManagedNginxService::fromEnv()->doctorSnapshot();
            } catch (\Throwable) {
                $currentManagedNginx = [];
            }
            if (!$this->managedNginxObservationMatches($targetManagedNginx, $currentManagedNginx)) {
                $endpointPolicyBound = false;
                $endpointPolicySource = 'persisted_endpoint_unbound';
                $endpointPolicyError =
                    'Managed Nginx owner identity changed while the benchmark target was being bound';
            }
        }
        if ($endpointPolicyBound
            && $endpointEdgeAdapter === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX
            && $requestedTargetSurface === 'public_edge'
            && $targetHostGateway !== []
        ) {
            try {
                $hostGatewayMatches = $this->hostGatewayObservationStillMatches(
                    $targetHostGateway,
                    (string)($serverConfig['host'] ?? ''),
                    (string)($serverConfig['authority_host'] ?? $serverConfig['host'] ?? ''),
                );
            } catch (\Throwable) {
                $hostGatewayMatches = false;
            }
            if (!$hostGatewayMatches) {
                $endpointPolicyBound = false;
                $endpointPolicySource = 'persisted_endpoint_unbound';
                $endpointPolicyError =
                    'Host gateway identity or active project route changed while the benchmark target was being bound';
            }
        }
        $httpProtocolCapabilities = (new HttpProtocolCapabilityProbe())->snapshot(
            $endpointEdgeValid ? $endpointEdgeAdapter : null,
            $endpointSelection,
            $endpointPolicyBound,
            $endpointPolicySource,
            null,
        );
        if ($endpointPolicyBound && $targetHostGateway !== []) {
            $httpProtocolCapabilities = $this->bindHostGatewayProtocolCapabilities(
                $httpProtocolCapabilities,
                $targetHostGateway,
                $endpointPolicySource,
            );
        }
        $httpDefaultPolicy = \is_array($httpProtocolCapabilities['default_policy'] ?? null)
            ? $httpProtocolCapabilities['default_policy']
            : [];
        $httpPolicySurfaces = \is_array($httpDefaultPolicy['surfaces'] ?? null)
            ? $httpDefaultPolicy['surfaces']
            : [];
        $benchmarkTargetSurface = match (true) {
            $endpointPolicyBound => $requestedTargetSurface,
            $targetAttributed && $targetSurfaceValid => $requestedTargetSurface . '_unbound',
            $targetAttributed => 'attributed_endpoint_unbound',
            default => 'unattributed_endpoint',
        };
        $targetPolicy = $endpointPolicyBound
            ? (\is_array($httpPolicySurfaces[$requestedTargetSurface] ?? null)
                ? $httpPolicySurfaces[$requestedTargetSurface]
                : $httpDefaultPolicy)
            : [];
        $edgeSnapshot = \is_array($httpProtocolCapabilities['edge'] ?? null)
            ? $httpProtocolCapabilities['edge']
            : [];
        $managedNginx = \is_array($edgeSnapshot['managed_nginx'] ?? null)
            ? $edgeSnapshot['managed_nginx']
            : [];
        $hostGatewayEdge = \is_array($edgeSnapshot['host_gateway'] ?? null)
            ? $edgeSnapshot['host_gateway']
            : [];
        $verifiedTargetProtocols = $endpointPolicyBound
            ? $this->runtimeVerifiedProtocolsForSurface($httpProtocolCapabilities, $requestedTargetSurface)
            : [];
        $clientMultiplexOptionEnabled = \defined('CURLPIPE_MULTIPLEX');
        $explicitTransportResolve = (bool)($serverConfig['explicit_transport_resolve'] ?? false);
        $wlsAdapters = \is_array($httpProtocolCapabilities['wls_adapters'] ?? null)
            ? $httpProtocolCapabilities['wls_adapters']
            : [];
        $pureWlsMultiplexVerified = $endpointEdgeAdapter
            === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS
            && $requestedTargetSurface === 'wls_endpoint'
            && (bool)($wlsAdapters['http2']['enabled'] ?? false)
            && (bool)($wlsAdapters['http2']['runtime_verified'] ?? false);
        $multiplexVerified = $clientMultiplexOptionEnabled
            && $endpointPolicyBound
            && (
                ($requestedTargetSurface === 'public_edge'
                    && ((bool)($managedNginx['http2_runtime_verified'] ?? false)
                        || (bool)($managedNginx['http3_runtime_verified'] ?? false)
                        || (bool)($hostGatewayEdge['http2_runtime_verified'] ?? false)))
                || $pureWlsMultiplexVerified
            );
        $explicitRemoteMultiplex = $clientMultiplexOptionEnabled
            && $explicitTransportResolve
            && !$noKeepAlive
            && \in_array($httpVersion, ['2', '3'], true);
        $multiplexSchedulingAuthorized = $multiplexVerified || $explicitRemoteMultiplex;
        // Conservative client scheduling budget, not an inferred Nginx setting.
        // The run still records actual physical connections and negotiated
        // versions before passing its multiplex quality gates.
        $multiplexMaxConcurrentStreams = $multiplexSchedulingAuthorized ? 64 : 0;

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
            'connection_share_enabled' => false,
            'ssl_session_share_supported' => \defined('CURL_LOCK_DATA_SSL_SESSION'),
            'ssl_session_share_enabled' => false,
            'curl_multi_cache_scope' => 'per-physical-connection-lane',
            'curl_multi_connection_reuse_enabled' => !$noKeepAlive,
            'curl_multi_tls_session_cache_enabled' => $ssl,
            'curl_multi_tls_session_resumption_verified' => false,
            'curl_multiplex_option_enabled' => $clientMultiplexOptionEnabled,
            'curl_pipewait_supported' => \defined('CURLOPT_PIPEWAIT'),
            'curl_pipewait_enabled' => false,
            'curl_max_concurrent_streams_supported' => \defined('CURLMOPT_MAX_CONCURRENT_STREAMS'),
            'http_multiplex_capability_verified' => $multiplexVerified,
            'http_multiplex_scheduling_authorized' => $multiplexSchedulingAuthorized,
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
            'benchmark_target_surface' => $benchmarkTargetSurface,
            'endpoint_policy_bound' => $endpointPolicyBound,
            'endpoint_policy_source' => $endpointPolicySource,
            'endpoint_edge_adapter' => $endpointEdgeValid ? $endpointEdgeAdapter : null,
            'endpoint_http_protocol_selection' => $endpointSelection?->toArray(),
            'endpoint_policy_error' => $endpointPolicyError,
            'endpoint_http3_activation' => null,
            'http_default_target' => $targetPolicy['target_preferred'] ?? null,
            'http_default_effective' => $verifiedTargetProtocols[0] ?? null,
            'http_default_fallback' => \array_slice($verifiedTargetProtocols, 1),
            'http3_data_plane_enabled' => $endpointPolicyBound
                && $requestedTargetSurface === 'public_edge'
                && (bool)($managedNginx['http3_runtime_verified'] ?? false),
            'managed_nginx_generation_required' =>
                $endpointPolicyBound
                && $endpointEdgeAdapter === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX
                && $requestedTargetSurface === 'public_edge'
                && $targetHostGateway === [],
            'managed_nginx_expected_generation' =>
                $endpointPolicyBound
                && $endpointEdgeAdapter === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX
                && $requestedTargetSurface === 'public_edge'
                && $targetHostGateway === []
                    ? (string)($targetManagedNginx['owner_config_generation'] ?? '')
                    : '',
            '_managed_nginx_owner_observation' =>
                $endpointPolicyBound
                && $endpointEdgeAdapter === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX
                && $requestedTargetSurface === 'public_edge'
                && $targetHostGateway === []
                    ? $targetManagedNginx : [],
            'host_gateway_identity_required' =>
                $endpointPolicyBound
                && $endpointEdgeAdapter === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX
                && $requestedTargetSurface === 'public_edge'
                && $targetHostGateway !== [],
            '_host_gateway_observation' =>
                $endpointPolicyBound
                && $endpointEdgeAdapter === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_NGINX
                && $requestedTargetSurface === 'public_edge'
                    ? $targetHostGateway : [],
            'http3_data_plane_reason' => $endpointEdgeAdapter
                === \Weline\Server\Service\Edge\EdgeAdapterInterface::NAME_WLS
                    ? 'Pure WLS HTTP/3 is unavailable; use managed Nginx.'
                    : ($requestedTargetSurface === 'public_edge'
                        ? (string)($targetPolicy['http3_reason'] ?? 'Managed Nginx HTTP/3 is not runtime verified.')
                        : 'Managed Nginx owns public HTTP/3; the internal WLS backend is HTTP/1.1 only.'),
            'http_protocol_capabilities' => $httpProtocolCapabilities,
            'instance_name' => (string)($serverConfig['instance'] ?? ''),
            'target_attribution' => $targetAttribution,
            'target_endpoint_role' => (string)($serverConfig['target_endpoint_role'] ?? ''),
            'connect_host' => (string)($serverConfig['host'] ?? ''),
            'authority_host' => (string)($serverConfig['authority_host'] ?? $serverConfig['host'] ?? ''),
            'explicit_transport_resolve' => $explicitTransportResolve,
            'runtime_metadata_source' => $runtime['metadata_source'] ?? null,
            'endpoint_schema_version' => $runtime['endpoint_schema_version'] ?? null,
            'runtime_selection' => $runtimeSelection,
            'worker_count' => (int)($serverConfig['worker_count'] ?? 0),
            'benchmark_carrier_role' => (string)($serverConfig['benchmark_carrier_role']
                ?? ControlMessage::ROLE_WORKER),
            'benchmark_expected_carriers' => \max(
                0,
                (int)($serverConfig['benchmark_expected_carriers'] ?? 0),
            ),
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
    private function assertRequestedHttpVersionIsRunnable(
        string $requested,
        bool $ssl,
        array $capabilities,
        string $targetSurface,
    ): void
    {
        if (\str_ends_with($targetSurface, '_unbound')) {
            throw new \RuntimeException((string)__('已归因目标缺少完整的边缘身份或协议运行证据，拒绝开始压测。'));
        }

        if (\in_array($targetSurface, ['public_edge', 'wls_endpoint'], true)) {
            $allowed = $this->runtimeVerifiedProtocolsForSurface($capabilities, $targetSurface);
            if ($allowed === []) {
                throw new \RuntimeException((string)__('目标没有任何已完成运行验证的 HTTP 协议。'));
            }
            if ($requested === 'auto') {
                return;
            }
            $requestedProtocol = match ($requested) {
                '1.1' => 'http/1.1',
                '2' => 'http/2',
                '3' => 'http/3',
                default => '',
            };
            if (!\in_array($requestedProtocol, $allowed, true)) {
                $binding = \is_array($capabilities['endpoint_policy_binding'] ?? null)
                    ? $capabilities['endpoint_policy_binding']
                    : [];
                $surfaceLabel = $targetSurface === 'public_edge'
                    ? __('Managed Nginx 公网入口')
                    : ((string)($binding['edge_adapter'] ?? '') === 'wls'
                        ? __('纯 WLS 公网入口')
                        : __('内部 WLS HTTP/1.1 回源'));
                throw new \RuntimeException((string)__(
                    '%{1} 尚未实测验证 HTTP/%{2}，拒绝把配置能力当作压测证据。',
                    [(string)$surfaceLabel, $requested],
                ));
            }
        } elseif ($requested === 'auto' || $requested === '1.1') {
            return;
        }

        if (\in_array($requested, ['2', '3'], true) && !$ssl) {
            throw new \RuntimeException((string)__('当前 Nginx 公网 HTTP/2 与 HTTP/3 门禁要求 HTTPS 目标。'));
        }
        $curl = (array)($capabilities['curl_client'] ?? []);
        if ($requested === '2'
            && (!(bool)($curl['http2_constant'] ?? false)
                || !(bool)($curl['http2_feature'] ?? false))
        ) {
            throw new \RuntimeException((string)__('当前 PHP cURL/libcurl 不能发起 HTTP/2 请求。'));
        }
        if ($requested !== '3') {
            return;
        }

        $curlHttp3 = (bool)($curl['http3_constant'] ?? false) && (bool)($curl['http3_feature'] ?? false);
        if (!$curlHttp3) {
            throw new \RuntimeException((string)__('当前 PHP cURL/libcurl 不能发起 HTTP/3 请求；可使用 auto 回退到已验证的 Nginx HTTP/2/1.1。'));
        }
    }

    /**
     * Replace project-managed Nginx capability guesses with live, signed
     * project-route evidence from the host gateway.
     *
     * @param array<string,mixed> $capabilities
     * @param array<string,mixed> $observation
     * @return array<string,mixed>
     */
    private function bindHostGatewayProtocolCapabilities(
        array $capabilities,
        array $observation,
        string $policySource,
    ): array {
        $observedProtocols = \is_array($observation['public_protocols'] ?? null)
            ? \array_values(\array_filter(\array_map(
                static fn(mixed $protocol): string => \strtolower(\trim((string)$protocol)),
                $observation['public_protocols'],
            )))
            : [];
        $order = [];
        foreach (['http/2', 'http/1.1'] as $protocol) {
            if (\in_array($protocol, $observedProtocols, true)) {
                $order[] = $protocol;
            }
        }
        $defaultPolicy = \is_array($capabilities['default_policy'] ?? null)
            ? $capabilities['default_policy']
            : [];
        $surfaces = \is_array($defaultPolicy['surfaces'] ?? null)
            ? $defaultPolicy['surfaces']
            : [];
        $surfaces['public_edge'] = [
            'owner' => 'host_gateway',
            'role' => 'public_https',
            'target_preferred' => $order[0] ?? null,
            'effective_preferred' => $order[0] ?? null,
            'fallback' => \array_slice($order, 1),
            'negotiation_order' => $order,
            'policy_bound' => true,
            'policy_source' => $policySource,
            'runtime_verified' => $order !== [],
            'capability_verified' => $order !== [],
            'observed_preferred' => $order[0] ?? null,
            'verification_required' => 'signed own-status plus authenticated public sentinel ALPN probes',
            'http3_when_available' => false,
            'http3_reason' => 'Project-scoped gateway status does not publish live HTTP/3 evidence.',
        ];
        $defaultPolicy['surfaces'] = $surfaces;
        $capabilities['default_policy'] = $defaultPolicy;
        $edge = \is_array($capabilities['edge'] ?? null) ? $capabilities['edge'] : [];
        $edge['host_gateway'] = [
            'protocol' => (string)($observation['protocol'] ?? ''),
            'epoch' => (string)($observation['epoch'] ?? ''),
            'route_id' => (string)($observation['route_id'] ?? ''),
            'route_generation' => (int)($observation['route_generation'] ?? 0),
            'public_protocols' => $order,
            'http1_runtime_verified' => \in_array('http/1.1', $order, true),
            'http2_runtime_verified' => \in_array('http/2', $order, true),
            'http3_runtime_verified' => false,
        ];
        $capabilities['edge'] = $edge;

        return $capabilities;
    }

    /**
     * Return only protocols with live evidence on the selected data plane.
     *
     * @param array<string,mixed> $capabilities
     * @return list<string>
     */
    private function runtimeVerifiedProtocolsForSurface(array $capabilities, string $targetSurface): array
    {
        $policy = \is_array($capabilities['default_policy'] ?? null)
            ? $capabilities['default_policy']
            : [];
        $surfaces = \is_array($policy['surfaces'] ?? null) ? $policy['surfaces'] : [];
        $surface = \is_array($surfaces[$targetSurface] ?? null)
            ? $surfaces[$targetSurface]
            : [];
        if ($targetSurface === 'wls_endpoint') {
            $order = \is_array($surface['negotiation_order'] ?? null)
                ? $surface['negotiation_order']
                : [];
            if ((string)($surface['role'] ?? '') === 'nginx_backend') {
                return \in_array('http/1.1', $order, true) ? ['http/1.1'] : [];
            }
            $wlsAdapters = \is_array($capabilities['wls_adapters'] ?? null)
                ? $capabilities['wls_adapters']
                : [];
            $verified = [
                'http/2' => (bool)($wlsAdapters['http2']['enabled'] ?? false)
                    && (bool)($wlsAdapters['http2']['runtime_verified'] ?? false),
                'http/1.1' => (bool)($wlsAdapters['http1']['enabled'] ?? false)
                    && (bool)($wlsAdapters['http1']['runtime_verified'] ?? false),
            ];
            $protocols = [];
            foreach ($order as $protocol) {
                $protocol = \strtolower(\trim((string)$protocol));
                if (($verified[$protocol] ?? false) && !\in_array($protocol, $protocols, true)) {
                    $protocols[] = $protocol;
                }
            }
            return $protocols;
        }
        $surfaceOwner = (string)($surface['owner'] ?? '');
        if ($targetSurface !== 'public_edge'
            || !\in_array($surfaceOwner, ['nginx', 'host_gateway'], true)
        ) {
            return [];
        }

        $edge = \is_array($capabilities['edge'] ?? null) ? $capabilities['edge'] : [];
        $managed = $surfaceOwner === 'host_gateway'
            ? (\is_array($edge['host_gateway'] ?? null) ? $edge['host_gateway'] : [])
            : (\is_array($edge['managed_nginx'] ?? null) ? $edge['managed_nginx'] : []);
        $verified = [
            'http/3' => (bool)($managed['http3_runtime_verified'] ?? false),
            'http/2' => (bool)($managed['http2_runtime_verified'] ?? false),
            'http/1.1' => (bool)($managed['http1_runtime_verified'] ?? false),
        ];
        $order = \is_array($surface['negotiation_order'] ?? null)
            ? $surface['negotiation_order']
            : [];
        $protocols = [];
        foreach ($order as $protocol) {
            $protocol = \strtolower(\trim((string)$protocol));
            if (($verified[$protocol] ?? false) && !\in_array($protocol, $protocols, true)) {
                $protocols[] = $protocol;
            }
        }

        return $protocols;
    }

    /** @param array<string,mixed> $capabilities */
    private function resolveEffectiveHttpVersion(
        string $requested,
        bool $ssl,
        array $capabilities,
        string $targetSurface,
    ): string
    {
        if ($requested !== 'auto') {
            return $requested;
        }
        if (\str_ends_with($targetSurface, '_unbound')) {
            throw new \RuntimeException((string)__('已归因目标的边缘身份或协议策略未绑定，auto 已拒绝降级猜测。'));
        }
        if (!\in_array($targetSurface, ['public_edge', 'wls_endpoint'], true)) {
            return 'auto';
        }

        $curl = \is_array($capabilities['curl_client'] ?? null) ? $capabilities['curl_client'] : [];
        foreach ($this->runtimeVerifiedProtocolsForSurface($capabilities, $targetSurface) as $protocol) {
            if ($protocol === 'http/3'
                && $ssl
                && (bool)($curl['http3_constant'] ?? false)
                && (bool)($curl['http3_feature'] ?? false)
            ) {
                return '3';
            }
            if ($protocol === 'http/2'
                && $ssl
                && (bool)($curl['http2_constant'] ?? false)
                && (bool)($curl['http2_feature'] ?? false)
            ) {
                return '2';
            }
            if ($protocol === 'http/1.1') {
                return '1.1';
            }
        }

        throw new \RuntimeException((string)__('目标与当前 cURL 没有共同且已完成运行验证的 HTTP 协议。'));
    }


    private function curlHttpVersionOption(string $httpVersion, bool $allowFallback = false): int
    {
        return match ($httpVersion) {
            'auto' => \defined('CURL_HTTP_VERSION_NONE') ? (int)\constant('CURL_HTTP_VERSION_NONE') : 0,
            '1.1' => CURL_HTTP_VERSION_1_1,
            '2' => $this->requireCurlHttpVersionConstant('CURL_HTTP_VERSION_2_0', 'HTTP/2'),
            // A protocol verification run must never silently count HTTP/2
            // fallback as HTTP/3 success. CURL_HTTP_VERSION_3 permits fallback;
            // Auto uses fallback-capable HTTP/3. Explicit --http-version 3
            // remains an exact transport assertion through 3ONLY.
            '3' => $allowFallback
                ? $this->requireCurlHttpVersionConstant('CURL_HTTP_VERSION_3', 'fallback-capable HTTP/3')
                : $this->requireCurlHttpVersionConstant('CURL_HTTP_VERSION_3ONLY', 'HTTP/3-only'),
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
