<?php
declare(strict_types=1);

/**
 * Reads the WLS Panel Plan completion documents and reports whether the full
 * WLS Panel objective is currently complete.
 *
 * By default the tool exits 0 and prints the audit payload so it can be used in
 * local status checks. Pass --fail-on-incomplete=1 for release gates.
 */

const WLS_PANEL_COMPLETION_EXIT_ASSERTION_FAILED = 1;

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function wlsPanelCompletionParseArgs(array $argv): array
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
function wlsPanelCompletionFinish(array $payload, int $exitCode): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($exitCode);
}

function wlsPanelCompletionReadFile(string $path, array &$errors): string
{
    if (!is_file($path) || !is_readable($path)) {
        $errors[] = 'file_unreadable:' . $path;
        return '';
    }

    return (string)file_get_contents($path);
}

function wlsPanelCompletionSection(string $content, string $startHeading, string $endHeading): string
{
    $start = strpos($content, $startHeading);
    if ($start === false) {
        return '';
    }

    $start += strlen($startHeading);
    $end = strpos($content, $endHeading, $start);
    if ($end === false) {
        $end = strlen($content);
    }

    return substr($content, $start, $end - $start);
}

/**
 * @return list<string>
 */
function wlsPanelCompletionSplitMarkdownRow(string $line): array
{
    $line = trim($line);
    if ($line === '' || !str_starts_with($line, '|')) {
        return [];
    }

    $line = trim($line, '|');
    $cells = array_map(static fn(string $cell): string => trim($cell), explode('|', $line));
    if ($cells === [] || preg_match('/^-+$/', str_replace(' ', '', implode('', $cells))) === 1) {
        return [];
    }

    return $cells;
}

/**
 * @return list<array{requirement:string,status:string,evidence:string,remaining_gate:string}>
 */
function wlsPanelCompletionRequirementRows(string $auditContent): array
{
    $section = wlsPanelCompletionSection($auditContent, '## Requirement Matrix', '## Current Host Gate Refresh');
    $rows = [];
    foreach (preg_split('/\R/', $section) ?: [] as $line) {
        $cells = wlsPanelCompletionSplitMarkdownRow((string)$line);
        if (count($cells) < 4 || strtolower($cells[0]) === 'requirement') {
            continue;
        }

        $rows[] = [
            'requirement' => $cells[0],
            'status' => $cells[1],
            'evidence' => $cells[2],
            'remaining_gate' => $cells[3],
        ];
    }

    return $rows;
}

/**
 * @return list<array{requirement:string,status:string,evidence:string,remaining_gate:string}>
 */
function wlsPanelCompletionTraceabilityRows(string $traceContent): array
{
    $section = wlsPanelCompletionSection($traceContent, '## Requirement Matrix', '## Current Hard Gate');
    $rows = [];
    foreach (preg_split('/\R/', $section) ?: [] as $line) {
        $cells = wlsPanelCompletionSplitMarkdownRow((string)$line);
        if (count($cells) < 4 || strtolower($cells[0]) === 'user requirement') {
            continue;
        }

        $rows[] = [
            'requirement' => $cells[0],
            'status' => $cells[2],
            'evidence' => $cells[1],
            'remaining_gate' => $cells[3],
        ];
    }

    return $rows;
}

function wlsPanelCompletionStatusIsProven(string $status): bool
{
    return str_starts_with(strtolower(trim($status)), 'proven');
}

/**
 * @param list<string> $relativePaths
 * @return list<string>
 */
function wlsPanelCompletionMissingFiles(string $baseDir, array $relativePaths): array
{
    $missing = [];
    foreach ($relativePaths as $relativePath) {
        $path = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($path)) {
            $missing[] = $relativePath;
        }
    }

    return $missing;
}

/**
 * @param array<string, string> $documents
 * @return array<string, bool>
 */
