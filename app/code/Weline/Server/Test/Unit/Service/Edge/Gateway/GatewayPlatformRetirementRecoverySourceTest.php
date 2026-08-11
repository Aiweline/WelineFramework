<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class GatewayPlatformRetirementRecoverySourceTest extends TestCase
{
    private string $moduleRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moduleRoot = \dirname(__DIR__, 5);
    }

    public function testControllerKeepsIrreversiblePublicationPendingUntilPlatformProof(): void
    {
        $source = $this->read('bin/wls_gateway_controller.php');

        self::assertStringContainsString(
            "'SERVICE_TREE_RETIREMENT_PENDING'",
            $source,
        );
        self::assertStringContainsString(
            '$this->processPendingServiceTreeRetirementPublication();',
            $source,
        );
        self::assertStringContainsString(
            '$this->publication[\'service_tree_retirement\'] = $pendingIntent;',
            $source,
        );
        self::assertStringContainsString(
            "'proof_type' => 'platform_service_tree_receipt'",
            $source,
        );

        $failure = $this->between(
            $source,
            "Irrevocable certificate publication did not retire the old ",
            '$this->publication[\'security_retirement\'] = $retirement[\'receipt\'];',
        );
        self::assertStringNotContainsString('rollbackRoutingMutation(', $failure);
        self::assertStringNotContainsString('completePublication(', $failure);
        self::assertStringContainsString('persistPublication()', $failure);
    }

    public function testPosixLauncherRequiresARealPlatformGenerationForPromotion(): void
    {
        $source = $this->read(
            'Service/Edge/Gateway/Native/posix/wls_gateway_launcher.c',
        );

        self::assertStringContainsString('getenv("INVOCATION_ID")', $source);
        self::assertStringContainsString('service_mode && getppid() == 1', $source);
        self::assertStringContainsString(
            'wls_seal_platform_retirement_pending(',
            $source,
        );
        self::assertStringContainsString('wls_promote_platform_retirement(', $source);
        self::assertMatchesRegularExpression(
            '/strcmp\(\s*receipt\.requested_launcher_generation,\s*'
                . 'launcher_generation\s*\) == 0/s',
            $source,
        );
        self::assertStringContainsString(
            'WLS-PROCESS-TREE-RETIRE/2',
            $source,
        );
    }

    public function testWindowsJobIsClosedOnlyAfterPendingReceiptIsSealed(): void
    {
        $source = $this->read(
            'Service/Edge/Gateway/Native/windows/wls_gateway_launcher.c',
        );
        $classification = \strpos(
            $source,
            'if (broker_exit == WLS_SERVICE_TREE_RESTART',
        );
        $jobClose = \strpos($source, 'CloseHandle(job);', $classification ?: 0);

        self::assertNotFalse($classification);
        self::assertNotFalse($jobClose);
        self::assertLessThan($jobClose, $classification);
        self::assertStringContainsString(
            'JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE',
            $source,
        );
        self::assertStringContainsString(
            'wls_promote_platform_retirement(home, runtime_generation)',
            $source,
        );
    }

    public function testBrokersAcceptPlatformReceiptWithoutReplacingNativeWriter(): void
    {
        foreach (['posix', 'windows'] as $platform) {
            $source = $this->read(
                'Service/Edge/Gateway/Native/' . $platform
                    . '/wls_gateway_broker.c',
            );
            self::assertStringContainsString(
                'WLS-PROCESS-TREE-RETIRE/2',
                $source,
            );
            self::assertStringContainsString(
                'WLS-PROCESS-TREE-RETIRE/1\\nstatus=%s',
                $source,
            );
            self::assertStringContainsString(
                'platform_service_tree_receipt',
                $source,
            );
            self::assertStringContainsString(
                'native_process_tree_receipt',
                $source,
            );
            self::assertStringContainsString(
                'wls-platform-service/1\\0',
                $source,
            );
        }
    }

    public function testControllerObtainsPrivateRetirementProofThroughBroker(): void
    {
        $source = $this->read('bin/wls_gateway_controller.php');
        $retirement = $this->between(
            $source,
            'private function retireAttestedNginxProcessTree(',
            'private function retireSecurityDataPlaneGeneration(',
        );

        self::assertStringContainsString(
            '$this->requestBrokerProcessTreeRetirement(',
            $retirement,
        );
        self::assertStringNotContainsString(
            'readProcessTreeRetirementReceipt(',
            $retirement,
        );
        self::assertStringContainsString(
            "'platform_service_tree_receipt'",
            $retirement,
        );
        self::assertStringContainsString(
            "'native_process_tree_receipt'",
            $retirement,
        );
    }

    public function testActiveControllerPathsNeverReadRootPrivateNativeReceipts(): void
    {
        $source = $this->read('bin/wls_gateway_controller.php');
        $attestation = $this->between(
            $source,
            'private function attestNginxProcess(',
            'private function processAttestationReceiptContents(',
        );
        self::assertStringNotContainsString(
            '$this->processAttestationReceiptFile()',
            $attestation,
        );
        self::assertStringContainsString(
            'A cached controller record is not a privileged Native proof.',
            $attestation,
        );
    }

    public function testDurableRetirementProofSurvivesALaterHostBoot(): void
    {
        foreach (['posix', 'windows'] as $platform) {
            $source = $this->read(
                'Service/Edge/Gateway/Native/' . $platform
                    . '/wls_gateway_broker.c',
            );
            $prefix = $platform === 'windows' ? 'wls_win_' : 'wls_';
            $match = $this->between(
                $source,
                'static int ' . $prefix . 'process_tree_retirement_matches(',
                'static int ' . $prefix . ($platform === 'windows'
                    ? 'collect_process_snapshot('
                    : 'stop_attested_nginx('),
            );

            self::assertStringNotContainsString('upgrade_boot_id', $match);
            self::assertStringContainsString('receipt->retirement_id', $match);
            self::assertStringContainsString('receipt->attestation_digest', $match);
        }
    }

    public function testServiceDefinitionsDeclareWholeTreeCleanupSemantics(): void
    {
        $systemd = $this->read('env/gateway/systemd.service.template');
        $launchd = $this->read('env/gateway/launchd.plist.template');

        self::assertStringContainsString('KillMode=mixed', $systemd);
        self::assertStringContainsString('TimeoutStopSec=330', $systemd);
        self::assertStringContainsString('SendSIGKILL=yes', $systemd);
        self::assertStringContainsString('Restart=on-failure', $systemd);
        self::assertStringContainsString('<key>AbandonProcessGroup</key>', $launchd);
        self::assertStringContainsString(
            "<key>AbandonProcessGroup</key>\n  <false/>",
            $launchd,
        );
        self::assertStringContainsString(
            "<key>ExitTimeOut</key>\n  <integer>330</integer>",
            $launchd,
        );
    }

    public function testPosixTerminalPlatformShutdownDrainsAttestedNginxOnly(): void
    {
        $launcher = $this->read(
            'Service/Edge/Gateway/Native/posix/wls_gateway_launcher.c',
        );
        $broker = $this->read(
            'Service/Edge/Gateway/Native/posix/wls_gateway_broker.c',
        );

        self::assertStringContainsString(
            '#define WLS_BROKER_TERM_GRACE_MILLISECONDS 5000LL',
            $launcher,
        );
        self::assertStringContainsString(
            '#define WLS_PLATFORM_SHUTDOWN_GRACE_MILLISECONDS 300000LL',
            $launcher,
        );
        self::assertStringContainsString(
            'wls_gracefully_terminate_broker(broker_pid)',
            $launcher,
        );
        self::assertStringContainsString(
            '#define WLS_PLATFORM_SHUTDOWN_GRACE_MILLISECONDS 300000ULL',
            $broker,
        );
        self::assertStringContainsString(
            'wls_platform_shutdown_attested_nginx(',
            $broker,
        );
        self::assertStringContainsString(
            'wls_process_attestation_authority_current(',
            $broker,
        );
        self::assertMatchesRegularExpression(
            '/"QUIT"\s*,\s*NULL\s*,\s*0U/s',
            $broker,
        );
        self::assertMatchesRegularExpression(
            '/"-s",\s*"quit"/s',
            $broker,
        );
    }

    public function testPosixCapacityAllocationDirectoryIsDurableBeforeCredits(): void
    {
        $source = $this->read(
            'Service/Edge/Gateway/Native/posix/wls_gateway_launcher.c',
        );
        $allocation = $this->between(
            $source,
            'mkdirat(capacity_fd, allocating, 0700)',
            'if (wls_capacity_platform_reserve_create(',
        );

        self::assertStringContainsString('fsync(capacity_fd)', $allocation);
    }

    public function testLinuxRemovalFencePublishesEachDestructivePhaseBeforeContinuing(): void
    {
        $installer = $this->read(
            'Service/Edge/Gateway/GatewayPlatformServiceInstaller.php',
        );
        $source = $this->between(
            $installer,
            'if (\is_array($linuxSystemdRemoval)) {',
            'public function renderDefinition(',
        );
        $linkRemoval = \strpos(
            $source,
            '->removeCurrentCanonicalFixedLink(',
        );
        $linkPhase = \strpos(
            $source,
            '$removalFence[\'phase\'] = \'canonical-removed\';',
        );
        $linkPhasePublish = \strpos(
            $source,
            '$this->atomicWrite(',
            ($linkPhase ?: 0),
        );
        $targetRemoval = \strpos(
            $source,
            '->removeCurrentTargetAfterFixedLink(',
        );
        $targetPhase = \strpos(
            $source,
            '$removalFence[\'phase\'] = \'definition-removed\';',
            ($targetRemoval ?: 0),
        );
        $targetPhasePublish = \strpos(
            $source,
            '$this->atomicWrite(',
            ($targetPhase ?: 0),
        );
        $metadataRemoval = \strpos(
            $source,
            '$this->removeVerifiedRegularFile($metadata)',
        );
        $fenceRemoval = \strpos(
            $source,
            "'completed gateway platform removal fence'",
        );

        foreach ([
            $linkRemoval,
            $linkPhase,
            $linkPhasePublish,
            $targetRemoval,
            $targetPhase,
            $targetPhasePublish,
            $metadataRemoval,
            $fenceRemoval,
        ] as $position) {
            self::assertNotFalse($position);
        }
        self::assertLessThan($linkPhase, $linkRemoval);
        self::assertLessThan($linkPhasePublish, $linkPhase);
        self::assertLessThan($targetRemoval, $linkPhasePublish);
        self::assertLessThan($targetPhase, $targetRemoval);
        self::assertLessThan($targetPhasePublish, $targetPhase);
        self::assertLessThan($metadataRemoval, $targetPhasePublish);
        self::assertLessThan($fenceRemoval, $metadataRemoval);
        self::assertStringContainsString(
            'elseif ((int)$removalFence[\'schema\'] === 1)',
            $installer,
        );
        self::assertStringContainsString(
            '->removeExactLegacyDefinition(',
            $source,
        );
        self::assertStringContainsString(
            '->assertLegacyDefinitionRemoved()',
            $source,
        );
    }

    public function testCandidateFenceIsArmedBeforeEitherConfigSwitch(): void
    {
        $source = $this->between(
            $this->read('bin/wls_gateway_controller.php'),
            'private function publishIfDirty(): bool',
            'private function restorePublicationDataPlane(',
        );
        $initialFence = \strpos(
            $source,
            '$this->prepareCandidateAttestationFence(',
        );
        $initialFenceDeadline = \strpos(
            $source,
            '$publicationDeadline,',
            $initialFence ?: 0,
        );
        $initialSwitch = \strpos(
            $source,
            '$this->atomicWrite($current, $candidateConfig, 0600);',
        );
        $fallbackDigest = \strpos(
            $source,
            '$this->publication[\'candidate_digest\'] = \\hash(',
        );
        $fallbackFence = \strpos(
            $source,
            '$this->prepareCandidateAttestationFence(',
            ($initialFence ?: 0) + 1,
        );
        $fallbackFenceDeadline = \strpos(
            $source,
            '$publicationDeadline,',
            $fallbackFence ?: 0,
        );
        $fallbackSwitch = \strpos(
            $source,
            '$this->atomicWrite($current, $fallbackConfig, 0600);',
        );

        self::assertNotFalse($initialFence);
        self::assertNotFalse($initialFenceDeadline);
        self::assertNotFalse($initialSwitch);
        self::assertLessThan($initialSwitch, $initialFence);
        self::assertLessThan($initialSwitch, $initialFenceDeadline);
        self::assertNotFalse($fallbackDigest);
        self::assertNotFalse($fallbackFence);
        self::assertNotFalse($fallbackFenceDeadline);
        self::assertNotFalse($fallbackSwitch);
        self::assertLessThan($fallbackFence, $fallbackDigest);
        self::assertLessThan($fallbackSwitch, $fallbackFence);
        self::assertLessThan($fallbackSwitch, $fallbackFenceDeadline);
    }

    public function testCandidateFenceBindsControllerTransactionRuntimeAndPhase(): void
    {
        $controller = $this->read('bin/wls_gateway_controller.php');
        self::assertStringContainsString(
            'WLS-CONTROLLER-CANDIDATE-FENCE-BINDING/1',
            $controller,
        );
        self::assertStringContainsString(
            "'candidate_attestation_binding_digest' => ''",
            $controller,
        );
        self::assertStringContainsString(
            '$bindingDigest,',
            $controller,
        );
        foreach (['posix', 'windows'] as $platform) {
            $source = $this->read(
                'Service/Edge/Gateway/Native/' . $platform
                    . '/wls_gateway_broker.c',
            );
            self::assertStringContainsString(
                'WLS-PROCESS-ATTEST-CANDIDATE/1',
                $source,
            );
            self::assertStringContainsString(
                'controller_fencing_digest=%s',
                $source,
            );
            self::assertStringContainsString('transaction_id=%s', $source);
            self::assertStringContainsString('candidate_generation=', $source);
            self::assertStringContainsString('config_path_digest=%s', $source);
            self::assertStringContainsString('active_slot=%s', $source);
            self::assertStringContainsString('runtime_generation=%s', $source);
            self::assertStringContainsString('allowed_phase=%s', $source);
            self::assertStringContainsString('gateway_epoch=%s', $source);
            self::assertStringContainsString(
                'PROCESS_ATTEST_CANDIDATE_PREPARE',
                $source,
            );
            self::assertStringContainsString(
                'PROCESS_ATTEST_CANDIDATE_CLEAR',
                $source,
            );
        }
    }

    public function testCandidateFenceRejectsCrossTransactionAndClearedReplay(): void
    {
        foreach (['posix', 'windows'] as $platform) {
            $source = $this->read(
                'Service/Edge/Gateway/Native/' . $platform
                    . '/wls_gateway_broker.c',
            );
            $prefix = $platform === 'windows' ? 'wls_win_' : 'wls_';
            $prepare = $this->between(
                $source,
                'static int ' . $prefix . 'candidate_attestation_prepare_v2(',
                'static int ' . $prefix . 'candidate_attestation_clear_v2(',
            );
            self::assertMatchesRegularExpression(
                '/strcmp\(existing\.status, "ARMED"\) == 0.*'
                    . 'strcmp\(existing\.transaction_id, transaction_id\) != 0/s',
                $prepare,
            );
            self::assertMatchesRegularExpression(
                '/strcmp\(existing\.transaction_id, transaction_id\) == 0.*'
                    . 'goto denied/s',
                $prepare,
            );
            $clear = $this->between(
                $source,
                'static int ' . $prefix . 'candidate_attestation_clear_v2(',
                'static int ' . $prefix . 'candidate_attestation_action_serialized(',
            );
            self::assertStringContainsString(
                '"ARMED", existing.controller_fencing_digest,',
                $clear,
            );
            self::assertStringContainsString(
                'expected_armed_digest, expected_fence_digest',
                $clear,
            );
        }
    }

    public function testProcessAttestationV3ClosesCandidateRetirementReplay(): void
    {
        $controller = $this->read('bin/wls_gateway_controller.php');
        self::assertStringContainsString('WLS-PROCESS-ATTEST/3', $controller);
        self::assertStringContainsString(
            "'fence_kind=' . (string)(\$attestation['fence_kind'] ?? '')",
            $controller,
        );
        self::assertStringContainsString(
            "'candidate_transaction_id=' . (string)(",
            $controller,
        );
        self::assertStringContainsString(
            "'candidate_phase=' . (string)(",
            $controller,
        );
        self::assertStringContainsString(
            "'candidate_fence_digest=' . (string)(",
            $controller,
        );
        self::assertStringNotContainsString(
            'WLS-PROCESS-ATTEST/2',
            $controller,
        );
        self::assertStringContainsString(
            "(int)(\$attestation['publication_generation'] ?? 0) < 1",
            $controller,
        );
        $controllerReceipt = $this->between(
            $controller,
            '$receipt = $this->processAttestationReceiptContents([',
            "if (!\\hash_equals((string)\$response[0], \\hash('sha256', \$receipt)))",
        );
        foreach ([
            "'fence_kind' => (string)\$response[8]",
            "'candidate_transaction_id' => (string)\$response[9]",
            "'candidate_phase' => (string)\$response[10]",
            "'candidate_fence_digest' => (string)\$response[11]",
        ] as $field) {
            self::assertStringContainsString($field, $controllerReceipt);
        }

        foreach (['posix', 'windows'] as $platform) {
            foreach (['broker', 'launcher'] as $binary) {
                $source = $this->read(
                    'Service/Edge/Gateway/Native/' . $platform
                        . '/wls_gateway_' . $binary . '.c',
                );
                self::assertStringContainsString(
                    'WLS-PROCESS-ATTEST/3',
                    $source,
                );
                self::assertStringNotContainsString(
                    'WLS-PROCESS-ATTEST/2',
                    $source,
                );
                self::assertStringContainsString('fence_kind=%', $source);
                self::assertStringContainsString(
                    'candidate_transaction_id=%',
                    $source,
                );
                self::assertStringContainsString('candidate_phase=%', $source);
                self::assertStringContainsString(
                    'candidate_fence_digest=%',
                    $source,
                );
                if ($binary === 'launcher') {
                    self::assertStringContainsString(
                        'receipt->publication_generation == 0',
                        $source,
                    );
                }
            }
            $broker = $this->read(
                'Service/Edge/Gateway/Native/' . $platform
                    . '/wls_gateway_broker.c',
            );
            $prefix = $platform === 'windows' ? 'wls_win_' : 'wls_';
            $retirement = $this->between(
                $broker,
                'static int ' . $prefix . 'process_tree_retire_v2(',
                'static int wls_handle_action_v2(',
            );
            self::assertStringContainsString(
                $prefix . 'process_attestation_authority_current(',
                $retirement,
            );
            self::assertStringContainsString(
                'candidate.controller_fencing_digest',
                $broker,
            );
            self::assertStringContainsString(
                'candidate.receipt_digest',
                $broker,
            );
            self::assertStringContainsString(
                $platform === 'windows'
                    ? 'publication == 0ULL'
                    : 'receipt->publication == 0U',
                $broker,
            );
        }
    }

    public function testRetirementPhaseTransitionReattestsBeforeDestructiveRetry(): void
    {
        $source = $this->read('bin/wls_gateway_controller.php');
        $rebind = $this->between(
            $source,
            'private function rebindPendingServiceTreeRetirementIntent(',
            'private function retireAttestedNginxProcessTree(',
        );
        self::assertStringContainsString(
            "'SERVICE_TREE_RETIREMENT_PENDING'",
            $rebind,
        );
        self::assertMatchesRegularExpression(
            '/\$status = \$this->nginxStatus\(\s*true,\s*true,\s*'
                . '\$deadlineMonotonic,\s*\);/s',
            $rebind,
        );
        self::assertStringContainsString(
            '$this->nativeProcessTreeRetirementIntent(',
            $rebind,
        );
        self::assertStringContainsString(
            '$this->persistPublication();',
            $rebind,
        );

        $retire = $this->between(
            $source,
            'private function retireSecurityDataPlaneGeneration(',
            'private function forceStopSecurityDataPlane(): bool',
        );
        self::assertGreaterThanOrEqual(
            2,
            \substr_count(
                $retire,
                '$this->requestBrokerProcessTreeRetirement(',
            ),
        );
        self::assertStringContainsString(
            '$this->rebindPendingServiceTreeRetirementIntent(',
            $retire,
        );
        $pendingPrepare = \strpos(
            $retire,
            '$this->prepareCandidateAttestationFence(' . "\n"
                . "                    'SERVICE_TREE_RETIREMENT_PENDING',",
        );
        $pendingRebind = \strpos(
            $retire,
            '$this->rebindPendingServiceTreeRetirementIntent(',
            $pendingPrepare ?: 0,
        );
        $destructive = \strpos(
            $retire,
            '$this->retireAttestedNginxProcessTree(',
            $pendingRebind ?: 0,
        );
        self::assertNotFalse($pendingPrepare);
        self::assertNotFalse($pendingRebind);
        self::assertNotFalse($destructive);
        self::assertLessThan($pendingRebind, $pendingPrepare);
        self::assertLessThan($destructive, $pendingRebind);

        $completed = \strpos(
            $retire,
            "if ((\$retirement['ok'] ?? false) === true)",
        );
        $obsolete = \strpos(
            $retire,
            "unset(\$this->state['nginx_process_attestation']);",
            $completed ?: 0,
        );
        $replacementAttestation = \strpos(
            $retire,
            '$replacementStatus = $this->nginxStatus(',
            $obsolete ?: 0,
        );
        $replacementDeadline = \strpos(
            $retire,
            '$deadlineMonotonic,',
            $replacementAttestation ?: 0,
        );
        $activation = \strpos(
            $retire,
            '$activation = $this->activateCurrentConfigAndProbe(',
            $replacementAttestation ?: 0,
        );
        self::assertNotFalse($completed);
        self::assertNotFalse($obsolete);
        self::assertNotFalse($replacementAttestation);
        self::assertNotFalse($replacementDeadline);
        self::assertNotFalse($activation);
        self::assertLessThan($obsolete, $completed);
        self::assertLessThan($replacementAttestation, $obsolete);
        self::assertLessThan($activation, $replacementDeadline);
        self::assertLessThan($activation, $replacementAttestation);
        self::assertStringContainsString(
            '} elseif ($this->pidRunningState($currentPid) === false) {',
            $retire,
        );
        self::assertStringNotContainsString(
            '} elseif ($currentPid === $pid' . "\n"
                . '                        && $this->pidRunningState($currentPid) === false',
            $retire,
        );
    }

    public function testWindowsSystemRootUsesBoundFfiCast(): void
    {
        $source = $this->read(
            'Service/Edge/Gateway/GatewayPlatformServiceInstaller.php',
        );

        self::assertStringContainsString(
            '$ffi->cast(\'char*\', $buffer)',
            $source,
        );
        self::assertStringNotContainsString(
            '\\FFI::cast(\'char*\', $buffer)',
            $source,
        );
    }

    public function testCandidateFenceIsReboundDuringCrashRecovery(): void
    {
        $source = $this->read('bin/wls_gateway_controller.php');
        $pending = $this->between(
            $source,
            'private function processPendingServiceTreeRetirementPublication(): void',
            'private function updatePublicationOperations(',
        );
        $recovery = $this->between(
            $source,
            'private function reconcileInterruptedPublication(): void',
            'private function publishIfDirty(): bool',
        );

        self::assertStringContainsString(
            '$this->prepareCandidateAttestationFence(',
            $pending,
        );
        self::assertStringContainsString(
            "'SERVICE_TREE_RETIREMENT_PENDING',",
            $pending,
        );
        self::assertMatchesRegularExpression(
            '/\$this->prepareCandidateAttestationFence\(\s*'
                . '\'ACTIVATING\',\s*\$activationRecoveryDeadline,\s*\);/s',
            $recovery,
        );
        self::assertStringContainsString(
            "['ACTIVATING', 'DURABILITY_PENDING']",
            $recovery,
        );
        self::assertStringContainsString(
            'BROKER_ACTION_PENDING_RECOVERY',
            $recovery,
        );
        $activeDurable = \strpos($recovery, '$activeFenceDurable =');
        $lostAckClear = \strpos(
            $recovery,
            '$this->clearCandidateAttestationFence(',
            $activeDurable ?: 0,
        );
        $lostAckDeadline = \strpos(
            $recovery,
            '$activationRecoveryDeadline,',
            $lostAckClear ?: 0,
        );
        $rebind = \strpos(
            $recovery,
            '$this->prepareCandidateAttestationFence(',
            $lostAckClear ?: 0,
        );
        $rebindDeadline = \strpos(
            $recovery,
            '$activationRecoveryDeadline,',
            $rebind ?: 0,
        );
        self::assertNotFalse($activeDurable);
        self::assertNotFalse($lostAckClear);
        self::assertNotFalse($lostAckDeadline);
        self::assertNotFalse($rebind);
        self::assertNotFalse($rebindDeadline);
        self::assertLessThan($lostAckClear, $activeDurable);
        self::assertLessThan($rebind, $lostAckDeadline);
        self::assertLessThan($rebind, $lostAckClear);
        self::assertLessThan($rebindDeadline, $rebind);
    }

    public function testPostSwitchExceptionKeepsPublicationForReconciliation(): void
    {
        $source = $this->between(
            $this->read('bin/wls_gateway_controller.php'),
            'private function abortRoutingMutation(string $reason): void',
            'private function rollbackRoutingMutation(string $reason): void',
        );
        $candidateCheck = \strpos($source, '$candidateIsAtActivePath =');
        $recovery = \strpos($source, "'PUBLICATION_RECOVERY'");
        $requestRollback = \strpos(
            $source,
            'if ($this->requestStateBeforeMutation !== null',
        );

        self::assertNotFalse($candidateCheck);
        self::assertNotFalse($recovery);
        self::assertNotFalse($requestRollback);
        self::assertLessThan($recovery, $candidateCheck);
        self::assertLessThan($requestRollback, $recovery);
    }

    public function testH3FallbackFailureReturnsToExplicitRollbackPath(): void
    {
        $source = $this->between(
            $this->read('bin/wls_gateway_controller.php'),
            'if (!$publicVerified && (bool)($this->state[\'h3_enabled\'] ?? false))',
            'if (!$publicVerified) {',
        );

        self::assertStringContainsString('try {', $source);
        self::assertStringContainsString('catch (\\Throwable $throwable)', $source);
        self::assertStringContainsString('$publicVerified = false;', $source);
        self::assertStringContainsString(
            'H3 runtime fallback publication failed:',
            $source,
        );
    }

    public function testRollbackConsumesCandidateFenceBeforeRestoringState(): void
    {
        $source = $this->between(
            $this->read('bin/wls_gateway_controller.php'),
            'private function rollbackRoutingMutation(string $reason): void',
            'private function recordPublicationLeaseCandidate(',
        );
        $clear = \strpos($source, '$this->clearCandidateAttestationFence()');
        $previous = \strpos($source, '$previous = \\is_array(');

        self::assertNotFalse($clear);
        self::assertNotFalse($previous);
        self::assertLessThan($previous, $clear);
    }

    public function testCommitPersistsActiveFenceBeforeClearingCandidate(): void
    {
        $source = $this->between(
            $this->read('bin/wls_gateway_controller.php'),
            '// Public sentinels prove only backend reachability and identity.',
            '$this->publication[\'phase\'] = \'COMMITTED\';',
        );
        $active = \strpos(
            $source,
            '$this->state[\'active_config_generation\']',
        );
        $persist = \strpos($source, '$this->persistState();', $active ?: 0);
        $clear = \strpos(
            $source,
            '$this->clearCandidateAttestationFence(',
            $persist ?: 0,
        );
        $clearDeadline = \strpos(
            $source,
            '$retirementDeadline ?? $publicationDeadline,',
            $clear ?: 0,
        );

        self::assertNotFalse($active);
        self::assertNotFalse($persist);
        self::assertNotFalse($clear);
        self::assertNotFalse($clearDeadline);
        self::assertLessThan($persist, $active);
        self::assertLessThan($clear, $persist);
        self::assertLessThan($clearDeadline, $clear);
    }

    private function read(string $relative): string
    {
        $source = \file_get_contents(
            $this->moduleRoot . \DIRECTORY_SEPARATOR . $relative,
        );
        self::assertIsString($source);
        return $source;
    }

    private function between(string $source, string $start, string $end): string
    {
        $offset = \strpos($source, $start);
        self::assertNotFalse($offset);
        $limit = \strpos($source, $end, $offset);
        self::assertNotFalse($limit);
        return \substr($source, $offset, $limit - $offset);
    }
}
