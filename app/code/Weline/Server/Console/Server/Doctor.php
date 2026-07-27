<?php
declare(strict_types=1);

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\Runtime\HttpProtocolCapabilityProbe;
use Weline\Server\Service\Runtime\HttpProtocolSelection;
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
        $this->printNginxProtocolDiagnostics($diagnostics);
    }

    /** @param array<string,mixed> $diagnostics */
    private function printNginxProtocolDiagnostics(array $diagnostics): void
    {
        $protocols = \is_array($diagnostics['protocols'] ?? null) ? $diagnostics['protocols'] : [];
        $policy = \is_array($protocols['default_policy'] ?? null) ? $protocols['default_policy'] : [];
        $surfaces = \is_array($policy['surfaces'] ?? null) ? $policy['surfaces'] : [];
        $public = \is_array($surfaces['public_edge'] ?? null) ? $surfaces['public_edge'] : [];
        $backend = \is_array($surfaces['wls_endpoint'] ?? null) ? $surfaces['wls_endpoint'] : [];
        $binding = \is_array($protocols['endpoint_policy_binding'] ?? null)
            ? $protocols['endpoint_policy_binding']
            : [];
        $edge = \is_array($protocols['edge'] ?? null) ? $protocols['edge'] : [];
        $managed = \is_array($edge['managed_nginx'] ?? null) ? $edge['managed_nginx'] : [];
        $httpProtocol = \is_array($diagnostics['http_protocol'] ?? null)
            ? $diagnostics['http_protocol']
            : [];
        $dependency = \is_array($httpProtocol['dependency'] ?? null)
            ? $httpProtocol['dependency']
            : [];
        $policyBound = (bool)($binding['bound'] ?? false);
        $bindingSource = (string)($binding['source'] ?? '');
        $runningEndpoint = \str_starts_with($bindingSource, 'running_endpoint');
        $ownerBound = (bool)($dependency['owner_bound'] ?? false);
        $runtimeEvidenceUsable = $runningEndpoint && $ownerBound;
        $runtimeVerified = static fn(string $field): bool =>
            $runtimeEvidenceUsable && (bool)($managed[$field] ?? false);

        $this->printer->note(__('公网协议所有者：Nginx；WLS 仅提供内部 HTTP/1.1 回源。'));
        if ($runningEndpoint && (!$policyBound || !$ownerBound)) {
            $this->printer->warning(__('运行端点协议或 Managed Nginx owner 尚未完整绑定；下面不会把配置值提升为运行验证。'));
        } elseif (!$runningEndpoint) {
            $this->printer->note(__('当前为配置预览；运行验证只有在实例与 Managed Nginx owner 绑定后才归因。'));
        }

        $this->printer->note(__('Nginx reload：仅使用项目托管生命周期，不执行宿主机外部命令。'));
        $this->printer->note(__('Managed Nginx：托管=%{1}，已安装=%{2}，运行中=%{3}，owner=%{4}，upstream=%{5}:%{6}', [
            (bool)($managed['managed'] ?? false) ? __('是') : __('否'),
            (bool)($managed['installed'] ?? false) ? __('是') : __('否'),
            (bool)($managed['running'] ?? false) ? __('是') : __('否'),
            (string)($managed['owner_instance'] ?? __('未绑定')),
            (string)($managed['owner_upstream_host'] ?? ''),
            (string)($managed['owner_upstream_port'] ?? 0),
        ]));
        $this->printer->note(__('Managed Nginx 公网端口：owner HTTP=%{1}，HTTPS=%{2}，已绑定=%{3}；当前配置预期 HTTP=%{4}，HTTPS=%{5}', [
            (string)($managed['owner_listen_http'] ?? 0),
            (string)($managed['owner_listen_https'] ?? 0),
            (bool)($managed['owner_ports_bound'] ?? false) ? __('是') : __('否'),
            (string)($managed['configured_listen_http'] ?? 0),
            (string)($managed['configured_listen_https'] ?? 0),
        ]));

        $this->printer->note(__('Managed Nginx 身份：期望版本=%{1}，实际版本=%{2}，manifest=%{3}，配置摘要=%{4}，upstream 摘要=%{5}', [
            (string)($managed['expected_version'] ?? ''),
            (string)($managed['binary_version'] ?? ''),
            (bool)($managed['install_identity_matches'] ?? false) ? __('匹配') : __('不匹配'),
            (string)($managed['owner_config_sha256'] ?? ''),
            (string)($managed['owner_upstream_endpoint_sha256'] ?? ''),
        ]));

        $configuredOrder = \array_values(\array_filter(
            \array_map('strval', (array)($public['negotiation_order'] ?? [])),
        ));
        $this->printer->note(__('Nginx 公网协议：配置顺序=%{1}，配置首选=%{2}，实测首选=%{3}，整体运行验证=%{4}', [
            $configuredOrder === [] ? __('未配置') : \implode(' -> ', $configuredOrder),
            (string)($public['target_preferred'] ?? __('未知')),
            $runtimeEvidenceUsable
                ? (string)($public['observed_preferred'] ?? __('未验证'))
                : __('未绑定'),
            $runtimeEvidenceUsable && (bool)($public['runtime_verified'] ?? false)
                ? __('通过')
                : __('未通过'),
        ]));
        $this->printer->note(__('Nginx HTTP/2：模块能力=%{1}，已配置=%{2}，owner-bound 真实请求=%{3}', [
            (bool)($managed['http2_module'] ?? false) ? __('是') : __('否'),
            (bool)($managed['http2_enabled'] ?? false) ? __('是') : __('否'),
            $runtimeVerified('http2_runtime_verified') ? __('已验证') : __('未验证'),
        ]));
        $this->printer->note(__('Nginx HTTP/1.1 回退：已配置=%{1}，真实请求=%{2}', [
            (bool)($managed['http1_fallback'] ?? false) ? __('是') : __('否'),
            $runtimeVerified('http1_runtime_verified') ? __('已验证') : __('未验证'),
        ]));
        $this->printer->note(__('Nginx HTTP/3：二进制能力=%{1}，已配置=%{2}，Alt-Svc=%{3}，真实 QUIC=%{4}', [
            (bool)($managed['http3_capable'] ?? false) ? __('是') : __('否'),
            (bool)($managed['http3_configured'] ?? false) ? __('是') : __('否'),
            (bool)($managed['alt_svc_enabled'] ?? false) ? __('已启用') : __('未启用'),
            $runtimeVerified('http3_runtime_verified') ? __('已验证') : __('未验证'),
        ]));
        $http3Reason = \trim((string)($public['http3_reason'] ?? $managed['http3_reason'] ?? ''));
        if ($http3Reason !== '') {
            $this->printer->note(__('Nginx HTTP/3 说明：%{1}', [$http3Reason]));
        }

        $this->printer->note(__('Nginx TLS 1.3：二进制能力=%{1}，仅允许 TLS 1.3=%{2}，真实握手=%{3}', [
            (bool)($managed['tls13_capable'] ?? false) ? __('是') : __('否'),
            (bool)($managed['tls13_only'] ?? false) ? __('是') : __('否'),
            $runtimeVerified('tls13_runtime_verified') ? __('已验证') : __('未验证'),
        ]));
        $sessionRuntimeVerified = $runtimeVerified('tls_session_resumption_runtime_verified');
        $sessionEvidenceBound = $runtimeEvidenceUsable
            && (bool)($managed['tls_session_resumption_evidence_bound'] ?? false);
        $sessionMasterPidMatches = $runtimeEvidenceUsable
            && (bool)($managed['tls_session_resumption_master_pid_matches'] ?? false);
        $this->printer->note(__('Nginx TLS Session：shared cache=%{1}，tickets=%{2}，共享 ticket keys=%{3}，真实恢复握手=%{4}', [
            (bool)($managed['tls_session_cache_shared'] ?? false) ? __('已配置') : __('未配置'),
            (bool)($managed['tls_session_tickets'] ?? false) ? __('已配置') : __('未配置'),
            (bool)($managed['tls_session_ticket_keys_shared'] ?? false) ? __('已配置') : __('未配置'),
            $sessionRuntimeVerified ? __('已验证') : __('未验证'),
        ]));
        $resumedHandshakeP95Us = (int)($managed['tls_session_resumption_resumed_tls_handshake_p95_us'] ?? 0);
        $resumedHandshakeP95 = $sessionRuntimeVerified && $resumedHandshakeP95Us > 0
            ? \sprintf('%.3f ms (%d µs)', $resumedHandshakeP95Us / 1000, $resumedHandshakeP95Us)
            : __('未验证');
        $this->printer->note(__('Nginx TLS Session 证据：evidence-bound=%{1}，Master PID match=%{2}，resumed handshake P95=%{3}', [
            !$runtimeEvidenceUsable
                ? __('未绑定')
                : ($sessionEvidenceBound ? __('是') : __('否')),
            !$runtimeEvidenceUsable
                ? __('未绑定')
                : ($sessionMasterPidMatches ? __('匹配') : __('不匹配')),
            $resumedHandshakeP95,
        ]));

        $sessionSampleValue = static fn(string $field): int => $sessionRuntimeVerified
            ? \max(0, (int)($managed[$field] ?? 0))
            : 0;
        $this->printer->note(__('Nginx TLS Session 样本：总数=%{1}，完成=%{2}，失败=%{3}，fresh=%{4}，resumed=%{5}', [
            $sessionSampleValue('tls_session_resumption_sample_count'),
            $sessionSampleValue('tls_session_resumption_completed_count'),
            $sessionSampleValue('tls_session_resumption_failed_count'),
            $sessionSampleValue('tls_session_resumption_fresh_count'),
            $sessionSampleValue('tls_session_resumption_resumed_count'),
        ]));

        $sessionWorkerStatus = static function (string $status, bool $verified) use ($sessionRuntimeVerified): string {
            if (!$sessionRuntimeVerified) {
                return __('未验证');
            }
            return match ($status) {
                'verified' => $verified ? __('已验证') : __('未验证'),
                'not_applicable' => __('不适用'),
                'pending' => __('待验证'),
                default => __('未验证'),
            };
        };
        $sameWorkerStatus = (string)($managed['tls_session_resumption_same_worker_status'] ?? '');
        $crossWorkerStatus = (string)($managed['tls_session_resumption_cross_worker_status'] ?? '');
        $this->printer->note(__('Nginx TLS Session Worker：observed=%{1}，effective=%{2}；same=%{3}（resumed=%{4}），cross=%{5}（resumed=%{6}）', [
            $sessionSampleValue('tls_session_resumption_observed_worker_count'),
            $sessionSampleValue('tls_session_resumption_effective_worker_count'),
            $sessionWorkerStatus(
                $sameWorkerStatus,
                (bool)($managed['tls_session_resumption_same_worker_runtime_verified'] ?? false),
            ),
            $sessionSampleValue('tls_session_resumption_same_worker_resumed_count'),
            $sessionWorkerStatus(
                $crossWorkerStatus,
                (bool)($managed['tls_session_resumption_cross_worker_runtime_verified'] ?? false),
            ),
            $sessionSampleValue('tls_session_resumption_cross_worker_resumed_count'),
        ]));
        $reloadContinuityVerified = $sessionRuntimeVerified
            && (bool)($managed['tls_session_resumption_reload_continuity_verified'] ?? false);
        $reloadHandshakeUs = $reloadContinuityVerified
            ? \max(0, (int)($managed['tls_session_resumption_reload_tls_handshake_us'] ?? 0))
            : 0;
        $this->printer->note(__('Nginx TLS Session Reload：同一 Session 跨 reload=%{1}，旧 Worker PID=%{2}，新 Worker PID=%{3}，恢复握手=%{4}', [
            $reloadContinuityVerified ? __('已验证') : __('待验证'),
            $reloadContinuityVerified
                ? (int)($managed['tls_session_resumption_reload_issuer_worker_pid'] ?? 0)
                : __('未验证'),
            $reloadContinuityVerified
                ? (int)($managed['tls_session_resumption_reload_probe_worker_pid'] ?? 0)
                : __('未验证'),
            $reloadHandshakeUs > 0
                ? \sprintf('%.3f ms (%d µs)', $reloadHandshakeUs / 1000, $reloadHandshakeUs)
                : __('未验证'),
        ]));
        $sessionConfigured = (bool)($managed['tls_session_cache_shared'] ?? false)
            || (bool)($managed['tls_session_tickets'] ?? false)
            || (bool)($managed['tls_session_ticket_keys_shared'] ?? false);
        if ($sessionConfigured
            && !$sessionRuntimeVerified
        ) {
            $this->printer->warning(__('共享 Session Cache/Ticket Key 只是配置事实；尚无新连接恢复握手的实测证据。'));
        }

        $backendOrder = \array_values(\array_filter(
            \array_map('strval', (array)($backend['negotiation_order'] ?? [])),
        ));
        $this->printer->note(__('WLS 内部回源：角色=%{1}，协议=%{2}，公网 TLS/ALPN 证据不从 PHP/WLS 推导', [
            (string)($backend['role'] ?? 'nginx_backend'),
            $backendOrder === [] ? 'http/1.1' : \implode(' -> ', $backendOrder),
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
        $endpointEdgeAdapter = \strtolower(\trim((string)($endpoint['edge_adapter'] ?? '')));
        $httpSelection = null;
        $httpSelectionError = null;
        $endpointPolicyBound = false;
        $endpointPolicySource = $runningEndpoint
            ? 'running_endpoint_unbound'
            : 'runtime_config_preview';
        try {
            if ($runningEndpoint) {
                if ($endpointEdgeAdapter !== 'nginx') {
                    throw new \RuntimeException('running endpoint edge_adapter must be nginx');
                }
                $selectionData = $endpoint['http_protocol_selection'] ?? null;
                if (!\is_array($selectionData) || $selectionData === []) {
                    throw new \RuntimeException('running endpoint http_protocol_selection is missing');
                }
                $httpSelection = HttpProtocolSelection::fromArray($selectionData);
                $httpSelection->assertCompatibleEdgeAdapter('nginx');
                $endpointPolicyBound = true;
                $endpointPolicySource = 'running_endpoint';
            } else {
                $endpointEdgeAdapter = (new \Weline\Server\Service\Edge\EdgeAdapterResolver())
                    ->resolveFromWlsSection($config)
                    ->name();
                if ($endpointEdgeAdapter !== 'nginx') {
                    throw new \RuntimeException('configured edge_adapter must be nginx');
                }
                // Start canonicalizes the private backend after resolving the
                // public certificate: Nginx owns TLS/protocol negotiation and
                // WLS is always plaintext HTTP/1.1. Preview that same contract
                // instead of treating legacy public TLS/http config as Worker
                // transport evidence.
                $httpSelection = HttpProtocolSelection::fromConfig(['http' => [
                    'protocols' => [HttpProtocolSelection::HTTP_1],
                    'preferred' => HttpProtocolSelection::HTTP_1,
                    'protocol_edge' => HttpProtocolSelection::EDGE_DISABLED,
                    'tls_session_resumption' => false,
                    'alt_svc' => false,
                ]], false);
                $httpSelection->assertCompatibleEdgeAdapter('nginx');
            }
        } catch (\Throwable $exception) {
            $httpSelectionError = $exception->getMessage();
            if ($runningEndpoint) {
                $diagnostics['status'] = 'unsafe';
                $diagnostics['warnings'] = \array_values(\array_unique(\array_merge(
                    (array)($diagnostics['warnings'] ?? []),
                    ['Running endpoint protocol policy is unbound: ' . $httpSelectionError],
                )));
            }
        }
        $diagnostics['protocols'] = (new HttpProtocolCapabilityProbe())->snapshot(
            'nginx',
            $httpSelection,
            $endpointPolicyBound,
            $endpointPolicySource,
            null,
        );
        $diagnostics['instance'] = $instanceName;
        $diagnostics['config_source'] = $runningEndpoint && $endpointError === null
            ? 'running endpoint schema v4'
            : ($config['source'] ?? 'runtime/default');
        if ($endpointMetadata !== []) {
            $diagnostics['runtime_observation'] = $endpointMetadata;
        }
        try {
            if ($httpSelection === null) {
                throw new \RuntimeException($httpSelectionError ?? 'HTTP protocol selection is unavailable.');
            }
            $http = $httpSelection->toArray();
            $protocolSnapshot = \is_array($diagnostics['protocols'] ?? null)
                ? $diagnostics['protocols']
                : [];
            $edgeSnapshot = \is_array($protocolSnapshot['edge'] ?? null)
                ? $protocolSnapshot['edge']
                : [];
            $managed = \is_array($edgeSnapshot['managed_nginx'] ?? null)
                ? $edgeSnapshot['managed_nginx']
                : [];
            $ownerBound = $runningEndpoint
                && (bool)($managed['managed'] ?? false)
                && (bool)($managed['installed'] ?? false)
                && (bool)($managed['running'] ?? false)
                && (bool)($managed['install_identity_matches'] ?? false)
                && (bool)($managed['runtime_owner_active'] ?? false)
                && (bool)($managed['owner_ports_bound'] ?? false)
                && (bool)($managed['binary_capabilities_ok'] ?? false)
                && (int)($managed['pid'] ?? 0) > 0
                && \trim((string)($managed['owner_config_generation'] ?? '')) !== ''
                && \trim((string)($managed['owner_upstream_host'] ?? '')) !== ''
                && (int)($managed['owner_listen_http'] ?? 0) > 0
                && (int)($managed['owner_listen_https'] ?? 0) > 0
                && \hash_equals($instanceName, (string)($managed['owner_instance'] ?? ''))
                && (int)($managed['owner_upstream_port'] ?? 0)
                    === (int)($endpoint['port'] ?? $endpoint['main_port'] ?? 0)
                && \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    \strtolower(\trim((string)($managed['owner_config_sha256'] ?? ''))),
                ) === 1
                && \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    \strtolower(\trim((string)($managed['owner_upstream_endpoint_sha256'] ?? ''))),
                ) === 1;
            if (\is_array($diagnostics['protocols']['endpoint_policy_binding'] ?? null)) {
                $diagnostics['protocols']['endpoint_policy_binding']['public_owner_bound'] = $ownerBound;
            }
            $runtimeEvidenceBound = $runningEndpoint && $ownerBound;
            if (!$runtimeEvidenceBound
                && \is_array($diagnostics['protocols']['default_policy']['surfaces']['public_edge'] ?? null)
            ) {
                $publicSurface = $diagnostics['protocols']['default_policy']['surfaces']['public_edge'];
                $publicSurface['runtime_verified'] = false;
                $publicSurface['observed_preferred'] = null;
                $publicSurface['effective_preferred'] = null;
                $publicSurface['fallback'] = [];
                $publicSurface['verification_required'] = $runningEndpoint
                    ? 'Managed Nginx owner/upstream identity bound to this running endpoint'
                    : 'A running WLS endpoint bound to the active Managed Nginx owner';
                $publicSurface['tls13_runtime_verified'] = false;
                $publicSurface['tls_session_resumption_runtime_verified'] = false;
                $diagnostics['protocols']['default_policy']['surfaces']['public_edge'] = $publicSurface;
            }
            $http['dependency'] = [
                'status' => $runningEndpoint ? ($ownerBound ? 'ready' : 'unbound') : 'preview',
                'adapter' => 'nginx',
                'managed' => (bool)($managed['managed'] ?? false),
                'running' => (bool)($managed['running'] ?? false),
                'owner_bound' => $ownerBound,
                'binary' => (string)($managed['binary'] ?? ''),
                'version' => (string)($managed['binary_version'] ?? ''),
            ];
            if ($runningEndpoint && !$ownerBound) {
                $diagnostics['status'] = 'unsafe';
                $diagnostics['warnings'] = \array_values(\array_unique(\array_merge(
                    (array)($diagnostics['warnings'] ?? []),
                    ['Managed Nginx owner/upstream identity is not bound to the running WLS endpoint.'],
                )));
            }
            $diagnostics['http_protocol'] = $http;
        } catch (\Throwable $exception) {
            $diagnostics['status'] = 'unsafe';
            $diagnostics['http_protocol'] = [
                'status' => 'invalid',
                'error' => $exception->getMessage(),
            ];
            $diagnostics['warnings'] = \array_values(\array_unique(\array_merge(
                (array)($diagnostics['warnings'] ?? []),
                ['HTTP protocol selection is invalid: ' . $exception->getMessage()],
            )));
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