function wlsPanelCompletionRequiredTextChecks(array $documents): array
{
    $combined = implode("\n", $documents);

    return [
        'local_appstore_url' => str_contains($combined, 'https://app.weline.test:9523'),
        'production_appstore_url' => str_contains($combined, 'https://app.aiweline.com'),
        'official_www_not_marketplace' => str_contains($combined, 'www.aiweline.com') && str_contains($combined, 'not the WLS Panel marketplace endpoint'),
        'deploy_current_metadata' => str_contains($combined, 'var/deploy/current.json')
            && str_contains($combined, 'appstore_environment')
            && str_contains($combined, 'appstore_platform_url')
            && str_contains($combined, 'appstore_platform_url_source'),
        'deploy_endpoint_policy_gate' => str_contains($combined, 'validate-deploy-appstore-endpoint-policy.php')
            && str_contains($combined, 'https://app.aiweline.com')
            && str_contains($combined, 'local_records_exact_app_weline_platform_url')
            && str_contains($combined, 'production_records_exact_app_aiweline_platform_url'),
        'endpoint_source_contract_gate' => str_contains($combined, 'validate-appstore-endpoint-source-contract.php')
            && str_contains($combined, 'endpoint_source_contract_passed')
            && str_contains($combined, 'DeployOrchestratorService')
            && str_contains($combined, 'AppStorePlatformUrlResolver')
            && str_contains($combined, 'panel_has_locked_fallback_defaults')
            && str_contains($combined, 'panel_fallback_local_mode_is_explicit_only')
            && str_contains($combined, 'panel_fallback_reads_deployed_production_current')
            && str_contains($combined, 'deploy_rejects_official_website_marketplace_sources')
            && str_contains($combined, 'resolver_rejects_official_website_marketplace_sources')
            && str_contains($combined, 'panel_fallback_rejects_official_website_marketplace_sources'),
        'local_live_gate_wrapper' => str_contains($combined, 'local-appstore-typed-tag-live-gate.php')
            && str_contains($combined, 'local-appstore-typed-tag-live-gate.php --self-test=1')
            && str_contains($combined, 'local_live_gate_self_test_passed')
            && str_contains($combined, 'local_live_gate_guard_passed')
            && str_contains($combined, 'local_live_gate_no_live_call')
            && str_contains($combined, 'local_live_gate_premature_allow_blocked_no_live_call')
            && str_contains($combined, 'manual_endpoint_rejected')
            && str_contains($combined, 'local_insecure_disabled')
            && str_contains($combined, 'local_deploy_policy_exact_root')
            && str_contains($combined, 'readiness_app_env_deploy_mode_local')
            && str_contains($combined, 'readiness_deploy_current_locked')
            && str_contains($combined, '--allow-live=1'),
        'production_live_gate_wrapper' => str_contains($combined, 'production-appstore-typed-tag-live-gate.php')
            && str_contains($combined, 'production-appstore-typed-tag-live-gate.php --self-test=1')
            && str_contains($combined, 'production_live_gate_self_test_passed')
            && str_contains($combined, 'production_live_gate_guard_passed')
            && str_contains($combined, 'production_live_gate_no_live_call')
            && str_contains($combined, 'production_live_gate_premature_allow_blocked_no_live_call')
            && str_contains($combined, 'manual_endpoint_rejected')
            && str_contains($combined, 'production_insecure_disabled')
            && str_contains($combined, 'production_deploy_policy_exact_root')
            && str_contains($combined, 'appstore_platform_url=https://app.aiweline.com')
            && str_contains($combined, 'appstore_platform_url_source=production_default')
            && str_contains($combined, '--allow-live=1'),
        'typed_tag_runner_self_test' => str_contains($combined, 'marketplace-typed-tag-e2e.php --self-test=1')
            && str_contains($combined, 'module:wls-extra'),
        'typed_tag_negative_conclusive_gate' => str_contains($combined, '--require-negative-conclusive=1')
            && str_contains($combined, 'negative canary'),
        'official_manifest_canary_gate' => str_contains($combined, 'official-apps/manifest.json')
            && str_contains($combined, 'official_manifest_has_negative_canary')
            && str_contains($combined, 'official_manifest_negative_canary_exact'),
        'official_manifest_contract_validator' => str_contains($combined, 'validate-official-appstore-manifest-contract.php')
            && str_contains($combined, 'Weline_FileManager')
            && str_contains($combined, 'custom:wls-tag-canary'),
        'official_manifest_template_output' => str_contains($combined, '--template=1')
            && str_contains($combined, 'manifest_template')
            && str_contains($combined, 'Weline_PhpManager')
            && str_contains($combined, 'Weline_DbManager')
            && str_contains($combined, 'Weline_Deploy'),
        'official_manifest_materialize_guard' => str_contains($combined, '--template-target=')
            && str_contains($combined, 'WRITE_WLS_OFFICIAL_MANIFEST')
            && str_contains($combined, 'WRITE_WLS_OFFICIAL_SOURCES')
            && str_contains($combined, 'materialize.would_write=true')
            && str_contains($combined, 'source_plan')
            && str_contains($combined, 'authorized_source_write_command'),
        'local_readiness_materialize_plan' => str_contains($combined, 'official_manifest_materialize')
            && str_contains($combined, 'dry_run_command')
            && str_contains($combined, 'authorized_write_command'),
        'local_readiness_next_actions' => str_contains($combined, 'next_actions')
            && str_contains($combined, '--action-plan-only=1')
            && str_contains($combined, 'app_checkout_is_framework_official_app')
            && str_contains($combined, 'app_checkout_has_platform_appstore_module')
            && str_contains($combined, 'app_checkout_has_appstore_module')
            && str_contains($combined, 'app_env_deploy_mode_local')
            && str_contains($combined, 'app_env_wls_endpoint_matches_deploy_current')
            && str_contains($combined, 'app_env_wls_endpoint_matches_probe_endpoint')
            && str_contains($combined, 'local_deploy_current_matches_probe_endpoint')
            && str_contains($combined, 'select_local_appstore_checkout')
            && str_contains($combined, 'fix_local_deploy_current_marketplace_metadata')
            && str_contains($combined, 'safe_to_run_now')
            && str_contains($combined, 'run_local_app_setup_after_sync')
            && str_contains($combined, 'setup:upgrade --route --skip-env-check --skip-composer-dump')
            && str_contains($combined, 'run_live_typed_tag_e2e')
            && str_contains($combined, 'local-appstore-typed-tag-live-gate.php --allow-live=1'),
        'final_preflight_gate' => str_contains($combined, 'wls-panel-final-preflight.php')
            && str_contains($combined, 'ready_for_live_local_appstore_e2e')
            && str_contains($combined, '--report-only=1')
            && str_contains($combined, 'read-only aggregate')
            && str_contains($combined, 'local_readiness_app_checkout_identity_ok')
            && str_contains($combined, 'local_readiness_app_env_deploy_mode_local')
            && str_contains($combined, 'local_readiness_app_env_wls_endpoint_locked')
            && str_contains($combined, 'local_readiness_deploy_current_locked')
            && str_contains($combined, 'official_manifest_self_test_passed')
            && str_contains($combined, 'official_manifest_template_dry_run_passed')
            && str_contains($combined, 'official_manifest_template_catalog_contract_ok')
            && str_contains($combined, 'official_manifest_catalog_summary')
            && str_contains($combined, 'official_manifest_template_would_write')
            && str_contains($combined, 'official_manifest_source_plan_ready')
            && str_contains($combined, 'official_manifest_source_plan_would_write')
            && str_contains($combined, 'endpoint_source_contract_passed')
            && str_contains($combined, 'local_live_gate_self_test_passed')
            && str_contains($combined, 'local_live_gate_guard_passed')
            && str_contains($combined, 'local_live_gate_no_live_call')
            && str_contains($combined, 'local_live_gate_premature_allow_blocked_no_live_call')
            && str_contains($combined, 'production_live_gate_self_test_passed')
            && str_contains($combined, 'production_live_gate_guard_passed')
            && str_contains($combined, 'production_live_gate_no_live_call')
            && str_contains($combined, 'production_live_gate_premature_allow_blocked_no_live_call')
            && str_contains($combined, 'blocked_preflight_no_evidence_files')
            && str_contains($combined, 'local_deploy_endpoint_policy_passed')
            && str_contains($combined, 'local_deploy_endpoint_policy_exact_root')
            && str_contains($combined, 'production_deploy_endpoint_policy_passed')
            && str_contains($combined, 'production_deploy_endpoint_policy_exact_root')
            && str_contains($combined, 'authorization_pack_self_test_passed')
            && str_contains($combined, 'final_workorder_self_test_passed')
            && str_contains($combined, 'live_e2e_evidence_validator_self_test_passed')
            && str_contains($combined, 'live_e2e_capture_self_test_passed')
            && str_contains($combined, 'live_e2e_final_gate_self_test_passed')
            && str_contains($combined, 'deferred_actions_validator_self_test_passed')
            && str_contains($combined, 'goal_completion_gate_self_test_passed')
            && str_contains($combined, 'local_endpoint_locked')
            && str_contains($combined, 'production_endpoint_locked'),
        'goal_completion_gate' => str_contains($combined, 'wls-panel-goal-completion-gate.php')
            && str_contains($combined, 'wls-panel-goal-completion-gate.php --self-test=1')
            && str_contains($combined, 'completion_audit_complete')
            && str_contains($combined, 'completion_matrix_all_proven')
            && str_contains($combined, 'traceability_matrix_all_proven')
            && str_contains($combined, 'final_preflight_goal_complete')
            && str_contains($combined, 'workorder_authorization_consistency_passed')
            && str_contains($combined, 'workorder_authorization_consistency_roots_locked')
            && str_contains($combined, 'workorder_authorization_consistency_local_app_locked')
            && str_contains($combined, 'workorder_authorization_consistency_no_secret_values')
            && str_contains($combined, 'workorder_authorization_consistency_not_current')
            && str_contains($combined, 'rejects_workorder_authorization_consistency_drift')
            && str_contains($combined, 'rejects_workorder_authorization_production_www_root')
            && str_contains($combined, 'local_final_gate_ready')
            && str_contains($combined, 'production_final_gate_ready')
            && str_contains($combined, 'local_live_evidence_not_accepted')
            && str_contains($combined, 'production_live_evidence_not_accepted'),
        'deferred_actions_validator_gate' => str_contains($combined, 'validate-final-workorder-deferred-actions.php')
            && str_contains($combined, 'validate-final-workorder-deferred-actions.php --self-test=1')
            && str_contains($combined, 'required_actions_ordered')
            && str_contains($combined, 'blocked_state_all_actions_not_runnable')
            && str_contains($combined, 'ready_state_only_live_action_runnable')
            && str_contains($combined, 'sync_requires_user_authorization')
            && str_contains($combined, 'manifest_requires_confirmed_writes')
            && str_contains($combined, 'token_requires_secret_placeholder')
            && str_contains($combined, 'start_targets_app_weline_9523')
            && str_contains($combined, 'live_uses_guarded_local_gate')
            && str_contains($combined, 'production_capture_requires_production_default_source')
            && str_contains($combined, 'rejects_production_capture_without_production_default_source'),
        'final_workorder_gate' => str_contains($combined, 'wls-panel-final-workorder.php')
            && str_contains($combined, 'workorder_ready=true')
            && str_contains($combined, 'current_state=blocked_before_local_live_capture')
            && str_contains($combined, 'blocked_checks')
            && str_contains($combined, 'user_authorization_required')
            && str_contains($combined, 'user_secret_required')
            && str_contains($combined, 'forbidden_marketplace_roots')
            && str_contains($combined, 'deferred_action_plan')
            && str_contains($combined, 'readiness_action_count')
            && str_contains($combined, 'readiness_action_plan_contract_ok')
            && str_contains($combined, 'deferred_action_plan_all_blocked')
            && str_contains($combined, 'authorized_app_checkout_sync')
            && str_contains($combined, 'run_local_app_setup_after_sync')
            && str_contains($combined, 'prepare_official_manifest')
            && str_contains($combined, 'set_local_marketplace_bearer_token')
            && str_contains($combined, 'run_live_typed_tag_e2e')
            && str_contains($combined, 'local_live_capture_after_blockers_clear')
            && str_contains($combined, 'production_live_capture_after_launch')
            && str_contains($combined, 'production_capture_must_require_production_default_source'),
        'workorder_authorization_consistency_gate' => str_contains($combined, 'wls-panel-workorder-authorization-consistency.php')
            && str_contains($combined, 'workorder_authorization_consistency_self_test_passed')
            && str_contains($combined, 'preflight_local_app_checkout_identity_ok')
            && str_contains($combined, 'preflight_local_app_env_wls_endpoint_locked')
            && str_contains($combined, 'workorder_local_app_checkout_identity_ok')
            && str_contains($combined, 'workorder_local_app_env_wls_endpoint_locked')
            && str_contains($combined, 'authorization_local_app_checkout_identity_ok')
            && str_contains($combined, 'authorization_local_app_env_wls_endpoint_locked')
            && str_contains($combined, 'local_app_checkout_identity_consistent')
            && str_contains($combined, 'local_app_env_wls_endpoint_consistent'),
        'live_e2e_authorization_pack' => str_contains($combined, 'wls-panel-live-e2e-authorization-pack.php')
            && str_contains($combined, 'wls-panel-live-e2e-authorization-pack.php --self-test=1')
            && str_contains($combined, 'wls-panel-live-e2e-authorization-pack.php --fail-if-unsafe=1')
            && str_contains($combined, 'passed=true')
            && str_contains($combined, 'authorization_pack_ready_for_review')
            && str_contains($combined, 'current_state=blocked_before_live_run')
            && str_contains($combined, 'local_endpoint_exact_root')
            && str_contains($combined, 'production_endpoint_exact_root')
            && str_contains($combined, 'local_env_is_explicit_dev_or_local')
            && str_contains($combined, 'sync_manifest_ok')
            && str_contains($combined, 'local_live_gate_self_test_passed')
            && str_contains($combined, 'production_live_gate_self_test_passed')
            && str_contains($combined, 'live_gate_self_test_case_counts_ok')
            && str_contains($combined, 'capture_self_test_passed')
            && str_contains($combined, 'capture_path_traversal_guarded')
            && str_contains($combined, 'blocked_preflight_no_evidence_files')
            && str_contains($combined, 'official_manifest_catalog_contract_ok')
            && str_contains($combined, 'official_manifest_catalog_summary')
            && str_contains($combined, 'official_manifest_catalog_source_plan_ok')
            && str_contains($combined, 'all_side_effect_steps_deferred')
            && str_contains($combined, 'only_live_step_runnable_when_ready')
            && str_contains($combined, 'no_secret_values')
            && str_contains($combined, 'authorized_app_checkout_sync')
            && str_contains($combined, 'run_local_app_setup_after_sync')
            && str_contains($combined, 'prepare_official_manifest')
            && str_contains($combined, 'start_local_app_wls')
            && str_contains($combined, 'set_local_marketplace_bearer_token')
            && str_contains($combined, 'run_live_typed_tag_e2e'),
        'live_e2e_evidence_validator' => str_contains($combined, 'validate-appstore-live-e2e-evidence.php')
            && str_contains($combined, 'validate-appstore-live-e2e-evidence.php --self-test=1')
            && str_contains($combined, '--evidence=')
            && str_contains($combined, '--expect=local')
            && str_contains($combined, '--expect=production')
            && str_contains($combined, 'live_evidence')
            && str_contains($combined, 'single_tag_module_wls')
            && str_contains($combined, 'structured_tags_all_match')
            && str_contains($combined, 'negative_exact_match_module_wls')
            && str_contains($combined, 'require_negative_conclusive')
            && str_contains($combined, 'no_secret_values')
            && str_contains($combined, 'capture_metadata_present')
            && str_contains($combined, 'capture_metadata_source_gate')
            && str_contains($combined, 'evidence_endpoint_source_deploy_current')
            && str_contains($combined, 'capture_metadata_endpoint_source_deploy_current')
            && str_contains($combined, 'rejects_wrapper_non_deploy_current_endpoint_source')
            && str_contains($combined, 'rejects_wrapper_missing_capture_metadata')
            && str_contains($combined, 'capture_consistency_local_checkout_exact')
            && str_contains($combined, 'capture_consistency_local_env_wls_endpoint_exact')
            && str_contains($combined, 'local_development_checkout')
            && str_contains($combined, 'local_development_env_wls_endpoint')
            && str_contains($combined, 'rejects_wrapper_consistency_wrong_local_checkout')
            && str_contains($combined, 'rejects_wrapper_consistency_missing_env_endpoint_lock')
            && str_contains($combined, 'deployment-derived endpoint'),
        'live_e2e_capture_wrapper' => str_contains($combined, 'wls-panel-live-e2e-capture.php')
            && str_contains($combined, 'wls-panel-live-e2e-capture.php --self-test=1')
            && str_contains($combined, '--environment=local')
            && str_contains($combined, '--environment=production')
            && str_contains($combined, 'var\\wls-panel-plan\\local-appstore-live-e2e.json')
            && str_contains($combined, 'var\\wls-panel-plan\\production-appstore-live-e2e.json')
            && str_contains($combined, 'live_e2e_capture_self_test_passed')
            && str_contains($combined, 'captured_valid')
            && str_contains($combined, 'evidence_written')
            && str_contains($combined, 'captured_payload_has_metadata')
            && str_contains($combined, 'capture_metadata')
            && str_contains($combined, 'endpoint_source')
            && str_contains($combined, 'path_traversal_outside_var_rejected')
            && str_contains($combined, 'evidence_output_inside_var')
            && str_contains($combined, 'local_app_checkout_identity_consistent')
            && str_contains($combined, 'local_app_env_wls_endpoint_consistent')
            && str_contains($combined, 'local_development_checkout')
            && str_contains($combined, 'local_development_env_wls_endpoint')
            && str_contains($combined, 'var\\wls-panel-plan\\..\\leak.json')
            && str_contains($combined, 'validate-appstore-live-e2e-evidence.php --evidence=')
            && str_contains($combined, 'final_evidence_gate')
            && str_contains($combined, 'final_gate_passed_when_written'),
        'live_e2e_final_gate' => str_contains($combined, 'wls-panel-live-evidence-final-gate.php')
            && str_contains($combined, 'wls-panel-live-evidence-final-gate.php --self-test=1')
            && str_contains($combined, '--environment=local')
            && str_contains($combined, '--environment=production')
            && str_contains($combined, '--environment=both')
            && str_contains($combined, 'rejects_raw_runner_payload_for_final_gate')
            && str_contains($combined, 'rejects_missing_capture_metadata')
            && str_contains($combined, 'rejects_non_deploy_current_endpoint_source')
            && str_contains($combined, 'capture_consistency_local_app_identity_locked')
            && str_contains($combined, 'capture_consistency_local_app_env_endpoint_locked')
            && str_contains($combined, 'capture_consistency_local_checkout_exact')
            && str_contains($combined, 'capture_consistency_local_env_wls_endpoint_exact')
            && str_contains($combined, 'rejects_consistency_wrong_local_checkout')
            && str_contains($combined, 'rejects_consistency_missing_env_endpoint_lock')
            && str_contains($combined, 'live_e2e_final_gate_self_test_passed')
            && str_contains($combined, 'read-only final evidence gate'),
        'local_appstore_drift_report' => str_contains($combined, '--with-drift=1')
            && str_contains($combined, '--fail-on-drift=1')
            && str_contains($combined, '--drift-summary-only=1')
            && str_contains($combined, 'missing_app')
            && str_contains($combined, 'different')
            && str_contains($combined, 'rows_omitted'),
        'local_appstore_gate_named' => str_contains($combined, 'Local App Store')
            && str_contains($combined, 'typed-tag API E2E'),
        'completion_rule_named' => str_contains($combined, 'The WLS Panel goal can be treated as complete only when every row below is')
            && str_contains($combined, '`Proven`'),
    ];
}

