<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class NativeGatewayWindowsDataPlaneSecurityContractTest extends TestCase
{
    private string $broker;
    private string $launcher;
    private string $guardian;
    private string $guardianRuntime;
    private string $guardianRecovery;
    private string $cmake;
    private string $installer;
    private string $windowsServiceTemplate;
    private string $windowsRecoveryFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $serverRoot = \dirname(__DIR__, 5);
        $root = $serverRoot . '/Service/Edge/Gateway/Native/windows/';
        $this->broker = (string)\file_get_contents(
            $root . 'wls_gateway_broker.c',
        );
        $this->launcher = (string)\file_get_contents(
            $root . 'wls_gateway_launcher.c',
        );
        $this->guardian = (string)\file_get_contents(
            $root . 'wls_gateway_guardian.inc',
        );
        $this->guardianRuntime = (string)\file_get_contents(
            $root . 'wls_gateway_guardian_runtime.inc',
        );
        $this->guardianRecovery = (string)\file_get_contents(
            $root . 'wls_gateway_guardian_recovery.inc',
        );
        $this->cmake = (string)\file_get_contents(
            \dirname($root) . '/CMakeLists.txt',
        );
        $this->installer = (string)\file_get_contents(
            $serverRoot
                . '/Service/Edge/Gateway/GatewayPlatformServiceInstaller.php',
        );
        $this->windowsServiceTemplate = (string)\file_get_contents(
            $serverRoot . '/env/gateway/windows-service.json.template',
        );
        $this->windowsRecoveryFixture = (string)\file_get_contents(
            $serverRoot
                . '/Test/Integration/Service/Edge/Gateway/windows_service_recovery.php',
        );
    }

    public function testBrokerOwnsTheWindowsNginxPidNamespaceAcrossTestStartAndTerminalCleanup(): void
    {
        $prepare = $this->between(
            $this->broker,
            'static int wls_win_prepare_data_plane_runtime(',
            'static void wls_win_close_public_sockets(',
        );
        foreach ([
            'wls_win_nginx_pid_directory_open(',
            'wls_win_nginx_pid_directory_stable_acl_valid(',
        ] as $required) {
            self::assertStringContainsString($required, $prepare);
        }

        foreach ([
            'wls_win_nginx_pid_leaf_test_cleanup(',
            'wls_win_nginx_pid_leaf_seal(',
            'wls_win_nginx_pid_leaf_terminal_cleanup(',
            'FILE_ADD_FILE | FILE_DELETE_CHILD',
            'L"nginx-pid"',
            'wls_read_nginx_pid_leaf(',
            'wls_win_nginx_pid_legacy_absent(',
        ] as $required) {
            self::assertStringContainsString($required, $this->broker);
        }
        self::assertStringContainsString(
            'L"nginx-pid\\\\nginx.pid"',
            $this->launcher,
        );

        $retire = $this->between(
            $this->broker,
            'static int wls_win_process_tree_retire_v2(',
            'static int wls_handle_action_v2(',
        );
        $death = strpos($retire, 'wls_win_wait_process_tree_exit(');
        $cleanup = strpos(
            $retire,
            'wls_win_nginx_pid_terminal_cleanup_after_exact_death('
        );
        self::assertNotFalse($death);
        self::assertNotFalse($cleanup);
        self::assertGreaterThan($death, $cleanup);
        self::assertLessThan(
            strpos($retire, 'wls_win_write_root_process_tree_retirement('),
            $cleanup,
        );
        self::assertStringContainsString(
            'wls_nginx_pid_namespace_restart_latched',
            $this->broker,
        );
    }

    public function testPidSourcePublicationKeepsTheWindowsParentAclStable(): void
    {
        foreach ([
            'wls_win_nginx_pid_source_create(',
            'wls_win_nginx_pid_precreate_canonical_source(',
            'wls_win_nginx_pid_publish_from_source(',
            'wls_win_nginx_pid_source_config_create(',
            'wls_win_nginx_pid_source_config_exact(',
            'wls_win_nginx_action_config_relative(',
            'wls_win_nginx_pid_test_sources_prepare(',
            'wls_win_nginx_pid_directory_no_staged_residue(',
            'preheld source handle',
        ] as $required) {
            self::assertStringContainsString($required, $this->broker);
        }

        $lifecycle = $this->between(
            $this->broker,
            'static int wls_win_nginx_lifecycle_action_v2(',
            'typedef wchar_t wls_win_snapshot_name',
        );
        self::assertStringNotContainsString(
            'wls_win_nginx_pid_directory_window_acl_apply(',
            $lifecycle,
        );
        self::assertStringContainsString(
            'wls_win_nginx_pid_precreate_canonical_source(',
            $lifecycle,
        );
        self::assertStringContainsString(
            'before->config_path,',
            $lifecycle,
        );
        self::assertStringContainsString(
            'NGINX_START_SERVICE_TREE_RESTART_REQUIRED',
            $lifecycle,
        );
        self::assertStringContainsString(
            'wls_win_nginx_lifecycle_start_restart_required(',
            $lifecycle,
        );
        self::assertStringContainsString(
            'action_failed',
            $lifecycle,
        );
        self::assertStringContainsString(
            'committed',
            $lifecycle,
        );
        self::assertStringContainsString(
            'if (locked) ReleaseSRWLockExclusive',
            $lifecycle,
        );
        $restartPolicy = $this->between(
            $this->broker,
            'static int wls_win_nginx_lifecycle_start_restart_required(',
            'static int wls_win_nginx_lifecycle_action_v2(',
        );
        self::assertStringContainsString(
            'action_failed && (start_tree_may_exist || latched != 0L)',
            $restartPolicy,
        );
        self::assertStringContainsString(
            '!committed && latched != 0L',
            $restartPolicy,
        );
        $sourceAcl = $this->between(
            $this->broker,
            'static int wls_win_nginx_pid_source_acl_apply(',
            'static int wls_win_nginx_pid_source_valid(',
        );
        self::assertStringContainsString(
            'FILE_GENERIC_READ | FILE_GENERIC_WRITE',
            $sourceAcl,
        );
        self::assertStringNotContainsString('DELETE |', $sourceAcl);
        self::assertStringNotContainsString('WRITE_DAC |', $sourceAcl);
        self::assertStringNotContainsString('WRITE_OWNER |', $sourceAcl);
        $publication = $this->between(
            $this->broker,
            'static int wls_win_nginx_pid_publish_from_source(',
            'static int WLS_MAYBE_UNUSED wls_win_nginx_pid_leaf_test_cleanup(',
        );
        self::assertStringContainsString(
            'wcscmp(source_leaf, L"nginx.pid") != 0',
            $publication,
        );
        $pidNamespace = $this->between(
            $this->broker,
            'static int wls_win_nginx_pid_directory_stable_acl_valid(',
            'static int wls_win_nginx_pid_legacy_absent(',
        );
        self::assertStringNotContainsString(
            'FILE_ADD_FILE | FILE_DELETE_CHILD',
            $pidNamespace,
        );
        $command = $this->between(
            $this->broker,
            'static int wls_win_process_command_matches(',
            'static int wls_same_file_identity(',
        );
        self::assertStringContainsString(
            '_wcsicmp(arguments[4], config) == 0',
            $command,
        );
        self::assertStringNotContainsString('_wcsnicmp(arguments[4]', $command);
        $identity = $this->between(
            $this->broker,
            'static int wls_win_nginx_pid_identity(',
            'static int wls_win_nginx_action_receipt(',
        );
        self::assertStringContainsString('process_config = context->config_path;', $identity);
        self::assertStringNotContainsString('wls_nginx_shadow_config_', $identity);
        $sourceConfig = $this->between(
            $this->broker,
            'static int wls_win_nginx_pid_source_config_create(',
            'static int wls_win_nginx_pid_source_config_remove(',
        );
        self::assertStringContainsString('L"%ls\\\\nginx-pid\\\\%ls"', $sourceConfig);
        self::assertStringContainsString(
            'wls_win_nginx_pid_source_config_exact(',
            $sourceConfig,
        );
        self::assertStringContainsString(
            'wls_win_nginx_action_config_relative(context, relative)',
            $sourceConfig,
        );
        self::assertStringContainsString(
            'home_root, relative, WLS_MAX_ATOMIC_CONFIG,',
            $sourceConfig,
        );
        self::assertStringContainsString(
            'wls_win_nginx_pid_source_config_remove(',
            $sourceConfig,
        );
        self::assertStringContainsString(
            'config_created && config_leaf != NULL',
            $sourceConfig,
        );
        self::assertStringContainsString(
            'CloseHandle(shadow);',
            $sourceConfig,
        );
        self::assertStringContainsString(
            'wls_win_nginx_pid_flush_directory(nginx_pid_directory)',
            $sourceConfig,
        );
        self::assertStringContainsString(
            'GetLastError() != ERROR_FILE_NOT_FOUND',
            $sourceConfig,
        );
        $sourceRemove = $this->between(
            $this->broker,
            'static int wls_win_nginx_pid_source_remove_empty(',
            'static int wls_win_nginx_pid_publish_from_source(',
            2,
        );
        $sourceDisposition = strpos(
            $sourceRemove,
            'SetFileInformationByHandle(',
        );
        $sourceClose = strpos(
            $sourceRemove,
            'CloseHandle(source);',
            is_int($sourceDisposition) ? $sourceDisposition : 0,
        );
        $sourceFlush = strpos(
            $sourceRemove,
            'wls_win_nginx_pid_flush_directory(directory)',
            is_int($sourceClose) ? $sourceClose : 0,
        );
        $sourceAbsence = strpos(
            $sourceRemove,
            'GetLastError() != ERROR_FILE_NOT_FOUND',
            is_int($sourceFlush) ? $sourceFlush : 0,
        );
        self::assertIsInt($sourceDisposition);
        self::assertIsInt($sourceClose);
        self::assertIsInt($sourceFlush);
        self::assertIsInt($sourceAbsence);
        self::assertGreaterThan($sourceDisposition, $sourceClose);
        self::assertGreaterThan($sourceClose, $sourceFlush);
        self::assertGreaterThan($sourceFlush, $sourceAbsence);
        self::assertStringNotContainsString(
            'wls_win_neutral_tls_dispose_staged_leaf(',
            $sourceConfig,
        );
        self::assertStringNotContainsString('L"%ls\\\\runtime\\\\conf\\\\%ls"', $sourceConfig);
        $sidConversion = strpos(
            $sourceConfig,
            'data_length = MultiByteToWideChar(',
        );
        $sidZero = strpos($sourceConfig, 'ZeroMemory(data_sid, sizeof(data_sid));', $sidConversion);
        $sidSddl = strpos($sourceConfig, '_snwprintf_s(', $sidConversion);
        self::assertIsInt($sidConversion);
        self::assertIsInt($sidZero);
        self::assertIsInt($sidSddl);
        self::assertGreaterThan($sidSddl, $sidZero);
        self::assertStringContainsString(
            'wls_win_nginx_pid_canonical_source_empty_exact(', $this->broker,
        );
        $spawn = $this->between(
            $this->broker,
            'static int wls_win_nginx_spawn(',
            'static int wls_win_nginx_attestation_matches(',
        );
        self::assertStringContainsString(
            'if (strcmp(operation, "TEST") == 0)',
            $spawn,
        );
        self::assertStringContainsString(
            'wcscmp(config_path, context->config_path) == 0',
            $spawn,
        );
        self::assertStringContainsString(
            '!wls_win_nginx_pid_source_config_leaf_valid(leaf)',
            $spawn,
        );
        self::assertStringContainsString(
            '} else if (wcscmp(config_path, context->config_path) != 0)',
            $spawn,
        );
        $pidTest = $this->between(
            $this->broker,
            'static int wls_win_nginx_pid_test(',
            'static int wls_win_nginx_pid_identity(',
        );
        self::assertStringContainsString(
            'wls_win_nginx_pid_leaf_sealed_valid(canonical, canonical_pid)',
            $pidTest,
        );
        self::assertStringContainsString(
            'wls_same_file_identity(',
            $pidTest,
        );
        self::assertStringContainsString(
            'wls_win_nginx_pid_test_sources_prepare(',
            $pidTest,
        );
        $cleanup = $this->between(
            $this->broker,
            'static int wls_win_nginx_pid_test(',
            'static int wls_win_nginx_pid_identity(',
        );
        self::assertStringContainsString(
            'InterlockedExchange(&wls_nginx_pid_namespace_restart_latched, 1L);',
            $cleanup,
        );
    }

    public function testDataPlaneUsesAProductRestrictingSidAndCannotEnterControlPipe(): void
    {
        foreach ([
            'WLS_DATA_PLANE_SERVICE_NAME_UPPER',
            'L"WELINE-WLS-GATEWAY-V2-DATA-PLANE"',
            'CreateRestrictedToken(',
            'TokenRestrictedSids',
            '!wls_token_has_restricted_sid(restricted, restricting_sid)',
            'EqualSid(controller_sid, data_plane_sid)',
        ] as $required) {
            self::assertStringContainsString($required, $this->broker);
        }

        $main = $this->between($this->broker, 'int wmain(', null);
        self::assertStringContainsString('L"D:P(D;;GA;;;%ls)', $main);
        self::assertStringContainsString('wls_data_plane_sid_text', $main);
        self::assertStringContainsString(
            'wls_win_snapshot_recover_publish_temporaries_v2(home)',
            $main,
        );
        self::assertStringContainsString(
            'wls_win_snapshot_receipt_recover_temporaries_v2(home)',
            $main,
        );

        $pipeWorker = $this->between(
            $this->broker,
            'static DWORD WINAPI wls_channel_thread(',
            'static int wls_write_fencing_file(',
        );
        $peerCheck = \strpos($pipeWorker, 'wls_peer_sid(');
        $frameRead = \strpos($pipeWorker, 'wls_pipe_read_frame_alloc(');
        self::assertIsInt($peerCheck);
        self::assertIsInt($frameRead);
        self::assertLessThan($frameRead, $peerCheck);
        self::assertStringContainsString('restricted_sid_denied', $pipeWorker);
    }

    public function testNginxGetsOnlyValidatedPreboundSocketsInsideTheDataPlaneJob(): void
    {
        $open = $this->between(
            $this->broker,
            'static const char *wls_win_open_public_sockets(',
            'static const char *wls_win_tcp_port_probe(',
        );
        foreach ([
            'SO_EXCLUSIVEADDRUSE',
            'IPV6_V6ONLY',
            'SOCK_STREAM',
            'SOCK_DGRAM',
            '80U',
            '443U',
        ] as $required) {
            self::assertStringContainsString($required, $open);
        }

        $spawn = $this->between(
            $this->broker,
            'static int wls_win_nginx_spawn(',
            'static int wls_win_nginx_attestation_matches(',
        );
        foreach ([
            'wls_win_public_socket_set_valid(',
            'PROC_THREAD_ATTRIBUTE_HANDLE_LIST',
            'CREATE_SUSPENDED',
            'CreateProcessAsUserW(',
            'AssignProcessToJobObject(wls_data_plane_job, process.hProcess)',
            'wls_win_data_plane_process(process.hProcess)',
            'ResumeThread(process.hThread)',
        ] as $required) {
            self::assertStringContainsString($required, $spawn);
        }
        self::assertStringContainsString('L"NGINX"', $spawn . $this->broker);
        self::assertLessThan(
            \strpos($spawn, 'ResumeThread(process.hThread)'),
            \strpos($spawn, 'AssignProcessToJobObject('),
        );
        self::assertStringNotContainsString('CreateProcessW(', $spawn);

        $adoption = $this->between(
            $this->launcher,
            'static HANDLE wls_open_verified_job_nginx(',
            'static int wls_force_terminate_process(',
        );
        self::assertStringContainsString(
            'process, supervision_job, &belongs_to_supervision',
            $adoption,
        );
        self::assertStringContainsString(
            'IsProcessInJob(process, data_plane_job',
            $adoption,
        );
        self::assertStringContainsString(
            'wls_verify_slot_durable_state_contract_v2(',
            $adoption,
        );
    }

    public function testNginxTestUsesSealedCandidateAclAndIndependentLkgSourceClosure(): void
    {
        $dispatcher = $this->between(
            $this->broker,
            'static int wls_handle_action_v2(',
            'static const char *wls_json_value(',
        );
        self::assertStringContainsString('"NGINX_TEST"', $dispatcher);
        self::assertStringContainsString(
            'wls_win_nginx_test_action_v2(',
            $dispatcher,
        );

        $test = $this->between(
            $this->broker,
            'static int wls_win_nginx_test_action_v2(',
            'static int wls_win_nginx_lifecycle_action_v2(',
            occurrence: 2,
        );
        foreach ([
            'wls_win_nginx_test_open_regular(',
            'wls_win_nginx_pid_test(',
            '&before_context, sockets, deadline, nginx_pid_directory',
            'wls_win_nginx_test_candidate_restore(&config_before)',
            'wls_win_nginx_test_observation_same(',
            'WLS-ACTION/2\\tOK\\tNGINX_TEST',
            'NGINX_TEST_RESTORE_FAILED',
        ] as $required) {
            self::assertStringContainsString($required, $test);
        }

        $acl = $this->between(
            $this->broker,
            'static int wls_win_nginx_test_candidate_acl(',
            'static int wls_win_nginx_test_open_regular(',
        );
        self::assertStringContainsString(
            'L"O:SYD:P(A;;FA;;;SY)(A;;GRGWSD;;;%ls)(A;;GR;;;%ls)"',
            $acl,
        );
        self::assertStringContainsString('wls_data_plane_token', $acl);
        self::assertStringContainsString('WRITE_DAC', $acl);
        self::assertStringContainsString('WRITE_OWNER', $acl);

        $lkg = $this->between(
            $this->broker,
            'static int wls_win_nginx_lkg_context_load(',
            'static int wls_win_nginx_test_action_v2(',
        );
        foreach ([
            'candidate_config_digest',
            'source_config_digest',
            'source_lkg_manifest_digest',
            'wls_win_nginx_lkg_certificate_closure_v2(',
            'strcmp(context->config.digest, source_config_digest)',
        ] as $required) {
            self::assertStringContainsString($required, $lkg);
        }
        self::assertStringNotContainsString(
            'strcmp(source_config_digest, candidate_digest)',
            $lkg,
        );
        self::assertStringNotContainsString(
            'strcmp(candidate_digest, source_config_digest)',
            $lkg,
        );
    }

    public function testSnapshotAndReceiptPublicationRecoverOnlyExactDigestBoundTemporaries(): void
    {
        $snapshotName = $this->between(
            $this->broker,
            'static int wls_win_snapshot_publish_temp_name(',
            'static int wls_win_snapshot_rename_directory(',
        );
        self::assertStringContainsString('L".candidate-"', $snapshotName);
        self::assertStringContainsString('cursor[16] == L\'\\0\'', $snapshotName);

        $snapshotRecovery = $this->between(
            $this->broker,
            'static int wls_win_snapshot_recover_publish_temporaries_v2(',
            'static int wls_win_snapshot_publish_candidate(',
        );
        foreach ([
            'wls_win_snapshot_publish_temp_name(',
            'wls_win_snapshot_manifest_bound_to_digest(',
            'wls_win_snapshot_observe_leaf(',
            'wls_win_snapshot_observation_equivalent(',
            'wls_win_snapshot_rename_directory(',
            'snapshot_file_ids_digest',
        ] as $required) {
            self::assertStringContainsString($required, $snapshotRecovery);
        }
        self::assertStringContainsString(
            'previous_digest[0] != \'\\0\'',
            $snapshotRecovery,
        );
        self::assertStringContainsString('if (exact != 1) continue;', $snapshotRecovery);

        $snapshotPublish = $this->between(
            $this->broker,
            'static int wls_win_snapshot_publish_candidate(',
            'static int wls_win_snapshot_hash_enrolled_source(',
        );
        self::assertStringContainsString(
            'L".candidate-%hs-%lu-%hs"',
            $snapshotPublish,
        );
        self::assertStringContainsString(
            'wls_win_snapshot_observe_leaf(',
            $snapshotPublish,
        );

        $key = $this->between(
            $this->broker,
            'static int wls_win_snapshot_receipt_key(',
            'static int wls_win_snapshot_receipt_mac_v2(',
        );
        foreach ([
            'L".snapshot-receipt.key.candidate.%lu.%hs"',
            'L"O:SYD:P(A;;GA;;;SY)"',
            'CREATE_NEW',
            'wls_win_snapshot_receipt_key_read_handle(',
            'trust, L"snapshot-receipts"',
            'home_root, L"snapshots-v2"',
            'wls_same_file_identity(',
            'exact_candidates > 1U',
        ] as $required) {
            self::assertStringContainsString($required, $key);
        }
        $namespaceFence = $this->between(
            $this->broker,
            'static int wls_win_snapshot_receipt_key_namespace_empty(',
            'static int wls_win_snapshot_receipt_key(',
        );
        foreach ([
            'wls_nt_open_child(',
            'wls_handle_is_reparse(',
            'wls_win_snapshot_owner_system(',
            'wls_win_directory_names(',
            'name_count == 0U',
            'ERROR_FILE_NOT_FOUND',
            'ERROR_PATH_NOT_FOUND',
        ] as $required) {
            self::assertStringContainsString($required, $namespaceFence);
        }
        $receiptFence = \strpos(
            $key,
            'trust, L"snapshot-receipts"',
        );
        $snapshotFence = \strpos(
            $key,
            'home_root, L"snapshots-v2"',
        );
        $keyGeneration = \strpos($key, 'BCryptGenRandom(');
        self::assertIsInt($receiptFence);
        self::assertIsInt($snapshotFence);
        self::assertIsInt($keyGeneration);
        self::assertLessThan($keyGeneration, $receiptFence);
        self::assertLessThan($keyGeneration, $snapshotFence);

        $receiptRecovery = $this->between(
            $this->broker,
            'static int wls_win_snapshot_receipt_recover_temporaries_v2(',
            'static int wls_win_snapshot_receipt_publish_v2(',
        );
        foreach ([
            'wls_win_snapshot_receipt_temp_name(',
            'wls_win_snapshot_receipt_temp_content_valid(',
            'wls_win_snapshot_receipt_read_handle(',
            'wls_same_file_identity(',
            'wls_win_snapshot_rename_directory(',
        ] as $required) {
            self::assertStringContainsString($required, $receiptRecovery);
        }
        self::assertStringContainsString(
            'previous_digest[0] != \'\\0\'',
            $receiptRecovery,
        );

        $receiptPublish = $this->between(
            $this->broker,
            'static int wls_win_snapshot_receipt_publish_v2(',
            'static int wls_win_snapshot_receipt_read_v2(',
        );
        self::assertStringContainsString(
            'L".receipt-%hs-%lu-%ls.tmp"',
            $receiptPublish,
        );
        self::assertStringContainsString(
            'L"O:SYD:P(A;;GA;;;SY)(A;;GA;;;BA)"',
            $receiptPublish,
        );
        self::assertStringContainsString(
            'wls_win_snapshot_receipt_temp_content_valid(',
            $receiptPublish,
        );

        $seal = $this->between(
            $this->broker,
            'static int wls_win_snapshot_seal_action_v2(',
            'static int wls_win_snapshot_attest_action_v2(',
            occurrence: 2,
        );
        self::assertStringContainsString(
            'wls_win_snapshot_publish_candidate(',
            $seal,
        );
        self::assertStringContainsString(
            'wls_win_snapshot_receipt_publish_v2(',
            $seal,
        );
        $keyPreparation = \strpos(
            $seal,
            'wls_win_snapshot_receipt_key(',
        );
        $snapshotPublication = \strpos(
            $seal,
            'wls_win_snapshot_publish_candidate(',
        );
        self::assertIsInt($keyPreparation);
        self::assertIsInt($snapshotPublication);
        self::assertLessThan($snapshotPublication, $keyPreparation);
        self::assertStringContainsString(
            'wls_win_snapshot_receipt_reply_v2(',
            $seal,
        );
        self::assertStringNotContainsString(
            'WINDOWS_SNAPSHOT_RECEIPT_UNSUPPORTED',
            $seal,
        );
    }

    public function testLauncherRequiresExactSignedSchemaTwoRuntimeClosure(): void
    {
        $contract = $this->between(
            $this->launcher,
            'static int wls_launcher_exact_durable_contract_v2(',
            'static int wls_launcher_file_identity_equal(',
        );
        foreach ([
            'contract->count == 8U',
            'security_ledger_read_schema',
            'security_ledger_write_schema',
            'snapshot_receipt_read_schema',
            'snapshot_receipt_write_schema',
            'snapshot_namespace',
            'snapshots-v2',
            'nonce_wal_schema',
            'nginx_test_schema',
        ] as $required) {
            self::assertStringContainsString($required, $contract);
        }

        $verify = $this->between(
            $this->launcher,
            'static int wls_verify_slot_durable_state_contract_v2_at_path_with_key(',
            'static int wls_verify_slot_durable_state_contract_v2_with_key(',
        );
        foreach ([
            '"app/controller.php", 420ULL',
            '"bin/nginx.exe", 493ULL',
            '"bin/php.exe", 493ULL',
            '"bin/wls-gateway-broker.exe", 493ULL',
            '"bin/wls-gateway-launcher.exe", 493ULL',
            '"release/manifest.json", 384ULL',
            '"release/manifest.sig", 384ULL',
            'wls_verify_release_bytes(',
            'stable_launcher_rollback_target_proof',
            'wls_launcher_json_generation(',
            'wls_launcher_file_observation_equal(',
            'wls_launcher_file_identity_equal(',
        ] as $required) {
            self::assertStringContainsString($required, $verify);
        }
        self::assertMatchesRegularExpression(
            '/sodium_memcmp\(\s*declared_generation,\s*computed_generation,\s*64U\s*\)/',
            $verify,
        );

        $observe = $this->between(
            $this->launcher,
            'static int wls_launcher_file_observe(',
            'static int wls_launcher_file_observation_equal(',
        );
        foreach ([
            'FILE_FLAG_OPEN_REPARSE_POINT',
            'before.nNumberOfLinks != 1U',
            'after.nNumberOfLinks != 1U',
            'wls_launcher_file_identity_equal(&before, &after)',
        ] as $required) {
            self::assertStringContainsString($required, $observe);
        }

        $launch = $this->between(
            $this->launcher,
            'static int wls_launch(',
            'static VOID WINAPI wls_service_main(',
        );
        $proof = \strpos(
            $launch,
            'wls_verify_slot_durable_state_contract_v2(',
        );
        $brokerPath = \strpos(
            $launch,
            'wls_join(broker, WLS_PATH_CHARS, slot',
        );
        self::assertIsInt($proof);
        self::assertIsInt($brokerPath);
        self::assertLessThan($brokerPath, $proof);
    }

    public function testRollbackTargetProofIsLazyAndSelfTestExercisesPositiveAndNegativeSlots(): void
    {
        $reconcile = $this->between(
            $this->launcher,
            'static int wls_reconcile_upgrade_locked(',
            'static int wls_reconcile_upgrade(',
        );
        self::assertStringContainsString(
            'int rollback_target_verified = 0;',
            $reconcile,
        );
        self::assertStringContainsString(
            'wls_require_rollback_slot_contract_v2(',
            $reconcile,
        );
        $firstProof = \strpos(
            $reconcile,
            'wls_require_rollback_slot_contract_v2(',
        );
        $firstRollbackWrite = \strpos(
            $reconcile,
            'home, &upgrade, boot_id, "ROLLBACK_PENDING"',
        );
        self::assertIsInt($firstProof);
        self::assertIsInt($firstRollbackWrite);
        self::assertLessThan($firstRollbackWrite, $firstProof);

        $selfTest = $this->between(
            $this->launcher,
            'static int wls_launcher_rollback_target_proof_self_test(void)',
            'static const wchar_t *wls_argument(',
        );
        foreach ([
            'GetTempPathW(',
            'WelineWlsLauncherProof-%lu-%hs',
            'CreateDirectoryW(home, &home_security)',
            'crypto_sign_keypair(',
            'crypto_sign_detached(',
            'wls_verify_slot_durable_state_contract_v2_with_key(',
            'WLS_BUILD_INSTALLED("true", 0)',
            'WLS_BUILD_INSTALLED("false", 0)',
            'WLS_BUILD_INSTALLED("true", 1)',
            'wls_launcher_proof_self_test_delete_file(',
            'wls_launcher_proof_self_test_remove_directory(home)',
            'if (cleanup_failed) result = 1;',
        ] as $required) {
            self::assertStringContainsString($required, $selfTest);
        }
        self::assertStringContainsString(
            'stable_launcher_rollback_target_proof',
            $selfTest,
        );
        self::assertStringContainsString(
            '0000000000000000000000000000000000000000000000000000000000000000',
            $selfTest,
        );
        self::assertStringNotContainsString('system(', $selfTest);
        self::assertStringNotContainsString('ShellExecute', $selfTest);

        $selfTestSecurity = $this->between(
            $this->launcher,
            'static int wls_launcher_proof_self_test_security(',
            'static int wls_launcher_proof_self_test_digest(',
        );
        self::assertStringContainsString('OpenProcessToken(', $selfTestSecurity);
        self::assertStringContainsString('TokenUser', $selfTestSecurity);
        self::assertStringContainsString(
            'L"D:P(A;OICI;FA;;;%ls)"',
            $selfTestSecurity,
        );

        $main = $this->between($this->launcher, 'int wmain(', null);
        self::assertStringContainsString(
            'L"--rollback-target-proof-self-test"',
            $main,
        );
        self::assertStringContainsString(
            'wls_launcher_rollback_target_proof_self_test()',
            $main,
        );
    }

    public function testLauncherAndBrokerRequireDurableExactDataPlanePathAcls(): void
    {
        foreach ([
            'S-1-5-80-3070340479-3168417268-2770794561-992406300-110075626',
            'S-1-5-80-3611316956-1833621424-61377994-3153356469-2496947245',
        ] as $serviceSid) {
            self::assertStringContainsString($serviceSid, $this->launcher);
            self::assertStringContainsString($serviceSid, $this->installer);
        }

        $acl = $this->between(
            $this->launcher,
            'static int wls_launcher_slot_acl_valid_profile(',
            'static int wls_launcher_slot_acl_valid_mode(',
        );
        foreach ([
            'system_owner ? (PSID)system_buffer',
            '(control & SE_DACL_PROTECTED) == 0U',
            'dacl->AclRevision != ACL_REVISION',
            '+ (data_plane_acl ? 1U : 0U)',
            'EqualSid(ace_sid, data_plane_sid)',
            'ace->Mask != data_plane_mask',
            'data_plane_count == (data_plane_acl ? 1U : 0U)',
        ] as $required) {
            self::assertStringContainsString($required, $acl);
        }
        self::assertStringContainsString(
            'static int wls_launcher_slot_data_plane_directory_acl_valid(',
            $this->launcher,
        );
        $directoryAcl = $this->between(
            $this->launcher,
            'static int wls_launcher_data_plane_directory_acl_valid(',
            'static int wls_launcher_slot_data_plane_directory_acl_valid(',
        );
        foreach ([
            'object,',
            '1,',
            'controller_sid,',
            'data_plane_sid,',
            'system_owner,',
            'FILE_TRAVERSE',
        ] as $required) {
            self::assertStringContainsString($required, $directoryAcl);
        }

        $digest = $this->between(
            $this->launcher,
            'static int wls_file_digest(',
            'static int wls_utf8_to_wide(',
        );
        self::assertSame(
            2,
            \substr_count($digest, 'wls_launcher_nginx_acl_valid('),
        );
        $component = $this->between(
            $this->launcher,
            'static int wls_verify_component_v2(',
            'static int wls_verify_slot_durable_state_contract_v2_at_path_with_key(',
        );
        self::assertStringContainsString(
            'controller_acl && strcmp(relative, "bin/nginx.exe") == 0',
            $component,
        );

        $install = $this->between(
            $this->installer,
            'private function applyWindowsNginxExecutableAcl(',
            'private function applyWindowsAcl(',
        );
        foreach ([
            'applyExactPathSddl(',
            '(A;;FA;;;S-1-5-18)',
            '(A;;FA;;;S-1-5-32-544)',
            '0x1200a9',
            '0x20',
            'WINDOWS_CONTROLLER_SERVICE_SID',
            'WINDOWS_DATA_PLANE_SERVICE_SID',
            'four-ACE ACL verification failed',
        ] as $required) {
            self::assertStringContainsString($required, $install);
        }
        self::assertStringContainsString(
            '$this->applyWindowsNginxExecutableAcl(',
            $this->installer,
        );
        self::assertStringContainsString(
            '$this->applyWindowsDataPlaneTraversalAcl(',
            $this->installer,
        );

        $runtime = $this->between(
            $this->broker,
            'static int wls_win_service_path_acl_valid(',
            'static void wls_win_close_public_sockets(',
        );
        foreach ([
            'information.AceCount != 3U + (data_plane_acl ? 1U : 0U)',
            'system_owner ? (PSID)system_buffer',
            'EqualSid(ace_sid, controller_sid)',
            'EqualSid(ace_sid, wls_data_plane_sid)',
            'wls_win_verify_data_plane_binary_relative(',
            'wls_win_verify_data_plane_traversal_relative(',
            'home_root, binary_relative',
            'home_root, slot_relative, 0',
            'home_root, bin_relative, 0',
        ] as $required) {
            self::assertStringContainsString($required, $runtime);
        }
        self::assertStringNotContainsString(
            'home_root, binary_relative, 0, read_access',
            $runtime,
        );

        $selfTest = $this->between(
            $this->launcher,
            'static int wls_launcher_rollback_target_proof_self_test(void)',
            'static const wchar_t *wls_argument(',
        );
        self::assertStringContainsString(
            'component_paths[1], 0, slot_service_sid, 1',
            $selfTest,
        );
        self::assertStringContainsString(
            'component_paths[1], 0, slot_service_sid',
            $selfTest,
        );
        self::assertStringContainsString(
            'bin, 1, slot_service_sid',
            $selfTest,
        );
        self::assertStringContainsString(
            'bin, slot_service_sid, 0',
            $selfTest,
        );

        foreach ([
            'service-test',
            '--profile=default',
            'wlsApplyExactGatewayReadAcl',
            'WLS_WINDOWS_TEST_SERVICE',
        ] as $removedLegacyContract) {
            self::assertStringNotContainsString(
                $removedLegacyContract,
                $this->windowsRecoveryFixture,
            );
        }
    }

    public function testLauncherOwnsAStableNestedJobAcrossBrokerRestarts(): void
    {
        $create = $this->between(
            $this->launcher,
            'static HANDLE wls_create_data_plane_job(',
            'static int wls_run_supervisor(',
        );
        self::assertStringContainsString(
            'Global\\\\WelineWlsGatewayV2DataPlane-',
            $create,
        );
        self::assertStringContainsString('JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE', $create);
        self::assertStringContainsString(
            'JOB_OBJECT_LIMIT_DIE_ON_UNHANDLED_EXCEPTION',
            $create,
        );

        $supervisor = $this->between(
            $this->launcher,
            'static int wls_run_supervisor(',
            'static VOID WINAPI wls_service_main(',
        );
        self::assertStringContainsString('wls_create_data_plane_job(', $supervisor);
        self::assertStringContainsString('data_plane_job_name', $supervisor);
        self::assertLessThan(
            \strrpos($supervisor, 'CloseHandle(data_plane_job);'),
            \strpos($supervisor, 'wls_launch('),
        );
    }

    public function testGuardianIsTheOnlyScmEntrypointAndBothVariantsRemainWarningClean(): void
    {
        $template = \json_decode(
            $this->windowsServiceTemplate,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($template);
        self::assertSame([
            'service',
            'display_name',
            'account',
            'start',
            'failure_restart_seconds',
            'repeat_last_failure_action',
            'guardian',
            'arguments',
        ], \array_keys($template));
        self::assertSame('{{GUARDIAN}}', $template['guardian']);
        self::assertSame([
            '--service',
            '--home={{HOME}}',
            '--run={{RUN_DIR}}',
        ], $template['arguments']);

        $configuration = $this->between(
            $this->installer,
            'private function configureWindowsServiceDefinition(',
            'private function enableWindowsServiceDefinition(',
        );
        self::assertStringContainsString(
            '$this->paths->guardianFile()',
            $configuration,
        );
        self::assertStringContainsString('" --service', $configuration);
        self::assertStringNotContainsString('launcherFile()', $configuration);
        self::assertStringNotContainsString('--profile', $configuration);

        foreach ([
            "add_executable(\n        wls-gateway-guardian",
            'WLS_GUARDIAN_EXECUTABLE=1',
            'RUNTIME DESTINATION guardian/v1',
            'wls-gateway-launcher PRIVATE /W4 /WX /sdl /wd4505',
            'wls-gateway-guardian PRIVATE /W4 /WX /sdl /wd4505',
        ] as $required) {
            self::assertStringContainsString($required, $this->cmake);
        }
        self::assertSame(2, \substr_count(
            $this->cmake,
            '-Wno-unused-function',
        ));

        $main = $this->between($this->launcher, 'int wmain(', null);
        self::assertStringContainsString(
            'return wls_guardian_child_command(argc, argv);',
            $main,
        );
        self::assertStringContainsString(
            'if (service_mode) return 64;',
            $main,
        );
        self::assertStringContainsString(
            'if (!service_mode || argc != 4) return 64;',
            $main,
        );
        self::assertStringContainsString(
            'wls_guardian_fixed_runtime_paths(',
            $main,
        );
        self::assertStringContainsString(
            'wls_guardian_scm_definition_valid(',
            $this->guardian,
        );
    }

    public function testGuardianRequiresPreshutdownAndAnExactProtectedScmDacl(): void
    {
        $definition = $this->between(
            $this->guardian,
            'static int wls_guardian_scm_definition_valid(',
            'static int wls_guardian_core_self_test(',
        );
        foreach ([
            'SERVICE_CONFIG_PRESHUTDOWN_INFO',
            'SERVICE_PRESHUTDOWN_INFO',
            'dwPreshutdownTimeout != 330000U',
            'wls_guardian_scm_service_dacl_exact(',
        ] as $required) {
            self::assertStringContainsString($required, $definition);
        }
        self::assertStringContainsString(
            'QueryServiceObjectSecurity(',
            $this->guardian,
        );

        $dacl = $this->between(
            $this->guardian,
            'static int wls_guardian_scm_service_dacl_exact(',
            'static int wls_guardian_scm_definition_valid(',
        );
        foreach ([
            'SE_DACL_PROTECTED',
            'ACCESS_ALLOWED_ACE_TYPE',
            'ace->Header.AceFlags != 0U',
            'SERVICE_ALL_ACCESS',
            'WinLocalSystemSid',
            'WinBuiltinAdministratorsSid',
            'system_count != 1U',
            'administrators_count != 1U',
            'information.AceCount != 2U',
        ] as $required) {
            self::assertStringContainsString($required, $dacl);
        }

        $selfTest = $this->between(
            $this->guardian,
            'static int wls_guardian_scm_service_dacl_self_test(',
            'static int wls_guardian_core_self_test(',
        );
        foreach ([
            'D:P(A;;0xF01FF;;;SY)(A;;0xF01FF;;;BA)',
            '(A;;GR;;;BU)',
            '(A;ID;0xF01FF;;;SY)',
            '(A;;GR;;;BA)',
        ] as $negativeFixture) {
            self::assertStringContainsString($negativeFixture, $selfTest);
        }

        $service = $this->between(
            $this->launcher,
            'static DWORD WINAPI wls_service_control(',
            'static HANDLE wls_create_supervision_job(',
        );
        self::assertStringContainsString('SERVICE_CONTROL_PRESHUTDOWN', $service);
        self::assertStringContainsString('wls_service_stop_intent_persisted()', $service);
        self::assertStringContainsString('WLS_GUARDIAN_DRAIN_MILLISECONDS', $this->guardianRuntime);
        self::assertStringContainsString('SERVICE_ACCEPT_PRESHUTDOWN', $this->launcher);
    }

    public function testPreshutdownPersistenceFailureCannotRestartTheDataPlaneInTheSameBoot(): void
    {
        $intent = $this->between(
            $this->guardian,
            'static const wchar_t wls_service_stop_intent_registry_path[]',
            'static HANDLE wls_guardian_singleton(',
        );
        foreach ([
            'RegCreateKeyExW(',
            'REG_OPTION_VOLATILE',
            'RegFlushKey(',
            'WLS-SERVICE-PRESHUTDOWN/1',
            'host_boot_id',
            'D:P(A;;KA;;;SY)(A;;KA;;;BA)',
            'wls_guardian_service_stop_intent_volatile_active(',
        ] as $required) {
            self::assertStringContainsString($required, $intent);
        }

        $service = $this->between(
            $this->launcher,
            'static DWORD WINAPI wls_service_control(',
            'static HANDLE wls_create_supervision_job(',
        );
        self::assertStringContainsString(
            'InterlockedExchange(&wls_service_preshutdown_unsealed, 1)',
            $service,
        );
        $serviceMain = $this->between(
            $this->launcher,
            'static VOID WINAPI wls_service_main(',
            'struct wls_launcher_proof_self_test_component',
        );
        self::assertStringContainsString(
            'wls_service_preshutdown_unsealed',
            $serviceMain,
        );
        self::assertStringContainsString('Sleep(10000U)', $serviceMain);

        $guardianRun = $this->between(
            $this->guardianRuntime,
            'static int wls_guardian_service_run(',
            null,
        );
        $active = \strpos(
            $guardianRun,
            'wls_guardian_service_stop_intent_active(run_directory)',
        );
        $prepare = \strpos(
            $guardianRun,
            'wls_guardian_runtime_prepare_child(',
        );
        self::assertIsInt($active);
        self::assertIsInt($prepare);
        self::assertLessThan($prepare, $active);

        self::assertStringContainsString(
            'now_monotonic - last_checkpoint >= 10000ULL',
            $this->launcher,
        );
    }

    public function testRecoveryScmMutationIsSealedAndTransactional(): void
    {
        $apply = $this->between(
            $this->guardianRecovery,
            'static int wls_guardian_recovery_platform_apply_scm(',
            'static int wls_guardian_recovery_runtime_path_binding(',
        );

        foreach ([
            'wls_guardian_recovery_scm_snapshot_capture(',
            'SERVICE_CONFIG_PRESHUTDOWN_INFO',
            'dwPreshutdownTimeout = 330000U',
            'PROTECTED_DACL_SECURITY_INFORMATION',
            'SetSecurityInfo(',
            'wls_guardian_scm_definition_valid(',
            'SERVICE_DEMAND_START',
            'wls_guardian_recovery_scm_delete_verified(',
            'wls_guardian_recovery_scm_snapshot_restore(',
            'wls_guardian_recovery_scm_snapshot_matches(',
            'wls_guardian_recovery_scm_force_safe_start(',
            'rollback_hard_failure = 1',
        ] as $required) {
            self::assertStringContainsString($required, $apply);
        }
        foreach ([
            'if (!DeleteService(*service)) return 1;',
            'ERROR_SERVICE_MARKED_FOR_DELETE',
            'SERVICE_CHANGE_CONFIG | SERVICE_QUERY_CONFIG',
        ] as $required) {
            self::assertStringContainsString($required, $this->guardianRecovery);
        }
        self::assertStringNotContainsString('(void)DeleteService(', $apply);
        self::assertStringNotContainsString(
            '(void)wls_guardian_recovery_scm_snapshot_restore(',
            $apply,
        );
        self::assertStringContainsString(
            'int readback_failed = wls_guardian_recovery_scm_snapshot_matches(',
            $apply,
        );
        self::assertStringContainsString('result = 2;', $apply);

        self::assertLessThan(
            \strpos($apply, 'wls_guardian_scm_definition_valid('),
            \strpos($apply, 'SERVICE_AUTO_START'),
            'The recovery path must keep a new SCM object non-automatic until sealing succeeds.',
        );
        self::assertLessThan(
            \strpos($apply, 'result = 0;'),
            \strrpos($apply, 'wls_guardian_scm_definition_valid('),
            'Strict SCM read-back must complete before recovery publishes success.',
        );
    }

    public function testContinuityPublisherPersistsItsMonotonicFloorInsideTheHeadLock(): void
    {
        $floor = $this->between(
            $this->broker,
            'static int wls_guardian_existing_continuity_floor(',
            'static int wls_guardian_unique_issued_monotonic(',
        );
        foreach ([
            'FILE_FLAG_OPEN_REPARSE_POINT',
            'standard.NumberOfLinks != 1U',
            'wls_win_controller_receipt_acl_valid(file)',
            'wls_same_file_identity(&before, &after)',
            'wls_hmac_sha256(',
            'strcmp(host_id, expected_host_id) != 0',
            'strcmp(boot_id, expected_boot_id) == 0 ? parsed : 0ULL',
        ] as $required) {
            self::assertStringContainsString($required, $floor);
        }

        $publishStart = \strrpos(
            $this->broker,
            'static int wls_guardian_health_publish(',
        );
        self::assertIsInt($publishStart);
        $publish = \substr($this->broker, $publishStart);
        $lock = \strpos($publish, 'wls_guardian_transition_lock_acquire(');
        $key = \strpos($publish, 'wls_read_hex_authority_file(token_path');
        $durable = \strpos(
            $publish,
            'wls_guardian_existing_continuity_floor(',
        );
        $issued = \strpos(
            $publish,
            'wls_guardian_unique_issued_monotonic(',
        );
        $continuityWrite = \strpos(
            $publish,
            "wls_atomic_sddl_bytes(\n            continuity_path",
        );
        $unlock = \strpos(
            $publish,
            'wls_guardian_transition_lock_release(',
        );
        foreach ([$lock, $key, $durable, $issued, $continuityWrite, $unlock] as $offset) {
            self::assertIsInt($offset);
        }
        self::assertTrue($lock < $key);
        self::assertTrue($key < $durable);
        self::assertTrue($durable < $issued);
        self::assertTrue($issued < $continuityWrite);
        self::assertTrue($continuityWrite < $unlock);
        self::assertStringContainsString(
            '(void)DeleteFileW(continuity_path);',
            $publish,
        );
        self::assertStringContainsString(
            'publication_checked_ms - issued_monotonic_ms',
            $publish,
        );

        $unique = $this->between(
            $this->broker,
            'static int wls_guardian_unique_issued_monotonic(',
            'static int wls_guardian_process_system_service(',
        );
        self::assertStringContainsString(
            'durable_floor < context->last_guardian_issued_monotonic_ms',
            $unique,
        );
        self::assertStringContainsString(
            '&context, observed, first, &second',
            $unique,
        );

        $accept = $this->between(
            $this->guardianRuntime,
            'static int wls_guardian_runtime_sample_accept(',
            'static int wls_guardian_runtime_self_test(',
        );
        $copy = \strpos($accept, 'next = *observation;');
        $commit = \strpos($accept, '*observation = next;');
        self::assertIsInt($copy);
        self::assertIsInt($commit);
        self::assertTrue($copy < $commit);
        self::assertStringContainsString(
            "sample->issued_monotonic_ms\n                    == next.last_issued_monotonic_ms",
            $accept,
        );
        self::assertStringContainsString(
            'sample->raw_sha256',
            $accept,
        );
    }

    public function testNeutralTlsServingPairIsBrokerSealedAndRevalidatedBeforeEverySpawn(): void
    {
        $servingRootAcl = $this->between(
            $this->broker,
            'static int wls_win_neutral_tls_acl_valid(',
            'static int wls_win_neutral_tls_apply_acl(',
        );
        foreach ([
            'administrators_buffer',
            'administrators_count',
            'information.AceCount != 3U',
            'FILE_ALL_ACCESS',
            'FILE_TRAVERSE',
            'FILE_GENERIC_READ',
        ] as $required) {
            self::assertStringContainsString($required, $servingRootAcl);
        }
        $servingLeafApply = $this->between(
            $this->broker,
            'static int wls_win_neutral_tls_apply_acl(',
            'static HANDLE wls_win_neutral_tls_root_open(',
        );
        self::assertStringContainsString(
            'L"O:SYD:P(A;;FA;;;SY)(A;;FA;;;BA)(A;;0x120089;;;%ls)"',
            $servingLeafApply,
        );
        self::assertStringNotContainsString(
            'L"O:SYD:P(A;;FA;;;SY)(A;;FA;;;BA)(A;;GR;;;%ls)"',
            $servingLeafApply,
        );

        $stateParentAcl = $this->between(
            $this->broker,
            'static int wls_win_neutral_tls_state_parent_acl_valid(',
            'static int wls_win_neutral_tls_state_source_acl_valid(',
        );
        foreach ([
            'administrators_buffer',
            'SE_DACL_PROTECTED',
            'OBJECT_INHERIT_ACE | CONTAINER_INHERIT_ACE',
            'information.AceCount != 3U',
            '0x001301BFUL',
        ] as $required) {
            self::assertStringContainsString($required, $stateParentAcl);
        }

        $publication = $this->between(
            $this->broker,
            'static int wls_win_neutral_tls_publish_and_validate(',
            'static int wls_win_prepare_data_plane_runtime(',
        );
        foreach ([
            'wls_win_neutral_tls_publish_from_open_sources(',
            'wls_win_neutral_tls_consumer_validate(',
            'wls_win_neutral_tls_state_parent_acl_valid(state)',
        ] as $required) {
            self::assertStringContainsString($required, $publication);
        }
        self::assertStringNotContainsString(
            'wls_win_controller_receipt_acl_valid(state)',
            $publication,
        );

        $source = $this->between(
            $this->broker,
            'static int wls_win_neutral_tls_source_open(',
            'static int wls_win_neutral_tls_receipt_canonical(',
        );
        foreach ([
            'int certificate_source',
            'wls_win_neutral_tls_source_certificate_acl_valid(',
            'wls_win_neutral_tls_source_key_acl_valid(',
            'wls_handle_is_reparse(',
            'NumberOfLinks != 1U',
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }

        $sourceAcl = $this->between(
            $this->broker,
            'static int wls_win_neutral_tls_state_source_acl_valid(',
            'static int wls_win_neutral_tls_source_certificate_acl_valid(',
        );
        foreach ([
            'INHERITED_ACE',
            'SE_DACL_AUTO_INHERITED',
            'SE_DACL_PROTECTED',
            'administrators_buffer',
            'controller_sid',
            'information.AceCount != 3U',
            'ace->Header.AceFlags != INHERITED_ACE',
            'FILE_ALL_ACCESS',
            '0x001301BFUL',
        ] as $required) {
            self::assertStringContainsString($required, $sourceAcl);
        }
        $sourceValidators = $this->between(
            $this->broker,
            'static int wls_win_neutral_tls_source_certificate_acl_valid(',
            'static int wls_win_neutral_tls_source_open(',
        );
        self::assertStringNotContainsString(
            'wls_win_controller_receipt_acl_valid(',
            $sourceValidators,
        );
        self::assertSame(
            2,
            \substr_count(
                $sourceValidators,
                'wls_win_neutral_tls_state_source_acl_valid('
            ),
        );

        foreach ([
            'L"state"',
            'L"neutral-tls"',
            'L"neutral-cert.pem"',
            'L"neutral-key.pem"',
            'L"neutral-tls.receipt"',
            'wls_win_snapshot_receipt_mac_v2(',
            'FILE_FLAG_OPEN_REPARSE_POINT',
            'wls_same_file_identity(',
            'wls_win_read_digest_bounded(',
            'wls_win_snapshot_rename_directory(',
        ] as $required) {
            self::assertStringContainsString($required, $this->broker);
        }

        $prepare = $this->between(
            $this->broker,
            'static int wls_win_prepare_data_plane_runtime(',
            'static void wls_win_close_public_sockets(',
        );
        self::assertStringNotContainsString('L"state"', $prepare);
        self::assertStringNotContainsString(
            'L"state\\neutral-cert.pem"',
            $prepare,
        );
        self::assertStringNotContainsString(
            'L"state\\neutral-key.pem"',
            $prepare,
        );

        $consumer = $this->between(
            $this->broker,
            'static int wls_win_neutral_tls_consumer_validate(',
            'static int wls_win_neutral_tls_publish_and_validate(',
        );
        foreach ([
            'L"neutral-cert.pem"',
            'L"neutral-key.pem"',
            'L"neutral-tls.receipt"',
            'wls_win_neutral_tls_read_leaf(',
        ] as $required) {
            self::assertStringContainsString($required, $consumer);
        }
        foreach ([
            'wls_access_check_handle(',
            'GENERIC_WRITE',
            'DELETE',
            'WRITE_DAC',
            'WRITE_OWNER',
        ] as $required) {
            self::assertStringContainsString($required, $this->broker);
        }

        $test = $this->between(
            $this->broker,
            'static int wls_win_nginx_test_action_v2(',
            'static int wls_win_nginx_lifecycle_action_v2(',
        );
        self::assertStringContainsString(
            'wls_win_neutral_tls_publish_and_validate(',
            $test,
        );
        self::assertLessThan(
            \strpos($test, 'wls_win_nginx_spawn('),
            \strpos($test, 'wls_win_neutral_tls_consumer_validate('),
        );

        $lifecycle = $this->between(
            $this->broker,
            'static int wls_win_nginx_lifecycle_action_v2(',
            null,
        );
        self::assertStringContainsString(
            'wls_win_neutral_tls_publish_and_validate(',
            $lifecycle,
        );
        self::assertGreaterThanOrEqual(
            3,
            \substr_count($lifecycle, 'wls_win_neutral_tls_consumer_validate('),
        );
    }

    private function between(
        string $source,
        string $startNeedle,
        ?string $endNeedle,
        int $occurrence = 1,
    ): string {
        $offset = 0;
        $start = false;
        for ($index = 0; $index < $occurrence; ++$index) {
            $start = \strpos($source, $startNeedle, $offset);
            self::assertIsInt($start, 'Missing source marker: ' . $startNeedle);
            $offset = $start + \strlen($startNeedle);
        }
        if ($endNeedle === null) {
            return \substr($source, $start);
        }
        $end = \strpos($source, $endNeedle, $offset);
        self::assertIsInt($end, 'Missing source marker: ' . $endNeedle);
        return \substr($source, $start, $end - $start);
    }
}
