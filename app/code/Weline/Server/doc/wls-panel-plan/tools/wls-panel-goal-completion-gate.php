<?php
declare(strict_types=1);

/**
 * Final read-only completion gate for the WLS Panel goal.
 *
 * This tool is intentionally stricter than the status-oriented completion
 * audit. It requires the documentation matrix, final preflight, final
 * workorder/authorization consistency, deferred action contract, and captured
 * local/production AppStore live evidence gates to agree before the thread goal
 * can be marked complete.
 *
 * It never syncs files, starts WLS, writes manifests, reads token values, or
 * calls AppStore live APIs.
 */

const WLS_PANEL_GOAL_GATE_FAILED = 1;
const WLS_PANEL_GOAL_GATE_LOCAL_APPSTORE_ROOT = 'https://app.weline.test:9523';
const WLS_PANEL_GOAL_GATE_PRODUCTION_APPSTORE_ROOT = 'https://app.aiweline.com';
const WLS_PANEL_GOAL_GATE_LOCAL_APP_CHECKOUT = 'E:\\WelineFramework\\Framework-Official\\App\\weline';

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function wlsPanelGoalGateParseArgs(array $argv): array
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
function wlsPanelGoalGateFinish(array $payload, int $exitCode): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($exitCode);
}

function wlsPanelGoalGatePath(string $base, string $relative): string
{
    return rtrim($base, "\\/") . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
}

/**
 * @return array<string, mixed>|null
 */
function wlsPanelGoalGateExtractJson(string $output): ?array
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
function wlsPanelGoalGateRunTool(string $workspaceRoot, string $relativeTool, array $args = []): array
{
    $command = array_merge([PHP_BINARY, wlsPanelGoalGatePath($workspaceRoot, $relativeTool)], $args);
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
    $payload = wlsPanelGoalGateExtractJson($output);

    return [
        'exit_code' => $exitCode,
        'parsed' => $payload !== null,
        'payload' => $payload,
        'output_bytes' => strlen($output),
    ];
}

/**
 * @param array<string, mixed> $completion
 * @param array<string, mixed> $preflight
 * @param array<string, mixed> $deferred
 * @param array<string, mixed> $consistency
 * @param array<string, mixed> $localFinal
 * @param array<string, mixed> $productionFinal
 * @return array<string, mixed>
 */