$args = wlsPanelCompletionParseArgs($argv);
$baseDir = dirname(__DIR__);
$auditPath = trim((string)($args['audit'] ?? ($baseDir . DIRECTORY_SEPARATOR . '90-completion-audit-and-next-gates.md')));
$tracePath = trim((string)($args['trace'] ?? ($baseDir . DIRECTORY_SEPARATOR . '96-requirement-traceability.md')));
$runbookPath = trim((string)($args['runbook'] ?? ($baseDir . DIRECTORY_SEPARATOR . '95-final-acceptance-runbook.md')));
$manifestPath = trim((string)($args['manifest'] ?? ($baseDir . DIRECTORY_SEPARATOR . '92-local-appstore-sync-manifest.md')));
$contractPath = trim((string)($args['contract'] ?? ($baseDir . DIRECTORY_SEPARATOR . '93-official-appstore-manifest-contract.md')));
$failOnIncomplete = (string)($args['fail-on-incomplete'] ?? '0') === '1';

$errors = [];
$documents = [
    'audit' => wlsPanelCompletionReadFile($auditPath, $errors),
    'traceability' => wlsPanelCompletionReadFile($tracePath, $errors),
    'runbook' => wlsPanelCompletionReadFile($runbookPath, $errors),
    'manifest' => wlsPanelCompletionReadFile($manifestPath, $errors),
    'contract' => wlsPanelCompletionReadFile($contractPath, $errors),
];

