<?php
declare(strict_types=1);

namespace Weline\Server\Service\Control;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Timeouts;
use Weline\Server\Service\Runtime\NamespaceInvalidationProtocol;

class IpcControlGateway implements IpcControlGatewayInterface
{
    private ?NamespaceInvalidationProtocol $namespaceInvalidationProtocol = null;

    public function command(
        string $instanceName,
        string $action,
        string $reloadType = '',
        array $payload = [],
        float $timeout = 6.0
    ): array {
        $requestId = (string)($payload['msg_id'] ?? ControlCommandResult::requestId($action));
        $payload['msg_id'] = $requestId;
        if ($action === ControlMessage::ACTION_STOP && !isset($payload['stop_intent'])) {
            $payload['stop_intent'] = 'explicit';
        }
        if ($action === ControlMessage::ACTION_STOP && !isset($payload['stop_source'])) {
            $payload['stop_source'] = 'ipc_gateway';
        }
        if ($action === ControlMessage::ACTION_STOP && !isset($payload['stop_trace_id'])) {
            $payload['stop_trace_id'] = 'gw-' . \getmypid() . '-' . \time();
        }

        $endpoint = $this->resolveControlEndpoint($instanceName);
        $controlPort = (int)$endpoint['port'];
        if ($controlPort <= 0) {
            return ControlCommandResult::normalize([
                'success' => false,
                'message' => (string)__('实例 %{1} 的 Master 未运行，无法通过 IPC 控制。', [$instanceName]),
                'data' => [],
            ], $instanceName, $action, $requestId);
        }

        $result = $this->sendCommand(
            $controlPort,
            ControlMessage::command($action, $reloadType, $payload, (string)$endpoint['control_token']),
            $timeout
        );

        return ControlCommandResult::normalize($result, $instanceName, $action, $requestId);
    }

    public function reloadAsync(
        string $instanceName,
        string $reloadType,
        float $timeout = 5.0
    ): array {
        return $this->commandAsync(
            $instanceName,
            ControlMessage::ACTION_RELOAD,
            $reloadType,
            [],
            $timeout,
            'Reload initiated'
        );
    }

    public function cacheClear(string $instanceName, float $timeout = 5.0): array
    {
        return $this->commandAsync(
            $instanceName,
            ControlMessage::ACTION_CACHE_CLEAR,
            '',
            [],
            $timeout,
            'Cache clear queued'
        );
    }

    public function cacheNamespaceInvalidateV1(
        string $instanceName,
        int $authorityClock,
        array $changes,
        string $requestId = '',
        float $timeout = 0.1,
        ?string $operationId = null,
    ): array {
        try {
            $frame = $this->namespaceInvalidationProtocol()->buildFrame(
                $authorityClock,
                $changes,
                $operationId,
            );
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'message' => $throwable->getMessage(),
                'data' => [
                    'accepted' => false,
                    'error_code' => \property_exists($throwable, 'errorCode')
                        ? (string)$throwable->errorCode
                        : 'frame_invalid',
                ],
            ];
        }

