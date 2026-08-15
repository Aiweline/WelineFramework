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
        self::assertStringContainsString('wls_guardian_parent_start_id', $source);
        self::assertStringContainsString('wls-launchd-guardian/1', $source);
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

    public function testLaunchersRecoverPidStagingOnlyFromTheRetiredPlatformGeneration(): void
    {
        foreach (['posix', 'windows'] as $platform) {
            $source = $this->read(
                'Service/Edge/Gateway/Native/' . $platform
                    . '/wls_gateway_launcher.c',
            );

            self::assertStringContainsString(
                'WLS-NGINX-PID-RESIDUE/1',
                $source,
            );
            self::assertStringContainsString(
                'nginx-pid-residue.intent',
                $source,
            );
            self::assertStringContainsString(
                'wls_seal_nginx_pid_residue_pending(',
                $source,
            );
            self::assertStringContainsString(
                'wls_consume_nginx_pid_residue_pending(',
                $source,
            );
            self::assertStringContainsString(
                'requested_launcher_generation',
                $source,
            );
            if ($platform === 'windows') {
                self::assertStringContainsString(
                    'wls_nginx_pid_residue_acl_valid',
                    $source,
                );
            }
            self::assertStringContainsString(
                'nginx.pid',
                $source,
            );
            self::assertMatchesRegularExpression(
                '/wls_consume_nginx_pid_residue_pending\([\s\S]*?\)\s*!=\s*0'
                    . '[\s\S]*?wls_promote_platform_retirement\(/',
                $source,
            );
            self::assertMatchesRegularExpression(
                '/wls_seal_platform_retirement_pending\([\s\S]*?\)\s*!=\s*0'
                    . '[\s\S]*?else if\s*\(wls_seal_nginx_pid_residue_pending\(/',
                $source,
            );
        }
    }

    public function testPidResidueBindsIndeterminateRetirementBeforePromotion(): void
    {
        foreach (['posix', 'windows'] as $platform) {
            $source = $this->read(
                'Service/Edge/Gateway/Native/' . $platform
                    . '/wls_gateway_launcher.c',
            );

            self::assertStringContainsString(
                'wls_validate_pending_retirement_for_pid_residue',
                $source,
            );
            if ($platform === 'windows') {
                self::assertMatchesRegularExpression(
                    '/wls_validate_pending_retirement_for_pid_residue\([\s\S]*?'
                        . 'wls_platform_retirement_receipt_read\(home, &receipt\)'
                        . '[\s\S]*?INDETERMINATE[\s\S]*?'
                        . 'requested_launcher_generation[\s\S]*?'
                        . 'runtime_generation/',
                    $source,
                );
            } else {
                self::assertMatchesRegularExpression(
                    '/wls_validate_pending_retirement_for_pid_residue\([\s\S]*?'
                        . 'WLS-PROCESS-TREE-RETIRE\/2[\s\S]*?'
                        . 'INDETERMINATE[\s\S]*?'
                        . 'requested_launcher_generation[\s\S]*?'
                        . 'runtime_generation/',
                    $source,
                );
            }
            self::assertMatchesRegularExpression(
                '/wls_consume_nginx_pid_residue_pending\([\s\S]*?'
                    . 'wls_validate_pending_retirement_for_pid_residue\([\s\S]*?'
                    . 'wls_promote_platform_retirement\(/',
                $source,
            );
            if ($platform === 'windows') {
                self::assertMatchesRegularExpression(
                    '/wls_validate_pending_retirement_for_pid_residue\([\s\S]*?'
                        . 'wls_authenticated_platform_generation/',
                    $source,
                );
            } else {
                self::assertMatchesRegularExpression(
                    '/strcmp\(platform, "standalone"\) == 0[\s\S]*?'
                        . 'wls_consume_nginx_pid_residue_pending\(/',
                    $source,
                );
            }
        }
    }

    public function testPidResidueDeletionRechecksEmptyBeforeDroppingIntent(): void
    {
        $windows = $this->read(
            'Service/Edge/Gateway/Native/windows/wls_gateway_launcher.c',
        );
        $posix = $this->read(
            'Service/Edge/Gateway/Native/posix/wls_gateway_launcher.c',
        );
        $windowsConsume = $this->between(
            $windows,
            'static int wls_consume_nginx_pid_residue_pending(',
            'static int wls_upgrade_state_read(',
        );
        $posixConsume = $this->between(
            $posix,
            'static int wls_consume_nginx_pid_residue_pending(',
            '/* 0=acquired, 1=busy, -1=invalid/error. */',
        );

        self::assertMatchesRegularExpression(
            '/SetFileInformationByHandle\([\s\S]*?CloseHandle\(leaf\);[\s\S]*?'
                . 'wls_launcher_flush_verified_directory\(directory\)[\s\S]*?'
                . 'wls_nginx_pid_residue_scan\([\s\S]*?actual\.count != 0U[\s\S]*?'
                . 'DeleteFileW\(path\)[\s\S]*?wls_flush_trust_directory\(home\)/',
            $windowsConsume,
        );
        self::assertMatchesRegularExpression(
            '/unlinkat\([\s\S]*?fsync\(directory_fd\)[\s\S]*?'
                . 'wls_nginx_pid_residue_scan\([\s\S]*?actual_count != 0U[\s\S]*?'
                . 'unlink\(path\)[\s\S]*?fsync\(trust_fd\)/',
            $posixConsume,
        );
    }

    public function testDirectLaunchersClassifyPidResidueEvidenceBeforeMutation(): void
    {
        foreach (['posix', 'windows'] as $platform) {
            $source = $this->read(
                'Service/Edge/Gateway/Native/' . $platform
                    . '/wls_gateway_launcher.c',
            );

            self::assertStringContainsString(
                'wls_pid_residue_recovery_evidence_state',
                $source,
            );
            self::assertStringContainsString(
                'WLS_PID_RESIDUE_EVIDENCE_CLEAN',
                $source,
            );
            self::assertStringContainsString(
                'WLS_PID_RESIDUE_EVIDENCE_PENDING',
                $source,
            );
            self::assertStringContainsString(
                'WLS_PID_RESIDUE_EVIDENCE_UNSAFE',
                $source,
            );
            self::assertMatchesRegularExpression(
                '/else if \(retirement_pending && !intent_pending && residue_count == 0U\)[\s\S]*?'
                    . 'WLS_PID_RESIDUE_EVIDENCE_PENDING/',
                $source,
            );
            self::assertMatchesRegularExpression(
                '/wls_pid_residue_recovery_evidence_state\([\s\S]*?'
                    . 'wls_consume_nginx_pid_residue_pending\([\s\S]*?'
                    . 'wls_promote_platform_retirement\(/',
                $source,
            );
            if ($platform === 'posix') {
                self::assertMatchesRegularExpression(
                    '/strcmp\(platform, "standalone"\) == 0[\s\S]*?'
                        . 'WLS_PID_RESIDUE_EVIDENCE_CLEAN[\s\S]*?'
                        . 'goto pid_residue_recovery_ready;[\s\S]*?'
                        . 'wls_consume_nginx_pid_residue_pending\(/',
                    $source,
                );
            } else {
                self::assertMatchesRegularExpression(
                    '/wls_authenticated_platform_generation[\s\S]*?'
                        . 'WLS_PID_RESIDUE_EVIDENCE_CLEAN[\s\S]*?'
                        . 'goto pid_residue_recovery_ready;[\s\S]*?'
                        . 'wls_consume_nginx_pid_residue_pending\(/',
                    $source,
                );
                $promotion = $this->between(
                    $source,
                    'static int wls_promote_platform_retirement(',
                    'static int wls_nginx_pid_residue_kind(',
                );
                self::assertStringContainsString(
                    'wls_authenticated_platform_generation',
                    $promotion,
                );
            }
        }
    }

    public function testWindowsRetirementReceiptReadIsExplicitTriState(): void
    {
        $source = $this->read(
            'Service/Edge/Gateway/Native/windows/wls_gateway_launcher.c',
        );
        $reader = $this->between(
            $source,
            'static int wls_platform_retirement_receipt_read(',
            'static int wls_seal_platform_retirement_pending(',
        );
        $promotion = $this->between(
            $source,
            'static int wls_promote_platform_retirement(',
            'static int wls_nginx_pid_residue_kind(',
        );

        self::assertMatchesRegularExpression(
            '/CreateFileW\([\s\S]*?INVALID_HANDLE_VALUE[\s\S]*?'
                . 'DWORD error = GetLastError\(\)[\s\S]*?'
                . 'ERROR_FILE_NOT_FOUND[\s\S]*?'
                . 'WLS_RETIREMENT_RECEIPT_ABSENT/',
            $reader,
        );
        self::assertStringContainsString('FileAttributeTagInfo', $reader);
        self::assertStringContainsString('GetFileInformationByHandle', $reader);
        self::assertStringContainsString('WLS_RETIREMENT_RECEIPT_INVALID', $reader);
        /* A stale FILE_NOT_FOUND must not turn a successfully opened unsafe
         * receipt into absence: LastError is sampled only in the open-failure
         * branch, while every post-open validation falls through as INVALID. */
        self::assertSame(1, substr_count($reader, 'GetLastError()'));
        self::assertMatchesRegularExpression(
            '/if \(file == INVALID_HANDLE_VALUE\) \{[\s\S]*?'
                . 'DWORD error = GetLastError\(\)[\s\S]*?'
                . 'goto cleanup;\s*\}[\s\S]*?'
                . 'GetFileInformationByHandleEx[\s\S]*?goto cleanup;/',
            $reader,
        );
        self::assertStringContainsString(
            'wls_platform_retirement_receipt_read(home, &receipt)',
            $promotion,
        );
        self::assertStringNotContainsString(
            'wls_read_file(path, 2048U, &contents, &length)',
            $promotion,
        );
        self::assertMatchesRegularExpression(
            '/wls_pid_residue_recovery_evidence_state\([\s\S]*?'
                . 'wls_platform_retirement_receipt_read\([\s\S]*?'
                . 'wls_validate_pending_retirement_for_pid_residue\([\s\S]*?'
                . 'wls_platform_retirement_receipt_read\(/',
            $source,
        );
    }

    public function testPidResidueIntentIsSealedAsRootOnlyTrustState(): void
    {
        $paths = $this->read('Service/Edge/Gateway/GatewayPaths.php');
        $installer = $this->read(
            'Service/Edge/Gateway/GatewayPlatformServiceInstaller.php',
        );
        $recovery = $this->read(
            'Service/Edge/Gateway/HostGatewayPackageManager.php',
        );

        self::assertStringContainsString('nginxPidResidueIntentFile', $paths);
        self::assertStringContainsString(
            "'nginx-pid-residue.intent'",
            $installer,
        );
        self::assertStringContainsString(
            "'nginx-pid-residue.intent'",
            $recovery,
        );
        self::assertStringContainsString(
            '$this->paths->nginxPidResidueIntentFile()',
            $recovery,
        );
        self::assertMatchesRegularExpression(
            '/emptyInitialBootstrapScaffoldingIsFullyRetired\(\)[\s\S]*?'
                . 'nginxPidResidueIntentFile\(\)/',
            $recovery,
        );
    }

    public function testWindowsPidStagingUsesAnExplicitEarlyCrashDacl(): void
    {
        $broker = $this->read(
            'Service/Edge/Gateway/Native/windows/wls_gateway_broker.c',
        );
        $launcher = $this->read(
            'Service/Edge/Gateway/Native/windows/wls_gateway_launcher.c',
        );

        self::assertStringContainsString(
            'wls_nt_create_nginx_pid_residue_child',
            $broker,
        );
        self::assertStringContainsString(
            'O:SYD:P(A;;FA;;;SY)(A;;FA;;;BA)',
            $broker,
        );
        self::assertStringContainsString(
            'ConvertStringSecurityDescriptorToSecurityDescriptorW',
            $broker,
        );
        self::assertSame(
            4,
            \substr_count($broker, 'wls_nt_create_nginx_pid_residue_child('),
        );
        self::assertStringContainsString(
            'leaf, 0, NULL, 0, NULL, 0, 1, 0, 0U',
            $launcher,
        );
        self::assertStringContainsString(
            'wls_nginx_pid_residue_intent_name_valid',
            $launcher,
        );
        self::assertStringNotContainsString(
            'inherited from this service context',
            $launcher,
        );
    }

    public function testWindowsPidResidueRequiresAnAuthenticatedGuardianPlatform(): void
    {
        $launcher = $this->read(
            'Service/Edge/Gateway/Native/windows/wls_gateway_launcher.c',
        );
        $guardian = $this->read(
            'Service/Edge/Gateway/Native/windows/wls_gateway_guardian.inc',
        );
        $runtime = $this->read(
            'Service/Edge/Gateway/Native/windows/wls_gateway_guardian_runtime.inc',
        );

        self::assertStringContainsString(
            'wls_authenticated_platform_generation',
            $launcher,
        );
        self::assertMatchesRegularExpression(
            '/wls_guardian_child_parent_valid\([\s\S]*?'
                . 'InterlockedExchange\(&wls_authenticated_platform_generation, 1\)/',
            $guardian,
        );
        self::assertMatchesRegularExpression(
            '/wls_consume_nginx_pid_residue_pending\([\s\S]*?'
                . 'wls_authenticated_platform_generation/',
            $launcher,
        );
        self::assertMatchesRegularExpression(
            '/wls_seal_nginx_pid_residue_pending\([\s\S]*?'
                . 'wls_authenticated_platform_generation/',
            $launcher,
        );
        self::assertMatchesRegularExpression(
            '/child_exit == WLS_SERVICE_TREE_RESTART[\s\S]*?'
                . 'WLS_GUARDIAN_MONITOR_RESTART_CHILD/',
            $runtime,
        );
        self::assertMatchesRegularExpression(
            '/WLS_GUARDIAN_MONITOR_RESTART_CHILD[\s\S]*?'
                . 'wls_guardian_child_stop\([\s\S]*?'
                . 'wls_guardian_child_process_close\([\s\S]*?continue;/',
            $runtime,
        );
        self::assertMatchesRegularExpression(
            '/wls_guardian_child_process_close\([\s\S]*?'
                . 'CloseHandle\(child->job\);/',
            $guardian,
        );
    }

    public function testWindowsPidResidueEmptyDirectoryIsAnExactEmptyManifest(): void
    {
        $launcher = $this->read(
            'Service/Edge/Gateway/Native/windows/wls_gateway_launcher.c',
        );
        $scan = $this->between(
            $launcher,
            'static int wls_nginx_pid_residue_scan(',
            'static int wls_write_nginx_pid_residue_intent(',
        );

        self::assertMatchesRegularExpression(
            '/FindFirstFileW\(pattern, &found\)[\s\S]*?'
                . 'INVALID_HANDLE_VALUE[\s\S]*?'
                . 'GetLastError\(\) == ERROR_FILE_NOT_FOUND[\s\S]*?'
                . 'status = 0;[\s\S]*?goto cleanup;/',
            $scan,
        );
        self::assertStringNotContainsString('ERROR_PATH_NOT_FOUND', $scan);
    }

    public function testWindowsRestartExitTerminatesAndProvesBothJobsAreEmpty(): void
    {
        $launcher = $this->read(
            'Service/Edge/Gateway/Native/windows/wls_gateway_launcher.c',
        );
        $supervisor = $this->between(
            $launcher,
            'static int wls_run_supervisor(',
            '#ifdef WLS_GUARDIAN_EXECUTABLE',
        );
        $termination = $this->between(
            $launcher,
            'static int wls_terminate_jobs_and_wait_zero(',
            'static int wls_run_supervisor(',
        );

        self::assertMatchesRegularExpression(
            '/result == \(int\)WLS_SERVICE_TREE_RESTART[\s\S]*?'
                . 'wls_terminate_jobs_and_wait_zero\(job, data_plane_job\)[\s\S]*?'
                . 'CloseHandle\(data_plane_job\);[\s\S]*?CloseHandle\(job\);/',
            $supervisor,
        );
        self::assertStringContainsString(
            'TerminateJobObject(job, WLS_SERVICE_TREE_RESTART)',
            $termination,
        );
        self::assertStringContainsString('TerminateJobObject(', $termination);
        self::assertStringContainsString(
            'data_plane_job, WLS_SERVICE_TREE_RESTART',
            $termination,
        );
        self::assertStringContainsString(
            'wls_job_active_processes_zero(job, &supervision_zero)',
            $termination,
        );
        self::assertStringContainsString(
            'wls_job_active_processes_zero(data_plane_job, &data_plane_zero)',
            $termination,
        );
        self::assertStringContainsString('GetQueuedCompletionStatus', $termination);
        self::assertStringContainsString(
            'JOB_OBJECT_MSG_ACTIVE_PROCESS_ZERO',
            $termination,
        );
        self::assertStringContainsString('QueryInformationJobObject', $launcher);
    }

    public function testWindowsPidResidueDurabilityParentsAreOpenedWritable(): void
    {
        $launcher = $this->read(
            'Service/Edge/Gateway/Native/windows/wls_gateway_launcher.c',
        );
        $broker = $this->read(
            'Service/Edge/Gateway/Native/windows/wls_gateway_broker.c',
        );

        self::assertMatchesRegularExpression(
            '/wls_nginx_pid_residue_directory\([\s\S]*?'
                . 'FILE_LIST_DIRECTORY \| FILE_TRAVERSE \| FILE_WRITE_DATA/',
            $launcher,
        );
        self::assertMatchesRegularExpression(
            '/wls_flush_trust_directory\([\s\S]*?'
                . 'FILE_LIST_DIRECTORY \| FILE_TRAVERSE \| FILE_WRITE_DATA/',
            $launcher,
        );
        self::assertStringContainsString(
            'wls_launcher_root_only_directory_acl_valid',
            $launcher,
        );
        self::assertStringContainsString(
            'wls_launcher_flush_verified_directory',
            $launcher,
        );
        self::assertStringContainsString('NtFlushBuffersFile', $launcher);
        self::assertStringContainsString(
            'wls_win_nginx_pid_flush_directory',
            $broker,
        );
        self::assertMatchesRegularExpression(
            '/wls_win_nginx_pid_directory_open\([\s\S]*?'
                . 'FILE_LIST_DIRECTORY \| FILE_TRAVERSE \| FILE_WRITE_DATA/',
            $broker,
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
        self::assertStringContainsString('After=network.target', $systemd);
        self::assertStringNotContainsString('After=network-online.target', $systemd);
        self::assertStringNotContainsString('Wants=network-online.target', $systemd);
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
            '/"QUIT"\s*,\s*context\.config_path\s*,\s*NULL\s*,\s*0U/s',
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
