<?php
declare(strict_types=1);

/**
 * Validates the final WLS Panel workorder deferred-action contract.
 *
 * This tool is read-only. By default it runs wls-panel-final-workorder.php and
 * checks that the remaining AppStore live-E2E steps are ordered, non-runnable
 * while blocked, endpoint-locked, and safe for operator handoff.
 */

const WLS_PANEL_DEFERRED_ACTIONS_LOCAL_ROOT = 'https://app.weline.test:9523';
const WLS_PANEL_DEFERRED_ACTIONS_PRODUCTION_ROOT = 'https://app.aiweline.com';
const WLS_PANEL_DEFERRED_ACTIONS_FAILED = 1;

/**
 * @return list<string>
 */
function wlsPanelDeferredActionsExpectedOrder(): array
{
    return [
        'authorized_app_checkout_sync',
        'run_local_app_setup_after_sync',
        'prepare_official_manifest',
        'start_local_app_wls',
        'set_local_marketplace_bearer_token',
        'run_live_typed_tag_e2e',
    ];
}

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function wlsPanelDeferredActionsParseArgs(array $argv): array
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
function wlsPanelDeferredActionsFinish(array $payload, int $exitCode): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($exitCode);
}

function wlsPanelDeferredActionsPath(string $base, string $relative): string
{
    return rtrim($base, "\\/") . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
}

/**
 * @return array<string, mixed>|null
 */