        return $this->boundedNamespaceCommand(
            $instanceName,
            ControlMessage::ACTION_CACHE_NAMESPACE_INVALIDATE_V1,
            [
                'frame' => $frame,
                'request_id' => \substr($requestId, 0, 128),
            ],
            $timeout,
        );
    }

    public function cacheNamespaceInvalidationStatusV1(
        string $instanceName,
        string $operationId,
        float $timeout = 0.1,
    ): array {
        if (\preg_match(NamespaceInvalidationProtocol::OPERATION_ID_PATTERN, $operationId) !== 1) {
            return [
                'success' => false,
                'message' => (string)__('缓存命名空间失效操作 ID 无效。'),
                'data' => ['error_code' => 'operation_id_invalid'],
            ];
        }

        return $this->boundedNamespaceCommand(
            $instanceName,
            ControlMessage::ACTION_CACHE_NAMESPACE_STATUS_V1,
            ['operation_id' => $operationId],
            $timeout,
        );
    }

    public function setMaintenanceMode(
        string $instanceName,
        bool $enabled,
        float $timeout = 6.0,
        bool $dispatcherOnly = false
    ): array
    {
        return $this->commandAsync(
            $instanceName,
            $enabled ? ControlMessage::ACTION_MAINTENANCE_ENABLE : ControlMessage::ACTION_MAINTENANCE_DISABLE,
            '',
            $dispatcherOnly ? ['dispatcher_only' => true] : [],
            $timeout,
            $enabled ? 'Maintenance enable queued' : 'Maintenance disable queued'
        );
    }

    public function routingCacheClear(string $instanceName, float $timeout = 5.0): array
    {
        return $this->commandAsync(
            $instanceName,
            ControlMessage::ACTION_ROUTING_CACHE_CLEAR,
            '',
            [],
            $timeout,
            'Routing cache clear queued'
        );
    }

    public function getStatus(string $instanceName = 'default', float $timeout = 4.0): array
    {
        return $this->command($instanceName, ControlMessage::ACTION_STATUS, '', [], $timeout ?: Timeouts::CONTROL_CMD_STATUS_READ_SEC);
    }

    public function getStatusBrief(string $instanceName = 'default', float $timeout = 1.5): array
    {
        return $this->command($instanceName, ControlMessage::ACTION_STATUS, '', ['brief' => true], $timeout);
    }

    public function reloadSslCert(string $instanceName = 'default', array $domains = []): array
    {
        unset($domains);
        return ControlCommandResult::normalize([
            'success' => false,
            'message' => 'Legacy SSL reload is disabled; publish an immutable serving manifest and call reloadSslCertAndWait().',
            'data' => [
                'code' => 'exact_tls_reload_fence_required',
                'deprecated_api' => 'reloadSslCert',
            ],
        ], $instanceName, ControlMessage::ACTION_SSL_CERT_RELOAD, ControlCommandResult::requestId(
            ControlMessage::ACTION_SSL_CERT_RELOAD,
        ));
    }

    public function reloadSslCertAndWait(
        array $domains,
        string $instanceName,
        string $operationId,
        int $expectedManifestGeneration,
        string $expectedManifestDigest,
        int $expectedTlsRouteCount,
        float $timeout = 8.0,
    ): array {
        $operationId = \substr(\strtolower(\trim($operationId)), 0, 128);
        $expectedManifestDigest = \substr(
            \strtolower(\trim($expectedManifestDigest)),
            0,
            128,
        );
        $requestId = ControlCommandResult::requestId(
            ControlMessage::ACTION_SSL_CERT_RELOAD,
        );
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $operationId) !== 1
            || $expectedManifestGeneration < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $expectedManifestDigest) !== 1
            || $expectedTlsRouteCount < 0
            || $expectedTlsRouteCount > 256
        ) {
            return ControlCommandResult::normalize([
                'success' => false,
                'message' => 'Exact SSL reload operation/manifest fence is invalid.',
                'data' => $this->sslReloadFailureData(
                    $operationId,
                    $expectedManifestGeneration,
                    $expectedManifestDigest,
                    $expectedTlsRouteCount,
                    'invalid_reload_fence',
                ),
            ], $instanceName, ControlMessage::ACTION_SSL_CERT_RELOAD, $requestId);
        }

        $normalizedDomains = [];
        foreach ($domains as $domain) {
            if (!\is_string($domain)) {
                continue;
            }
            $domain = \substr(\strtolower(\trim($domain)), 0, 253);
            if ($domain !== '') {
                $normalizedDomains[$domain] = true;
            }
            if (\count($normalizedDomains) >= 256) {
                break;
            }
        }
        // The caller's timeout is the Worker ACK budget. Master may then need
        // the bounded fail-stop containment window before returning a terminal
        // receipt; keep the publication transaction/socket alive for both.
        $ackTimeout = \max(0.5, \min(20.0, $timeout));
        $readTimeout = \min(32.0, $ackTimeout + 6.0);
        $result = $this->command(
            $instanceName,
            ControlMessage::ACTION_SSL_CERT_RELOAD,
            '',
            [
                'msg_id' => $requestId,
                'domains' => \array_keys($normalizedDomains),
                'operation_id' => $operationId,
                'expected_manifest_generation' => $expectedManifestGeneration,
                'expected_manifest_digest' => $expectedManifestDigest,
                'expected_tls_route_count' => $expectedTlsRouteCount,
                'ack_timeout_sec' => $ackTimeout,
            ],
            $readTimeout,
        );
        $data = \is_array($result['data'] ?? null) ? $result['data'] : [];
        if (!\is_bool($data['success'] ?? null)
            || ($data['success'] ?? null) !== ($result['success'] ?? false)
            || !\hash_equals($operationId, (string)($data['operation_id'] ?? ''))
            || (int)($data['expected_manifest_generation'] ?? 0)
                !== $expectedManifestGeneration
            || !\hash_equals(
                $expectedManifestDigest,
                (string)($data['expected_manifest_digest'] ?? ''),
            )
            || (int)($data['expected_tls_route_count'] ?? -1)
                !== $expectedTlsRouteCount
            || !\hash_equals(
                $expectedTlsRouteCount === 0 ? 'neutral' : 'routes',
                (string)($data['expected_serving_mode'] ?? ''),
            )
            || !\is_array($data['eligible_workers'] ?? null)
            || !\array_is_list($data['eligible_workers'])
            || !\is_array($data['acked_workers'] ?? null)
            || !\array_is_list($data['acked_workers'])
            || !\is_array($data['failed_workers'] ?? null)
            || !\array_is_list($data['failed_workers'])
            || !\is_int($data['expected_retired_context_count'] ?? null)
            || (int)$data['expected_retired_context_count'] < 0
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($data['expected_retired_context_digest'] ?? ''),
            ) !== 1
            || ($data['async'] ?? null) !== false
            || !$this->sslReloadTerminalIsSemanticallyValid(
                $data,
                $expectedManifestGeneration,
                $expectedManifestDigest,
                $expectedTlsRouteCount,
            )
        ) {
            return ControlCommandResult::normalize([
                'success' => false,
                'message' => 'Master did not return the exact terminal SSL reload contract.',
                'data' => $this->sslReloadFailureData(
                    $operationId,
                    $expectedManifestGeneration,
                    $expectedManifestDigest,
                    $expectedTlsRouteCount,
                    'terminal_contract_missing',
                ),
                'timed_out' => (bool)($result['timed_out'] ?? false),
            ], $instanceName, ControlMessage::ACTION_SSL_CERT_RELOAD, $requestId);
        }

        return $result;
    }

    public function quarantineSslServingAndWait(
        string $instanceName,
        string $operationId,
        string $reason,
        float $timeoutSeconds = 8.0,
    ): array {
        $operationId = \strtolower(\trim($operationId));
        $requestId = ControlCommandResult::requestId(
            ControlMessage::ACTION_SSL_SERVING_QUARANTINE,
        );
        $endpoint = MasterProcess::getMasterEndpoint($instanceName);
        $gateway = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        $masterPid = (int)($endpoint['master_pid'] ?? 0);
        $masterEpoch = (int)($endpoint['master_epoch'] ?? 0);
        $instanceGeneration = (int)($gateway['instance_generation'] ?? 0);
        $launchId = \strtolower(\trim((string)($gateway['launch_id'] ?? '')));
        if (!\is_array($endpoint)
            || \preg_match('/\A[a-f0-9]{32}\z/D', $operationId) !== 1
            || $masterPid < 1
            || $masterEpoch < 1
            || $instanceGeneration < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1
        ) {
            return ControlCommandResult::normalize([
                'success' => false,
                'message' => 'Exact Master endpoint fence is unavailable for TLS quarantine.',
                'data' => [
                    'success' => false,
                    'async' => false,
                    'operation_id' => $operationId,
                    'quarantined' => false,
                    'zero_serving' => false,
                    'code' => 'quarantine_master_fence_invalid',
                ],
            ], $instanceName, ControlMessage::ACTION_SSL_SERVING_QUARANTINE, $requestId);
        }
        $reason = \substr((string)\preg_replace(
            '/[\r\n\x00-\x1f\x7f]+/',
            ' ',
            \trim($reason),
        ), 0, 256);
        if ($reason === '') {
            $reason = 'certificate_transaction_containment';
        }
        $containmentBudget = \max(1.0, \min(20.0, $timeoutSeconds));
        $readBudget = \min(32.0, $containmentBudget + 2.0);
        $result = $this->command(
            $instanceName,
            ControlMessage::ACTION_SSL_SERVING_QUARANTINE,
            '',
            [
                'msg_id' => $requestId,
                'operation_id' => $operationId,
                'reason' => $reason,
                'master_pid' => $masterPid,
                'master_epoch' => $masterEpoch,
                'instance_generation' => $instanceGeneration,
                'launch_id' => $launchId,
                'containment_timeout_sec' => $containmentBudget,
            ],
            $readBudget,
        );
        $data = \is_array($result['data'] ?? null) ? $result['data'] : [];
        $terminal = \is_bool($data['success'] ?? null)
            && ($data['success'] ?? null) === ($result['success'] ?? null)
            && ($data['async'] ?? null) === false
            && \hash_equals($operationId, (string)($data['operation_id'] ?? ''))
            && (int)($data['master_pid'] ?? 0) === $masterPid
            && (int)($data['master_epoch'] ?? 0) === $masterEpoch
            && (int)($data['instance_generation'] ?? 0) === $instanceGeneration
            && \hash_equals($launchId, (string)($data['launch_id'] ?? ''))
            && \is_bool($data['quarantined'] ?? null)
            && \is_bool($data['zero_serving'] ?? null)
            && \is_array($data['eligible_workers'] ?? null)
            && \array_is_list($data['eligible_workers'])
            && \is_array($data['contained_workers'] ?? null)
            && \array_is_list($data['contained_workers'])
            && \is_array($data['remaining_workers'] ?? null)
            && \array_is_list($data['remaining_workers'])
            && \is_array($data['failures'] ?? null)
            && \array_is_list($data['failures']);
        if (!$terminal
            || (($result['success'] ?? false) === true
                && (($data['quarantined'] ?? false) !== true
                    || ($data['zero_serving'] ?? false) !== true
                    || $data['remaining_workers'] !== []
                    || $data['failures'] !== []))
        ) {
            return ControlCommandResult::normalize([
                'success' => false,
                'message' => 'Master did not return the exact zero-serving quarantine receipt.',
                'data' => [
                    'success' => false,
                    'async' => false,
                    'operation_id' => $operationId,
                    'master_pid' => $masterPid,
                    'master_epoch' => $masterEpoch,
                    'instance_generation' => $instanceGeneration,
                    'launch_id' => $launchId,
                    'quarantined' => false,
                    'zero_serving' => false,
                    'eligible_workers' => [],
                    'contained_workers' => [],
                    'remaining_workers' => [],
                    'failures' => [['code' => 'quarantine_terminal_contract_missing']],
                ],
                'timed_out' => (bool)($result['timed_out'] ?? false),
            ], $instanceName, ControlMessage::ACTION_SSL_SERVING_QUARANTINE, $requestId);
        }
        return $result;
    }

    /** @param array<string,mixed> $data */
    private function sslReloadTerminalIsSemanticallyValid(
        array $data,
        int $expectedManifestGeneration,
        string $expectedManifestDigest,
        int $expectedTlsRouteCount,
    ): bool {
        $eligible = $data['eligible_workers'] ?? null;
        $acked = $data['acked_workers'] ?? null;
        $failed = $data['failed_workers'] ?? null;
        if (!\is_array($eligible)
            || !\array_is_list($eligible)
            || !\is_array($acked)
            || !\array_is_list($acked)
            || !\is_array($failed)
            || !\array_is_list($failed)
        ) {
            return false;
        }
        // A terminal failure is authoritative without pretending that a
        // partial Worker set completed. Exact set equality is mandatory only
        // for a green transaction.
        if (($data['success'] ?? false) !== true) {
            return true;
        }
        if ($eligible === [] || $failed !== [] || \count($eligible) !== \count($acked)) {
            return false;
        }
        $expected = [];
        foreach ($eligible as $identity) {
            if (!\is_array($identity)) {
                return false;
            }
            $clientId = (int)($identity['client_id'] ?? 0);
            if ($clientId < 1 || isset($expected[$clientId])) {
                return false;
            }
            $expected[$clientId] = $identity;
        }
        $expectedServingMode = $expectedTlsRouteCount === 0 ? 'neutral' : 'routes';
        $expectedContextState = $expectedTlsRouteCount === 0 ? 'disabled' : 'active';
        $expectedRetiredContextCount = (int)$data['expected_retired_context_count'];
        $expectedRetiredContextDigest = (string)$data['expected_retired_context_digest'];
        foreach ($acked as $receipt) {
            if (!\is_array($receipt)) {
                return false;
            }
            $clientId = (int)($receipt['client_id'] ?? 0);
            $identity = $expected[$clientId] ?? null;
            if (!\is_array($identity)
                || (int)($receipt['worker_id'] ?? 0)
                    !== (int)($identity['instance_id'] ?? 0)
                || !\hash_equals(
                    (string)($identity['role'] ?? ''),
                    (string)($receipt['role'] ?? ''),
                )
                || !\hash_equals(
                    (string)($identity['slot_id'] ?? ''),
                    (string)($receipt['slot_id'] ?? ''),
                )
                || !\hash_equals(
                    (string)($identity['lease_id'] ?? ''),
                    (string)($receipt['lease_id'] ?? ''),
                )
                || (int)($receipt['generation'] ?? 0)
                    !== (int)($identity['generation'] ?? -1)
                || (int)($receipt['pid'] ?? 0) !== (int)($identity['pid'] ?? -1)
                || (int)($receipt['applied_manifest_generation'] ?? 0)
                    !== $expectedManifestGeneration
                || !\hash_equals(
                    $expectedManifestDigest,
                    (string)($receipt['applied_manifest_digest'] ?? ''),
                )
                || (int)($receipt['applied_tls_route_count'] ?? -1)
                    !== $expectedTlsRouteCount
                || !\hash_equals(
                    $expectedServingMode,
                    (string)($receipt['serving_mode'] ?? ''),
                )
                || !\hash_equals(
                    $expectedContextState,
                    (string)($receipt['tls_context_state'] ?? ''),
                )
                || !\is_int($receipt['retired_context_count'] ?? null)
                || (int)($receipt['retired_context_count'] ?? -1)
                    !== $expectedRetiredContextCount
                || !\hash_equals(
                    $expectedRetiredContextDigest,
                    (string)($receipt['retired_context_digest'] ?? ''),
                )
            ) {
                return false;
            }
            unset($expected[$clientId]);
        }

        return $expected === [];
    }

    /** @return array<string,mixed> */
    private function sslReloadFailureData(
        string $operationId,
        int $expectedManifestGeneration,
        string $expectedManifestDigest,
        int $expectedTlsRouteCount,
        string $code,
    ): array {
        return [
            'success' => false,
            'async' => false,
            'state' => 'failed',
            'operation_id' => $operationId,
            'expected_manifest_generation' => $expectedManifestGeneration,
            'expected_manifest_digest' => $expectedManifestDigest,
            'expected_tls_route_count' => $expectedTlsRouteCount,
            'expected_serving_mode' => $expectedTlsRouteCount === 0 ? 'neutral' : 'routes',
            'expected_retired_context_count' => 0,
            'expected_retired_context_digest' => \hash('sha256', '[]'),
            'eligible_workers' => [],
            'acked_workers' => [],
            'failed_workers' => [['code' => $code]],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $routes
     */
    public function proxyApply(string $instanceName = 'default', array $routes = [], float $timeout = 5.0): array
    {
        unset($instanceName, $routes, $timeout);

        return [
            'success' => false,
            'message' => (string)__('WLS 公网边缘固定使用 Nginx；已拒绝非 Nginx 适配器。'),
            'data' => ['routes' => 0, 'gateways' => 0, 'targets' => []],
        ];
    }

    public function securityUnblock(string $instanceName = 'default', ?string $ip = null, bool $clearAll = false): array
    {
        $payload = ['clear_all' => $clearAll];
        if ($ip !== null && $ip !== '') {
            $payload['ip'] = $ip;
        }

        return $this->command(
            $instanceName,
            ControlMessage::ACTION_SECURITY_UNBLOCK,
            '',
            $payload,
            Timeouts::CONTROL_CMD_DEFAULT_READ_SEC
        );
    }

    public function scaleWorkers(string $instanceName, int $targetWorkers, float $timeout = 10.0): array
    {
        return $this->command(
            $instanceName,
            ControlMessage::ACTION_SCALE_WORKERS,
            '',
            ['target_workers' => $targetWorkers],
            $timeout
        );
    }

    public function scalingStatus(string $instanceName, float $timeout = 4.0): array
    {
        return $this->command(
            $instanceName,
            ControlMessage::ACTION_SCALING_STATUS,
            '',
            [],
            $timeout
        );
    }

    // ==================== 并发批量派发（P0-3） ====================
    //
    // 旧 BroadcastControlDispatchService::dispatchToRunningInstances 使用 foreach 串行调用
    // 每个实例的 sendCommand，每次最长阻塞 $timeout。N 个实例最差 N × timeout。
    //
    // 下列 *Many 方法 + sendCommandsParallel 改为：
    //   1) 先对所有实例快速完成 connect + fwrite（单次 write 通常 <1ms）
    //   2) 用 stream_select 在一个总超时窗口内并发等待 N 个 ACK
    //
    // 总耗时从 N × timeout 降为 max(单实例 RTT) ≈ timeout（最差场景）。
    //
    // 说明：
    // - Interface IpcControlGatewayInterface 未扩展，仅在具体实现上添加，避免破坏实现方。
    // - 内部不触发 Fiber，依赖 stream_select 多路复用在单进程事件环内等待 ACK。

    /**
     * @param string[] $instanceNames
     * @return array<string, array{success:bool,message:string,data:array}>
     */
    public function reloadAsyncMany(array $instanceNames, string $reloadType, float $timeout = 5.0): array
    {
        return $this->commandAsyncMany(
            $instanceNames,
            ControlMessage::ACTION_RELOAD,
            $reloadType,
            [],
            $timeout,
            'Reload initiated'
        );
    }

    /**
     * @param string[] $instanceNames
     * @return array<string, array{success:bool,message:string,data:array}>
     */
    public function cacheClearMany(array $instanceNames, float $timeout = 5.0): array
    {
        return $this->commandAsyncMany(
            $instanceNames,
            ControlMessage::ACTION_CACHE_CLEAR,
            '',
            [],
            $timeout,
            'Cache clear queued'
        );
    }

    /**
     * @param string[] $instanceNames
     * @return array<string, array{success:bool,message:string,data:array}>
     */
    public function setMaintenanceModeMany(
        array $instanceNames,
        bool $enabled,
        float $timeout = 6.0,
        bool $dispatcherOnly = false
    ): array {
        return $this->commandAsyncMany(
            $instanceNames,
            $enabled ? ControlMessage::ACTION_MAINTENANCE_ENABLE : ControlMessage::ACTION_MAINTENANCE_DISABLE,
            '',
            $dispatcherOnly ? ['dispatcher_only' => true] : [],
            $timeout,
            $enabled ? 'Maintenance enable queued' : 'Maintenance disable queued'
        );
    }

    /**
     * @param string[] $instanceNames
     * @return array<string, array{success:bool,message:string,data:array}>
     */
    public function routingCacheClearMany(array $instanceNames, float $timeout = 5.0): array
    {
        return $this->commandAsyncMany(
            $instanceNames,
            ControlMessage::ACTION_ROUTING_CACHE_CLEAR,
            '',
            [],
            $timeout,
            'Routing cache clear queued'
        );
    }

    /**
     * @param string[] $instanceNames
     * @param string[] $domains
     * @return array<string, array{success:bool,message:string,data:array}>
     */
    public function reloadSslCertMany(array $instanceNames, array $domains = [], float $timeout = 6.0): array
    {
        unset($domains, $timeout);
        $results = [];
        foreach ($instanceNames as $instanceName) {
            if (!\is_string($instanceName)) {
                continue;
            }
            $instanceName = \trim($instanceName);
            if ($instanceName === '' || isset($results[$instanceName])) {
                continue;
            }
            $results[$instanceName] = $this->reloadSslCert($instanceName);
        }
        return $results;
    }

    /**
     * @param string[] $instanceNames
     * @return array<string, array{success:bool,message:string,data:array}>
     */
    protected function commandAsyncMany(
        array $instanceNames,
        string $action,
        string $reloadType,
        array $payload,
        float $timeout,
        string $acceptedMessage
    ): array {
        return $this->dispatchCommandMany(
            $instanceNames,
            $action,
            $reloadType,
            $payload,
            $timeout,
            true,
            $acceptedMessage
        );
    }

    /**
     * @param string[] $instanceNames
     * @return array<string, array{success:bool,message:string,data:array}>
     */
    protected function commandMany(
        array $instanceNames,
        string $action,
        string $reloadType,
        array $payload,
        float $timeout
    ): array {
        return $this->dispatchCommandMany(
            $instanceNames,
            $action,
            $reloadType,
            $payload,
            $timeout,
            false,
            ''
        );
    }

    /**
     * @param string[] $instanceNames
     * @return array<string, array{success:bool,message:string,data:array}>
     */
    private function dispatchCommandMany(
        array $instanceNames,
        string $action,
        string $reloadType,
        array $payload,
        float $timeout,
        bool $asyncAck,
        string $acceptedMessage
    ): array {
        $results = [];
        $commands = [];
        foreach ($instanceNames as $name) {
            $endpoint = $this->resolveControlEndpoint($name);
            $port = (int)$endpoint['port'];
            $requestId = ControlCommandResult::requestId($action);
            if ($port <= 0) {
                $results[$name] = ControlCommandResult::normalize([
                    'success' => false,
                    'message' => (string)__('实例 %{1} 的 Master 未运行，无法通过 IPC 控制。', [$name]),
                    'data' => [],
                ], $name, $action, $requestId, $asyncAck);
                continue;
            }
            $payloadWithId = $payload;
            $payloadWithId['msg_id'] = $requestId;
            $commands[$name] = [
                'port' => $port,
                'action' => $action,
                'request_id' => $requestId,
                'async' => $asyncAck,
                'command' => ControlMessage::command(
                    $action,
                    $reloadType,
                    $payloadWithId,
                    (string)$endpoint['control_token']
                ),
            ];
        }

        if ($commands === []) {
            return $results;
        }

        $parallel = $this->sendCommandsParallel($commands, $timeout, $asyncAck, $acceptedMessage);
        foreach ($parallel as $name => $r) {
            $meta = $commands[$name] ?? [];
            $results[$name] = ControlCommandResult::normalize(
                $r,
                $name,
                (string)($meta['action'] ?? $action),
                (string)($meta['request_id'] ?? ''),
                (bool)($meta['async'] ?? $asyncAck)
            );
        }
        return $results;
    }

    /**
     * 对一组 (instance => controlPort) 并发发送同一条控制命令，用 stream_select 多路复用等待 ACK。
     *
     * 语义复用自 sendCommand：
     *   - $acceptWriteTimeoutAsAsyncAck=true：读超时时以 "已接受" 返回
     *   - false：读超时返回 timed_out 错误
     *
     * @param array<string, array{port:int,command:string}> $instanceCommands
     * @return array<string, array{success:bool,message:string,data:array}>
     */
    private function sendCommandsParallel(
        array $instanceCommands,
        float $timeout,
        bool $acceptWriteTimeoutAsAsyncAck,
        string $acceptedMessage
    ): array {
        $results = [];
        if ($instanceCommands === []) {
            return $results;
        }

        $readTimeout = \max(0.05, $timeout);
        $connectTimeout = \max(Timeouts::CONTROL_MIN_CONNECT_TIMEOUT_SEC, $readTimeout);

        /** @var array<string, resource> $connections */
        $connections = [];
        /** @var array<string, string> $buffers */
        $buffers = [];

        foreach ($instanceCommands as $instance => $endpoint) {
            $port = (int)($endpoint['port'] ?? 0);
            $command = (string)($endpoint['command'] ?? '');
            $errno = 0;
            $errstr = '';
            $conn = null;
            for ($attempt = 1; $attempt <= Timeouts::CONTROL_CONNECT_ATTEMPTS; $attempt++) {
                $conn = @\stream_socket_client(
                    "tcp://127.0.0.1:{$port}",
                    $errno,
                    $errstr,
                    $connectTimeout
                );
                if ($conn) {
                    break;
                }
                if ($attempt < Timeouts::CONTROL_CONNECT_ATTEMPTS) {
                    SchedulerSystem::usleep(Timeouts::CONTROL_CONNECT_RETRY_USEC);
                }
            }
            if (!$conn) {
                $results[$instance] = [
                    'success' => false,
                    'message' => (string)__('连接控制端口失败：%{1}', [$errstr ?: 'unknown']),
                    'data' => ['errno' => (int)$errno],
                ];
                continue;
            }

            if (!$this->writeCommandFully($conn, $command, $readTimeout)) {
                @\fclose($conn);
                $results[$instance] = [
                    'success' => false,
                    'message' => (string)__('发送控制命令失败，请检查 Orchestrator IPC 连接状态。'),
                    'data' => [],
                ];
                continue;
            }

            $connections[$instance] = $conn;
            $buffers[$instance] = '';
        }

        $deadline = ControlMessage::monotonicSeconds() + $readTimeout;
        while ($connections !== []) {
            $remaining = $deadline - ControlMessage::monotonicSeconds();
            if ($remaining <= 0) {
                break;
            }

            $readable = \array_values($connections);
            $write = null;
            $except = null;
            $sec = (int)\floor($remaining);
            $usec = (int)(($remaining - $sec) * 1_000_000);

            $ready = @\stream_select($readable, $write, $except, $sec, $usec);
            if ($ready === false || $ready === 0) {
                break;
            }

            foreach ($readable as $readyConn) {
                $readyInstance = null;
                foreach ($connections as $inst => $c) {
                    if ($c === $readyConn) {
                        $readyInstance = $inst;
                        break;
                    }
                }
                if ($readyInstance === null) {
                    continue;
                }

                $chunk = @\fread($readyConn, 65536);
                if ($chunk === false) {
                    $results[$readyInstance] = [
                        'success' => false,
                        'message' => (string)__('读取控制命令响应失败。'),
                        'data' => [],
                    ];
                    @\fclose($readyConn);
                    unset($connections[$readyInstance], $buffers[$readyInstance]);
                    continue;
                }

                if ($chunk !== '') {
                    $buffers[$readyInstance] .= $chunk;
                    $parsed = false;
                    foreach (ControlMessage::extractMessages($buffers[$readyInstance]) as $message) {
                        if (($message['type'] ?? '') !== ControlMessage::TYPE_COMMAND_RESULT) {
                            continue;
                        }
                        $results[$readyInstance] = [
                            'success' => (bool)($message['success'] ?? false),
                            'message' => (string)($message['message'] ?? ''),
                            'data' => \is_array($message['data'] ?? null) ? $message['data'] : [],
                        ];
                        $parsed = true;
                        break;
                    }
                    if ($parsed) {
                        @\fclose($readyConn);
                        unset($connections[$readyInstance], $buffers[$readyInstance]);
                        continue;
                    }
                }

                if (\feof($readyConn)) {
                    if (!isset($results[$readyInstance])) {
                        $results[$readyInstance] = [
                            'success' => false,
                            'message' => (string)__('读取控制命令响应失败。'),
                            'data' => [],
                            'timed_out' => false,
                        ];
                    }
                    @\fclose($readyConn);
                    unset($connections[$readyInstance], $buffers[$readyInstance]);
                }
            }
        }

        // 剩余未回 ACK 的连接 → 按 write-timeout 回退语义处理
        foreach ($connections as $instance => $conn) {
            if ($acceptWriteTimeoutAsAsyncAck) {
                $results[$instance] = [
                    'success' => true,
                    'message' => $acceptedMessage !== '' ? $acceptedMessage : (string)__('控制命令已发送'),
                    'data' => [
                        'async' => true,
                        'accepted' => true,
                        'accepted_via' => 'write_timeout_fallback',
                    ],
                ];
            } else {
                $results[$instance] = [
                    'success' => false,
                    'message' => (string)__('等待控制命令响应超时（%{1}s）。', [\round($readTimeout, 1)]),
                    'data' => [],
                    'timed_out' => true,
                ];
            }
            @\fclose($conn);
        }

        return $results;
    }

    /**
     * Master 未运行时，按进程管理器启动 WLS（仅用于后台 start 兜底）
     */
    public function startInstance(string $instanceName = 'default', int $workers = 0): array
    {
        $command = PHP_BINARY . ' ' . BP . 'bin/w server:start ' . \escapeshellarg($instanceName);
        if ($workers > 0) {
            $command .= ' -c ' . $workers;
        }

        $pid = Processer::create($command, false);
        if ($pid <= 0) {
            return [
                'success' => false,
                'message' => (string)__('启动命令已提交，但未返回有效 PID，请稍后刷新状态确认。'),
                'data' => [],
            ];
        }

        return [
            'success' => true,
            'message' => (string)__('启动命令已提交，Master PID: %{1}', [$pid]),
            'data' => ['pid' => $pid],
        ];
    }

    private function resolveControlPort(string $instanceName): int
    {
        return (int)$this->resolveControlEndpoint($instanceName)['port'];
    }

    /**
     * @return array{port:int,control_token:string}
     */
    private function resolveControlEndpoint(string $instanceName): array
    {
        $master = MasterProcess::getMasterEndpoint($instanceName);
        return [
            'port' => (int)($master['control_port'] ?? 0),
            'control_token' => (string)($master['control_token'] ?? ''),
        ];
    }

    /**
     * 异步控制命令：写入成功后优先等待短 ACK；若 Master 忙于主循环导致超时，则按“已接受”返回。
     *
     * @return array{success:bool,message:string,data:array}
     */
    protected function commandAsync(
        string $instanceName,
        string $action,
        string $reloadType = '',
        array $payload = [],
        float $timeout = 5.0,
        string $acceptedMessage = 'Command queued'
    ): array {
        $requestId = (string)($payload['msg_id'] ?? ControlCommandResult::requestId($action));
        $payload['msg_id'] = $requestId;
        $endpoint = $this->resolveControlEndpoint($instanceName);
        $controlPort = (int)$endpoint['port'];
        if ($controlPort <= 0) {
            return ControlCommandResult::normalize([
                'success' => false,
                'message' => (string)__('实例 %{1} 的 Master 未运行，无法通过 IPC 控制。', [$instanceName]),
                'data' => [],
            ], $instanceName, $action, $requestId, true);
        }

        $result = $this->sendCommand(
            $controlPort,
            ControlMessage::command($action, $reloadType, $payload, (string)$endpoint['control_token']),
            $timeout,
            true,
            $acceptedMessage
        );

        return ControlCommandResult::normalize($result, $instanceName, $action, $requestId, true);
    }

    /**
     * @param resource $conn
     * @return array{success:bool,message:string,data:array,timed_out?:bool}
     */
    private function readCommandResult($conn, float $timeout): array
    {
        \stream_set_timeout($conn, (int)\ceil($timeout));
        \stream_set_blocking($conn, false);

        $buffer = '';
        $deadline = ControlMessage::monotonicSeconds() + $timeout;
        while (ControlMessage::monotonicSeconds() < $deadline) {
            $remaining = $deadline - ControlMessage::monotonicSeconds();
            if ($remaining <= 0) {
                break;
            }

            $read = [$conn];
            $write = null;
            $except = null;
            $sec = (int)\floor($remaining);
            $usec = (int)(($remaining - $sec) * 1_000_000);
            $ready = @\stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false) {
                return [
                    'success' => false,
                    'message' => 'Failed to read control command response.',
                    'data' => [],
                ];
            }
            if ($ready === 0) {
                continue;
            }

            $chunk = @\fread($conn, 65536);
            if ($chunk === false) {
                return [
                    'success' => false,
                    'message' => (string)__('读取控制命令响应失败。'),
                    'data' => [],
                ];
            }

            if ($chunk !== '') {
                $buffer .= $chunk;
                foreach (ControlMessage::extractMessages($buffer) as $message) {
                    if (($message['type'] ?? '') !== ControlMessage::TYPE_COMMAND_RESULT) {
                        continue;
                    }

                    return [
                        'success' => (bool)($message['success'] ?? false),
                        'message' => (string)($message['message'] ?? ''),
                        'data' => \is_array($message['data'] ?? null) ? $message['data'] : [],
                    ];
                }
            }

            if (\feof($conn)) {
                return [
                    'success' => false,
                    'message' => (string)__('读取控制命令响应失败。'),
                    'data' => [],
                    'timed_out' => false,
                ];
            }

        }

        return [
            'success' => false,
            'message' => (string)__('等待控制命令响应超时（%{1}s）。', [\round($timeout, 1)]),
            'data' => [],
            'timed_out' => true,
        ];
    }

    /**
     * @return array{success:bool,message:string,data:array,timed_out?:bool}
     */
    private function sendCommand(
        int $controlPort,
        string $command,
        float $timeout,
        bool $acceptWriteTimeoutAsAsyncAck = false,
        string $acceptedMessage = ''
    ): array
    {
        $readTimeout = \max(0.05, $timeout);
        $connectTimeout = \max(Timeouts::CONTROL_MIN_CONNECT_TIMEOUT_SEC, $readTimeout);

        $conn = null;
        $errno = 0;
        $errstr = '';
        for ($attempt = 1; $attempt <= Timeouts::CONTROL_CONNECT_ATTEMPTS; $attempt++) {
            $conn = @\stream_socket_client(
                "tcp://127.0.0.1:{$controlPort}",
                $errno,
                $errstr,
                $connectTimeout
            );
            if ($conn) {
                break;
            }
            if ($attempt < Timeouts::CONTROL_CONNECT_ATTEMPTS) {
                SchedulerSystem::usleep(Timeouts::CONTROL_CONNECT_RETRY_USEC);
            }
        }
        if (!$conn) {
            return [
                'success' => false,
                'message' => (string)__('连接控制端口失败：%{1}', [$errstr ?: 'unknown']),
                'data' => ['errno' => (int)$errno],
            ];
        }

        try {
            if (!$this->writeCommandFully($conn, $command, $readTimeout)) {
                return [
                    'success' => false,
                    'message' => (string)__('发送控制命令失败，请检查 Orchestrator IPC 连接状态。'),
                    'data' => [],
                ];
            }

            $result = $this->readCommandResult($conn, $readTimeout);
            if ($acceptWriteTimeoutAsAsyncAck && !empty($result['timed_out'])) {
                return [
                    'success' => true,
                    'message' => $acceptedMessage !== '' ? $acceptedMessage : (string)__('控制命令已发送'),
                    'data' => [
                        'async' => true,
                        'accepted' => true,
                        'accepted_via' => 'write_timeout_fallback',
                    ],
                ];
            }

            return [
                'success' => (bool)($result['success'] ?? false),
                'message' => (string)($result['message'] ?? ''),
                'data' => \is_array($result['data'] ?? null) ? $result['data'] : [],
                'timed_out' => (bool)($result['timed_out'] ?? false),
            ];
        } finally {
            @\fclose($conn);
        }
    }

    /** @param resource $connection */
    private function writeCommandFully($connection, string $command, float $timeout): bool
    {
        $length = \strlen($command);
        if ($length < 1 || $length > 1_048_577) {
            return false;
        }
        if (!@\stream_set_blocking($connection, false)) {
            return false;
        }
        $deadline = ControlMessage::monotonicSeconds() + \max(0.05, $timeout);
        $offset = 0;
        while ($offset < $length) {
            $remaining = $deadline - ControlMessage::monotonicSeconds();
            if ($remaining <= 0.0) {
                return false;
            }
            $read = null;
            $write = [$connection];
            $except = null;
            $seconds = (int)\floor($remaining);
            $microseconds = (int)(($remaining - $seconds) * 1_000_000);
            $ready = @\stream_select(
                $read,
                $write,
                $except,
                $seconds,
                $microseconds,
            );
            if ($ready === false || $ready === 0) {
                return false;
            }
            $written = @\fwrite(
                $connection,
                \substr($command, $offset, \min(65_536, $length - $offset)),
            );
            if (!\is_int($written) || $written < 1) {
                return false;
            }
            $offset += $written;
        }

        return true;
    }

    /** @return array{success:bool,message:string,data:array} */
    private function boundedNamespaceCommand(
        string $instanceName,
        string $action,
        array $payload,
        float $timeout,
    ): array {
        $timeout = \max(0.02, \min(0.1, $timeout));
        $startedAt = ControlMessage::monotonicSeconds();
        $requestId = ControlCommandResult::requestId($action);
        $payload['msg_id'] = $requestId;
        $endpoint = $this->resolveControlEndpoint($instanceName);
        $port = (int)$endpoint['port'];
        if ($port <= 0) {
            return ControlCommandResult::normalize([
                'success' => false,
                'message' => (string)__('实例 %{1} 的 Master 未运行，无法通过 IPC 控制。', [$instanceName]),
                'data' => ['accepted' => false, 'error_code' => 'runtime_not_present'],
            ], $instanceName, $action, $requestId, true);
        }

        $errno = 0;
        $errstr = '';
        $connectBudget = \max(0.01, \min(0.05, $timeout));
        $connection = @\stream_socket_client(
            "tcp://127.0.0.1:{$port}",
            $errno,
            $errstr,
            $connectBudget,
        );
        if (!\is_resource($connection)) {
            return ControlCommandResult::normalize([
                'success' => false,
                'message' => (string)__('连接控制端口失败：%{1}', [$errstr ?: 'unknown']),
                'data' => ['accepted' => false, 'error_code' => 'connect_failed', 'errno' => $errno],
            ], $instanceName, $action, $requestId, true);
        }

        try {
            $command = ControlMessage::command(
                $action,
                '',
                $payload,
                (string)$endpoint['control_token'],
            );
            $remaining = $timeout - (ControlMessage::monotonicSeconds() - $startedAt);
            if ($remaining <= 0.0) {
                return ControlCommandResult::normalize([
                    'success' => false,
                    'message' => (string)__('缓存命名空间失效接收超时。'),
                    'data' => ['accepted' => false, 'error_code' => 'accept_timeout'],
                ], $instanceName, $action, $requestId, true);
            }
            if (!$this->writeCommandFully($connection, $command, $remaining)) {
                return ControlCommandResult::normalize([
                    'success' => false,
                    'message' => (string)__('缓存命名空间失效控制帧未完整写入。'),
                    'data' => ['accepted' => false, 'error_code' => 'send_failed'],
                ], $instanceName, $action, $requestId, true);
            }
            $remaining = $timeout - (ControlMessage::monotonicSeconds() - $startedAt);
            if ($remaining <= 0.0) {
                return ControlCommandResult::normalize([
                    'success' => false,
                    'message' => (string)__('缓存命名空间失效接收超时。'),
                    'data' => ['accepted' => false, 'error_code' => 'accept_timeout'],
                ], $instanceName, $action, $requestId, true);
            }
            $result = $this->readCommandResult($connection, $remaining);
            return ControlCommandResult::normalize($result, $instanceName, $action, $requestId, true);
        } finally {
            @\fclose($connection);
        }
    }

    private function namespaceInvalidationProtocol(): NamespaceInvalidationProtocol
    {
        return $this->namespaceInvalidationProtocol ??= new NamespaceInvalidationProtocol(
            new \Weline\Framework\Cache\Namespace\NamespacePath(),
        );
    }
}