$rows = $documents['audit'] !== '' ? wlsPanelCompletionRequirementRows($documents['audit']) : [];
$incomplete = [];
foreach ($rows as $row) {
    if (!wlsPanelCompletionStatusIsProven($row['status'])) {
        $incomplete[] = [
            'requirement' => $row['requirement'],
            'status' => $row['status'],
            'remaining_gate' => $row['remaining_gate'],
        ];
    }
}

$traceRows = $documents['traceability'] !== '' ? wlsPanelCompletionTraceabilityRows($documents['traceability']) : [];
$traceIncomplete = [];
foreach ($traceRows as $row) {
    if (!wlsPanelCompletionStatusIsProven($row['status'])) {
        $traceIncomplete[] = [
            'requirement' => $row['requirement'],
            'status' => $row['status'],
            'remaining_gate' => $row['remaining_gate'],
        ];
    }
}

$requiredFiles = [
    '10-prototype.md',
    '20-plugin-tag-logic.md',
    '30-atomic-task-plan.md',
    '75-stage-1-panel-shell-e2e-evidence.md',
    '76-wls-panel-plugin-ui-normalization-evidence.md',
    '77-current-integrated-verification-evidence.md',
    '90-completion-audit-and-next-gates.md',
    '92-local-appstore-sync-manifest.md',
    '93-official-appstore-manifest-contract.md',
    '95-final-acceptance-runbook.md',
    '96-requirement-traceability.md',
    'tools/deploy-current-local-development.json',
    'tools/deploy-current-production-default.json',
    'tools/local-appstore-readiness-probe.php',
    'tools/local-appstore-typed-tag-live-gate.php',
    'tools/marketplace-typed-tag-e2e.php',
    'tools/production-appstore-typed-tag-live-gate.php',
    'tools/validate-appstore-endpoint-source-contract.php',
    'tools/validate-appstore-live-e2e-evidence.php',
    'tools/validate-deploy-appstore-endpoint-policy.php',
    'tools/validate-final-workorder-deferred-actions.php',
    'tools/validate-local-appstore-sync-manifest.php',
    'tools/validate-official-appstore-manifest-contract.php',
    'tools/wls-panel-final-preflight.php',
    'tools/wls-panel-final-workorder.php',
    'tools/wls-panel-goal-completion-gate.php',
    'tools/wls-panel-live-e2e-authorization-pack.php',
    'tools/wls-panel-live-e2e-capture.php',
    'tools/wls-panel-live-evidence-final-gate.php',
    'tools/wls-panel-workorder-authorization-consistency.php',
    'tools/wls-panel-completion-audit.php',
];