function wlsPanelGoalGateAssess(
    array $completion,
    array $preflight,
    array $deferred,
    array $consistency,
    array $localFinal,
    array $productionFinal
): array {
    $completionSummary = is_array($completion['summary'] ?? null) ? $completion['summary'] : [];
    $preflightChecks = is_array($preflight['checks'] ?? null) ? $preflight['checks'] : [];
    $consistencyChecks = is_array($consistency['checks'] ?? null) ? $consistency['checks'] : [];
    $consistencyContract = is_array($consistency['contract'] ?? null) ? $consistency['contract'] : [];
    $consistencySourceSummary = is_array($consistency['source_summary'] ?? null)
        ? $consistency['source_summary']
        : [];
    $localResults = is_array($localFinal['results'] ?? null) ? $localFinal['results'] : [];
    $productionResults = is_array($productionFinal['results'] ?? null) ? $productionFinal['results'] : [];
    $localEvidence = is_array($localResults['local'] ?? null) ? $localResults['local'] : [];
    $productionEvidence = is_array($productionResults['production'] ?? null) ? $productionResults['production'] : [];
    $localEvidencePath = str_replace('\\', '/', (string)($localEvidence['path'] ?? ''));
    $productionEvidencePath = str_replace('\\', '/', (string)($productionEvidence['path'] ?? ''));
    $localRequiredEnvironments = is_array($localFinal['required_environments'] ?? null)
        ? array_values($localFinal['required_environments'])
        : [];
    $productionRequiredEnvironments = is_array($productionFinal['required_environments'] ?? null)
        ? array_values($productionFinal['required_environments'])
        : [];

    $checks = [
        'completion_audit_complete' => ($completion['complete'] ?? false) === true,
        'completion_audit_has_no_errors' => ($completion['errors'] ?? []) === []
            && ($completion['missing_files'] ?? []) === [],
        'completion_matrix_all_proven' => (int)($completionSummary['completion_total'] ?? -1)
            === (int)($completionSummary['completion_proven_count'] ?? -2),
        'traceability_matrix_all_proven' => (int)($completionSummary['traceability_total'] ?? -1)
            === (int)($completionSummary['traceability_proven_count'] ?? -2),
        'final_preflight_ok' => ($preflight['ok'] ?? false) === true,
        'final_preflight_goal_complete' => ($preflight['goal_complete'] ?? false) === true,
        'final_preflight_contract_gates_green' =>
            ($preflightChecks['endpoint_source_contract_passed'] ?? false) === true
            && ($preflightChecks['local_deploy_endpoint_policy_exact_root'] ?? false) === true
            && ($preflightChecks['production_deploy_endpoint_policy_exact_root'] ?? false) === true
            && ($preflightChecks['deferred_actions_validator_self_test_passed'] ?? false) === true,
        'deferred_actions_valid' => ($deferred['passed'] ?? false) === true,
        'workorder_authorization_consistency_passed' => ($consistency['passed'] ?? false) === true,
        'workorder_authorization_consistency_roots_locked' =>
            ($consistencyChecks['drift_fingerprints_match'] ?? false) === true
            && ($consistencyChecks['preflight_local_endpoint_locked'] ?? false) === true
            && ($consistencyChecks['preflight_production_endpoint_locked'] ?? false) === true
            && ($consistencyChecks['workorder_local_root_locked'] ?? false) === true
            && ($consistencyChecks['workorder_production_root_locked'] ?? false) === true
            && ($consistencyChecks['authorization_local_root_locked'] ?? false) === true
            && ($consistencyChecks['authorization_production_root_locked'] ?? false) === true
            && ($consistencyContract['local_development_root'] ?? '') === WLS_PANEL_GOAL_GATE_LOCAL_APPSTORE_ROOT
            && ($consistencyContract['production_deployed_root'] ?? '') === WLS_PANEL_GOAL_GATE_PRODUCTION_APPSTORE_ROOT,
        'workorder_authorization_consistency_local_app_locked' =>
            ($consistencyChecks['preflight_local_app_checkout_identity_ok'] ?? false) === true
            && ($consistencyChecks['preflight_local_app_env_wls_endpoint_locked'] ?? false) === true
            && ($consistencyChecks['workorder_local_app_checkout_identity_ok'] ?? false) === true
            && ($consistencyChecks['workorder_local_app_env_wls_endpoint_locked'] ?? false) === true
            && ($consistencyChecks['authorization_local_app_checkout_identity_ok'] ?? false) === true
            && ($consistencyChecks['authorization_local_app_env_wls_endpoint_locked'] ?? false) === true
            && ($consistencyChecks['local_app_checkout_identity_consistent'] ?? false) === true
            && ($consistencyChecks['local_app_env_wls_endpoint_consistent'] ?? false) === true
            && ($consistencyContract['local_development_checkout'] ?? '') === WLS_PANEL_GOAL_GATE_LOCAL_APP_CHECKOUT
            && ($consistencyContract['local_development_env_wls_endpoint'] ?? '')
                === WLS_PANEL_GOAL_GATE_LOCAL_APPSTORE_ROOT,
        'workorder_authorization_consistency_no_secret_values' =>
            ($consistencyChecks['no_secret_values'] ?? false) === true,
        'local_final_gate_ready' => ($localFinal['ready'] ?? false) === true
            && ($localEvidence['accepted'] ?? false) === true
            && ($localEvidence['exists'] ?? false) === true,
        'local_final_gate_inside_var' => ($localEvidence['inside_var'] ?? false) === true,
        'local_final_gate_environment_scope' => ($localFinal['environment'] ?? '') === 'local'
            && $localRequiredEnvironments === ['local']
            && array_keys($localResults) === ['local'],
        'local_final_gate_canonical_evidence_path' => str_ends_with(
            $localEvidencePath,
            'var/wls-panel-plan/local-appstore-live-e2e.json'
        ),
        'production_final_gate_ready' => ($productionFinal['ready'] ?? false) === true
            && ($productionEvidence['accepted'] ?? false) === true
            && ($productionEvidence['exists'] ?? false) === true,
        'production_final_gate_inside_var' => ($productionEvidence['inside_var'] ?? false) === true,
        'production_final_gate_environment_scope' => ($productionFinal['environment'] ?? '') === 'production'
            && $productionRequiredEnvironments === ['production']
            && array_keys($productionResults) === ['production'],
        'production_final_gate_canonical_evidence_path' => str_ends_with(
            $productionEvidencePath,
            'var/wls-panel-plan/production-appstore-live-e2e.json'
        ),
        'local_and_production_evidence_paths_distinct' => $localEvidencePath !== ''
            && $productionEvidencePath !== ''
            && $localEvidencePath !== $productionEvidencePath,
    ];

    $errors = [];
    foreach ($checks as $name => $passed) {
        if ($passed !== true) {
            $errors[] = 'check_failed:' . $name;
        }
    }

    $blockers = [];
    if (!$checks['completion_audit_complete']) {
        $blockers[] = [
            'id' => 'completion_audit_incomplete',
            'details' => [
                'open_rows_total' => $completionSummary['open_rows_total'] ?? null,
                'first_incomplete_requirement' => $completionSummary['first_incomplete_requirement'] ?? null,
            ],
        ];
    }
    if (!$checks['local_final_gate_ready']) {
        $blockers[] = [
            'id' => 'local_live_evidence_not_accepted',
            'details' => [
                'path' => $localEvidence['path'] ?? null,
                'exists' => $localEvidence['exists'] ?? null,
                'inside_var' => $localEvidence['inside_var'] ?? null,
                'errors' => $localEvidence['errors'] ?? ($localFinal['errors'] ?? []),
            ],
        ];
    }
    if (!$checks['workorder_authorization_consistency_passed']
        || !$checks['workorder_authorization_consistency_roots_locked']
        || !$checks['workorder_authorization_consistency_local_app_locked']
        || !$checks['workorder_authorization_consistency_no_secret_values']
    ) {
        $blockers[] = [
            'id' => 'workorder_authorization_consistency_not_current',
            'details' => [
                'passed' => $consistency['passed'] ?? null,
                'local_development_checkout' => $consistencyContract['local_development_checkout'] ?? null,
                'local_development_env_wls_endpoint' =>
                    $consistencyContract['local_development_env_wls_endpoint'] ?? null,
                'production_deployed_root' => $consistencyContract['production_deployed_root'] ?? null,
                'errors' => $consistency['errors'] ?? [],
            ],
        ];
    }
    if (!$checks['production_final_gate_ready']) {
        $blockers[] = [
            'id' => 'production_live_evidence_not_accepted',
            'details' => [
                'path' => $productionEvidence['path'] ?? null,
                'exists' => $productionEvidence['exists'] ?? null,
                'inside_var' => $productionEvidence['inside_var'] ?? null,
                'errors' => $productionEvidence['errors'] ?? ($productionFinal['errors'] ?? []),
            ],
        ];
    }

    return [
        'complete' => $errors === [],
        'checks' => $checks,
        'errors' => $errors,
        'blockers' => $blockers,
        'summary' => [
            'completion_open_rows' => $completionSummary['open_rows_total'] ?? null,
            'first_incomplete_requirement' => $completionSummary['first_incomplete_requirement'] ?? null,
            'local_evidence_path' => $localEvidencePath !== '' ? $localEvidencePath : null,
            'local_evidence_inside_var' => $localEvidence['inside_var'] ?? null,
            'production_evidence_path' => $productionEvidencePath !== '' ? $productionEvidencePath : null,
            'production_evidence_inside_var' => $productionEvidence['inside_var'] ?? null,
            'workorder_authorization_consistency_passed' => $consistency['passed'] ?? null,
            'workorder_authorization_consistency_drift_fingerprint' =>
                $consistencySourceSummary['preflight_drift_review_fingerprint'] ?? null,
            'workorder_authorization_consistency_local_checkout' =>
                $consistencyContract['local_development_checkout'] ?? null,
            'workorder_authorization_consistency_local_env_wls_endpoint' =>
                $consistencyContract['local_development_env_wls_endpoint'] ?? null,
            'workorder_authorization_consistency_production_root' =>
                $consistencyContract['production_deployed_root'] ?? null,
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function wlsPanelGoalGateSelfTest(): array
{
    $completeAudit = [
        'complete' => true,
        'errors' => [],
        'missing_files' => [],
        'summary' => [
            'completion_total' => 14,
            'completion_proven_count' => 14,
            'traceability_total' => 22,
            'traceability_proven_count' => 22,
            'open_rows_total' => 0,
        ],
    ];
    $preflight = [
        'ok' => true,
        'goal_complete' => true,
        'checks' => [
            'endpoint_source_contract_passed' => true,
            'local_deploy_endpoint_policy_exact_root' => true,
            'production_deploy_endpoint_policy_exact_root' => true,
            'deferred_actions_validator_self_test_passed' => true,
        ],
    ];
    $deferred = ['passed' => true];
    $consistency = [
        'passed' => true,
        'checks' => [
            'drift_fingerprints_match' => true,
            'preflight_local_endpoint_locked' => true,
            'preflight_production_endpoint_locked' => true,
            'workorder_local_root_locked' => true,
            'workorder_production_root_locked' => true,
            'authorization_local_root_locked' => true,
            'authorization_production_root_locked' => true,
            'preflight_local_app_checkout_identity_ok' => true,
            'preflight_local_app_env_wls_endpoint_locked' => true,
            'workorder_local_app_checkout_identity_ok' => true,
            'workorder_local_app_env_wls_endpoint_locked' => true,
            'authorization_local_app_checkout_identity_ok' => true,
            'authorization_local_app_env_wls_endpoint_locked' => true,
            'local_app_checkout_identity_consistent' => true,
            'local_app_env_wls_endpoint_consistent' => true,
            'no_secret_values' => true,
        ],
        'contract' => [
            'local_development_root' => WLS_PANEL_GOAL_GATE_LOCAL_APPSTORE_ROOT,
            'local_development_checkout' => WLS_PANEL_GOAL_GATE_LOCAL_APP_CHECKOUT,
            'local_development_env_wls_endpoint' => WLS_PANEL_GOAL_GATE_LOCAL_APPSTORE_ROOT,
            'production_deployed_root' => WLS_PANEL_GOAL_GATE_PRODUCTION_APPSTORE_ROOT,
        ],
        'source_summary' => [
            'preflight_drift_review_fingerprint' => '1234567890abcdef',
        ],
        'errors' => [],
    ];
    $local = [
        'ready' => true,
        'environment' => 'local',
        'required_environments' => ['local'],
        'results' => [
            'local' => [
                'accepted' => true,
                'exists' => true,
                'path' => 'var/wls-panel-plan/local-appstore-live-e2e.json',
                'inside_var' => true,
            ],
        ],
    ];
    $production = [
        'ready' => true,
        'environment' => 'production',
        'required_environments' => ['production'],
        'results' => [
            'production' => [
                'accepted' => true,
                'exists' => true,
                'path' => 'var/wls-panel-plan/production-appstore-live-e2e.json',
                'inside_var' => true,
            ],
        ],
    ];

    $incompleteAudit = $completeAudit;
    $incompleteAudit['complete'] = false;
    $incompleteAudit['summary']['completion_proven_count'] = 13;
    $missingLocal = $local;
    $missingLocal['ready'] = false;
    $missingLocal['results']['local']['accepted'] = false;
    $missingLocal['results']['local']['exists'] = false;
    $missingProduction = $production;
    $missingProduction['ready'] = false;
    $missingProduction['results']['production']['accepted'] = false;
    $missingProduction['results']['production']['exists'] = false;
    $badPreflight = $preflight;
    $badPreflight['checks']['production_deploy_endpoint_policy_exact_root'] = false;
    $badConsistency = $consistency;
    $badConsistency['passed'] = false;
    $badConsistency['checks']['local_app_checkout_identity_consistent'] = false;
    $badConsistency['errors'] = ['check_failed:local_app_checkout_identity_consistent'];
    $badProductionConsistency = $consistency;
    $badProductionConsistency['passed'] = false;
    $badProductionConsistency['contract']['production_deployed_root'] = 'https://www.aiweline.com';
    $badProductionConsistency['checks']['workorder_production_root_locked'] = false;
    $badProductionConsistency['errors'] = ['check_failed:workorder_production_root_locked'];
    $swappedLocal = $production;
    $swappedProduction = $local;
    $wrongLocalPath = $local;
    $wrongLocalPath['results']['local']['path'] = 'var/wls-panel-plan/production-appstore-live-e2e.json';
    $localOutsideVar = $local;
    $localOutsideVar['results']['local']['inside_var'] = false;
    $productionOutsideVar = $production;
    $productionOutsideVar['results']['production']['inside_var'] = false;
    $wrongLocalEnvironment = $local;
    $wrongLocalEnvironment['environment'] = 'both';
    $wrongLocalEnvironment['required_environments'] = ['local', 'production'];
    $wrongLocalEnvironment['results']['production'] = [
        'accepted' => true,
        'exists' => true,
        'path' => 'var/wls-panel-plan/production-appstore-live-e2e.json',
        'inside_var' => true,
    ];
    $wrongProductionEnvironment = $production;
    $wrongProductionEnvironment['environment'] = 'both';
    $wrongProductionEnvironment['required_environments'] = ['local', 'production'];
    $wrongProductionEnvironment['results'] = [
        'local' => [
            'accepted' => true,
            'exists' => true,
            'path' => 'var/wls-panel-plan/local-appstore-live-e2e.json',
            'inside_var' => true,
        ],
    ] + $wrongProductionEnvironment['results'];

    $cases = [
        [
            'name' => 'accepts_complete_goal_evidence',
            'case_ok' =>
                wlsPanelGoalGateAssess($completeAudit, $preflight, $deferred, $consistency, $local, $production)['complete']
                === true,
        ],
        [
            'name' => 'rejects_incomplete_completion_audit',
            'case_ok' =>
                wlsPanelGoalGateAssess($incompleteAudit, $preflight, $deferred, $consistency, $local, $production)['complete']
                === false,
        ],
        [
            'name' => 'rejects_missing_local_evidence',
            'case_ok' =>
                wlsPanelGoalGateAssess($completeAudit, $preflight, $deferred, $consistency, $missingLocal, $production)['complete']
                === false,
        ],
        [
            'name' => 'rejects_missing_production_evidence',
            'case_ok' =>
                wlsPanelGoalGateAssess($completeAudit, $preflight, $deferred, $consistency, $local, $missingProduction)['complete']
                === false,
        ],
        [
            'name' => 'rejects_endpoint_policy_drift',
            'case_ok' =>
                wlsPanelGoalGateAssess($completeAudit, $badPreflight, $deferred, $consistency, $local, $production)['complete']
                === false,
        ],
        [
            'name' => 'rejects_workorder_authorization_consistency_drift',
            'case_ok' =>
                wlsPanelGoalGateAssess($completeAudit, $preflight, $deferred, $badConsistency, $local, $production)['complete']
                === false,
        ],
        [
            'name' => 'rejects_workorder_authorization_production_www_root',
            'case_ok' =>
                wlsPanelGoalGateAssess(
                    $completeAudit,
                    $preflight,
                    $deferred,
                    $badProductionConsistency,
                    $local,
                    $production
                )['complete'] === false,
        ],
        [
            'name' => 'rejects_swapped_local_production_final_gate_payloads',
            'case_ok' =>
                wlsPanelGoalGateAssess(
                    $completeAudit,
                    $preflight,
                    $deferred,
                    $consistency,
                    $swappedLocal,
                    $swappedProduction
                )['complete'] === false,
        ],
        [
            'name' => 'rejects_local_evidence_using_production_path',
            'case_ok' =>
                wlsPanelGoalGateAssess($completeAudit, $preflight, $deferred, $consistency, $wrongLocalPath, $production)['complete']
                === false,
        ],
        [
            'name' => 'rejects_local_final_gate_outside_var',
            'case_ok' =>
                wlsPanelGoalGateAssess($completeAudit, $preflight, $deferred, $consistency, $localOutsideVar, $production)['complete']
                === false,
        ],
        [
            'name' => 'rejects_production_final_gate_outside_var',
            'case_ok' =>
                wlsPanelGoalGateAssess(
                    $completeAudit,
                    $preflight,
                    $deferred,
                    $consistency,
                    $local,
                    $productionOutsideVar
                )['complete'] === false,
        ],
        [
            'name' => 'rejects_local_final_gate_with_both_environment_scope',
            'case_ok' =>
                wlsPanelGoalGateAssess(
                    $completeAudit,
                    $preflight,
                    $deferred,
                    $consistency,
                    $wrongLocalEnvironment,
                    $production
                )['complete'] === false,
        ],
        [
            'name' => 'rejects_production_final_gate_with_both_environment_scope',
            'case_ok' =>
                wlsPanelGoalGateAssess(
                    $completeAudit,
                    $preflight,
                    $deferred,
                    $consistency,
                    $local,
                    $wrongProductionEnvironment
                )['complete'] === false,
        ],
    ];

    return [
        'passed' => !in_array(false, array_column($cases, 'case_ok'), true),
        'self_test' => true,
        'cases' => $cases,
        'side_effects' => 'in-memory self-test: no file read, no network, no token, no WLS start, no writes',
    ];
}

$args = wlsPanelGoalGateParseArgs($argv);
if (($args['self-test'] ?? '0') === '1') {
    $selfTest = wlsPanelGoalGateSelfTest();
    wlsPanelGoalGateFinish($selfTest, ($selfTest['passed'] ?? false) === true ? 0 : WLS_PANEL_GOAL_GATE_FAILED);
}

$workspaceRoot = trim((string)($args['workspace-root'] ?? dirname(__DIR__, 7)));
$tools = 'app/code/Weline/Server/doc/wls-panel-plan/tools/';

$completion = wlsPanelGoalGateRunTool($workspaceRoot, $tools . 'wls-panel-completion-audit.php');
$preflight = wlsPanelGoalGateRunTool($workspaceRoot, $tools . 'wls-panel-final-preflight.php', ['--report-only=1']);
$deferred = wlsPanelGoalGateRunTool($workspaceRoot, $tools . 'validate-final-workorder-deferred-actions.php');
$consistency = wlsPanelGoalGateRunTool($workspaceRoot, $tools . 'wls-panel-workorder-authorization-consistency.php');
$localFinal = wlsPanelGoalGateRunTool($workspaceRoot, $tools . 'wls-panel-live-evidence-final-gate.php', [
    '--environment=local',
    '--report-only=1',
]);
$productionFinal = wlsPanelGoalGateRunTool($workspaceRoot, $tools . 'wls-panel-live-evidence-final-gate.php', [
    '--environment=production',
    '--report-only=1',
]);

$toolResults = [
    'completion_audit' => $completion,
    'final_preflight' => $preflight,
    'deferred_actions' => $deferred,
    'workorder_authorization_consistency' => $consistency,
    'local_final_gate' => $localFinal,
    'production_final_gate' => $productionFinal,
];
$parsed = true;
foreach ($toolResults as $result) {
    if (($result['parsed'] ?? false) !== true) {
        $parsed = false;
        break;
    }
}

$completionPayload = is_array($completion['payload'] ?? null) ? $completion['payload'] : [];
$preflightPayload = is_array($preflight['payload'] ?? null) ? $preflight['payload'] : [];
$deferredPayload = is_array($deferred['payload'] ?? null) ? $deferred['payload'] : [];
$consistencyPayload = is_array($consistency['payload'] ?? null) ? $consistency['payload'] : [];
$localFinalPayload = is_array($localFinal['payload'] ?? null) ? $localFinal['payload'] : [];
$productionFinalPayload = is_array($productionFinal['payload'] ?? null) ? $productionFinal['payload'] : [];
$assessment = $parsed
    ? wlsPanelGoalGateAssess(
        $completionPayload,
        $preflightPayload,
        $deferredPayload,
        $consistencyPayload,
        $localFinalPayload,
        $productionFinalPayload
    )
    : [
        'complete' => false,
        'checks' => ['all_tools_parsed' => false],
        'errors' => ['tool_unparsed'],
        'blockers' => [],
        'summary' => [],
    ];

$compactToolResults = [];
foreach ($toolResults as $name => $result) {
    $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
    $compactToolResults[$name] = [
        'exit_code' => (int)($result['exit_code'] ?? 127),
        'parsed' => ($result['parsed'] ?? false) === true,
        'output_bytes' => (int)($result['output_bytes'] ?? 0),
        'complete' => $payload['complete'] ?? null,
        'ready' => $payload['ready'] ?? null,
        'passed' => $payload['passed'] ?? null,
    ];
}

wlsPanelGoalGateFinish([
    'ok' => true,
    'complete' => ($assessment['complete'] ?? false) === true,
    'checks' => $assessment['checks'],
    'errors' => $assessment['errors'],
    'blockers' => $assessment['blockers'],
    'summary' => $assessment['summary'],
    'tool_results' => $compactToolResults,
    'notes' => [
        'side_effects' => 'read-only goal gate: no sync, no setup, no WLS start, no manifest writes, no token values, no live API calls',
    ],
], ($assessment['complete'] ?? false) === true ? 0 : WLS_PANEL_GOAL_GATE_FAILED);
