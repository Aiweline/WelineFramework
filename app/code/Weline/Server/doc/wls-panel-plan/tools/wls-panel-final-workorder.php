<?php
declare(strict_types=1);

/**
 * Produces a compact read-only work order for the final WLS Panel AppStore
 * marketplace live gates.
 *
 * This tool does not sync files, start WLS, write manifests, read token values,
 * or call the live AppStore API. It runs the existing final preflight and turns
 * the result into a copy-safe local/production execution sequence.
 */

const WLS_PANEL_WORKORDER_LOCAL_ROOT = 'https://app.weline.test:9523';
const WLS_PANEL_WORKORDER_PRODUCTION_ROOT = 'https://app.aiweline.com';
const WLS_PANEL_WORKORDER_EXIT_FAILED = 1;

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function wlsPanelWorkorderParseArgs(array $argv): array
{
    $args = [];
    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        $arg = (string)$argv[$i];
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$key, $value] = explode('=', $arg, 2);
            $args[$key] = $value;
            continue;
        }

        $next = $argv[$i + 1] ?? null;
        if (is_string($next) && !str_starts_with($next, '--')) {
            $args[$arg] = $next;
            $i++;
            continue;
        }

        $args[$arg] = '1';
    }

    return $args;
}

/**
 * @param array<string, mixed> $payload
 */
function wlsPanelWorkorderFinish(array $payload, int $exitCode): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($exitCode);
}

function wlsPanelWorkorderPath(string $base, string $relative): string
{
    return rtrim($base, "\\/") . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
}

/**
 * @return array<string, mixed>|null
 */
function wlsPanelWorkorderExtractJson(string $output): ?array
{
    $start = strpos($output, '{');
    $end = strrpos($output, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }

    $payload = json_decode(substr($output, $start, $end - $start + 1), true);
    return is_array($payload) ? $payload : null;
}

/**
 * @param list<string> $args
 * @return array<string, mixed>
 */
function wlsPanelWorkorderRunTool(string $workspaceRoot, string $relativeTool, array $args = []): array
{
    $command = array_merge([PHP_BINARY, wlsPanelWorkorderPath($workspaceRoot, $relativeTool)], $args);
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, $workspaceRoot);
    if (!is_resource($process)) {
        return [
            'tool' => $relativeTool,
            'exit_code' => 127,
            'parsed' => false,
            'payload' => null,
            'error' => 'proc_open_failed',
        ];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $output = trim((string)$stdout . PHP_EOL . (string)$stderr);

    return [
        'tool' => $relativeTool,
        'exit_code' => $exitCode,
        'parsed' => wlsPanelWorkorderExtractJson($output) !== null,
        'payload' => wlsPanelWorkorderExtractJson($output),
        'output_bytes' => strlen($output),
    ];
}

/**
 * @param list<string> $blockers
 */
function wlsPanelWorkorderState(bool $goalComplete, bool $readyForLocalLive, array $blockers): string
{
    if ($goalComplete) {
        return 'goal_complete';
    }

    if ($readyForLocalLive) {
        return 'ready_for_local_live_capture';
    }

    if ($blockers !== []) {
        return 'blocked_before_local_live_capture';
    }

    return 'preflight_inconclusive';
}

/**
 * @param list<string> $blockers
 */