$missingFiles = wlsPanelCompletionMissingFiles($baseDir, $requiredFiles);
$textChecks = wlsPanelCompletionRequiredTextChecks($documents);
foreach ($textChecks as $label => $passed) {
    if (!$passed) {
        $errors[] = 'missing_required_text:' . $label;
    }
}

if ($rows === []) {
    $errors[] = 'requirement_matrix_empty';
}

if ($traceRows === []) {
    $errors[] = 'traceability_matrix_empty';
}

if ($missingFiles !== []) {
    foreach ($missingFiles as $missingFile) {
        $errors[] = 'required_file_missing:' . $missingFile;
    }
}

$complete = $errors === [] && $incomplete === [] && $traceIncomplete === [];
$completionMatrixTotal = count($rows);
$completionIncompleteCount = count($incomplete);
$traceabilityMatrixTotal = count($traceRows);
$traceabilityIncompleteCount = count($traceIncomplete);
$firstIncomplete = $incomplete[0] ?? ($traceIncomplete[0] ?? null);
$payload = [
    'ok' => $errors === [],
    'complete' => $complete,
    'completion_matrix_total' => $completionMatrixTotal,
    'completion_proven_rows' => $completionMatrixTotal - $completionIncompleteCount,
    'completion_incomplete_rows' => $incomplete,
    'traceability_matrix_total' => $traceabilityMatrixTotal,
    'traceability_proven_rows' => $traceabilityMatrixTotal - $traceabilityIncompleteCount,
    'traceability_incomplete_rows' => $traceIncomplete,
    'summary' => [
        'complete' => $complete,
        'completion_total' => $completionMatrixTotal,
        'completion_proven_count' => $completionMatrixTotal - $completionIncompleteCount,
        'completion_matrix_total' => $completionMatrixTotal,
        'completion_proven_rows' => $completionMatrixTotal - $completionIncompleteCount,
        'completion_incomplete_count' => $completionIncompleteCount,
        'traceability_total' => $traceabilityMatrixTotal,
        'traceability_proven_count' => $traceabilityMatrixTotal - $traceabilityIncompleteCount,
        'traceability_matrix_total' => $traceabilityMatrixTotal,
        'traceability_proven_rows' => $traceabilityMatrixTotal - $traceabilityIncompleteCount,
        'traceability_incomplete_count' => $traceabilityIncompleteCount,
        'open_rows_total' => $completionIncompleteCount + $traceabilityIncompleteCount,
        'first_incomplete_requirement' => is_array($firstIncomplete) ? ($firstIncomplete['requirement'] ?? null) : null,
    ],
    'missing_files' => $missingFiles,
    'checks' => $textChecks,
    'errors' => $errors,
    'fail_on_incomplete' => $failOnIncomplete,
];

$exitCode = (!$complete && $failOnIncomplete) || $errors !== []
    ? WLS_PANEL_COMPLETION_EXIT_ASSERTION_FAILED
    : 0;
wlsPanelCompletionFinish($payload, $exitCode);
