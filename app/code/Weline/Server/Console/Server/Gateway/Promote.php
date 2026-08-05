<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\Edge\Gateway\GatewayBoundedText;
use Weline\Server\Service\Edge\Gateway\GatewayBoundedTreeWalker;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;
use Weline\Server\Service\Edge\Gateway\SavedInstanceConfigStore;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;
use Weline\Server\Service\Runtime\ProtocolEdgeRuntime;

final class Promote extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        $json = $this->isJson($args);
        $recoverOnly = isset($args['recover']);
        if (!$recoverOnly && !isset($args['confirm'])) {
            return $this->failure(
                __('显式提升必须携带 --confirm，且只能由当前 80/443 的受管 owner 执行。'),
                $json,
                'confirmation_required',
            );
        }
        $package = \trim((string)($args['package'] ?? ''));
        if (!$recoverOnly && $package === '') {
            return $this->failure(
                __('显式提升必须通过 --package 提供签名、自包含的 WLS 2.0 宿主包。'),
                $json,
                'package_required',
            );
        }
        $profile = \strtolower(\trim((string)($args['profile'] ?? 'default')));
        try {
            return GatewayProjectStateFilesystem::withExclusiveLock(
                $this->promotionLockFile(),
                $recoverOnly
                    ? fn (): int => $this->executeRecoveryOnlyLocked($json)
                    : fn (): int => $this->executePromotionLocked(
                        $json,
                        $package,
                        $profile,
                    ),
            );
        } catch (\Throwable $throwable) {
            return $this->failure(
                __('提升事务无法安全启动：%{1}', [$throwable->getMessage()]),
                $json,
                'promotion_transaction_unavailable',
            );
        }
    }

    private function executeRecoveryOnlyLocked(bool $json): int
    {
        $recovered = $this->recoverIncompletePromotion(new GatewayHostManager());
        if (!$json) {
            if ($recovered === []) {
                $this->printer->success(__('没有未完成的 WLS Gateway 提升事务。'));
            } else {
                $this->printer->success(__('WLS Gateway 提升事务恢复完成。'));
            }
        }
        $this->output(['recovery' => $recovered], $json);
        return 0;
    }

    private function executePromotionLocked(
        bool $json,
        string $package,
        string $profile,
    ): int {
        $gateway = new GatewayHostManager();
        $recovered = $this->recoverIncompletePromotion($gateway);
        if ($recovered !== []) {
            if (!$json) {
                $this->printer->warning(__('已先恢复上次未完成的提升事务。'));
            }
        }
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
        $downtimeStarted = 0.0;
        $journal = null;
        try {
            // Package, Broker, locked runtimes, system definition and project
            // desired state are validated while the legacy owner keeps serving.
            $staged = $gateway->stageLegacyPromotion($package, $profile);
            $journal = $this->beginPromotionJournal(
                $staged,
                $owner,
                $projectRoot,
                $upstreamHost,
                $upstreamPort,
                $serverNames,
                $legacyRuntimeRoot,
                $legacyRuntimeOwnership,
                $legacyPublicBaseline,
            );
            $journal = $this->advancePromotionJournal(
                $journal,
                'LEGACY_STOPPING',
                ['legacy_stop_started' => true],
            );
            $stopped = $legacy->stopForInstance($owner);
            if (!($stopped['ok'] ?? false)) {
                throw new \RuntimeException(
                    'Legacy Nginx did not release the public ports: '
                    . (string)($stopped['message'] ?? '')
                );
            }
            $legacyStopped = true;
            $downtimeStarted = \microtime(true);
            $journal = $this->advancePromotionJournal($journal, 'LEGACY_STOPPED');
            $journal = $this->advancePromotionJournal(
                $journal,
                'GATEWAY_ACTIVATING',
                ['gateway_activation_started' => true],
            );
            $gateway->activateLegacyPromotion($staged);
            $activated = true;
            $journal = $this->advancePromotionJournal($journal, 'GATEWAY_ACTIVE');
            $gateway->enrollCurrentProjectForPromotion($builder, $projectRoot);
            $journal = $this->advancePromotionJournal($journal, 'PROJECT_ENROLLED');
            // An administrator promotion may encounter a root-owned runtime
            // directory left by WLS 1.x. Repair it before the project Master
            // must create the authenticated join-backend capability token.
            ProtocolEdgeRuntime::ensureTokenFile($owner);
            $this->persistPromotedInstanceEdgeMode(
                $owner,
                function (array $snapshot) use (&$journal): void {
                    $journal = $this->advancePromotionJournal(
                        $journal,
                        'EDGE_MODE_UPDATING',
                        ['saved_edge_snapshot' => $snapshot],
                    );
                },
            );
            $journal = $this->advancePromotionJournal($journal, 'EDGE_MODE_UPDATED');
            // Mark the attempt before IPC so a lost enable acknowledgement is
            // still compensated by the idempotent Master-side disable action.
            $journal = $this->advancePromotionJournal(
                $journal,
                'AGENT_ATTACHING',
                ['agent_attach_started' => true],
            );
            $agent = $this->setProjectGatewayAgentEnabled($owner, true);
            $transactionId = (string)(
                $agent['runtime_endpoint']['transaction_id'] ?? ''
            );
            $journal = $this->advancePromotionJournal(
                $journal,
                'AGENT_ATTACHED',
                ['agent_transaction_id' => $transactionId],
            );
            $registration = $gateway->awaitPromotionProjectActivation(
                $owner,
                $projectRoot,
            );
            $journal = $this->advancePromotionJournal($journal, 'PROJECT_ACTIVE');
            $commit = $this->commitProjectGatewayAgentPromotion($owner, $transactionId);
            $journal = $this->advancePromotionJournal($journal, 'COMMITTED');
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
                'recovered_previous_promotion' => $recovered,
                'promotion_transaction_id' => (string)$journal['transaction_id'],
            ], $json);
            return 0;
        } catch (\Throwable $throwable) {
            if (\is_array($journal)) {
                try {
                    $rollForward = $this->rollForwardCommittedPromotion($journal);
                    if ($rollForward !== null) {
                        if (!$json) {
                            $this->printer->success(__(
                                'Gateway Agent 已提交；已从丢失的本地日志确认中前滚恢复。'
                            ));
                        }
                        $this->output([
                            'promotion_transaction_id' => (string)$journal['transaction_id'],
                            'recovery' => $rollForward,
                        ], $json);
                        return 0;
                    }
                } catch (\Throwable $reconciliationError) {
                    return $this->failure(
                        __('提升提交状态无法安全对账：%{1}', [
                            $reconciliationError->getMessage(),
                        ]),
                        $json,
                        'promotion_recovery_blocked',
                    );
                }
            }
            $details = [
                'legacy_stopped' => $legacyStopped,
                'gateway_activated' => $activated,
                'legacy_rollback' => 'not_required',
            ];
            if (\is_array($journal)) {
                try {
                    $journal = $this->advancePromotionJournal(
                        $journal,
                        'ROLLING_BACK',
                        [
                            'rollback_started' => true,
                            'failure_reason' => GatewayBoundedText::singleLine(
                                $throwable->getMessage(),
                                1024,
                                'Promotion failed.',
                            ),
                        ],
                    );
                    $rollbackDetails = $this->rollbackPromotionFromJournal(
                        $gateway,
                        $journal,
                    );
                    $details = \array_replace($details, $rollbackDetails);
                } catch (\Throwable $rollbackError) {
                    $details['legacy_rollback'] = 'failed';
                    $details['legacy_rollback_error'] = $rollbackError->getMessage();
                    try {
                        $journal = $this->advancePromotionJournal(
                            $journal,
                            'ROLLBACK_BLOCKED',
                            ['rollback_error' => GatewayBoundedText::singleLine(
                                $rollbackError->getMessage(),
                                1024,
                                'Promotion rollback failed.',
                            )],
                        );
                    } catch (\Throwable) {
                    }
                    if (!$json) {
                        $this->printer->error(__('提升事务自动恢复失败：%{1}', [
                            $rollbackError->getMessage(),
                        ]));
                    }
                }
            } elseif (\is_array($staged)) {
                // Staging completed but the first durable journal publication
                // failed. Public ownership has not moved yet, so only discard
                // the disabled staged service/package.
                try {
                    $gateway->abortLegacyPromotion($staged, false);
                } catch (\Throwable $abort) {
                    $details['gateway_cleanup_error'] = $abort->getMessage();
                }
            }
            if (($details['legacy_rollback'] ?? 'not_required') === 'not_required') {
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
            if (($details['legacy_rollback'] ?? '') === 'restored') {
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
            } elseif (!$json) {
                $message = (string)($details['legacy_rollback_error'] ?? 'unknown rollback failure');
                $this->printer->error(__('原项目 Nginx 回滚也失败：%{1}', [$message]));
            }
            if (($details['legacy_rollback'] ?? '') !== 'restored') {
                if (!$json) {
                    $this->printer->warning(
                        __('已保留可恢复的提升事务日志；下次 promote 会先继续恢复。')
                    );
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

    private function promotionStateDirectory(): string
    {
        $paths = new GatewayPaths();
        $paths->ensureDirectories();
        $directory = $paths->trustDir()
            . DIRECTORY_SEPARATOR . 'promotion-transaction';
        if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
            throw new \RuntimeException('Unable to create the promotion transaction directory.');
        }
        $canonical = \realpath($directory);
        $status = @\lstat($directory);
        if (!\is_string($canonical)
            || !\hash_equals(\rtrim($canonical, '/\\'), \rtrim($directory, '/\\'))
            || \is_link($directory)
            || !\is_array($status)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
            || (\PHP_OS_FAMILY !== 'Windows'
                && (((((int)($status['mode'] ?? 0)) & 0077) !== 0)
                    || (\function_exists('posix_geteuid')
                        && (int)($status['uid'] ?? -1) !== (int)\posix_geteuid())))
        ) {
            throw new \RuntimeException('Promotion transaction directory is unsafe.');
        }
        return $canonical;
    }

    private function promotionLockFile(): string
    {
        return $this->promotionStateDirectory() . DIRECTORY_SEPARATOR . 'transaction.lock';
    }

    private function promotionJournalFile(): string
    {
        return $this->promotionStateDirectory() . DIRECTORY_SEPARATOR . 'journal.json';
    }

    /**
     * @param array<string,mixed> $staged
     * @param list<string> $serverNames
     * @param array{uid:int,gid:int}|null $runtimeOwnership
     * @param array<string,mixed> $baseline
     * @return array<string,mixed>
     */
    private function beginPromotionJournal(
        array $staged,
        string $owner,
        string $projectRoot,
        string $upstreamHost,
        int $upstreamPort,
        array $serverNames,
        string $runtimeRoot,
        ?array $runtimeOwnership,
        array $baseline,
    ): array {
        $service = \is_array($staged['service'] ?? null) ? $staged['service'] : [];
        $kind = \trim((string)($service['kind'] ?? ''));
        $slot = \strtoupper(\trim((string)($staged['slot'] ?? '')));
        $previousSlot = \strtoupper(\trim((string)($staged['previous_active_slot'] ?? '')));
        $profile = \strtolower(\trim((string)($staged['profile'] ?? '')));
        $runtimeGeneration = \strtolower(\trim((string)(
            $staged['runtime_generation'] ?? ''
        )));
        $normalizedNames = [];
        foreach ($serverNames as $serverName) {
            $serverName = \strtolower(\rtrim(\trim((string)$serverName), '.'));
            if ($serverName === '' || \strlen($serverName) > 253) {
                continue;
            }
            $normalizedNames[$serverName] = true;
            if (\count($normalizedNames) > 256) {
                throw new \RuntimeException('Promotion server-name set exceeds its fixed bound.');
            }
        }
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $owner) !== 1
            || !\in_array($slot, ['A', 'B'], true)
            || ($previousSlot !== '' && !\in_array($previousSlot, ['A', 'B'], true))
            || !\in_array($profile, ['default', 'ipv4-only'], true)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $runtimeGeneration) !== 1
            || $kind === ''
            || \strlen($kind) > 191
            || $projectRoot === ''
            || \strlen($projectRoot) > 4096
            || $runtimeRoot === ''
            || \strlen($runtimeRoot) > 4096
            || $upstreamHost === ''
            || \strlen($upstreamHost) > 253
            || $upstreamPort < 1
            || $upstreamPort > 65535
            || $normalizedNames === []
        ) {
            throw new \RuntimeException('Promotion transaction facts are invalid.');
        }
        $journal = [
            'schema_version' => 1,
            'transaction_id' => \bin2hex(\random_bytes(16)),
            'phase' => 'PREPARED',
            'sequence' => 1,
            'owner_instance' => $owner,
            'project_root' => $projectRoot,
            'staged' => [
                'slot' => $slot,
                'previous_active_slot' => $previousSlot,
                'profile' => $profile,
                'runtime_generation' => $runtimeGeneration,
                'service' => ['kind' => $kind],
            ],
            'legacy' => [
                'upstream_host' => $upstreamHost,
                'upstream_port' => $upstreamPort,
                'server_names' => \array_keys($normalizedNames),
                'runtime_root' => $runtimeRoot,
                'runtime_ownership' => $runtimeOwnership,
                'public_baseline' => $baseline,
            ],
            'legacy_stop_started' => false,
            'gateway_activation_started' => false,
            'agent_attach_started' => false,
            'rollback_started' => false,
            'saved_edge_snapshot' => null,
            'created_at' => \gmdate(DATE_ATOM),
        ];
        return $this->writePromotionJournal($journal);
    }

    /**
     * @param array<string,mixed> $journal
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    private function advancePromotionJournal(
        array $journal,
        string $phase,
        array $changes = [],
    ): array {
        $allowedPhases = [
            'PREPARED',
            'LEGACY_STOPPING',
            'LEGACY_STOPPED',
            'GATEWAY_ACTIVATING',
            'GATEWAY_ACTIVE',
            'PROJECT_ENROLLED',
            'EDGE_MODE_UPDATING',
            'EDGE_MODE_UPDATED',
            'AGENT_ATTACHING',
            'AGENT_ATTACHED',
            'PROJECT_ACTIVE',
            'COMMITTED',
            'ROLLING_BACK',
            'GATEWAY_QUIESCED',
            'LEGACY_RESTORED',
            'GATEWAY_DISMANTLING',
            'ROLLED_BACK',
            'ROLLBACK_BLOCKED',
        ];
        if (!\in_array($phase, $allowedPhases, true)) {
            throw new \RuntimeException('Promotion journal phase is invalid.');
        }
        unset($journal['digest'], $journal['updated_at']);
        foreach ($changes as $key => $value) {
            if (!\is_string($key)
                || \preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $key) !== 1
            ) {
                throw new \RuntimeException('Promotion journal update field is invalid.');
            }
            $journal[$key] = $value;
        }
        $journal['phase'] = $phase;
        $journal['sequence'] = (int)($journal['sequence'] ?? 0) + 1;
        return $this->writePromotionJournal($journal);
    }

    /** @param array<string,mixed> $journal @return array<string,mixed> */
    private function writePromotionJournal(array $journal): array
    {
        unset($journal['digest']);
        $journal['updated_at'] = \gmdate(DATE_ATOM);
        $journal['digest'] = \hash(
            'sha256',
            \Weline\Server\Service\Edge\Gateway\GatewayClient::canonicalJson($journal),
        );
        $encoded = \json_encode(
            $journal,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR,
        );
        if (\strlen($encoded) > 8 * 1024 * 1024) {
            throw new \RuntimeException('Promotion journal exceeds its fixed size limit.');
        }
        GatewayProjectStateFilesystem::atomicWrite(
            $this->promotionJournalFile(),
            $encoded . "\n",
            0600,
        );
        return $journal;
    }

    /** @return array<string,mixed>|null */
    private function readPromotionJournal(): ?array
    {
        $contents = GatewayProjectStateFilesystem::readOptional(
            $this->promotionJournalFile(),
            8 * 1024 * 1024,
            'Promotion transaction journal',
        );
        if ($contents === null) {
            return null;
        }
        $journal = \json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        if (!\is_array($journal)) {
            throw new \RuntimeException('Promotion transaction journal is invalid.');
        }
        $digest = \strtolower(\trim((string)($journal['digest'] ?? '')));
        $signed = $journal;
        unset($signed['digest']);
        $expected = \hash(
            'sha256',
            \Weline\Server\Service\Edge\Gateway\GatewayClient::canonicalJson($signed),
        );
        if (($journal['schema_version'] ?? null) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)($journal['transaction_id'] ?? '')) !== 1
            || !\is_int($journal['sequence'] ?? null)
            || (int)$journal['sequence'] < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || !\hash_equals($expected, $digest)
        ) {
            throw new \RuntimeException('Promotion transaction journal failed integrity checks.');
        }
        return $journal;
    }

    /** @return array<string,mixed> */
    private function recoverIncompletePromotion(GatewayHostManager $gateway): array
    {
        $journal = $this->readPromotionJournal();
        if ($journal === null
            || \in_array((string)($journal['phase'] ?? ''), ['COMMITTED', 'ROLLED_BACK'], true)
        ) {
            return [];
        }
        $phase = (string)($journal['phase'] ?? 'UNKNOWN');
        try {
            $rollbackInProgress = ($journal['rollback_started'] ?? false) === true
                || \in_array($phase, [
                    'ROLLING_BACK',
                    'GATEWAY_QUIESCED',
                    'LEGACY_RESTORED',
                    'GATEWAY_DISMANTLING',
                ], true);
            if (!$rollbackInProgress
                && ($journal['agent_attach_started'] ?? false) === true
            ) {
                $transactionId = \strtolower(\trim((string)(
                    $journal['agent_transaction_id'] ?? ''
                )));
                if (\preg_match('/\A[a-f0-9]{32}\z/D', $transactionId) !== 1) {
                    // The Master owns transaction allocation. Replaying enable
                    // after a lost acknowledgement returns its existing
                    // ATTACHING transaction instead of allocating a second one.
                    $agent = $this->setProjectGatewayAgentEnabled(
                        (string)($journal['owner_instance'] ?? ''),
                        true,
                    );
                    $transactionId = \strtolower(\trim((string)(
                        $agent['runtime_endpoint']['transaction_id'] ?? ''
                    )));
                    if (\preg_match('/\A[a-f0-9]{32}\z/D', $transactionId) !== 1) {
                        throw new \RuntimeException(
                            'Promotion ATTACHING transaction acknowledgement could not be recovered.'
                        );
                    }
                    $journal = $this->advancePromotionJournal(
                        $journal,
                        'AGENT_ATTACHED',
                        [
                            'agent_transaction_id' => $transactionId,
                            'agent_acknowledgement_recovered' => true,
                        ],
                    );
                }
                $rollForward = $this->rollForwardCommittedPromotion($journal);
                if ($rollForward !== null) {
                    return [
                        'transaction_id' => (string)$journal['transaction_id'],
                        'recovered_from_phase' => $phase,
                    ] + $rollForward;
                }
                // rollForwardCommittedPromotion returns null only for the
                // matching ATTACHING transaction. That is the sole state in
                // which compensating rollback is authorized.
            }
            $journal = $this->advancePromotionJournal(
                $journal,
                'ROLLING_BACK',
                [
                    'rollback_started' => true,
                    'recovered_from_phase' => GatewayBoundedText::singleLine(
                        $phase,
                        64,
                        'UNKNOWN',
                    ),
                ],
            );
            $details = $this->rollbackPromotionFromJournal($gateway, $journal);
            return [
                'transaction_id' => (string)$journal['transaction_id'],
                'recovered_from_phase' => $phase,
            ] + $details;
        } catch (\Throwable $throwable) {
            try {
                $this->advancePromotionJournal(
                    $journal,
                    'ROLLBACK_BLOCKED',
                    ['rollback_error' => GatewayBoundedText::singleLine(
                        $throwable->getMessage(),
                        1024,
                        'Promotion recovery failed.',
                    )],
                );
            } catch (\Throwable) {
            }
            throw new \RuntimeException(
                'Incomplete promotion recovery is blocked: ' . $throwable->getMessage(),
                0,
                $throwable,
            );
        }
    }

    /**
     * @param array<string,mixed> $journal
     * @return array<string,mixed>|null null means the matching transaction is still ATTACHING
     */
    private function rollForwardCommittedPromotion(array $journal): ?array
    {
        $owner = (string)($journal['owner_instance'] ?? '');
        $transactionId = \strtolower(\trim((string)(
            $journal['agent_transaction_id'] ?? ''
        )));
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $owner) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $transactionId) !== 1
        ) {
            return null;
        }
        $status = $this->queryProjectGatewayAgentPromotionStatus(
            $owner,
            $transactionId,
        );
        $state = (string)($status['state'] ?? '');
        $matches = ($status['matches'] ?? false) === true
            && \hash_equals(
                $transactionId,
                \strtolower(\trim((string)($status['transaction_id'] ?? ''))),
            );
        if ($state === 'COMMITTED'
            && $matches
            && (string)($status['edge_adapter'] ?? '') === 'wls'
            && (string)($status['requested_mode'] ?? '') === 'auto'
        ) {
            $this->advancePromotionJournal(
                $journal,
                'COMMITTED',
                [
                    'commit_acknowledgement_recovered' => true,
                    'agent_transaction_id' => $transactionId,
                ],
            );
            return [
                'recovery_action' => 'roll_forward',
                'agent_transaction_id' => $transactionId,
                'master_state' => 'COMMITTED',
            ];
        }
        if ($state === 'ATTACHING' && $matches) {
            return null;
        }
        throw new \RuntimeException(
            'Promotion endpoint transaction does not match the recovery journal; '
                . 'automatic rollback is fenced.'
        );
    }

    /** @return array<string,mixed> */
    private function queryProjectGatewayAgentPromotionStatus(
        string $instanceName,
        string $transactionId,
    ): array {
        $result = (new IpcControlGateway())->command(
            $instanceName,
            ControlMessage::ACTION_GATEWAY_AGENT_STATUS,
            '',
            ['promotion_transaction_id' => $transactionId],
            10.0,
        );
        if (!($result['success'] ?? false)) {
            throw new \RuntimeException(
                'Gateway Agent promotion status query failed: '
                    . (string)($result['message'] ?? 'Master rejected the status command.')
            );
        }
        $status = \is_array($result['data'] ?? null) ? $result['data'] : [];
        if ($status === []
            || !\is_bool($status['matches'] ?? null)
            || !\is_string($status['state'] ?? null)
        ) {
            throw new \RuntimeException('Gateway Agent promotion status receipt is invalid.');
        }
        return $status;
    }

    /**
     * @param array<string,mixed> $journal
     * @return array<string,mixed>
     */
    private function rollbackPromotionFromJournal(
        GatewayHostManager $gateway,
        array $journal,
    ): array {
        $owner = (string)($journal['owner_instance'] ?? '');
        $staged = \is_array($journal['staged'] ?? null) ? $journal['staged'] : [];
        $legacy = \is_array($journal['legacy'] ?? null) ? $journal['legacy'] : [];
        $serverNames = \is_array($legacy['server_names'] ?? null)
            ? \array_values(\array_map('strval', $legacy['server_names']))
            : [];
        $runtimeOwnership = \is_array($legacy['runtime_ownership'] ?? null)
            ? $legacy['runtime_ownership']
            : null;
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $owner) !== 1
            || $staged === []
            || $serverNames === []
        ) {
            throw new \RuntimeException('Promotion rollback journal facts are incomplete.');
        }
        $details = ['legacy_rollback' => 'not_required'];
        if (($journal['agent_attach_started'] ?? false) === true) {
            try {
                $this->setProjectGatewayAgentEnabled($owner, false);
                $details['gateway_agent_cleanup'] = 'detached';
            } catch (\Throwable $throwable) {
                // Continue restoring public service; the removed gateway and
                // restored saved edge mode fence a stale Agent even if its
                // idempotent disable acknowledgement is lost.
                $details['gateway_agent_cleanup'] = 'acknowledgement_lost';
                $details['gateway_agent_cleanup_error'] = GatewayBoundedText::singleLine(
                    $throwable->getMessage(),
                    1024,
                    'Gateway Agent cleanup acknowledgement was lost.',
                );
            }
        }
        $saved = \is_array($journal['saved_edge_snapshot'] ?? null)
            ? $journal['saved_edge_snapshot']
            : null;
        if ($saved !== null) {
            $configRollback = $this->restoreSavedInstanceEdgeMode($owner, $saved);
            $details['saved_edge_mode_rollback'] = $configRollback['conflicts'] === []
                ? 'restored'
                : 'concurrent_changes_preserved';
            $details['saved_edge_mode_fields'] = $configRollback;
        }
        if (($journal['gateway_activation_started'] ?? false) === true
            && ($journal['gateway_quiesced'] ?? false) !== true
            && ($journal['legacy_restored'] ?? false) !== true
        ) {
            $gateway->quiesceLegacyPromotion($staged);
            $journal = $this->advancePromotionJournal(
                $journal,
                'GATEWAY_QUIESCED',
                ['gateway_quiesced' => true],
            );
            $details['gateway_quiesced'] = true;
        }
        if (($journal['legacy_stop_started'] ?? false) === true
            && ($journal['legacy_restored'] ?? false) !== true
        ) {
            if ($runtimeOwnership !== null) {
                $this->restoreProjectRuntimeOwnership(
                    (string)($legacy['runtime_root'] ?? ''),
                    (int)($runtimeOwnership['uid'] ?? -1),
                    (int)($runtimeOwnership['gid'] ?? -1),
                );
            }
            $rollback = $this->restoreLegacyNginxThroughProjectMaster(
                $owner,
                (int)($legacy['upstream_port'] ?? 0),
                (string)($legacy['upstream_host'] ?? ''),
                $serverNames,
            );
            if (!($rollback['ok'] ?? false)) {
                throw new \RuntimeException(
                    (string)($rollback['message'] ?? 'Legacy Nginx restore failed.')
                );
            }
            $probe = $this->probeLegacyPublicResponses(
                (new GatewayPaths())->publicHttpsPort(),
                $serverNames,
                \is_array($legacy['public_baseline'] ?? null)
                    ? $legacy['public_baseline']
                    : null,
            );
            if (!($probe['ok'] ?? false)) {
                throw new \RuntimeException(
                    'Legacy Nginx restore probe failed: '
                        . (string)($probe['reason'] ?? 'unknown response failure')
                );
            }
            $details['legacy_rollback'] = 'restored';
            $details['legacy_rollback_probe'] = $probe;
            $journal = $this->advancePromotionJournal(
                $journal,
                'LEGACY_RESTORED',
                ['legacy_restored' => true],
            );
        } elseif (($journal['legacy_restored'] ?? false) === true) {
            $details['legacy_rollback'] = 'restored';
        }

        // Only now dismantle the reversible gateway staging/activation. Until
        // this point its immutable slot and service definition remain
        // available for operator recovery if the legacy owner cannot return.
        $journal = $this->advancePromotionJournal($journal, 'GATEWAY_DISMANTLING');
        $gateway->abortLegacyPromotion(
            $staged,
            ($journal['gateway_activation_started'] ?? false) === true,
        );
        $this->advancePromotionJournal($journal, 'ROLLED_BACK');
        $details['gateway_cleanup'] = 'removed_after_legacy_probe';
        return $details;
    }

    /**
     * The snapshot is journaled before the first configuration mutation, so a
     * process crash at either side of the atomic write remains recoverable.
     *
     * Only the fields owned by promotion are journaled. Persisting the whole
     * instance configuration here would copy unrelated project secrets into a
     * host-level recovery journal and a later rollback could erase concurrent
     * changes to unrelated settings.
     *
     * @param \Closure(array<string,mixed>):void $beforeWrite
     * @return array<string,mixed>
     */
    private function persistPromotedInstanceEdgeMode(
        string $instanceName,
        \Closure $beforeWrite,
    ): array
    {
        $savedAt = \date('Y-m-d H:i:s');
        $afterValues = [
            'edge_mode' => 'auto',
            'edge_adapter' => 'nginx',
            'ssl_enabled' => false,
            'saved_at' => $savedAt,
        ];
        return (new SavedInstanceConfigStore())->update(
            $instanceName,
            static function (array $config) use ($beforeWrite, $afterValues): array {
                $fields = [];
                $afterFields = [];
                foreach ($afterValues as $field => $value) {
                    $fields[$field] = [
                        'exists' => \array_key_exists($field, $config),
                        'value' => $config[$field] ?? null,
                    ];
                    $afterFields[$field] = ['exists' => true, 'value' => $value];
                }
                $snapshot = [
                    'fields' => $fields,
                    'after_fields' => $afterFields,
                    'before_digest' => \hash(
                        'sha256',
                        \Weline\Server\Service\Edge\Gateway\GatewayClient::canonicalJson($config),
                    ),
                ];
                $next = $config;
                foreach ($afterValues as $field => $value) {
                    $next[$field] = $value;
                }
                $snapshot['after_digest'] = \hash(
                    'sha256',
                    \Weline\Server\Service\Edge\Gateway\GatewayClient::canonicalJson($next),
                );
                $beforeWrite($snapshot);
                return [$next, $snapshot];
            },
            true,
        );
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array{restored:list<string>,conflicts:list<string>}
     */
    private function restoreSavedInstanceEdgeMode(
        string $instanceName,
        array $snapshot,
    ): array {
        $fields = $snapshot['fields'] ?? null;
        $afterFields = $snapshot['after_fields'] ?? null;
        if (!\is_array($fields)
            || !\is_array($afterFields)
            || \array_keys($fields) !== ['edge_mode', 'edge_adapter', 'ssl_enabled', 'saved_at']
            || \array_keys($afterFields) !== \array_keys($fields)
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $snapshot['before_digest'] ?? ''
            )) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $snapshot['after_digest'] ?? ''
            )) !== 1
        ) {
            throw new \RuntimeException('Saved promotion edge-field snapshot is invalid.');
        }
        return (new SavedInstanceConfigStore())->restoreOwnedFields(
            $instanceName,
            $fields,
            $afterFields,
        );
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
        // A failed command acknowledgement is ambiguous. Read the exact
        // transaction through the authenticated Master control channel and
        // under the endpoint lock instead of trusting an unlocked file image.
        $status = $this->queryProjectGatewayAgentPromotionStatus(
            $instanceName,
            $transactionId,
        );
        if ((string)($status['state'] ?? '') === 'COMMITTED'
            && ($status['matches'] ?? false) === true
            && (string)($status['edge_adapter'] ?? '') === 'wls'
            && (string)($status['requested_mode'] ?? '') === 'auto'
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
        $entries = GatewayBoundedTreeWalker::collect($canonical, true, true);
        foreach ($entries as $entry) {
            GatewayBoundedTreeWalker::revalidate($entry);
        }
        foreach ($entries as $entry) {
            $path = $entry['path'];
            GatewayBoundedTreeWalker::revalidate($entry);
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
            'server:gateway:promote (--package=/absolute/package --confirm [--profile=default] | --recover)',
            $this->tip(),
            [
                '--package' => __('签名、自包含的 WLS 2.0 宿主包目录'),
                '--profile' => __('default（IPv4+IPv6）或 ipv4-only'),
                '--confirm' => __('确认进入有实测维护窗的公共端口所有权切换'),
                '--recover' => __('仅恢复/修复未完成事务；无需宿主包或再次确认'),
                '--json' => __('输出稳定 JSON 文档'),
            ],
            [__('回滚') => __('先在旧入口在线时完成影子预检；交接失败时先停宿主服务，再用冻结快照恢复旧 Nginx。')],
            [],
        );
    }
}