function wlsPanelDeferredActionsExtractJson(string $output): ?array
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
function wlsPanelDeferredActionsRunTool(string $workspaceRoot, string $relativeTool, array $args = []): array
{
    $command = array_merge([PHP_BINARY, wlsPanelDeferredActionsPath($workspaceRoot, $relativeTool)], $args);
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, $workspaceRoot);
    if (!is_resource($process)) {
        return [
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
    $payload = wlsPanelDeferredActionsExtractJson($output);

    return [
        'exit_code' => $exitCode,
        'parsed' => $payload !== null,
        'payload' => $payload,
        'output_bytes' => strlen($output),
    ];
}

/**
 * @param list<array<string, mixed>> $actions
 * @return array<string, array<string, mixed>>
 */
function wlsPanelDeferredActionsMapById(array $actions): array
{
    $map = [];
    foreach ($actions as $action) {
        if (!is_array($action)) {
            continue;
        }

        $id = (string)($action['id'] ?? '');
        if ($id !== '') {
            $map[$id] = $action;
        }
    }

    return $map;
}

/**
 * @param list<array<string, mixed>> $actions
 * @param list<string> $expected
 */
function wlsPanelDeferredActionsOrdered(array $actions, array $expected): bool
{
    $ids = [];
    foreach ($actions as $action) {
        if (is_array($action)) {
            $ids[] = (string)($action['id'] ?? '');
        }
    }

    $position = -1;
    foreach ($expected as $id) {
        $index = array_search($id, $ids, true);
        if ($index === false || $index <= $position) {
            return false;
        }

        $position = (int)$index;
    }

    return true;
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function wlsPanelDeferredActionsValidate(array $payload): array
{
    $errors = [];
    $checks = [];
    $expected = wlsPanelDeferredActionsExpectedOrder();
    $policy = is_array($payload['environment_policy'] ?? null) ? $payload['environment_policy'] : [];
    $localPolicy = is_array($policy['local_development'] ?? null) ? $policy['local_development'] : [];
    $productionPolicy = is_array($policy['production_deployed'] ?? null) ? $policy['production_deployed'] : [];
    $forbiddenRoots = is_array($policy['forbidden_marketplace_roots'] ?? null)
        ? array_values(array_map('strval', $policy['forbidden_marketplace_roots']))
        : [];
    $acceptanceContract = is_array($payload['acceptance_contract'] ?? null)
        ? array_values(array_map('strval', $payload['acceptance_contract']))
        : [];
    $actions = is_array($payload['deferred_action_plan'] ?? null)
        ? array_values($payload['deferred_action_plan'])
        : [];
    $sourceSummary = is_array($payload['source_summary'] ?? null) ? $payload['source_summary'] : [];
    $actionMap = wlsPanelDeferredActionsMapById($actions);
    $operatorSequence = is_array($payload['operator_sequence'] ?? null)
        ? array_values($payload['operator_sequence'])
        : [];
    $operatorMap = wlsPanelDeferredActionsMapById($operatorSequence);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';

    $checks['workorder_ready'] = ($payload['workorder_ready'] ?? false) === true;
    $checks['local_root_locked'] = ($localPolicy['root'] ?? '') === WLS_PANEL_DEFERRED_ACTIONS_LOCAL_ROOT;
    $checks['production_root_locked'] = ($productionPolicy['root'] ?? '') === WLS_PANEL_DEFERRED_ACTIONS_PRODUCTION_ROOT;
    $checks['local_policy_records_framework_official_app_checkout'] =
        ($localPolicy['checkout'] ?? '') === 'E:\\WelineFramework\\Framework-Official\\App\\weline';
    $checks['local_policy_records_app_env_wls_endpoint'] =
        ($localPolicy['env_wls_endpoint'] ?? '') === WLS_PANEL_DEFERRED_ACTIONS_LOCAL_ROOT;
    $checks['forbidden_www_roots_present'] = in_array('https://www.aiweline.com', $forbiddenRoots, true)
        && (
            in_array('http://www.weline.test:9518', $forbiddenRoots, true)
            || in_array('https://www.weline.test:9518', $forbiddenRoots, true)
        );
    $checks['deferred_action_plan_present'] = $actions !== [];
    $checks['operator_sequence_present'] = $operatorSequence !== [];
    $checks['required_actions_present'] = count(array_intersect($expected, array_keys($actionMap))) === count($expected);
    $checks['required_actions_ordered'] = wlsPanelDeferredActionsOrdered($actions, $expected);
    $checks['readiness_action_count_matches'] = (int)($sourceSummary['readiness_action_count'] ?? -1) === count($actions);
    $checks['source_summary_drift_review_fingerprint_present'] =
        trim((string)($sourceSummary['drift_review_fingerprint'] ?? '')) !== '';
    $checks['local_app_checkout_identity_preflight_locked'] =
        ($payload['preflight_checks']['local_readiness_app_checkout_identity_ok'] ?? false) === true
        && ($sourceSummary['local_app_checkout_identity_ok'] ?? false) === true;
    $checks['local_app_env_wls_endpoint_preflight_locked'] =
        ($payload['preflight_checks']['local_readiness_app_env_wls_endpoint_locked'] ?? false) === true
        && ($sourceSummary['local_app_env_wls_endpoint_locked'] ?? false) === true
        && ($sourceSummary['local_app_env_wls_endpoint_url'] ?? '') === WLS_PANEL_DEFERRED_ACTIONS_LOCAL_ROOT;
    $checks['no_secret_values'] = !str_contains($json, 'Bearer ')
        && !str_contains($json, 'WLS_MARKETPLACE_BEARER_TOKEN=')
        && !str_contains($json, 'Authorization:');
    $checks['acceptance_contract_has_required_invariants'] = count(array_intersect([
        'local_capture_must_report_captured_valid_true',
        'local_capture_must_report_tool_results_final_evidence_gate_ready_true',
        'production_capture_must_read_app_aiweline_from_deploy_current',
        'production_capture_must_use_deployed_var_current_json_source',
        'production_capture_must_require_production_default_source',
        'module_wls_extra_negative_canary_must_be_conclusive',
        'app_checkout_sync_must_compare_drift_review_fingerprint_before_and_after_sync',
        'local_appstore_checkout_identity_must_be_verified',
        'local_app_env_wls_endpoint_must_match_local_deploy_current',
        'production_capture_must_auto_use_app_aiweline_after_deploy',
        'no_secret_values_in_evidence',
    ], $acceptanceContract)) === 11;

    $sync = $actionMap['authorized_app_checkout_sync'] ?? [];
    $setup = $actionMap['run_local_app_setup_after_sync'] ?? [];
    $manifest = $actionMap['prepare_official_manifest'] ?? [];
    $start = $actionMap['start_local_app_wls'] ?? [];
    $token = $actionMap['set_local_marketplace_bearer_token'] ?? [];
    $live = $actionMap['run_live_typed_tag_e2e'] ?? [];
    $localCapture = $operatorMap['local_live_capture_after_blockers_clear'] ?? [];
    $localFinalGate = $operatorMap['local_final_gate_after_capture'] ?? [];
    $productionCapture = $operatorMap['production_live_capture_after_launch'] ?? [];
    $productionFinalGate = $operatorMap['production_final_gate_after_capture'] ?? [];
    $syncSchema = is_array($sync['schema_sync'] ?? null) ? $sync['schema_sync'] : [];
    $setupSchema = is_array($setup['schema_sync'] ?? null) ? $setup['schema_sync'] : [];

    $checks['sync_requires_user_authorization'] = ($sync['requires_user_authorization'] ?? false) === true
        && ($sync['safe_to_run_now'] ?? true) === false
        && str_contains((string)($sync['preflight_command'] ?? ''), 'validate-local-appstore-sync-manifest.php');
    $checks['sync_requires_drift_fingerprint_review_chain'] =
        str_contains((string)($sync['preflight_command'] ?? ''), '--with-drift=1')
        && str_contains((string)($sync['preflight_command'] ?? ''), '--drift-summary-only=1')
        && str_contains((string)($sync['pre_authorization_review_command'] ?? ''), 'wls-panel-live-e2e-authorization-pack.php')
        && str_contains((string)($sync['pre_authorization_review_command'] ?? ''), '--include-drift-rows=1')
        && str_contains((string)($sync['pre_authorization_review_command'] ?? ''), '--include-rollback-review=1')
        && str_contains((string)($sync['pre_authorization_review_command'] ?? ''), '--fail-if-unsafe=1')
        && str_contains((string)($sync['rollback_review_command'] ?? ''), 'validate-local-appstore-sync-manifest.php')
        && str_contains((string)($sync['rollback_review_command'] ?? ''), '--rollback-review=1')
        && str_contains((string)($sync['post_sync_gate_command'] ?? ''), '--fail-on-drift=1')
        && str_contains((string)($sync['post_sync_gate_command'] ?? ''), '--drift-summary-only=1');
    $checks['sync_schema_sync_diagnostic_present'] =
        (($syncSchema['source']['guard_present'] ?? false) === true)
        && (($syncSchema['target']['guard_present'] ?? true) === false)
        && (($syncSchema['authorized_sync_required'] ?? false) === true)
        && (($syncSchema['setup_required_after_sync'] ?? false) === true)
        && (($syncSchema['allowed_sync_path'] ?? '') === 'app/code/Weline/Framework/Database/Schema/SchemaMigrationExecutor.php');
    $checks['setup_targets_local_app_checkout'] = ($setup['safe_to_run_now'] ?? true) === false
        && str_contains((string)($setup['working_directory'] ?? ''), 'Framework-Official')
        && str_contains((string)($setup['command'] ?? ''), 'setup:upgrade --route --skip-env-check --skip-composer-dump');
    $checks['setup_schema_sync_diagnostic_present'] =
        (($setupSchema['source']['guard_present'] ?? false) === true)
        && (($setupSchema['target']['guard_present'] ?? true) === false)
        && (($setupSchema['authorized_sync_required'] ?? false) === true)
        && (($setupSchema['setup_required_after_sync'] ?? false) === true)
        && (($setupSchema['allowed_sync_path'] ?? '') === 'app/code/Weline/Framework/Database/Schema/SchemaMigrationExecutor.php');
    $checks['manifest_requires_confirmed_writes'] = ($manifest['requires_user_authorization'] ?? false) === true
        && ($manifest['safe_to_run_now'] ?? true) === false
        && str_contains((string)($manifest['authorized_write_command'] ?? ''), 'WRITE_WLS_OFFICIAL_MANIFEST')
        && str_contains((string)($manifest['authorized_source_write_command'] ?? ''), 'WRITE_WLS_OFFICIAL_SOURCES')
        && str_contains((string)($manifest['authorized_catalog_write_command'] ?? ''), 'WRITE_WLS_OFFICIAL_MANIFEST')
        && str_contains((string)($manifest['authorized_catalog_write_command'] ?? ''), 'WRITE_WLS_OFFICIAL_SOURCES');
    $checks['start_targets_app_weline_9523'] = ($start['safe_to_run_now'] ?? true) === false
        && str_contains((string)($start['working_directory'] ?? ''), 'Framework-Official')
        && str_contains((string)($start['command'] ?? ''), 'app.weline.test')
        && str_contains((string)($start['command'] ?? ''), '9523');
    $checks['token_requires_secret_placeholder'] = ($token['requires_user_secret'] ?? false) === true
        && ($token['safe_to_run_now'] ?? true) === false
        && str_contains((string)($token['command'] ?? $token['precondition'] ?? ''), '<set outside docs>');
    $checks['live_uses_guarded_local_gate'] = str_contains((string)($live['command'] ?? ''), 'local-appstore-typed-tag-live-gate.php')
        && str_contains((string)($live['command'] ?? ''), '--allow-live=1');
    $checks['local_capture_operator_step_present'] = str_contains((string)($localCapture['command'] ?? ''), 'wls-panel-live-e2e-capture.php')
        && str_contains((string)($localCapture['command'] ?? ''), '--environment=local')
        && str_contains((string)($localCapture['command'] ?? ''), '--allow-live=1')
        && str_contains((string)($localCapture['command'] ?? ''), 'var\\wls-panel-plan\\local-appstore-live-e2e.json');
    $localCaptureRequires = is_array($localCapture['requires'] ?? null)
        ? implode("\n", array_map('strval', $localCapture['requires']))
        : '';
    $checks['local_capture_requires_reviewed_appstore_prerequisites'] = str_contains($localCaptureRequires, 'App checkout sync and setup completed through the reviewed path')
        && str_contains($localCaptureRequires, 'local App checkout identity verified as E:\\WelineFramework\\Framework-Official\\App\\weline')
        && str_contains($localCaptureRequires, 'local App env WLS endpoint locked to https://app.weline.test:9523')
        && str_contains($localCaptureRequires, 'drift_review_fingerprint compared between compact drift summary and authorization packet before sync')
        && str_contains($localCaptureRequires, 'official-apps manifest/source catalog ready with module:wls and module:wls-extra canary')
        && str_contains($localCaptureRequires, 'app.weline.test:9523 WLS listener ready')
        && str_contains($localCaptureRequires, 'WLS_MARKETPLACE_BEARER_TOKEN set outside repository files');
    $checks['local_final_gate_operator_step_present'] = ($localFinalGate['safe_to_run_now'] ?? true) === false
        && str_contains((string)($localFinalGate['command'] ?? ''), 'wls-panel-live-evidence-final-gate.php')
        && str_contains((string)($localFinalGate['command'] ?? ''), '--environment=local');
    $checks['production_capture_uses_deploy_current'] = ($productionCapture['safe_to_run_now'] ?? true) === false
        && str_contains((string)($productionCapture['command'] ?? ''), 'wls-panel-live-e2e-capture.php')
        && str_contains((string)($productionCapture['command'] ?? ''), '--environment=production')
        && (
            str_contains((string)($productionCapture['command'] ?? ''), '--deploy-current=var\\deploy\\current.json')
            || str_contains((string)($productionCapture['command'] ?? ''), '--deploy-current=var/deploy/current.json')
        )
        && str_contains((string)($productionCapture['command'] ?? ''), '--allow-live=1')
        && str_contains((string)($productionCapture['command'] ?? ''), 'var\\wls-panel-plan\\production-appstore-live-e2e.json');
    $productionCaptureRequires = is_array($productionCapture['requires'] ?? null)
        ? implode("\n", array_map('strval', $productionCapture['requires']))
        : '';
    $checks['production_capture_requires_deployed_app_aiweline'] = str_contains($productionCaptureRequires, 'appstore_platform_url=https://app.aiweline.com')
        && str_contains($productionCaptureRequires, 'endpoint_source and capture_metadata.endpoint_source are deployed var\\deploy\\current.json')
        && str_contains($productionCaptureRequires, 'app.aiweline.com App Store API live')
        && str_contains($productionCaptureRequires, 'production token/account ready outside repository files');
    $checks['production_capture_requires_production_default_source'] = str_contains(
        $productionCaptureRequires,
        'appstore_platform_url_source=production_default'
    );
    $checks['production_final_gate_operator_step_present'] = ($productionFinalGate['safe_to_run_now'] ?? true) === false
        && str_contains((string)($productionFinalGate['command'] ?? ''), 'wls-panel-live-evidence-final-gate.php')
        && str_contains((string)($productionFinalGate['command'] ?? ''), '--environment=production');

    $currentState = (string)($payload['current_state'] ?? '');
    if ($currentState === 'blocked_before_local_live_capture') {
        $allBlocked = true;
        foreach ($actions as $action) {
            if (is_array($action) && ($action['safe_to_run_now'] ?? false) === true) {
                $allBlocked = false;
                break;
            }
        }
        $checks['blocked_state_all_actions_not_runnable'] = $allBlocked
            && (($payload['preflight_checks']['deferred_action_plan_all_blocked'] ?? false) === true);
    } elseif ($currentState === 'ready_for_local_live_capture') {
        $runnable = [];
        foreach ($actions as $action) {
            if (is_array($action) && ($action['safe_to_run_now'] ?? false) === true) {
                $runnable[] = (string)($action['id'] ?? '');
            }
        }
        $checks['ready_state_only_live_action_runnable'] = $runnable === ['run_live_typed_tag_e2e'];
    } else {
        $checks['state_is_valid_for_deferred_action_contract'] = in_array($currentState, [
            'goal_complete',
            'preflight_inconclusive',
        ], true);
    }

    if ($currentState === 'goal_complete') {
        foreach ([
            'required_actions_present',
            'required_actions_ordered',
            'sync_requires_user_authorization',
            'sync_requires_drift_fingerprint_review_chain',
            'sync_schema_sync_diagnostic_present',
            'setup_targets_local_app_checkout',
            'setup_schema_sync_diagnostic_present',
            'manifest_requires_confirmed_writes',
            'start_targets_app_weline_9523',
        ] as $completedPreCaptureCheck) {
            $checks[$completedPreCaptureCheck] = true;
        }
    }

    foreach ($checks as $name => $passed) {
        if ($passed !== true) {
            $errors[] = 'check_failed:' . $name;
        }
    }

    return [
        'passed' => $errors === [],
        'checks' => $checks,
        'errors' => $errors,
        'current_state' => $currentState,
        'action_count' => count($actions),
        'expected_order' => $expected,
    ];
}

/**
 * @return array<string, mixed>
 */
function wlsPanelDeferredActionsSelfTest(): array
{
    $schemaSync = [
        'source' => [
            'path' => 'E:\\WelineFramework\\DEV-workspace\\app\\code\\Weline\\Framework\\Database\\Schema\\SchemaMigrationExecutor.php',
            'readiness_check' => 'dev_schema_has_sqlite_composite_pk_guard',
            'guard_present' => true,
        ],
        'target' => [
            'path' => 'E:\\WelineFramework\\Framework-Official\\App\\weline\\app\\code\\Weline\\Framework\\Database\\Schema\\SchemaMigrationExecutor.php',
            'readiness_check' => 'app_schema_has_sqlite_composite_pk_guard',
            'guard_present' => false,
        ],
        'authorized_sync_required' => true,
        'setup_required_after_sync' => true,
        'allowed_sync_path' => 'app/code/Weline/Framework/Database/Schema/SchemaMigrationExecutor.php',
    ];

    $base = [
        'workorder_ready' => true,
        'current_state' => 'blocked_before_local_live_capture',
        'environment_policy' => [
            'local_development' => [
                'root' => WLS_PANEL_DEFERRED_ACTIONS_LOCAL_ROOT,
                'checkout' => 'E:\\WelineFramework\\Framework-Official\\App\\weline',
                'env_wls_endpoint' => WLS_PANEL_DEFERRED_ACTIONS_LOCAL_ROOT,
            ],
            'production_deployed' => ['root' => WLS_PANEL_DEFERRED_ACTIONS_PRODUCTION_ROOT],
            'forbidden_marketplace_roots' => [
                'https://www.aiweline.com',
                'http://www.weline.test:9518',
            ],
        ],
        'preflight_checks' => [
            'deferred_action_plan_all_blocked' => true,
            'local_readiness_app_checkout_identity_ok' => true,
            'local_readiness_app_env_wls_endpoint_locked' => true,
        ],
        'source_summary' => [
            'readiness_action_count' => 6,
            'drift_review_fingerprint' => '1234567890abcdef',
            'local_app_checkout_identity_ok' => true,
            'local_app_env_wls_endpoint_locked' => true,
            'local_app_env_wls_endpoint_url' => WLS_PANEL_DEFERRED_ACTIONS_LOCAL_ROOT,
        ],
        'acceptance_contract' => [
            'local_capture_must_report_captured_valid_true',
            'local_capture_must_report_tool_results_final_evidence_gate_ready_true',
            'production_capture_must_read_app_aiweline_from_deploy_current',
            'production_capture_must_use_deployed_var_current_json_source',
            'production_capture_must_require_production_default_source',
            'module_wls_extra_negative_canary_must_be_conclusive',
            'app_checkout_sync_must_compare_drift_review_fingerprint_before_and_after_sync',
            'local_appstore_checkout_identity_must_be_verified',
            'local_app_env_wls_endpoint_must_match_local_deploy_current',
            'production_capture_must_auto_use_app_aiweline_after_deploy',
            'no_secret_values_in_evidence',
        ],
        'operator_sequence' => [
            [
                'id' => 'local_live_capture_after_blockers_clear',
                'safe_to_run_now' => false,
                'command' => 'php app\\code\\Weline\\Server\\doc\\wls-panel-plan\\tools\\wls-panel-live-e2e-capture.php --environment=local --allow-live=1 --evidence-output=var\\wls-panel-plan\\local-appstore-live-e2e.json',
                'requires' => [
                    'local App checkout identity verified as E:\\WelineFramework\\Framework-Official\\App\\weline',
                    'local App env WLS endpoint locked to https://app.weline.test:9523',
                    'App checkout sync and setup completed through the reviewed path',
                    'drift_review_fingerprint compared between compact drift summary and authorization packet before sync',
                    'official-apps manifest/source catalog ready with module:wls and module:wls-extra canary',
                    'app.weline.test:9523 WLS listener ready',
                    'WLS_MARKETPLACE_BEARER_TOKEN set outside repository files',
                ],
            ],
            [
                'id' => 'local_final_gate_after_capture',
                'safe_to_run_now' => false,
                'command' => 'php app\\code\\Weline\\Server\\doc\\wls-panel-plan\\tools\\wls-panel-live-evidence-final-gate.php --environment=local',
            ],
            [
                'id' => 'production_live_capture_after_launch',
                'safe_to_run_now' => false,
                'command' => 'php app\\code\\Weline\\Server\\doc\\wls-panel-plan\\tools\\wls-panel-live-e2e-capture.php --environment=production --deploy-current=var\\deploy\\current.json --allow-live=1 --evidence-output=var\\wls-panel-plan\\production-appstore-live-e2e.json',
                'requires' => [
                    'deployed var\\deploy\\current.json records appstore_platform_url=https://app.aiweline.com',
                    'deployed var\\deploy\\current.json records appstore_platform_url_source=production_default',
                    'captured evidence endpoint_source and capture_metadata.endpoint_source are deployed var\\deploy\\current.json',
                    'production token/account ready outside repository files',
                    'app.aiweline.com App Store API live',
                ],
            ],
            [
                'id' => 'production_final_gate_after_capture',
                'safe_to_run_now' => false,
                'command' => 'php app\\code\\Weline\\Server\\doc\\wls-panel-plan\\tools\\wls-panel-live-evidence-final-gate.php --environment=production',
            ],
        ],
        'deferred_action_plan' => [
            [
                'id' => 'authorized_app_checkout_sync',
                'requires_user_authorization' => true,
                'safe_to_run_now' => false,
                'preflight_command' => 'php tools\\validate-local-appstore-sync-manifest.php --with-drift=1 --drift-summary-only=1',
                'pre_authorization_review_command' => 'php tools\\wls-panel-live-e2e-authorization-pack.php --include-drift-rows=1 --include-rollback-review=1 --fail-if-unsafe=1',
                'rollback_review_command' => 'php tools\\validate-local-appstore-sync-manifest.php --with-drift=1 --rollback-review=1',
                'post_sync_gate_command' => 'php tools\\validate-local-appstore-sync-manifest.php --fail-on-drift=1 --drift-summary-only=1',
                'schema_sync' => $schemaSync,
            ],
            [
                'id' => 'run_local_app_setup_after_sync',
                'safe_to_run_now' => false,
                'working_directory' => 'E:\\WelineFramework\\Framework-Official\\App\\weline',
                'command' => 'php bin/w setup:upgrade --route --skip-env-check --skip-composer-dump',
                'schema_sync' => $schemaSync,
            ],
            [
                'id' => 'prepare_official_manifest',
                'requires_user_authorization' => true,
                'safe_to_run_now' => false,
                'authorized_write_command' => '--confirm=WRITE_WLS_OFFICIAL_MANIFEST',
                'authorized_source_write_command' => '--confirm-sources=WRITE_WLS_OFFICIAL_SOURCES',
                'authorized_catalog_write_command' => '--confirm=WRITE_WLS_OFFICIAL_MANIFEST --confirm-sources=WRITE_WLS_OFFICIAL_SOURCES',
            ],
            [
                'id' => 'start_local_app_wls',
                'safe_to_run_now' => false,
                'working_directory' => 'E:\\WelineFramework\\Framework-Official\\App\\weline',
                'command' => 'php bin/w server:start wls --host app.weline.test --port 9523 --ssl-domain app.weline.test',
            ],
            [
                'id' => 'set_local_marketplace_bearer_token',
                'requires_user_secret' => true,
                'safe_to_run_now' => false,
                'command' => '$env:WLS_MARKETPLACE_BEARER_TOKEN = \'<set outside docs>\'',
            ],
            [
                'id' => 'run_live_typed_tag_e2e',
                'safe_to_run_now' => false,
                'command' => 'php tools\\local-appstore-typed-tag-live-gate.php --allow-live=1',
            ],
        ],
    ];

    $missingStart = $base;
    unset($missingStart['deferred_action_plan'][3]);
    $missingStart['deferred_action_plan'] = array_values($missingStart['deferred_action_plan']);
    $missingStart['source_summary']['readiness_action_count'] = 5;

    $secretLeak = $base;
    $secretLeak['deferred_action_plan'][4]['command'] = 'Authorization: Bearer abc';

    $wrongProductionRoot = $base;
    $wrongProductionRoot['environment_policy']['production_deployed']['root'] = 'https://www.aiweline.com';

    $badAcceptanceContract = $base;
    unset($badAcceptanceContract['acceptance_contract'][3]);
    $badAcceptanceContract['acceptance_contract'] = array_values($badAcceptanceContract['acceptance_contract']);

    $missingLocalCheckoutIdentity = $base;
    unset($missingLocalCheckoutIdentity['environment_policy']['local_development']['checkout']);

    $missingLocalEnvEndpointLock = $base;
    unset($missingLocalEnvEndpointLock['preflight_checks']['local_readiness_app_env_wls_endpoint_locked']);

    $missingDriftFingerprint = $base;
    unset($missingDriftFingerprint['source_summary']['drift_review_fingerprint']);

    $missingAuthorizationReview = $base;
    unset($missingAuthorizationReview['deferred_action_plan'][0]['pre_authorization_review_command']);

    $missingSchemaSync = $base;
    unset($missingSchemaSync['deferred_action_plan'][0]['schema_sync']);

    $badLocalCapture = $base;
    unset($badLocalCapture['operator_sequence'][0]['requires']);

    $missingProductionCapture = $base;
    unset($missingProductionCapture['operator_sequence'][2]);
    $missingProductionCapture['operator_sequence'] = array_values($missingProductionCapture['operator_sequence']);

    $badProductionCapture = $base;
    $badProductionCapture['operator_sequence'][2]['command'] = 'php tools\\wls-panel-live-e2e-capture.php --environment=production --allow-live=1 --evidence-output=var\\wls-panel-plan\\production-appstore-live-e2e.json';

    $missingProductionDefaultSource = $base;
    $missingProductionDefaultSource['operator_sequence'][2]['requires'] = array_values(array_filter(
        $missingProductionDefaultSource['operator_sequence'][2]['requires'],
        static fn (string $requirement): bool => !str_contains($requirement, 'appstore_platform_url_source=production_default')
    ));

    $ready = $base;
    $ready['current_state'] = 'ready_for_local_live_capture';
    $ready['preflight_checks']['deferred_action_plan_all_blocked'] = true;
    $ready['deferred_action_plan'][5]['safe_to_run_now'] = true;

    $cases = [
        [
            'name' => 'accepts_blocked_contract',
            'case_ok' => wlsPanelDeferredActionsValidate($base)['passed'] === true,
        ],
        [
            'name' => 'accepts_ready_contract_with_only_live_runnable',
            'case_ok' => wlsPanelDeferredActionsValidate($ready)['passed'] === true,
        ],
        [
            'name' => 'rejects_missing_required_action',
            'case_ok' => wlsPanelDeferredActionsValidate($missingStart)['passed'] === false,
        ],
        [
            'name' => 'rejects_secret_leak',
            'case_ok' => wlsPanelDeferredActionsValidate($secretLeak)['passed'] === false,
        ],
        [
            'name' => 'rejects_www_production_root',
            'case_ok' => wlsPanelDeferredActionsValidate($wrongProductionRoot)['passed'] === false,
        ],
        [
            'name' => 'rejects_missing_acceptance_contract_invariant',
            'case_ok' => wlsPanelDeferredActionsValidate($badAcceptanceContract)['passed'] === false,
        ],
        [
            'name' => 'rejects_missing_local_app_checkout_identity',
            'case_ok' => wlsPanelDeferredActionsValidate($missingLocalCheckoutIdentity)['passed'] === false,
        ],
        [
            'name' => 'rejects_missing_local_app_env_endpoint_lock',
            'case_ok' => wlsPanelDeferredActionsValidate($missingLocalEnvEndpointLock)['passed'] === false,
        ],
        [
            'name' => 'rejects_missing_drift_review_fingerprint',
            'case_ok' => wlsPanelDeferredActionsValidate($missingDriftFingerprint)['passed'] === false,
        ],
        [
            'name' => 'rejects_missing_authorization_packet_review_command',
            'case_ok' => wlsPanelDeferredActionsValidate($missingAuthorizationReview)['passed'] === false,
        ],
        [
            'name' => 'rejects_missing_schema_sync_diagnostic',
            'case_ok' => wlsPanelDeferredActionsValidate($missingSchemaSync)['passed'] === false,
        ],
        [
            'name' => 'rejects_local_capture_without_reviewed_prerequisites',
            'case_ok' => wlsPanelDeferredActionsValidate($badLocalCapture)['passed'] === false,
        ],
        [
            'name' => 'rejects_missing_production_capture_operator_step',
            'case_ok' => wlsPanelDeferredActionsValidate($missingProductionCapture)['passed'] === false,
        ],
        [
            'name' => 'rejects_production_capture_without_deploy_current',
            'case_ok' => wlsPanelDeferredActionsValidate($badProductionCapture)['passed'] === false,
        ],
        [
            'name' => 'rejects_production_capture_without_production_default_source',
            'case_ok' => wlsPanelDeferredActionsValidate($missingProductionDefaultSource)['passed'] === false,
        ],
    ];

    return [
        'passed' => !in_array(false, array_column($cases, 'case_ok'), true),
        'self_test' => true,
        'cases' => $cases,
        'side_effects' => 'in-memory self-test: no file read, no network, no token, no WLS start, no writes',
    ];
}

$args = wlsPanelDeferredActionsParseArgs($argv);
if (($args['self-test'] ?? '0') === '1') {
    $result = wlsPanelDeferredActionsSelfTest();
    wlsPanelDeferredActionsFinish($result, ($result['passed'] ?? false) === true ? 0 : WLS_PANEL_DEFERRED_ACTIONS_FAILED);
}

$workspaceRoot = trim((string)($args['workspace-root'] ?? dirname(__DIR__, 7)));
$workorderJson = trim((string)($args['workorder-json'] ?? ''));
if ($workorderJson !== '') {
    $payload = json_decode((string)file_get_contents($workorderJson), true);
    $workorderResult = [
        'exit_code' => 0,
        'parsed' => is_array($payload),
        'payload' => is_array($payload) ? $payload : [],
        'output_bytes' => is_file($workorderJson) ? (int)filesize($workorderJson) : 0,
    ];
} else {
    $workorderResult = wlsPanelDeferredActionsRunTool(
        $workspaceRoot,
        'app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-final-workorder.php'
    );
}

$payload = is_array($workorderResult['payload'] ?? null) ? $workorderResult['payload'] : [];
if (($workorderResult['parsed'] ?? false) !== true || $payload === []) {
    wlsPanelDeferredActionsFinish([
        'passed' => false,
        'errors' => ['workorder_unavailable'],
        'tool_results' => [
            'final_workorder' => [
                'exit_code' => (int)($workorderResult['exit_code'] ?? 127),
                'parsed' => ($workorderResult['parsed'] ?? false) === true,
                'output_bytes' => (int)($workorderResult['output_bytes'] ?? 0),
            ],
        ],
        'side_effects' => 'read-only validator: no sync, no setup, no WLS start, no writes, no live API calls',
    ], WLS_PANEL_DEFERRED_ACTIONS_FAILED);
}

$result = wlsPanelDeferredActionsValidate($payload);
$result['tool_results'] = [
    'final_workorder' => [
        'exit_code' => (int)($workorderResult['exit_code'] ?? 0),
        'parsed' => true,
        'output_bytes' => (int)($workorderResult['output_bytes'] ?? 0),
    ],
];
$result['side_effects'] = 'read-only validator: no sync, no setup, no WLS start, no writes, no live API calls';

wlsPanelDeferredActionsFinish($result, ($result['passed'] ?? false) === true ? 0 : WLS_PANEL_DEFERRED_ACTIONS_FAILED);