function wlsPanelWorkorderNeedsUserAuthorization(array $blockers): bool
{
    foreach ($blockers as $blocker) {
        if (
            str_contains($blocker, 'schema')
            || str_contains($blocker, 'manifest')
            || str_contains($blocker, 'source')
        ) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<string> $blockers
 */
function wlsPanelWorkorderNeedsUserSecret(array $blockers): bool
{
    return in_array('bearer_token_env_present', $blockers, true);
}

/**
 * @param list<array<string, mixed>> $actions
 * @return list<array<string, mixed>>
 */
function wlsPanelWorkorderDeferredActionPlan(array $actions): array
{
    $plan = [];
    foreach ($actions as $action) {
        if (!is_array($action)) {
            continue;
        }

        $id = trim((string)($action['id'] ?? ''));
        if ($id === '') {
            continue;
        }

        $entry = [
            'id' => $id,
            'phase' => trim((string)($action['phase'] ?? '')),
            'blocked_checks' => is_array($action['blocked_checks'] ?? null)
                ? array_values(array_map('strval', $action['blocked_checks']))
                : [],
            'requires_user_authorization' => ($action['requires_user_authorization'] ?? false) === true,
            'requires_user_secret' => ($action['requires_user_secret'] ?? false) === true,
            'safe_to_run_now' => ($action['safe_to_run_now'] ?? false) === true,
        ];

        foreach ([
            'working_directory',
            'command',
            'preflight_self_test_command',
            'preflight_command',
            'pre_authorization_review_command',
            'rollback_review_command',
            'post_sync_gate_command',
            'dry_run_command',
            'authorized_write_command',
            'authorized_source_write_command',
            'authorized_catalog_write_command',
            'post_write_gate_command',
            'precondition',
            'side_effects',
        ] as $key) {
            if (array_key_exists($key, $action)) {
                $entry[$key] = (string)$action[$key];
            }
        }

        if (is_array($action['schema_sync'] ?? null)) {
            $entry['schema_sync'] = $action['schema_sync'];
        }

        $plan[] = $entry;
    }

    return $plan;
}

/**
 * @param list<array<string, mixed>> $actions
 */
function wlsPanelWorkorderNoDeferredActionRunsNow(array $actions): bool
{
    foreach ($actions as $action) {
        if (($action['safe_to_run_now'] ?? false) === true) {
            return false;
        }
    }

    return true;
}

/**
 * @return array<string, mixed>
 */
function wlsPanelWorkorderBuild(array $preflight): array
{
    $summary = $preflight['summary'] ?? [];
    $summary = is_array($summary) ? $summary : [];
    $checks = $preflight['checks'] ?? [];
    $checks = is_array($checks) ? $checks : [];
    $toolResults = $preflight['tool_results'] ?? [];
    $toolResults = is_array($toolResults) ? $toolResults : [];
    $blockers = $summary['readiness_blockers'] ?? [];
    $blockers = is_array($blockers) ? array_values(array_map('strval', $blockers)) : [];

    $readyForLocalLive = ($preflight['ready_for_live_local_appstore_e2e'] ?? false) === true;
    $goalComplete = ($preflight['goal_complete'] ?? false) === true;
    $state = wlsPanelWorkorderState($goalComplete, $readyForLocalLive, $blockers);

    $localEndpoint = (string)($summary['local_endpoint'] ?? (WLS_PANEL_WORKORDER_LOCAL_ROOT . '/api/v1/platform/module/list'));
    $productionEndpoint = (string)($summary['production_endpoint'] ?? (WLS_PANEL_WORKORDER_PRODUCTION_ROOT . '/api/v1/platform/module/list'));
    $readinessActions = is_array($summary['readiness_next_actions'] ?? null)
        ? array_values($summary['readiness_next_actions'])
        : [];
    $deferredActionPlan = wlsPanelWorkorderDeferredActionPlan($readinessActions);

    return [
        'ok' => true,
        'workorder_ready' => true,
        'current_state' => $state,
        'ready_for_local_live_capture' => $readyForLocalLive,
        'goal_complete' => $goalComplete,
        'user_authorization_required' => wlsPanelWorkorderNeedsUserAuthorization($blockers),
        'user_secret_required' => wlsPanelWorkorderNeedsUserSecret($blockers),
        'environment_policy' => [
            'local_development' => [
                'root' => WLS_PANEL_WORKORDER_LOCAL_ROOT,
                'endpoint' => $localEndpoint,
                'checkout' => 'E:\\WelineFramework\\Framework-Official\\App\\weline',
                'env_wls_endpoint' => (string)($summary['readiness_app_env_wls_endpoint_url'] ?? WLS_PANEL_WORKORDER_LOCAL_ROOT),
                'source' => 'local App checkout env/config only when deploy=dev/local and root equals https://app.weline.test:9523',
            ],
            'production_deployed' => [
                'root' => WLS_PANEL_WORKORDER_PRODUCTION_ROOT,
                'endpoint' => $productionEndpoint,
                'source' => 'var/deploy/current.json appstore_platform_url',
            ],
            'forbidden_marketplace_roots' => [
                'https://www.aiweline.com',
                'http://www.weline.test:9518',
                'https://www.weline.test:9518',
            ],
        ],
        'blocked_checks' => $blockers,
        'preflight_checks' => [
            'local_endpoint_locked' => ($checks['local_endpoint_locked'] ?? false) === true,
            'production_endpoint_locked' => ($checks['production_endpoint_locked'] ?? false) === true,
            'local_readiness_app_checkout_identity_ok' =>
                ($checks['local_readiness_app_checkout_identity_ok'] ?? false) === true,
            'local_readiness_app_env_wls_endpoint_locked' =>
                ($checks['local_readiness_app_env_wls_endpoint_locked'] ?? false) === true,
            'sync_manifest_self_test_passed' => ($checks['sync_manifest_self_test_passed'] ?? false) === true,
            'sync_manifest_drift_review_fingerprint_present' =>
                ($checks['sync_manifest_drift_review_fingerprint_present'] ?? false) === true,
            'authorization_pack_self_test_passed' => ($checks['authorization_pack_self_test_passed'] ?? false) === true,
            'live_e2e_capture_self_test_passed' => ($checks['live_e2e_capture_self_test_passed'] ?? false) === true,
            'live_e2e_final_gate_self_test_passed' => ($checks['live_e2e_final_gate_self_test_passed'] ?? false) === true,
            'workorder_authorization_consistency_self_test_passed' =>
                ($checks['workorder_authorization_consistency_self_test_passed'] ?? false) === true,
            'endpoint_source_contract_passed' => ($checks['endpoint_source_contract_passed'] ?? false) === true,
            'local_deploy_endpoint_policy_exact_root' => ($checks['local_deploy_endpoint_policy_exact_root'] ?? false) === true,
            'production_deploy_endpoint_policy_exact_root' => ($checks['production_deploy_endpoint_policy_exact_root'] ?? false) === true,
            'official_manifest_catalog_contract_ok' => ($checks['official_manifest_template_catalog_contract_ok'] ?? false) === true,
            'blocked_preflight_no_evidence_files' => ($checks['blocked_preflight_no_evidence_files'] ?? false) === true,
            'readiness_action_plan_contract_ok' => ($summary['readiness_action_plan_contract_ok'] ?? false) === true,
            'deferred_action_plan_all_blocked' => !$readyForLocalLive
                ? wlsPanelWorkorderNoDeferredActionRunsNow($deferredActionPlan)
                : true,
        ],
        'deferred_action_plan' => $deferredActionPlan,
        'operator_sequence' => [
            [
                'id' => 'review_current_workorder',
                'safe_to_run_now' => true,
                'command' => 'php app\\code\\Weline\\Server\\doc\\wls-panel-plan\\tools\\wls-panel-final-workorder.php',
                'side_effects' => 'read-only status report',
            ],
            [
                'id' => 'review_authorization_packet',
                'safe_to_run_now' => true,
                'command' => 'php app\\code\\Weline\\Server\\doc\\wls-panel-plan\\tools\\wls-panel-live-e2e-authorization-pack.php --include-drift-rows=1 --include-rollback-review=1 --fail-if-unsafe=1',
                'side_effects' => 'read-only review packet; no sync, setup, WLS start, token value, live call, or writes',
            ],
            [
                'id' => 'review_workorder_authorization_consistency',
                'safe_to_run_now' => true,
                'command' => 'php app\\code\\Weline\\Server\\doc\\wls-panel-plan\\tools\\wls-panel-workorder-authorization-consistency.php',
                'side_effects' => 'read-only cross-check; verifies preflight, workorder, and authorization packet share marketplace roots and drift fingerprint',
            ],
            [
                'id' => 'local_live_capture_after_blockers_clear',
                'safe_to_run_now' => $readyForLocalLive,
                'command' => 'php app\\code\\Weline\\Server\\doc\\wls-panel-plan\\tools\\wls-panel-live-e2e-capture.php --environment=local --allow-live=1 --evidence-output=var\\wls-panel-plan\\local-appstore-live-e2e.json',
                'requires' => [
                    'local App checkout identity verified as E:\\WelineFramework\\Framework-Official\\App\\weline',
                    'local App env WLS endpoint locked to https://app.weline.test:9523',
                    'App checkout sync and setup completed through the reviewed path',
                    'drift_review_fingerprint compared between compact drift summary and authorization packet before sync',
                    'workorder authorization consistency gate passes and is embedded into capture metadata',
                    'official-apps manifest/source catalog ready with module:wls and module:wls-extra canary',
                    'app.weline.test:9523 WLS listener ready',
                    'WLS_MARKETPLACE_BEARER_TOKEN set outside repository files',
                ],
            ],
            [
                'id' => 'local_final_gate_after_capture',
                'safe_to_run_now' => false,
                'command' => 'php app\\code\\Weline\\Server\\doc\\wls-panel-plan\\tools\\wls-panel-live-evidence-final-gate.php --environment=local',
                'requires' => [
                    'local capture evidence exists under var\\wls-panel-plan',
                    'capture output has captured_valid=true',
                ],
            ],
            [
                'id' => 'production_live_capture_after_launch',
                'safe_to_run_now' => false,
                'command' => 'php app\\code\\Weline\\Server\\doc\\wls-panel-plan\\tools\\wls-panel-live-e2e-capture.php --environment=production --deploy-current=var\\deploy\\current.json --allow-live=1 --evidence-output=var\\wls-panel-plan\\production-appstore-live-e2e.json',
                'requires' => [
                    'deployed var\\deploy\\current.json records appstore_platform_url=https://app.aiweline.com',
                    'deployed var\\deploy\\current.json records appstore_platform_url_source=production_default',
                    'captured evidence endpoint_source and capture_metadata.endpoint_source are deployed var\\deploy\\current.json',
                    'capture_metadata.workorder_authorization_consistency records app.weline.test:9523 and app.aiweline.com',
                    'production token/account ready outside repository files',
                    'app.aiweline.com App Store API live',
                ],
            ],
            [
                'id' => 'production_final_gate_after_capture',
                'safe_to_run_now' => false,
                'command' => 'php app\\code\\Weline\\Server\\doc\\wls-panel-plan\\tools\\wls-panel-live-evidence-final-gate.php --environment=production',
                'requires' => [
                    'production capture evidence exists under var\\wls-panel-plan',
                    'capture output has captured_valid=true',
                ],
            ],
        ],
        'acceptance_contract' => [
            'local_capture_must_report_captured_valid_true',
            'local_capture_must_report_tool_results_final_evidence_gate_ready_true',
            'production_capture_must_read_app_aiweline_from_deploy_current',
            'production_capture_must_use_deployed_var_current_json_source',
            'production_capture_must_require_production_default_source',
            'module_wls_extra_negative_canary_must_be_conclusive',
            'app_checkout_sync_must_compare_drift_review_fingerprint_before_and_after_sync',
            'workorder_authorization_must_match_preflight_and_authorization_pack_roots_and_fingerprint',
            'capture_metadata_must_embed_workorder_authorization_consistency_contract',
            'local_appstore_checkout_identity_must_be_verified',
            'local_app_env_wls_endpoint_must_match_local_deploy_current',
            'production_capture_must_auto_use_app_aiweline_after_deploy',
            'no_secret_values_in_evidence',
        ],
        'source_summary' => [
            'completion_open_rows' => (int)($summary['completion_open_rows'] ?? -1),
            'drifted_count' => (int)($summary['drifted_count'] ?? -1),
            'drift_review_fingerprint' => (string)($summary['drift_review_fingerprint'] ?? ''),
            'local_app_checkout_identity_ok' => ($checks['local_readiness_app_checkout_identity_ok'] ?? false) === true,
            'local_app_env_wls_endpoint_locked' =>
                ($checks['local_readiness_app_env_wls_endpoint_locked'] ?? false) === true,
            'local_app_env_wls_endpoint_url' => (string)($summary['readiness_app_env_wls_endpoint_url'] ?? ''),
            'local_deploy_platform_url' => (string)($summary['local_deploy_platform_url'] ?? ''),
            'production_deploy_platform_url' => (string)($summary['production_deploy_platform_url'] ?? ''),
            'workorder_authorization_consistency_self_test_passed' =>
                ($checks['workorder_authorization_consistency_self_test_passed'] ?? false) === true,
            'readiness_action_count' => count($deferredActionPlan),
            'preflight_exit_code' => (int)($toolResults['final_preflight']['exit_code'] ?? 0),
        ],
        'notes' => [
            'side_effects' => 'read-only workorder: no sync, no setup, no WLS start, no manifest writes, no token values, no live API calls',
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function wlsPanelWorkorderSelfTest(): array
{
    $blocked = wlsPanelWorkorderBuild([
        'ready_for_live_local_appstore_e2e' => false,
        'goal_complete' => false,
        'summary' => [
            'readiness_blockers' => ['app_port_listening', 'bearer_token_env_present'],
            'local_endpoint' => WLS_PANEL_WORKORDER_LOCAL_ROOT . '/api/v1/platform/module/list',
            'production_endpoint' => WLS_PANEL_WORKORDER_PRODUCTION_ROOT . '/api/v1/platform/module/list',
            'drift_review_fingerprint' => '1234567890abcdef',
            'readiness_app_env_wls_endpoint_url' => WLS_PANEL_WORKORDER_LOCAL_ROOT,
            'readiness_action_plan_contract_ok' => true,
            'readiness_next_actions' => [
                [
                    'id' => 'authorized_app_checkout_sync',
                    'phase' => 'prepare_local_appstore_checkout',
                    'blocked_checks' => ['app_schema_has_sqlite_composite_pk_guard'],
                    'requires_user_authorization' => true,
                    'safe_to_run_now' => false,
                    'working_directory' => 'E:\\WelineFramework\\DEV-workspace',
                    'preflight_self_test_command' => 'php app\\code\\Weline\\Server\\doc\\wls-panel-plan\\tools\\validate-local-appstore-sync-manifest.php --self-test=1',
                    'preflight_command' => 'php app\\code\\Weline\\Server\\doc\\wls-panel-plan\\tools\\validate-local-appstore-sync-manifest.php --with-drift=1 --drift-summary-only=1',
                    'pre_authorization_review_command' => 'php app\\code\\Weline\\Server\\doc\\wls-panel-plan\\tools\\wls-panel-live-e2e-authorization-pack.php --include-drift-rows=1 --include-rollback-review=1 --fail-if-unsafe=1',
                    'rollback_review_command' => 'php app\\code\\Weline\\Server\\doc\\wls-panel-plan\\tools\\validate-local-appstore-sync-manifest.php --with-drift=1 --rollback-review=1',
                    'post_sync_gate_command' => 'php app\\code\\Weline\\Server\\doc\\wls-panel-plan\\tools\\validate-local-appstore-sync-manifest.php --fail-on-drift=1 --drift-summary-only=1',
                    'side_effects' => 'blocked until user explicitly authorizes the DEV-to-App sync',
                ],
                [
                    'id' => 'run_local_app_setup_after_sync',
                    'phase' => 'prepare_local_appstore_runtime',
                    'blocked_checks' => ['app_schema_has_sqlite_composite_pk_guard'],
                    'requires_user_authorization' => false,
                    'safe_to_run_now' => false,
                    'working_directory' => 'E:\\WelineFramework\\Framework-Official\\App\\weline',
                    'command' => 'php bin/w setup:upgrade --route --skip-env-check --skip-composer-dump',
                    'side_effects' => 'runs setup only after authorized sync is complete',
                ],
                [
                    'id' => 'prepare_official_manifest',
                    'phase' => 'prepare_wls_plugin_catalog',
                    'blocked_checks' => ['official_manifest_readable'],
                    'requires_user_authorization' => true,
                    'safe_to_run_now' => false,
                    'working_directory' => 'E:\\WelineFramework\\DEV-workspace',
                    'dry_run_command' => 'php app\\code\\Weline\\Server\\doc\\wls-panel-plan\\tools\\validate-official-appstore-manifest-contract.php --template=1 --template-target=E:\\WelineFramework\\Framework-Official\\App\\weline\\official-apps\\manifest.json',
                    'authorized_write_command' => 'php app\\code\\Weline\\Server\\doc\\wls-panel-plan\\tools\\validate-official-appstore-manifest-contract.php --template=1 --template-target=E:\\WelineFramework\\Framework-Official\\App\\weline\\official-apps\\manifest.json --write=1 --confirm=WRITE_WLS_OFFICIAL_MANIFEST --create-dir=1',
                    'side_effects' => 'manifest/source catalog writes are blocked until the guarded write command is explicitly authorized',
                ],
                [
                    'id' => 'set_local_marketplace_bearer_token',
                    'phase' => 'prepare_local_appstore_token',
                    'blocked_checks' => ['bearer_token_env_present'],
                    'requires_user_authorization' => false,
                    'requires_user_secret' => true,
                    'safe_to_run_now' => false,
                    'precondition' => 'set outside docs and repository files',
                    'side_effects' => 'operator-owned secret setup; token values are never printed',
                ],
                [
                    'id' => 'run_live_typed_tag_e2e',
                    'phase' => 'capture_local_appstore_evidence',
                    'blocked_checks' => ['app_port_listening', 'bearer_token_env_present'],
                    'requires_user_authorization' => false,
                    'safe_to_run_now' => false,
                    'command' => 'php app\\code\\Weline\\Server\\doc\\wls-panel-plan\\tools\\local-appstore-typed-tag-live-gate.php --allow-live=1',
                    'side_effects' => 'live local AppStore API call only after all preflight blockers clear',
                ],
            ],
        ],
        'checks' => [
            'local_endpoint_locked' => true,
            'production_endpoint_locked' => true,
            'local_readiness_app_checkout_identity_ok' => true,
            'local_readiness_app_env_wls_endpoint_locked' => true,
            'sync_manifest_drift_review_fingerprint_present' => true,
            'workorder_authorization_consistency_self_test_passed' => true,
        ],
    ]);
    $ready = wlsPanelWorkorderBuild([
        'ready_for_live_local_appstore_e2e' => true,
        'goal_complete' => false,
        'summary' => [
            'readiness_blockers' => [],
        ],
    ]);
    $complete = wlsPanelWorkorderBuild([
        'ready_for_live_local_appstore_e2e' => false,
        'goal_complete' => true,
        'summary' => [
            'readiness_blockers' => [],
        ],
    ]);
    $blockedDeferredActions = is_array($blocked['deferred_action_plan'] ?? null)
        ? $blocked['deferred_action_plan']
        : [];
    $blockedDeferredActionIds = [];
    foreach ($blockedDeferredActions as $action) {
        if (is_array($action)) {
            $blockedDeferredActionIds[] = (string)($action['id'] ?? '');
        }
    }
    $blockedAcceptanceContract = is_array($blocked['acceptance_contract'] ?? null)
        ? array_values(array_map('strval', $blocked['acceptance_contract']))
        : [];
    $blockedLocalCaptureRequires = is_array($blocked['operator_sequence'][3]['requires'] ?? null)
        ? implode("\n", array_map('strval', $blocked['operator_sequence'][3]['requires']))
        : '';
    $blockedJson = json_encode($blocked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';

    $cases = [
        [
            'name' => 'blocked_state_keeps_local_and_production_roots',
            'case_ok' => $blocked['current_state'] === 'blocked_before_local_live_capture'
                && ($blocked['environment_policy']['local_development']['root'] ?? '') === WLS_PANEL_WORKORDER_LOCAL_ROOT
                && ($blocked['environment_policy']['production_deployed']['root'] ?? '') === WLS_PANEL_WORKORDER_PRODUCTION_ROOT,
        ],
        [
            'name' => 'bearer_token_blocker_requires_secret_without_leaking_value',
            'case_ok' => ($blocked['user_secret_required'] ?? false) === true
                && !str_contains($blockedJson, 'Bearer '),
        ],
        [
            'name' => 'blocked_state_exports_deferred_action_plan',
            'case_ok' => count($blockedDeferredActions) >= 5
                && in_array('authorized_app_checkout_sync', $blockedDeferredActionIds, true)
                && in_array('prepare_official_manifest', $blockedDeferredActionIds, true)
                && in_array('set_local_marketplace_bearer_token', $blockedDeferredActionIds, true)
                && in_array('run_live_typed_tag_e2e', $blockedDeferredActionIds, true),
        ],
        [
            'name' => 'blocked_state_keeps_deferred_actions_not_runnable',
            'case_ok' => (($blocked['preflight_checks']['deferred_action_plan_all_blocked'] ?? false) === true)
                && wlsPanelWorkorderNoDeferredActionRunsNow($blockedDeferredActions),
        ],
        [
            'name' => 'deferred_action_plan_keeps_secret_as_placeholder',
            'case_ok' => !str_contains($blockedJson, 'Bearer ')
                && str_contains($blockedJson, 'set outside docs and repository files'),
        ],
        [
            'name' => 'acceptance_contract_preserves_final_evidence_invariants',
            'case_ok' => count(array_intersect([
                'local_capture_must_report_captured_valid_true',
                'local_capture_must_report_tool_results_final_evidence_gate_ready_true',
                'production_capture_must_read_app_aiweline_from_deploy_current',
                'production_capture_must_use_deployed_var_current_json_source',
                'production_capture_must_require_production_default_source',
                'module_wls_extra_negative_canary_must_be_conclusive',
                'app_checkout_sync_must_compare_drift_review_fingerprint_before_and_after_sync',
                'workorder_authorization_must_match_preflight_and_authorization_pack_roots_and_fingerprint',
                'capture_metadata_must_embed_workorder_authorization_consistency_contract',
                'local_appstore_checkout_identity_must_be_verified',
                'local_app_env_wls_endpoint_must_match_local_deploy_current',
                'production_capture_must_auto_use_app_aiweline_after_deploy',
                'no_secret_values_in_evidence',
            ], $blockedAcceptanceContract)) === 13,
        ],
        [
            'name' => 'blocked_state_exports_local_app_identity_and_env_endpoint_gates',
            'case_ok' => ($blocked['preflight_checks']['local_readiness_app_checkout_identity_ok'] ?? false) === true
                && ($blocked['preflight_checks']['local_readiness_app_env_wls_endpoint_locked'] ?? false) === true
                && ($blocked['source_summary']['local_app_checkout_identity_ok'] ?? false) === true
                && ($blocked['source_summary']['local_app_env_wls_endpoint_locked'] ?? false) === true
                && ($blocked['source_summary']['local_app_env_wls_endpoint_url'] ?? '') === WLS_PANEL_WORKORDER_LOCAL_ROOT
                && str_contains(
                    $blockedLocalCaptureRequires,
                    'local App checkout identity verified as E:\\WelineFramework\\Framework-Official\\App\\weline'
                )
                && str_contains($blockedLocalCaptureRequires, 'local App env WLS endpoint locked to https://app.weline.test:9523'),
        ],
        [
            'name' => 'ready_state_allows_only_local_live_capture_step',
            'case_ok' => $ready['current_state'] === 'ready_for_local_live_capture'
                && ($ready['operator_sequence'][3]['safe_to_run_now'] ?? false) === true
                && ($ready['operator_sequence'][5]['safe_to_run_now'] ?? true) === false,
        ],
        [
            'name' => 'complete_state_remains_distinct_from_ready_state',
            'case_ok' => $complete['current_state'] === 'goal_complete'
                && ($complete['goal_complete'] ?? false) === true,
        ],
    ];

    return [
        'passed' => !in_array(false, array_column($cases, 'case_ok'), true),
        'self_test' => true,
        'cases' => $cases,
        'side_effects' => 'in-memory self-test: no file read, no network, no token, no WLS start, no writes',
    ];
}

$args = wlsPanelWorkorderParseArgs($argv);
if (($args['self-test'] ?? '0') === '1') {
    $result = wlsPanelWorkorderSelfTest();
    wlsPanelWorkorderFinish($result, ($result['passed'] ?? false) === true ? 0 : WLS_PANEL_WORKORDER_EXIT_FAILED);
}

$workspaceRoot = trim((string)($args['workspace-root'] ?? dirname(__DIR__, 7)));
$tools = 'app/code/Weline/Server/doc/wls-panel-plan/tools/';
$preflightResult = wlsPanelWorkorderRunTool($workspaceRoot, $tools . 'wls-panel-final-preflight.php', [
    '--report-only=1',
]);
$preflight = is_array($preflightResult['payload'] ?? null) ? $preflightResult['payload'] : [];

if (($preflightResult['parsed'] ?? false) !== true || ($preflight['ok'] ?? false) !== true) {
    wlsPanelWorkorderFinish([
        'ok' => false,
        'workorder_ready' => false,
        'current_state' => 'preflight_unavailable',
        'tool_results' => [
            'final_preflight' => [
                'exit_code' => (int)($preflightResult['exit_code'] ?? 127),
                'parsed' => ($preflightResult['parsed'] ?? false) === true,
                'output_bytes' => (int)($preflightResult['output_bytes'] ?? 0),
            ],
        ],
        'notes' => [
            'side_effects' => 'read-only workorder: no sync, no setup, no WLS start, no manifest writes, no token values, no live API calls',
        ],
    ], WLS_PANEL_WORKORDER_EXIT_FAILED);
}

$payload = wlsPanelWorkorderBuild($preflight);
$payload['tool_results'] = [
    'final_preflight' => [
        'exit_code' => (int)($preflightResult['exit_code'] ?? 0),
        'parsed' => true,
        'ready_for_live_local_appstore_e2e' => ($preflight['ready_for_live_local_appstore_e2e'] ?? false) === true,
        'goal_complete' => ($preflight['goal_complete'] ?? false) === true,
        'output_bytes' => (int)($preflightResult['output_bytes'] ?? 0),
    ],
];

wlsPanelWorkorderFinish($payload, 0);
