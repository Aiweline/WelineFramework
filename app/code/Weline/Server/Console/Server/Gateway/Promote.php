<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;
use Weline\Framework\App\Env;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;
use Weline\Server\Service\ServerInstanceManager;
use Weline\Server\Service\Runtime\ProtocolEdgeRuntime;

final class Promote extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        $json = $this->isJson($args);
        if (!isset($args['confirm'])) {
            return $this->failure(
                __('显式提升必须携带 --confirm，且只能由当前 80/443 的受管 owner 执行。'),
                $json,
                'confirmation_required',
            );
        }
        $package = \trim((string)($args['package'] ?? ''));
        if ($package === '') {
            return $this->failure(
                __('显式提升必须通过 --package 提供签名、自包含的 WLS 2.0 宿主包。'),
                $json,
                'package_required',
            );
        }
        $profile = \strtolower(\trim((string)($args['profile'] ?? 'default')));
        $legacy = ManagedNginxService::fromEnv();
        $snapshot = $legacy->doctorSnapshot();
        $paths = new GatewayPaths();
        $owner = \trim((string)($snapshot['owner_instance'] ?? ''));
        if (!($snapshot['running'] ?? false)
            || !($snapshot['runtime_owner_active'] ?? false)
            || $owner === ''
            || (int)($snapshot['listen_http'] ?? 0) !== $paths->publicHttpPort()
            || (int)($snapshot['listen_https'] ?? 0) !== $paths->publicHttpsPort()
        ) {
            return $this->failure(
                __('只有身份已验证、正在占用目标公共端口的项目托管 Nginx 才能提升。'),
                $json,
                'legacy_owner_not_eligible',
            );
        }
        $upstreamHost = (string)($snapshot['owner_upstream_host'] ?? '127.0.0.1');
        $upstreamPort = (int)($snapshot['owner_upstream_port'] ?? 0);
        $serverNames = (array)($snapshot['owner_server_names'] ?? []);
        $legacyRuntimeRoot = $legacy->paths()->runtimeRoot();
        $legacyRuntimeOwnership = $this->projectRuntimeOwnership($legacyRuntimeRoot);
        $gateway = new GatewayHostManager();
        $builder = new GatewayRegistrationBuilder();
        $projectRoot = \realpath((string)BP);
        if (!\is_string($projectRoot) || $builder->desiredDomains() === []) {
            return $this->failure(
                __('当前项目缺少可迁移身份或域名/证书期望状态，旧 Nginx 保持不变。'),
                $json,
                'promotion_state_incomplete',
            );
        }
        $legacyPublicBaseline = $this->probeLegacyPublicResponses(
            $paths->publicHttpsPort(),
            $serverNames,
        );
        if (!($legacyPublicBaseline['ok'] ?? false)) {
            return $this->failure(
                __('旧 Nginx 的真实 HTTP/1.1 与 HTTP/2 响应未通过交接前门禁，保持原状。'),
                $json,
                'legacy_public_probe_failed',
                ['legacy_public_probe' => $legacyPublicBaseline],
            );
        }
        $staged = null;
        $activated = false;
        $legacyStopped = false;
        $agentAttached = false;
        $savedEdgeSnapshot = null;
        $downtimeStarted = 0.0;
        try {
            // Package, Broker, locked runtimes, system definition and project
            // desired state are validated while the legacy owner keeps serving.
            $staged = $gateway->stageLegacyPromotion($package, $profile);
            $stopped = $legacy->stopForInstance($owner);
            if (!($stopped['ok'] ?? false)) {
                throw new \RuntimeException(
                    'Legacy Nginx did not release the public ports: '
                    . (string)($stopped['message'] ?? '')
                );
            }
            $legacyStopped = true;
            $downtimeStarted = \microtime(true);
            $gateway->activateLegacyPromotion($staged);
            $activated = true;
            $gateway->enrollCurrentProjectForPromotion($builder, $projectRoot);
            // An administrator promotion may encounter a root-owned runtime
            // directory left by WLS 1.x. Repair it before the project Master
            // must create the authenticated join-backend capability token.
            ProtocolEdgeRuntime::ensureTokenFile($owner);
            $savedEdgeSnapshot = $this->persistPromotedInstanceEdgeMode($owner);
            // Mark the attempt before IPC so a lost enable acknowledgement is
            // still compensated by the idempotent Master-side disable action.
            $agentAttached = true;
            $agent = $this->setProjectGatewayAgentEnabled($owner, true);
            $registration = $gateway->awaitPromotionProjectActivation(
                $owner,
                $projectRoot,
            );
            $transactionId = (string)(
                $agent['runtime_endpoint']['transaction_id'] ?? ''
            );
            $commit = $this->commitProjectGatewayAgentPromotion($owner, $transactionId);
            $agentAttached = false;
            $registration['agent'] = $agent;
            $registration['agent_commit'] = $commit;
            $maintenanceWindow = \microtime(true) - $downtimeStarted;
            if (!$json) {
                $this->printer->success(__('WLS 1.x 公共端口 owner 已显式提升为宿主级 WLS 2.0 Gateway。'));
                $this->printer->note(__('实测维护窗：%{1} 秒。', [
                    \number_format($maintenanceWindow, 3, '.', ''),
                ]));
            }
            $this->output([
                'owner_instance' => $owner,
                'maintenance_window_seconds' => \round($maintenanceWindow, 3),
                'registration' => $registration,
            ], $json);
            return 0;
        } catch (\Throwable $throwable) {
            $details = [
                'legacy_stopped' => $legacyStopped,
                'gateway_activated' => $activated,
                'legacy_rollback' => 'not_required',
            ];
            if ($agentAttached) {
                try {
                    $this->setProjectGatewayAgentEnabled($owner, false);
                    $agentAttached = false;
                    $details['gateway_agent_cleanup'] = 'detached';
                } catch (\Throwable $agentCleanup) {
                    $details['gateway_agent_cleanup'] = 'failed';
                    $details['gateway_agent_cleanup_error'] = $agentCleanup->getMessage();
                }
            }
            if (\is_array($staged)) {
                try {
                    $gateway->abortLegacyPromotion($staged, $activated);
                } catch (\Throwable $abort) {
                    $details['gateway_cleanup_error'] = $abort->getMessage();
                    if (!$json) {
                        $this->printer->error(
                            __('宿主网关清理失败：%{1}', [$abort->getMessage()])
                        );
                    }
                }
            }
            if (\is_array($savedEdgeSnapshot)) {
                try {
                    $this->restoreSavedInstanceEdgeMode($owner, $savedEdgeSnapshot);
                    $details['saved_edge_mode_rollback'] = 'restored';
                } catch (\Throwable $savedConfigRollback) {
                    $details['saved_edge_mode_rollback'] = 'failed';
                    $details['saved_edge_mode_rollback_error'] = $savedConfigRollback->getMessage();
                }
            }
            if (!$legacyStopped) {
                if (!$json) {
                    $this->printer->warning(__('旧项目 Nginx 未进入交接，仍保持原状。'));
                }
                return $this->failure(
                    __('提升失败：%{1}', [$throwable->getMessage()]),
                    $json,
                    'promotion_failed',
                    $details,
                );
            }
            try {
                if (\is_array($legacyRuntimeOwnership)) {
                    $this->restoreProjectRuntimeOwnership(
                        $legacyRuntimeRoot,
                        (int)$legacyRuntimeOwnership['uid'],
                        (int)$legacyRuntimeOwnership['gid'],
                    );
                }
                $rollback = $this->restoreLegacyNginxThroughProjectMaster(
                    $owner,
                    $upstreamPort,
                    $upstreamHost,
                    $serverNames,
                );
                if ($rollback['ok'] ?? false) {
                    $rollbackProbe = $this->probeLegacyPublicResponses(
                        $paths->publicHttpsPort(),
                        $serverNames,
                        $legacyPublicBaseline,
                    );
                    $details['legacy_rollback_probe'] = $rollbackProbe;
                    if (!($rollbackProbe['ok'] ?? false)) {
                        $rollback = [
                            'ok' => false,
                            'message' => 'Project-owned legacy Nginx restarted, but its '
                                . 'complete public response did not recover: '
                                . (string)($rollbackProbe['reason'] ?? 'probe failed'),
                        ];
                    }
                }
            } catch (\Throwable $rollbackError) {
                $rollback = [
                    'ok' => false,
                    'message' => 'Project-owned legacy Nginx rollback failed: '
                        . $rollbackError->getMessage(),
                ];
            }
            if ($rollback['ok'] ?? false) {
                $details['legacy_rollback'] = 'restored';
                if (!$json) {
                    $this->printer->warning(__('已回滚并恢复原项目托管 Nginx。'));
                }
                if ($downtimeStarted > 0.0) {
                    $recoveryWindow = \microtime(true) - $downtimeStarted;
                    $details['recovery_window_seconds'] = \round($recoveryWindow, 3);
                    if (!$json) {
                        $this->printer->note(__('失败恢复窗：%{1} 秒。', [
                            \number_format($recoveryWindow, 3, '.', ''),
                        ]));
                    }
                }
            } else {
                $details['legacy_rollback'] = 'failed';
                $details['legacy_rollback_error'] = (string)($rollback['message'] ?? '');
                if (!$json) {
                    $this->printer->error(__('原项目 Nginx 回滚也失败：%{1}', [
                        (string)($rollback['message'] ?? ''),
                    ]));
                }
            }
            return $this->failure(
                __('提升失败：%{1}', [$throwable->getMessage()]),
                $json,
                'promotion_failed',
                $details,
            );
        }
    }

    /** @return array{content:string,mode:int,uid:int,gid:int} */
    private function persistPromotedInstanceEdgeMode(string $instanceName): array
    {
        $file = $this->savedInstanceConfigFile($instanceName);
        if (!\is_file($file) || \is_link($file)) {
            throw new \RuntimeException('Legacy promotion requires a regular saved instance configuration.');
        }
        $status = @\lstat($file);
        $content = @\file_get_contents($file);
        $config = \is_string($content) ? \json_decode($content, true) : null;
        if (!\is_array($status) || !\is_string($content) || !\is_array($config)) {
            throw new \RuntimeException('Saved instance configuration is unreadable or invalid.');
        }
        $snapshot = [
            'content' => $content,
            'mode' => (int)$status['mode'] & 0777,
            'uid' => (int)($status['uid'] ?? -1),
            'gid' => (int)($status['gid'] ?? -1),
        ];
        $config['edge_mode'] = 'auto';
        $config['edge_adapter'] = 'nginx';
        $config['ssl_enabled'] = false;
        $config['saved_at'] = \date('Y-m-d H:i:s');
        $encoded = \json_encode(
            $config,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        if (!\is_string($encoded)
            || !$this->writeSavedInstanceConfigAtomically(
                $file,
                $encoded . "\n",
                $snapshot['mode'],
                $snapshot['uid'],
                $snapshot['gid'],
            )
        ) {
            throw new \RuntimeException('Unable to persist the promoted instance edge mode.');
        }
        return $snapshot;
    }

    /** @param array{content:string,mode:int,uid:int,gid:int} $snapshot */
    private function restoreSavedInstanceEdgeMode(
        string $instanceName,
        array $snapshot,
    ): void {
        if (!$this->writeSavedInstanceConfigAtomically(
            $this->savedInstanceConfigFile($instanceName),
            (string)$snapshot['content'],
            (int)$snapshot['mode'],
            (int)$snapshot['uid'],
            (int)$snapshot['gid'],
        )) {
            throw new \RuntimeException('Unable to restore the saved legacy edge mode.');
        }
    }

    private function savedInstanceConfigFile(string $instanceName): string
    {
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $instanceName) !== 1) {
            throw new \RuntimeException('Promotion instance name is invalid.');
        }
        return Env::VAR_DIR . 'server' . DS . 'config' . DS . $instanceName . '.json';
    }

    private function writeSavedInstanceConfigAtomically(
        string $file,
        string $contents,
        int $mode,
        int $uid,
        int $gid,
    ): bool {
        $directory = \dirname($file);
        $canonicalDirectory = \realpath($directory);
        if (!\is_string($canonicalDirectory)
            || !\is_dir($canonicalDirectory)
            || \is_link($directory)
            || !\hash_equals(\rtrim($canonicalDirectory, '/\\'), \rtrim($directory, '/\\'))
        ) {
            throw new \RuntimeException('Saved instance configuration directory is unsafe.');
        }
        $mode = $mode > 0 ? ($mode & 0777) : 0644;
        $lockFile = $file . '.lock';
        if (\is_link($lockFile)) {
            throw new \RuntimeException('Saved instance configuration lock is unsafe.');
        }
        $lock = @\fopen($lockFile, 'c');
        if (!\is_resource($lock)) {
            return false;
        }
        $root = \function_exists('posix_geteuid') && \posix_geteuid() === 0;
        if ($root) {
            @\chown($lockFile, $uid);
            @\chgrp($lockFile, $gid);
        }
        @\chmod($lockFile, 0600);
        if (!@\flock($lock, LOCK_EX)) {
            @\fclose($lock);
            return false;
        }
        $temporary = $file . '.promotion-' . \bin2hex(\random_bytes(8));
        try {
            if (\is_link($file)) {
                throw new \RuntimeException('Saved instance configuration became a symbolic link.');
            }
            $handle = @\fopen($temporary, 'xb');
            if (!\is_resource($handle)) {
                return false;
            }
            try {
                $written = 0;
                $length = \strlen($contents);
                while ($written < $length) {
                    $amount = @\fwrite($handle, \substr($contents, $written));
                    if (!\is_int($amount) || $amount < 1) {
                        return false;
                    }
                    $written += $amount;
                }
                if (!@\fflush($handle)
                    || (\function_exists('fsync') && !@\fsync($handle))
                ) {
                    return false;
                }
            } finally {
                @\fclose($handle);
            }
            if (!@\chmod($temporary, $mode)) {
                return false;
            }
            if ($root && (!@\chown($temporary, $uid) || !@\chgrp($temporary, $gid))) {
                return false;
            }
            $attempts = \PHP_OS_FAMILY === 'Windows' ? 5 : 1;
            for ($attempt = 0; $attempt < $attempts; $attempt++) {
                if (@\rename($temporary, $file)) {
                    @\chmod($file, $mode);
                    return true;
                }
                if ($attempt + 1 < $attempts) {
                    \usleep(10000);
                }
            }
            return false;
        } finally {
            if (\is_file($temporary) && !\is_link($temporary)) {
                @\unlink($temporary);
            }
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }
    }

    /** @return array<string,mixed> */
    private function setProjectGatewayAgentEnabled(
        string $instanceName,
        bool $enabled,
    ): array {
        $action = $enabled
            ? ControlMessage::ACTION_GATEWAY_AGENT_ENABLE
            : ControlMessage::ACTION_GATEWAY_AGENT_DISABLE;
        $result = (new IpcControlGateway())->command(
            $instanceName,
            $action,
            '',
            [],
            30.0,
        );
        if (!($result['success'] ?? false)) {
            throw new \RuntimeException(
                ($enabled ? 'Gateway Agent attach failed: ' : 'Gateway Agent detach failed: ')
                    . (string)($result['message'] ?? 'Master rejected the lifecycle command.')
            );
        }
        return \is_array($result['data'] ?? null) ? $result['data'] : [];
    }

    /** @return array<string,mixed> */
    private function commitProjectGatewayAgentPromotion(
        string $instanceName,
        string $transactionId,
    ): array {
        $transactionId = \strtolower(\trim($transactionId));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $transactionId) !== 1) {
            throw new \RuntimeException('Gateway Agent promotion transaction identity is invalid.');
        }
        $result = (new IpcControlGateway())->command(
            $instanceName,
            ControlMessage::ACTION_GATEWAY_AGENT_COMMIT,
            '',
            ['promotion_transaction_id' => $transactionId],
            10.0,
        );
        if ($result['success'] ?? false) {
            return \is_array($result['data'] ?? null) ? $result['data'] : [];
        }
        $endpoint = (new ServerInstanceManager())->getRawInstanceData($instanceName);
        $gateway = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        $committedTransaction = \strtolower(\trim((string)(
            $gateway['promotion_committed_transaction_id'] ?? ''
        )));
        if ((string)($gateway['promotion_state'] ?? '') === 'COMMITTED'
            && \hash_equals($transactionId, $committedTransaction)
            && (string)($endpoint['edge_adapter'] ?? '') === 'wls'
            && (string)($gateway['requested_mode'] ?? '') === 'auto'
        ) {
            return [
                'state' => 'COMMITTED',
                'transaction_id' => $transactionId,
                'acknowledgement_recovered' => true,
            ];
        }
        throw new \RuntimeException(
            'Gateway Agent promotion commit failed: '
                . (string)($result['message'] ?? 'Master rejected the commit command.')
        );
    }

    /**
     * Restart legacy Nginx in the still-running project Master. The host
     * promotion command is privileged, while the Master preserves the exact
     * project uid/gid and must remain the process owner after rollback.
     *
     * @param list<string> $serverNames
     * @return array<string,mixed>
     */
    private function restoreLegacyNginxThroughProjectMaster(
        string $instanceName,
        int $upstreamPort,
        string $upstreamHost,
        array $serverNames,
    ): array {
        $result = (new IpcControlGateway())->command(
            $instanceName,
            ControlMessage::ACTION_GATEWAY_LEGACY_NGINX_RESTORE,
            '',
            [
                'owner_instance' => $instanceName,
                'upstream_port' => $upstreamPort,
                'upstream_host' => $upstreamHost,
                'server_names' => \array_values($serverNames),
            ],
            30.0,
        );
        $data = \is_array($result['data'] ?? null) ? $result['data'] : [];
        if (!($result['success'] ?? false) || !($data['ok'] ?? false)) {
            throw new \RuntimeException(
                (string)($result['message'] ?? $data['message']
                    ?? 'Project Master rejected the legacy Nginx restore command.')
            );
        }
        return $data;
    }

    /**
     * Probe complete public responses before hand-off and after rollback.
     * libcurl is used deliberately: unlike a header-only health check it
     * rejects truncated Content-Length bodies and HTTP/2 stream errors.
     *
     * @param list<string> $serverNames
     * @param array<string,mixed>|null $baseline
     * @return array<string,mixed>
     */
    private function probeLegacyPublicResponses(
        int $port,
        array $serverNames,
        ?array $baseline = null,
    ): array {
        $host = $this->legacyPublicProbeHost($serverNames);
        if ($port < 1
            || $port > 65535
            || $host === ''
            || !\extension_loaded('curl')
            || !\function_exists('curl_init')
            || !\defined('CURL_HTTP_VERSION_1_1')
            || !\defined('CURL_HTTP_VERSION_2_0')
            || !\defined('CURL_VERSION_HTTP2')
            || (((int)(\curl_version()['features'] ?? 0)
                & (int)\constant('CURL_VERSION_HTTP2')) === 0)
        ) {
            return [
                'ok' => false,
                'reason' => 'HTTP/1.1 and HTTP/2 rollback probe capability is unavailable.',
                'host' => $host,
                'port' => $port,
                'probes' => [],
            ];
        }

        $protocols = [
            'http1.1' => (int)\constant('CURL_HTTP_VERSION_1_1'),
            'http2' => (int)\constant('CURL_HTTP_VERSION_2_0'),
        ];
        $probes = [];
        foreach ($protocols as $name => $requestedVersion) {
            $probe = $this->probeLegacyPublicResponseOnce(
                $host,
                $port,
                $requestedVersion,
            );
            $probes[$name] = $probe;
            if (!($probe['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'reason' => $name . ': ' . (string)($probe['reason'] ?? 'probe failed'),
                    'host' => $host,
                    'port' => $port,
                    'probes' => $probes,
                ];
            }
            $expected = \is_array($baseline['probes'][$name] ?? null)
                ? $baseline['probes'][$name]
                : null;
            if ($expected !== null
                && ((int)($probe['status'] ?? 0) !== (int)($expected['status'] ?? 0)
                    || ((int)($expected['body_bytes'] ?? 0) > 0
                        && (int)($probe['body_bytes'] ?? 0) < 1))
            ) {
                return [
                    'ok' => false,
                    'reason' => $name . ': response no longer matches the pre-handoff baseline.',
                    'host' => $host,
                    'port' => $port,
                    'probes' => $probes,
                ];
            }
        }

        return [
            'ok' => true,
            'reason' => $baseline === null
                ? 'Pre-handoff public responses are complete.'
                : 'Rollback public responses match the pre-handoff status and framing.',
            'host' => $host,
            'port' => $port,
            'probes' => $probes,
        ];
    }

    /** @param list<string> $serverNames */
    private function legacyPublicProbeHost(array $serverNames): string
    {
        foreach ($serverNames as $serverName) {
            $candidate = \strtolower(\rtrim(\trim((string)$serverName), '.'));
            if ($candidate === ''
                || $candidate === '_'
                || \str_contains($candidate, '*')
                || \str_contains($candidate, ':')
                || \preg_match(
                    '/\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/D',
                    $candidate,
                ) !== 1
            ) {
                continue;
            }
            return $candidate;
        }
        return '';
    }

    /** @return array<string,mixed> */
    private function probeLegacyPublicResponseOnce(
        string $host,
        int $port,
        int $requestedVersion,
    ): array {
        $bodyBytes = 0;
        $hash = \hash_init('sha256');
        $handle = @\curl_init('https://' . $host . ':' . $port . '/');
        if ($handle === false) {
            return ['ok' => false, 'reason' => 'Unable to initialize libcurl.'];
        }
        $sslVersion = \defined('CURL_SSLVERSION_TLSv1_3')
            ? (int)\constant('CURL_SSLVERSION_TLSv1_3')
            : 0;
        if ($sslVersion > 0 && \defined('CURL_SSLVERSION_MAX_TLSv1_3')) {
            $sslVersion |= (int)\constant('CURL_SSLVERSION_MAX_TLSv1_3');
        }
        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => static function (mixed $curl, string $chunk) use (&$bodyBytes, $hash): int {
                $length = \strlen($chunk);
                $bodyBytes += $length;
                \hash_update($hash, $chunk);
                return $length;
            },
            CURLOPT_HTTP_VERSION => $requestedVersion,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_RESOLVE => [$host . ':' . $port . ':127.0.0.1'],
            CURLOPT_CONNECTTIMEOUT_MS => 1_500,
            CURLOPT_TIMEOUT_MS => 8_000,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_USERAGENT => 'WLS-Gateway-Promotion-Rollback-Probe/2',
            CURLOPT_HTTPHEADER => [
                'Accept-Encoding: identity',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
            ],
        ];
        if ($sslVersion > 0) {
            $options[CURLOPT_SSLVERSION] = $sslVersion;
        }
        if (\defined('CURLOPT_PROTOCOLS') && \defined('CURLPROTO_HTTPS')) {
            $options[(int)\constant('CURLOPT_PROTOCOLS')] = (int)\constant('CURLPROTO_HTTPS');
        }
        if (!@\curl_setopt_array($handle, $options)) {
            @\curl_close($handle);
            return ['ok' => false, 'reason' => 'Unable to configure the libcurl probe.'];
        }
        @\curl_exec($handle);
        $errno = (int)@\curl_errno($handle);
        $error = (string)@\curl_error($handle);
        $status = (int)@\curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $observedVersion = (int)@\curl_getinfo($handle, CURLINFO_HTTP_VERSION);
        @\curl_close($handle);
        $digest = \hash_final($hash);
        if ($errno !== CURLE_OK) {
            return [
                'ok' => false,
                'reason' => 'libcurl rejected the response framing: '
                    . ($error !== '' ? $error : (string)$errno),
                'curl_errno' => $errno,
                'status' => $status,
                'http_version' => $observedVersion,
                'body_bytes' => $bodyBytes,
                'body_sha256' => $digest,
            ];
        }
        if ($status < 200 || $status >= 500 || $observedVersion !== $requestedVersion) {
            return [
                'ok' => false,
                'reason' => 'Unexpected public status or negotiated HTTP version.',
                'curl_errno' => 0,
                'status' => $status,
                'http_version' => $observedVersion,
                'body_bytes' => $bodyBytes,
                'body_sha256' => $digest,
            ];
        }
        return [
            'ok' => true,
            'reason' => 'complete',
            'curl_errno' => 0,
            'status' => $status,
            'http_version' => $observedVersion,
            'body_bytes' => $bodyBytes,
            'body_sha256' => $digest,
        ];
    }

    /**
     * @return array{uid:int,gid:int}|null
     */
    private function projectRuntimeOwnership(string $root): ?array
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return null;
        }
        if (\is_link($root)) {
            throw new \RuntimeException(
                'Project Nginx runtime root cannot be a symbolic link.'
            );
        }
        $status = @\lstat($root);
        if (!\is_array($status)
            || !\is_int($status['uid'] ?? null)
            || !\is_int($status['gid'] ?? null)
        ) {
            throw new \RuntimeException(
                'Project Nginx runtime ownership cannot be established.'
            );
        }
        return ['uid' => (int)$status['uid'], 'gid' => (int)$status['gid']];
    }

    private function restoreProjectRuntimeOwnership(string $root, int $uid, int $gid): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return;
        }
        $canonical = \realpath($root);
        if (!\is_string($canonical)
            || \is_link($root)
            || $uid < 0
            || $gid < 0
        ) {
            throw new \RuntimeException(
                'Project Nginx runtime ownership target is invalid.'
            );
        }
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $canonical,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isLink()
                || !\str_starts_with($path . DIRECTORY_SEPARATOR, $canonical . DIRECTORY_SEPARATOR)
            ) {
                throw new \RuntimeException(
                    'Project Nginx runtime contains a symbolic link or escaped path.'
                );
            }
            $paths[] = $path;
        }
        $paths[] = $canonical;
        foreach ($paths as $path) {
            if (!@\chown($path, $uid) || !@\chgrp($path, $gid)) {
                throw new \RuntimeException(
                    'Unable to restore project Nginx runtime ownership: ' . $path
                );
            }
        }
    }

    public function tip(): string
    {
        return __('将当前 80/443 的 WLS 1.x 受管 owner 显式提升为 WLS 2.0 Gateway');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:gateway:promote --package=/absolute/package --confirm [--profile=default]',
            $this->tip(),
            [
                '--package' => __('签名、自包含的 WLS 2.0 宿主包目录'),
                '--profile' => __('default（IPv4+IPv6）或 ipv4-only'),
                '--confirm' => __('确认进入有实测维护窗的公共端口所有权切换'),
                '--json' => __('输出稳定 JSON 文档'),
            ],
            [__('回滚') => __('先在旧入口在线时完成影子预检；交接失败时先停宿主服务，再用冻结快照恢复旧 Nginx。')],
            [],
        );
    }
}
