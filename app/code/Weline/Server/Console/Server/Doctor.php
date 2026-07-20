<?php
declare(strict_types=1);

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\Runtime\HttpProtocolCapabilityProbe;
use Weline\Server\Service\Runtime\RuntimeCapabilityDetector;
use Weline\Server\Service\Runtime\RuntimeDiagnosticsFormatter;
use Weline\Server\Service\Runtime\RuntimeEndpointMetadata;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\Runtime\RuntimeStrategyResolver;
use Weline\Server\Service\ServerInstanceManager;

/**
 * server:doctor - read-only WLS runtime diagnostics.
 */
class Doctor extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        $json = isset($args['json']);
        $instanceName = $this->parseInstanceName($args);
        $diagnostics = $this->buildDiagnostics($instanceName);

        if ($json) {
            echo \json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            return;
        }

        $this->printer->setup('WLS Doctor');
        $this->printer->note('Instance: ' . $instanceName);
        $this->printer->note('Status: ' . (string)$diagnostics['status']);
        $strategy = \is_array($diagnostics['strategy'] ?? null) ? $diagnostics['strategy'] : [];
        foreach ((new RuntimeDiagnosticsFormatter())->formatStartupSummary(
            (new RuntimeCapabilityDetector())->detect(),
            $strategy
        ) as $line) {
            if (\str_starts_with($line, 'WARNING:') || \str_starts_with($line, 'Warning:')) {
                $this->printer->warning($line);
            } elseif (\str_starts_with($line, 'INFO:')) {
                $this->printer->note($line);
            } else {
                $this->printer->note($line);
            }
        }
        $protocols = \is_array($diagnostics['protocols'] ?? null) ? $diagnostics['protocols'] : [];
        $policy = \is_array($protocols['default_policy'] ?? null) ? $protocols['default_policy'] : [];
        $adapters = \is_array($protocols['wls_adapters'] ?? null) ? $protocols['wls_adapters'] : [];
        $edge = \is_array($protocols['edge'] ?? null) ? $protocols['edge'] : [];
        $http3Adapter = \is_array($adapters['http3'] ?? null) ? $adapters['http3'] : [];
        $http3Ready = (bool)($http3Adapter['enabled'] ?? false)
            && (bool)($http3Adapter['runtime_verified'] ?? false);
        $this->printer->note(__('边缘适配器：%{1}（原生 HTTP/2=%{2}，原生 HTTP/3=%{3}）', [
            (string)($edge['adapter'] ?? ($policy['edge_adapter'] ?? 'nginx')),
            (string)($edge['native_http2'] ?? ($adapters['http2']['edge_status'] ?? __('未知'))),
            (string)($edge['native_http3'] ?? ($adapters['http3']['edge_status'] ?? __('未知'))),
        ]));
        if (($edge['adapter'] ?? '') === 'nginx') {
            $reloadConfigured = (bool)($edge['reload_command_configured'] ?? false);
            $this->printer->note(__('Nginx 边缘 reload 命令：%{1}', [
                $reloadConfigured ? (string)($edge['reload_command'] ?? '') : __('未配置（证书更新后不会自动 reload）'),
            ]));
            $managed = \is_array($edge['managed_nginx'] ?? null) ? $edge['managed_nginx'] : [];
            if ($managed !== []) {
                $isManaged = (bool)($managed['managed'] ?? false);
                $managedMode = (string)($managed['managed_mode'] ?? ($isManaged ? 'true' : 'false'));
                $hostBinary = (string)($managed['host_nginx_binary'] ?? '');
                if ($isManaged) {
                    $this->printer->note(__('Nginx 模式：WLS 托管（本项目 extend/server/nginx，managed=%{1}）', [$managedMode]));
                    $this->printer->note(__('托管 Nginx：已安装=%{1}，运行中=%{2}，HTTP=%{3}，HTTPS=%{4}，偏移=%{5}', [
                        (bool)($managed['installed'] ?? false) ? __('是') : __('否'),
                        (bool)($managed['running'] ?? false) ? __('是') : __('否'),
                        (string)($managed['listen_http'] ?? ''),
                        (string)($managed['listen_https'] ?? ''),
                        (string)($managed['project_offset'] ?? ''),
                    ]));
                } elseif ($managedMode === 'auto' && $hostBinary !== '') {
                    $this->printer->note(__(
                        'Nginx 模式：自动检测为宿主机（%{1}）— WLS 仅处理业务回源，不安装/不启停托管 Nginx',
                        [$hostBinary]
                    ));
                } else {
                    $this->printer->note(__(
                        'Nginx 模式：宿主机（managed=false）— WLS 仅处理业务回源，不安装/不启停托管 Nginx；由用户自配系统 Nginx 反代'
                    ));
                }
            } else {
                $this->printer->note(__(
                    'Nginx 模式：边缘适配器为 nginx（未读到托管快照时，请检查 wls.edge.nginx 配置）'
                ));
            }
        }
        $this->printer->note(__('HTTP 默认：目标=%{1}，实际=%{2}', [
            (string)($policy['target_preferred'] ?? 'http/2'),
            (string)($policy['effective_preferred'] ?? __('未知')),
        ]));
        $this->printer->note(__('HTTP/3 就绪状态：%{1}', [
            $http3Ready ? __('就绪') : __('未就绪'),
        ]));
        $tlsSessionReuse = \is_array($policy['tls_session_reuse'] ?? null) ? $policy['tls_session_reuse'] : [];
        $http3TlsSessionReuse = \is_array($policy['http3_tls_session_resumption'] ?? null)
            ? $policy['http3_tls_session_resumption']
            : [];
        $crossWorkerTicket = \is_array($policy['cross_worker_session_ticket'] ?? null) ? $policy['cross_worker_session_ticket'] : [];
        $this->printer->note(__('TLS 运行时能力：TLS 1.3 服务端=%{1}，本机 TLS 1.3 握手=%{2}，本机 HTTP/2 ALPN=%{3}；实际端点协商以 benchmark/handshake 探针为准', [
            (bool)($policy['tls13_server_supported'] ?? false) ? __('是') : __('否'),
            (bool)($policy['tls13_runtime_verified'] ?? false) ? __('已验证') : __('未验证'),
            (bool)($policy['alpn_http2'] ?? false) ? __('已验证') : __('未验证'),
        ]));
        $tcpTlsExternalApiAvailable = (bool)($tlsSessionReuse['external_stateful_session_api_available'] ?? false);
        $tcpTlsExternalCacheConfigValid = (bool)($tlsSessionReuse['external_cache_config_valid'] ?? true);
        $tcpTlsExternalCacheConfigured = (bool)($tlsSessionReuse['external_cache_configured'] ?? false);
        $tcpTlsDurableEvidenceVerified = (bool)($tlsSessionReuse['durable_evidence_verified'] ?? false);
        $tcpTlsActiveConfigMatches = (bool)($tlsSessionReuse['active_config_matches_evidence'] ?? false);
        $tcpTlsCurrentScopeEvaluated = (bool)($tlsSessionReuse['current_scope_evaluated'] ?? false);
        $tcpTlsCurrentScopeMatches = (bool)($tlsSessionReuse['current_scope_matches_evidence'] ?? false);
        $tcpTlsRuntimePrerelease = (bool)($tlsSessionReuse['runtime_prerelease'] ?? true);
        $tcpTlsProductionReady = (bool)($tlsSessionReuse['production_ready'] ?? false);
        $tcpTlsSessionStatus = match (true) {
            $tcpTlsProductionReady => __('生产门禁已全部通过'),
            !$tcpTlsExternalCacheConfigValid => __('配置无效，启动前必须修复'),
            !$tcpTlsExternalApiAvailable => __('不可用（需要 PHP 8.6 外部有状态 Session API）'),
            !$tcpTlsExternalCacheConfigured => __('API 可用但外部有状态缓存未启用'),
            !$tcpTlsDurableEvidenceVerified => __('已配置，等待当前 runtime 的持久功能证据'),
            !$tcpTlsActiveConfigMatches => __('持久功能证据已验证，但活动配置与证据不匹配'),
            !$tcpTlsCurrentScopeEvaluated => __('持久功能证据与活动配置匹配；当前活动实例 scope 未评估'),
            !$tcpTlsCurrentScopeMatches => __('持久功能证据已验证，但当前活动实例 scope 不匹配'),
            default => __('当前活动实例 scope 匹配；仍有独立生产门禁未通过'),
        };
        $this->printer->note(__('TCP TLS Session 恢复：%{1}', [$tcpTlsSessionStatus]));
        $this->printer->note(__('TCP TLS 持久功能证据（不等同当前 active/production）：同 Worker=%{1}，跨 Worker=%{2}，reload 连续性=%{3}，Sidecar 恢复=%{4}', [
            (bool)($tlsSessionReuse['same_worker_session_resumption_verified'] ?? false) ? __('已验证') : __('待验证'),
            (bool)($tlsSessionReuse['cross_worker_session_resumption_verified'] ?? false) ? __('已验证') : __('待验证'),
            (bool)($tlsSessionReuse['reload_continuity_verified'] ?? false) ? __('已验证') : __('待验证'),
            (bool)($tlsSessionReuse['sidecar_recovery_verified'] ?? false) ? __('已验证') : __('待验证'),
        ]));
        $tcpTlsConfigEvidenceStatus = match (true) {
            !$tcpTlsExternalCacheConfigValid => __('无效'),
            !$tcpTlsExternalCacheConfigured => __('已禁用'),
            !$tcpTlsDurableEvidenceVerified => __('未建立'),
            $tcpTlsActiveConfigMatches => __('匹配'),
            default => __('不匹配'),
        };
        $tcpTlsScopeStatus = !$tcpTlsCurrentScopeEvaluated
            ? __('未评估')
            : ($tcpTlsCurrentScopeMatches ? __('匹配') : __('不匹配'));
        $this->printer->note(__('TCP TLS 当前状态：配置与证据=%{1}，活动实例 scope=%{2}，PHP 发行通道=%{3}', [
            $tcpTlsConfigEvidenceStatus,
            $tcpTlsScopeStatus,
            $tcpTlsRuntimePrerelease ? __('预发布（生产阻塞）') : __('稳定'),
        ]));
        $tcpTlsHasObservedP95 = \is_numeric($tlsSessionReuse['resumption_tls_p95_ms'] ?? null);
        $tcpTlsObservedP95 = $tcpTlsHasObservedP95
            ? \sprintf('%.3f ms', (float)$tlsSessionReuse['resumption_tls_p95_ms'])
            : __('未记录');
        $tcpTlsProductionP95Limit = \sprintf(
            '%.3f ms',
            (float)($tlsSessionReuse['production_resumption_tls_p95_limit_ms'] ?? 50.0)
        );
        $tcpTlsDiagnosticP95Limit = \is_numeric(
            $tlsSessionReuse['diagnostic_resumption_tls_p95_limit_ms'] ?? null
        ) ? \sprintf(
            '%.3f ms',
            (float)$tlsSessionReuse['diagnostic_resumption_tls_p95_limit_ms']
        ) : __('未记录');
        $tcpTlsLatencyGateStatus = !$tcpTlsHasObservedP95
            ? __('待验证')
            : ((bool)($tlsSessionReuse['resumption_latency_gate_verified'] ?? false)
                ? __('通过')
                : __('未通过'));
        $this->printer->note(__('TCP TLS 固定生产延迟门禁（P95≤%{1}）：实测=%{2}，结果=%{3}；验证器诊断上限=%{4}', [
            $tcpTlsProductionP95Limit,
            $tcpTlsObservedP95,
            $tcpTlsLatencyGateStatus,
            $tcpTlsDiagnosticP95Limit,
        ]));
        $this->printer->note(__('TCP TLS 生产门禁：性能基线=%{1}，稳定 macOS/Linux/Windows 矩阵=%{2}，production-ready=%{3}', [
            (bool)($tlsSessionReuse['performance_baseline_verified'] ?? false) ? __('已验证') : __('待验证'),
            (bool)($tlsSessionReuse['production_platform_matrix_verified'] ?? false) ? __('已验证') : __('待验证'),
            $tcpTlsProductionReady ? __('是') : __('否'),
        ]));
        $tcpTlsEvidence = \is_array($tlsSessionReuse['evidence'] ?? null) ? $tlsSessionReuse['evidence'] : [];
        $tcpTlsEvidencePath = \trim((string)($tcpTlsEvidence['evidence_path'] ?? ''));
        if ($tcpTlsEvidencePath !== '') {
            $this->printer->note(__('TCP TLS 证据文件：%{1}', [$tcpTlsEvidencePath]));
        }
        $http3TlsSessionStatus = match (true) {
            !(bool)($http3TlsSessionReuse['supported'] ?? false) => __('不可用'),
            (bool)($http3TlsSessionReuse['verified'] ?? false) => __('已验证'),
            default => __('可用但未验证'),
        };
        $http3CrossWorkerTicketStatus = match (true) {
            !(bool)($crossWorkerTicket['supported'] ?? false) => __('不可用'),
            (bool)($crossWorkerTicket['verified'] ?? false) => __('已验证'),
            default => __('可用但未验证'),
        };
        $this->printer->note(__('HTTP/3 无状态 TLS Ticket Key Ring（QUIC/UDP）：%{1}', [
            $http3TlsSessionStatus,
        ]));
        $this->printer->note(__('HTTP/3 无状态跨 Worker Ticket Key Ring：%{1}', [
            $http3CrossWorkerTicketStatus,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDiagnostics(string $instanceName = 'default'): array
    {
        $profile = (new RuntimeCapabilityDetector())->detect();
        $config = $this->resolveConfigForInstance($instanceName);
        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        $endpoint = $manager->getRawInstanceData($instanceName);
        $endpointMetadata = [];
        $endpointError = null;
        if (\is_array($endpoint)) {
            try {
                $endpointMetadata = RuntimeEndpointMetadata::fromEndpoint($endpoint)->toArray();
            } catch (\RuntimeException $exception) {
                $endpointError = $exception->getMessage();
                $endpointMetadata = [
                    'metadata_source' => 'rejected_endpoint',
                    'endpoint_schema_version' => (int)($endpoint['schema_version'] ?? 0),
                    'runtime_selection_valid' => false,
                    'runtime_selection_error' => $endpointError,
                ];
            }
        }

        try {
            $strategy = (new RuntimeStrategyResolver())->resolve($config, [], $profile);
        } catch (\RuntimeException $exception) {
            $strategy = [
                'status' => 'unsafe',
                'runtime_strategy' => $config['runtime_strategy'] ?? 'auto',
                'warnings' => [$exception->getMessage()],
            ];
        }

        $runningEndpoint = \is_array($endpoint)
            && \strtolower(\trim((string)($endpoint['lifecycle_state'] ?? ''))) === 'running';
        if ($runningEndpoint && $endpointError !== null) {
            $strategy['status'] = 'unsafe';
            $strategy['warnings'] = \array_values(\array_unique(\array_merge(
                (array)($strategy['warnings'] ?? []),
                ['Running endpoint schema v4 is invalid: ' . $endpointError]
            )));
        } elseif ($runningEndpoint && \is_array($endpointMetadata['runtime_selection'] ?? null)) {
            $selection = RuntimeSelection::fromArray($endpointMetadata['runtime_selection']);
            if ($selection->osFamily === $profile->osFamily()) {
                $strategy = \array_replace($strategy, [
                    'worker_count' => \max(1, (int)($endpoint['count'] ?? $strategy['worker_count'] ?? 1)),
                    'worker_count_reason' => 'observed running endpoint schema v4',
                    'runtime_selection' => $selection,
                ]);
            } else {
                $strategy['warnings'] = \array_values(\array_unique(\array_merge(
                    (array)($strategy['warnings'] ?? []),
                    ['Ignoring running endpoint runtime_selection from ' . $selection->osFamily
                        . ' while diagnosing current ' . $profile->osFamily() . ' runtime.']
                )));
                $endpointMetadata['runtime_selection_cross_runtime'] = true;
                $endpointMetadata['runtime_selection_ignored_reason'] = 'endpoint os_family=' . $selection->osFamily
                    . ', current os_family=' . $profile->osFamily();
            }
        }

        $diagnostics = (new RuntimeDiagnosticsFormatter())->toDiagnosticArray($profile, $strategy);
        $diagnostics['protocols'] = (new HttpProtocolCapabilityProbe())->snapshot();
        $diagnostics['instance'] = $instanceName;
        $diagnostics['config_source'] = $runningEndpoint && $endpointError === null
            ? 'running endpoint schema v4'
            : ($config['source'] ?? 'runtime/default');
        if ($endpointMetadata !== []) {
            $diagnostics['runtime_observation'] = $endpointMetadata;
        }

        return $diagnostics;
    }

    private function parseInstanceName(array $args): string
    {
        if (isset($args['instance']) && (string)$args['instance'] !== '') {
            return (string)$args['instance'];
        }

        $positional = [];
        foreach ($args as $key => $arg) {
            if (\is_int($key) && !\str_starts_with((string)$arg, '-')) {
                $positional[] = (string)$arg;
            }
        }
        \array_shift($positional);

        return $positional[0] ?? 'default';
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveConfigForInstance(string $instanceName): array
    {
        /** @var ServerInstanceManager $manager */
        $manager = ObjectManager::getInstance(ServerInstanceManager::class);
        $raw = $manager->getRawInstanceData($instanceName);
        $env = \Weline\Framework\App\Env::getInstance()->getConfig() ?: [];
        $wls = \is_array($env['wls'] ?? null) ? $env['wls'] : [];
        $runtime = \is_array($wls['runtime'] ?? null) ? $wls['runtime'] : [];
        $loop = \is_array($wls['loop'] ?? null) ? $wls['loop'] : [];
        $supervisor = \is_array($wls['supervisor'] ?? null) ? $wls['supervisor'] : [];
        $serverConfig = \is_array($wls['servers'][$instanceName] ?? null) ? $wls['servers'][$instanceName] : [];
        $config = \array_merge([
            'worker_count' => 'auto',
            'mode' => 'io',
            'runtime_strategy' => $runtime['strategy'] ?? 'auto',
            'runtime' => [
                'strategy' => $runtime['strategy'] ?? 'auto',
                'topology' => $runtime['topology'] ?? 'auto',
            ],
            'event_loop' => $loop['driver'] ?? 'auto',
            'supervisor' => ['enabled' => $supervisor['enabled'] ?? 'auto'],
            'source' => 'runtime/default',
        ], $wls, $serverConfig);

        if (\is_array($raw)) {
            foreach (['count', 'worker_count', 'mode', 'runtime_strategy', 'event_loop'] as $key) {
                if (isset($raw[$key])) {
                    $config[$key === 'count' ? 'worker_count' : $key] = $raw[$key];
                }
            }
            try {
                $metadata = RuntimeEndpointMetadata::fromEndpoint($raw)->toArray();
                $selectionData = $metadata['runtime_selection'] ?? null;
                if (\is_array($selectionData)) {
                    $selection = RuntimeSelection::fromArray($selectionData);
                    if (!\is_array($config['runtime'] ?? null)) {
                        $config['runtime'] = [];
                    }
                    $config['runtime']['topology'] = $selection->requestedTopology->value;
                }
            } catch (\RuntimeException) {
            }
            $config['source'] = 'instance record';
        }

        return $config;
    }

    public function tip(): string
    {
        return 'Read-only WLS runtime diagnostics and optimization advice';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:doctor [instance]',
            'Read-only WLS runtime diagnostics',
            [
                '[instance]' => 'Instance name, default: default',
                '--json' => 'Output machine-readable JSON',
            ],
            [],
            [
                'Show diagnostics' => 'php bin/w server:doctor',
                'Show JSON' => 'php bin/w server:doctor --json',
            ]
        );
    }
}
