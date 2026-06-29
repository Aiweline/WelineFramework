<?php
declare(strict_types=1);

/**
 * Builds a read-only authorization packet for the final WLS Panel local
 * AppStore typed-tag live E2E gate.
 *
 * The packet is meant to be reviewed before any App checkout sync, manifest
 * write, WLS startup, token use, or live API call. This tool only runs existing
 * read-only gates and prints a bounded JSON plan.
 */

const WLS_PANEL_AUTH_PACK_EXIT_UNSAFE = 1;
const WLS_PANEL_AUTH_PACK_LOCAL_ROOT = 'https://app.weline.test:9523';
const WLS_PANEL_AUTH_PACK_PRODUCTION_ROOT = 'https://app.aiweline.com';
const WLS_PANEL_AUTH_PACK_MAX_DRIFT_ROWS = 60;

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function wlsPanelAuthPackParseArgs(array $argv): array
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
function wlsPanelAuthPackFinish(array $payload, int $exitCode): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($exitCode);
}

function wlsPanelAuthPackPath(string $base, string $relative): string
{
    return rtrim($base, "\\/") . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
}

/**
 * @return array<string, mixed>|null
 */
function wlsPanelAuthPackExtractJson(string $output): ?array
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
function wlsPanelAuthPackRunTool(string $workspaceRoot, string $relativeTool, array $args = []): array
{
    $toolPath = wlsPanelAuthPackPath($workspaceRoot, $relativeTool);
    $command = array_merge([PHP_BINARY, $toolPath], $args);
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
        'parsed' => wlsPanelAuthPackExtractJson($output) !== null,
        'payload' => wlsPanelAuthPackExtractJson($output),
        'output_bytes' => strlen($output),
    ];
}

/**
 * @return list<string>
 */
function wlsPanelAuthPackBlockedChecks(array $payload): array
{
    $blockers = $payload['blockers'] ?? [];
    if (!is_array($blockers)) {
        return [];
    }

    return array_values(array_filter(array_map('strval', $blockers)));
}

/**
 * @param list<array<string, mixed>> $actions
 * @return list<array<string, mixed>>
 */
function wlsPanelAuthPackNormalizeActions(array $actions): array
{
    $normalized = [];
    foreach ($actions as $action) {
        $id = (string)($action['id'] ?? '');
        if ($id === '') {
            continue;
        }

        $entry = [
            'id' => $id,
            'phase' => (string)($action['phase'] ?? ''),
            'requires_user_authorization' => ($action['requires_user_authorization'] ?? false) === true,
            'requires_user_secret' => ($action['requires_user_secret'] ?? false) === true,
            'safe_to_run_now' => ($action['safe_to_run_now'] ?? false) === true,
            'blocked_checks' => is_array($action['blocked_checks'] ?? null)
                ? array_values(array_map('strval', $action['blocked_checks']))
                : [],
        ];

        foreach ([
            'preflight_command',
            'post_sync_gate_command',
            'command',
            'dry_run_command',
            'authorized_write_command',
            'authorized_source_write_command',
            'authorized_catalog_write_command',
            'post_write_gate_command',
            'side_effects',
            'working_directory',
            'precondition',
        ] as $key) {
            if (array_key_exists($key, $action)) {
                $entry[$key] = (string)$action[$key];
            }
        }

        $normalized[] = $entry;
    }

    return $normalized;
}

/**
 * @return list<array<string, mixed>>
 */
function wlsPanelAuthPackExecutionOrder(array $readinessPayload): array
{
    $actions = $readinessPayload['next_actions'] ?? [];
    if (!is_array($actions)) {
        return [];
    }

    $byId = [];
    foreach (wlsPanelAuthPackNormalizeActions($actions) as $action) {
        $byId[(string)$action['id']] = $action;
    }

    $order = [];
    foreach ([
        'select_local_appstore_checkout',
        'fix_local_deploy_current_marketplace_metadata',
        'authorized_app_checkout_sync',
        'run_local_app_setup_after_sync',
        'prepare_official_manifest',
        'start_local_app_wls',
        'set_local_marketplace_bearer_token',
        'run_live_typed_tag_e2e',
    ] as $id) {
        if (isset($byId[$id])) {
            $order[] = $byId[$id];
        }
    }

    return $order;
}

function wlsPanelAuthPackNoSecretLeak(mixed $value): bool
{
    $text = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($text)) {
        return false;
    }

    $lower = strtolower($text);
    if (preg_match('/bearer\s+[a-z0-9._~+\/=-]{12,}/i', $text) === 1) {
        return false;
    }

    if (preg_match('/wls_marketplace_bearer_token[^<]{12,}/i', $text) === 1) {
        return false;
    }

    return !str_contains($lower, 'authorization: bearer ')
        && !str_contains($lower, 'cookie:')
        && !str_contains($lower, 'private_key')
        && !str_contains($lower, '-----begin');
}

/**
 * @param list<array<string, mixed>> $steps
 */
function wlsPanelAuthPackAllSideEffectsDeferred(array $steps): bool
{
    foreach ($steps as $step) {
        if (($step['safe_to_run_now'] ?? false) === true) {
            return false;
        }
    }

    return true;
}

/**
 * @param list<array<string, mixed>> $steps
 */
function wlsPanelAuthPackOnlyLiveStepRunnable(array $steps): bool
{
    $foundRunnableLiveStep = false;
    foreach ($steps as $step) {
        $isRunnable = ($step['safe_to_run_now'] ?? false) === true;
        if (!$isRunnable) {
            continue;
        }

        if ((string)($step['id'] ?? '') !== 'run_live_typed_tag_e2e') {
            return false;
        }

        $foundRunnableLiveStep = true;
    }

    return $foundRunnableLiveStep;
}

function wlsPanelAuthPackHasPassingCase(array $payload, string $caseName): bool
{
    $cases = $payload['cases'] ?? [];
    if (!is_array($cases)) {
        return false;
    }

    foreach ($cases as $case) {
        if (!is_array($case)) {
            continue;
        }

        if ((string)($case['name'] ?? '') === $caseName && ($case['case_ok'] ?? false) === true) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<array<string, mixed>> $rows
 */
function wlsPanelAuthPackDriftRowsBounded(array $rows, int $manifestTotal): bool
{
    $rowCount = count($rows);
    if ($rowCount > WLS_PANEL_AUTH_PACK_MAX_DRIFT_ROWS) {
        return false;
    }

    return $manifestTotal <= 0 || $rowCount <= $manifestTotal;
}

function wlsPanelAuthPackRollbackReviewSafe(array $status): bool
{
    if (($status['parsed'] ?? false) !== true || (int)($status['exit_code'] ?? 1) !== 0) {
        return false;
    }

    $fingerprint = (string)($status['out_of_scope_fingerprint'] ?? '');
    $total = (int)($status['total_status_count'] ?? 0);
    $outOfScope = (int)($status['out_of_scope_status_count'] ?? 0);
    $allowed = (int)($status['allowed_status_count'] ?? 0);
    if ($total !== $allowed + $outOfScope) {
        return false;
    }
    if ($outOfScope > 0 && $fingerprint === '') {
        return false;
    }

    $rows = is_array($status['rows'] ?? null) ? $status['rows'] : [];
    $outOfScopeRows = is_array($status['out_of_scope_rows'] ?? null) ? $status['out_of_scope_rows'] : [];

    return count($rows) === $total && count($outOfScopeRows) === $outOfScope;
}

/**
 * @return array<string, mixed>
 */
function wlsPanelAuthPackOfficialCatalogSummary(array $preflightPayload): array
{
    $summary = $preflightPayload['summary']['official_manifest_catalog_summary'] ?? null;
    if (is_array($summary)) {
        return $summary;
    }

    $toolSummary = $preflightPayload['tool_results']['official_manifest_template']['catalog_summary'] ?? null;
    return is_array($toolSummary) ? $toolSummary : [];
}

/**
 * @return array{local_passed:bool,production_passed:bool,local_case_count:int,production_case_count:int,case_counts_ok:bool}
 */
function wlsPanelAuthPackLiveGateSelfTestSummary(array $preflightPayload): array
{
    $checks = is_array($preflightPayload['checks'] ?? null) ? $preflightPayload['checks'] : [];
    $summary = is_array($preflightPayload['summary'] ?? null) ? $preflightPayload['summary'] : [];

    $localCaseCount = (int)($summary['local_live_gate_self_test_case_count'] ?? 0);
    $productionCaseCount = (int)($summary['production_live_gate_self_test_case_count'] ?? 0);

    return [
        'local_passed' => ($checks['local_live_gate_self_test_passed'] ?? false) === true,
        'production_passed' => ($checks['production_live_gate_self_test_passed'] ?? false) === true,
        'local_case_count' => $localCaseCount,
        'production_case_count' => $productionCaseCount,
        'case_counts_ok' => $localCaseCount >= 6 && $productionCaseCount >= 6,
    ];
}

$args = wlsPanelAuthPackParseArgs($argv);

if ((string)($args['self-test'] ?? '0') === '1') {
    $safeSteps = [
        [
            'id' => 'authorized_app_checkout_sync',
            'safe_to_run_now' => false,
        ],
        [
            'id' => 'run_local_app_setup_after_sync',
            'safe_to_run_now' => false,
        ],
        [
            'id' => 'run_live_typed_tag_e2e',
            'safe_to_run_now' => false,
        ],
    ];
    $readySteps = [
        [
            'id' => 'authorized_app_checkout_sync',
            'safe_to_run_now' => false,
        ],
        [
            'id' => 'prepare_official_manifest',
            'safe_to_run_now' => false,
        ],
        [
            'id' => 'run_live_typed_tag_e2e',
            'safe_to_run_now' => true,
        ],
    ];
    $unsafeSteps = [
        [
            'id' => 'authorized_app_checkout_sync',
            'safe_to_run_now' => true,
        ],
        [
            'id' => 'run_live_typed_tag_e2e',
            'safe_to_run_now' => false,
        ],
    ];

    $cases = [
        [
            'name' => 'safe_packet_has_no_secret_values',
            'expected' => true,
            'actual' => wlsPanelAuthPackNoSecretLeak([
                'command' => '$env:WLS_MARKETPLACE_BEARER_TOKEN = \'<set outside docs>\'',
                'note' => 'Token value is supplied outside repository files.',
            ]),
        ],
        [
            'name' => 'bearer_value_is_rejected',
            'expected' => false,
            'actual' => wlsPanelAuthPackNoSecretLeak([
                'header' => 'Authorization: Bearer abcdefghijklmnop123456',
            ]),
        ],
        [
            'name' => 'cookie_value_is_rejected',
            'expected' => false,
            'actual' => wlsPanelAuthPackNoSecretLeak([
                'header' => 'Cookie: admin_session=abcdef1234567890',
            ]),
        ],
        [
            'name' => 'private_key_marker_is_rejected',
            'expected' => false,
            'actual' => wlsPanelAuthPackNoSecretLeak([
                'key' => '-----BEGIN PRIVATE KEY-----',
            ]),
        ],
        [
            'name' => 'blocked_state_requires_all_deferred',
            'expected' => true,
            'actual' => wlsPanelAuthPackAllSideEffectsDeferred($safeSteps),
        ],
        [
            'name' => 'ready_state_allows_only_live_step',
            'expected' => true,
            'actual' => wlsPanelAuthPackOnlyLiveStepRunnable($readySteps),
        ],
        [
            'name' => 'ready_state_rejects_non_live_runnable_step',
            'expected' => false,
            'actual' => wlsPanelAuthPackOnlyLiveStepRunnable($unsafeSteps),
        ],
        [
            'name' => 'execution_order_promotes_checkout_selection_before_sync',
            'expected' => [
                'select_local_appstore_checkout',
                'authorized_app_checkout_sync',
                'run_live_typed_tag_e2e',
            ],
            'actual' => array_map(
                static fn(array $action): string => (string)($action['id'] ?? ''),
                wlsPanelAuthPackExecutionOrder([
                    'next_actions' => [
                        ['id' => 'run_live_typed_tag_e2e'],
                        ['id' => 'authorized_app_checkout_sync'],
                        ['id' => 'select_local_appstore_checkout'],
                    ],
                ])
            ),
        ],
        [
            'name' => 'capture_guard_requires_path_traversal_case',
            'expected' => true,
            'actual' => wlsPanelAuthPackHasPassingCase([
                'cases' => [
                    [
                        'name' => 'path_traversal_outside_var_rejected',
                        'case_ok' => true,
                    ],
                ],
            ], 'path_traversal_outside_var_rejected'),
        ],
        [
            'name' => 'capture_guard_rejects_missing_path_traversal_case',
            'expected' => false,
            'actual' => wlsPanelAuthPackHasPassingCase([
                'cases' => [
                    [
                        'name' => 'outside_var_rejected',
                        'case_ok' => true,
                    ],
                ],
            ], 'path_traversal_outside_var_rejected'),
        ],
        [
            'name' => 'capture_guard_requires_consistency_contract_case',
            'expected' => true,
            'actual' => wlsPanelAuthPackHasPassingCase([
                'cases' => [
                    [
                        'name' => 'captured_payload_preserves_consistency_contract',
                        'case_ok' => true,
                    ],
                ],
            ], 'captured_payload_preserves_consistency_contract'),
        ],
        [
            'name' => 'capture_guard_rejects_missing_consistency_contract_case',
            'expected' => false,
            'actual' => wlsPanelAuthPackHasPassingCase([
                'cases' => [
                    [
                        'name' => 'captured_payload_has_metadata',
                        'case_ok' => true,
                    ],
                ],
            ], 'captured_payload_preserves_consistency_contract'),
        ],
        [
            'name' => 'live_gate_self_tests_require_both_wrappers_and_case_counts',
            'expected' => true,
            'actual' => !in_array(false, wlsPanelAuthPackLiveGateSelfTestSummary([
                'checks' => [
                    'local_live_gate_self_test_passed' => true,
                    'production_live_gate_self_test_passed' => true,
                ],
                'summary' => [
                    'local_live_gate_self_test_case_count' => 6,
                    'production_live_gate_self_test_case_count' => 6,
                ],
            ]), true),
        ],
        [
            'name' => 'live_gate_self_tests_reject_missing_production_wrapper',
            'expected' => false,
            'actual' => wlsPanelAuthPackLiveGateSelfTestSummary([
                'checks' => [
                    'local_live_gate_self_test_passed' => true,
                    'production_live_gate_self_test_passed' => false,
                ],
                'summary' => [
                    'local_live_gate_self_test_case_count' => 6,
                    'production_live_gate_self_test_case_count' => 6,
                ],
            ])['production_passed'],
        ],
        [
            'name' => 'drift_rows_accept_current_manifest_sized_packet',
            'expected' => true,
            'actual' => wlsPanelAuthPackDriftRowsBounded(array_fill(0, 46, []), 46),
        ],
        [
            'name' => 'drift_rows_reject_packet_above_bound',
            'expected' => false,
            'actual' => wlsPanelAuthPackDriftRowsBounded(array_fill(0, WLS_PANEL_AUTH_PACK_MAX_DRIFT_ROWS + 1, []), 61),
        ],
        [
            'name' => 'drift_rows_reject_more_rows_than_manifest_total',
            'expected' => false,
            'actual' => wlsPanelAuthPackDriftRowsBounded(array_fill(0, 10, []), 9),
        ],
        [
            'name' => 'rollback_review_accepts_out_of_scope_rows_with_fingerprint',
            'expected' => true,
            'actual' => wlsPanelAuthPackRollbackReviewSafe([
                'parsed' => true,
                'exit_code' => 0,
                'total_status_count' => 2,
                'allowed_status_count' => 0,
                'out_of_scope_status_count' => 2,
                'out_of_scope_fingerprint' => '100a6a1f2cbffe18',
                'rows' => [
                    ['path' => 'app/code/Weline/Admin/Service/BackendLoginReturnUrlService.php'],
                    ['path' => 'app/code/Weline/Admin/Test/Unit/Service/BackendLoginReturnUrlServiceTest.php'],
                ],
                'out_of_scope_rows' => [
                    ['path' => 'app/code/Weline/Admin/Service/BackendLoginReturnUrlService.php'],
                    ['path' => 'app/code/Weline/Admin/Test/Unit/Service/BackendLoginReturnUrlServiceTest.php'],
                ],
            ]),
        ],
        [
            'name' => 'rollback_review_accepts_reviewed_allowed_path_status',
            'expected' => true,
            'actual' => wlsPanelAuthPackRollbackReviewSafe([
                'parsed' => true,
                'exit_code' => 0,
                'total_status_count' => 1,
                'allowed_status_count' => 1,
                'out_of_scope_status_count' => 0,
                'out_of_scope_fingerprint' => '',
                'rows' => [
                    ['path' => 'app/code/Weline/AppStore/Service/AccountBindService.php', 'allowed_sync_path' => true],
                ],
                'out_of_scope_rows' => [],
            ]),
        ],
        [
            'name' => 'rollback_review_rejects_missing_out_of_scope_fingerprint',
            'expected' => false,
            'actual' => wlsPanelAuthPackRollbackReviewSafe([
                'parsed' => true,
                'exit_code' => 0,
                'total_status_count' => 1,
                'allowed_status_count' => 0,
                'out_of_scope_status_count' => 1,
                'out_of_scope_fingerprint' => '',
                'rows' => [
                    ['path' => 'app/code/Weline/Admin/Service/BackendLoginReturnUrlService.php'],
                ],
                'out_of_scope_rows' => [
                    ['path' => 'app/code/Weline/Admin/Service/BackendLoginReturnUrlService.php'],
                ],
            ]),
        ],
    ];

    $passed = true;
    foreach ($cases as &$case) {
        $case['case_ok'] = $case['actual'] === $case['expected'];
        $passed = $passed && $case['case_ok'];
    }
    unset($case);

    wlsPanelAuthPackFinish([
        'passed' => $passed,
        'self_test' => true,
        'cases' => $cases,
        'side_effects' => 'in-memory self-test: no file read, no network, no token values, no WLS start, no writes',
    ], $passed ? 0 : WLS_PANEL_AUTH_PACK_EXIT_UNSAFE);
}

$workspaceRoot = realpath((string)($args['workspace'] ?? getcwd()));
if (!is_string($workspaceRoot) || $workspaceRoot === '') {
    wlsPanelAuthPackFinish([
        'ok' => false,
        'errors' => ['workspace_not_found'],
    ], WLS_PANEL_AUTH_PACK_EXIT_UNSAFE);
}

$toolPrefix = 'app/code/Weline/Server/doc/wls-panel-plan/tools/';
$localDeployCurrent = wlsPanelAuthPackPath($workspaceRoot, $toolPrefix . 'deploy-current-local-development.json');
$productionDeployCurrent = wlsPanelAuthPackPath($workspaceRoot, $toolPrefix . 'deploy-current-production-default.json');
$includeDriftRows = (string)($args['include-drift-rows'] ?? '0') === '1';
$includeRollbackReview = (string)($args['include-rollback-review'] ?? '0') === '1';

$readiness = wlsPanelAuthPackRunTool($workspaceRoot, $toolPrefix . 'local-appstore-readiness-probe.php', [
    '--action-plan-only=1',
]);
$preflight = wlsPanelAuthPackRunTool($workspaceRoot, $toolPrefix . 'wls-panel-final-preflight.php', [
    '--report-only=1',
]);
$syncManifestArgs = [
    '--with-drift=1',
];
if (!$includeDriftRows) {
    $syncManifestArgs[] = '--drift-summary-only=1';
}
if ($includeRollbackReview) {
    $syncManifestArgs[] = '--rollback-review=1';
}
$syncManifest = wlsPanelAuthPackRunTool($workspaceRoot, $toolPrefix . 'validate-local-appstore-sync-manifest.php', $syncManifestArgs);
$localDeployPolicy = wlsPanelAuthPackRunTool($workspaceRoot, $toolPrefix . 'validate-deploy-appstore-endpoint-policy.php', [
    '--deploy-current=' . $localDeployCurrent,
    '--expect=local',
]);
$productionDeployPolicy = wlsPanelAuthPackRunTool($workspaceRoot, $toolPrefix . 'validate-deploy-appstore-endpoint-policy.php', [
    '--deploy-current=' . $productionDeployCurrent,
    '--expect=production',
]);
$captureSelfTest = wlsPanelAuthPackRunTool($workspaceRoot, $toolPrefix . 'wls-panel-live-e2e-capture.php', [
    '--self-test=1',
]);

$readinessPayload = is_array($readiness['payload'] ?? null) ? $readiness['payload'] : [];
$preflightPayload = is_array($preflight['payload'] ?? null) ? $preflight['payload'] : [];
$syncManifestPayload = is_array($syncManifest['payload'] ?? null) ? $syncManifest['payload'] : [];
$syncManifestDrift = is_array($syncManifestPayload['drift'] ?? null) ? $syncManifestPayload['drift'] : [];
$syncManifestDriftRows = is_array($syncManifestDrift['rows'] ?? null) ? $syncManifestDrift['rows'] : [];
$syncManifestDriftTotal = (int)($syncManifestDrift['total'] ?? 0);
$syncManifestDriftFingerprint = is_string($syncManifestDrift['review_fingerprint'] ?? null)
    ? (string)$syncManifestDrift['review_fingerprint']
    : '';
$syncManifestRollbackReview = is_array($syncManifestPayload['rollback_review'] ?? null)
    ? $syncManifestPayload['rollback_review']
    : [];
$syncManifestRollbackStatus = is_array($syncManifestRollbackReview['app_git_status'] ?? null)
    ? $syncManifestRollbackReview['app_git_status']
    : [];
$localDeployPolicyPayload = is_array($localDeployPolicy['payload'] ?? null) ? $localDeployPolicy['payload'] : [];
$productionDeployPolicyPayload = is_array($productionDeployPolicy['payload'] ?? null) ? $productionDeployPolicy['payload'] : [];
$captureSelfTestPayload = is_array($captureSelfTest['payload'] ?? null) ? $captureSelfTest['payload'] : [];
$executionOrder = wlsPanelAuthPackExecutionOrder($readinessPayload);

$localResolved = is_array($localDeployPolicyPayload['resolved'] ?? null) ? $localDeployPolicyPayload['resolved'] : [];
$productionResolved = is_array($productionDeployPolicyPayload['resolved'] ?? null) ? $productionDeployPolicyPayload['resolved'] : [];
$readinessChecks = is_array($readinessPayload['checks'] ?? null) ? $readinessPayload['checks'] : [];
$readinessAppEnv = is_array($readinessPayload['app_env'] ?? null) ? $readinessPayload['app_env'] : [];
$readinessAppEnvWlsEndpoint = is_array($readinessAppEnv['wls_endpoint'] ?? null)
    ? $readinessAppEnv['wls_endpoint']
    : [];
$preflightChecks = is_array($preflightPayload['checks'] ?? null) ? $preflightPayload['checks'] : [];
$preflightSummary = is_array($preflightPayload['summary'] ?? null) ? $preflightPayload['summary'] : [];
$officialManifestCatalogSummary = wlsPanelAuthPackOfficialCatalogSummary($preflightPayload);
$liveGateSelfTestSummary = wlsPanelAuthPackLiveGateSelfTestSummary($preflightPayload);
$allSideEffectStepsDeferred = wlsPanelAuthPackAllSideEffectsDeferred($executionOrder);
$onlyLiveStepRunnable = wlsPanelAuthPackOnlyLiveStepRunnable($executionOrder);
$readinessReady = ($readinessPayload['ready'] ?? false) === true;
$readyForLive = (($preflightPayload['ready_for_live_local_appstore_e2e'] ?? false) === true)
    || ($readinessReady && $onlyLiveStepRunnable);

$checks = [
    'readiness_parsed' => ($readiness['parsed'] ?? false) === true,
    'preflight_parsed' => ($preflight['parsed'] ?? false) === true,
    'sync_manifest_parsed' => ($syncManifest['parsed'] ?? false) === true,
    'sync_manifest_ok' => ($syncManifestPayload['ok'] ?? false) === true,
    'local_deploy_policy_parsed' => ($localDeployPolicy['parsed'] ?? false) === true,
    'production_deploy_policy_parsed' => ($productionDeployPolicy['parsed'] ?? false) === true,
    'capture_self_test_parsed' => ($captureSelfTest['parsed'] ?? false) === true,
    'capture_self_test_passed' => ($captureSelfTestPayload['passed'] ?? false) === true,
    'capture_path_traversal_guarded' => wlsPanelAuthPackHasPassingCase($captureSelfTestPayload, 'path_traversal_outside_var_rejected'),
    'capture_consistency_contract_guarded' =>
        wlsPanelAuthPackHasPassingCase($captureSelfTestPayload, 'captured_payload_preserves_consistency_contract')
        && wlsPanelAuthPackHasPassingCase(
            $captureSelfTestPayload,
            'production_captured_payload_preserves_consistency_contract'
        ),
    'local_env_is_explicit_dev_or_local' => ($readinessChecks['app_env_deploy_mode_local'] ?? false) === true,
    'local_app_checkout_identity_ok' => ($preflightChecks['local_readiness_app_checkout_identity_ok'] ?? false) === true,
    'local_app_env_wls_endpoint_locked' =>
        ($preflightChecks['local_readiness_app_env_wls_endpoint_locked'] ?? false) === true,
    'local_app_env_wls_endpoint_exact_root' =>
        (string)($preflightSummary['readiness_app_env_wls_endpoint_url'] ?? '') === WLS_PANEL_AUTH_PACK_LOCAL_ROOT,
    'local_endpoint_exact_root' => (string)($localResolved['resolved_platform_url'] ?? '') === WLS_PANEL_AUTH_PACK_LOCAL_ROOT,
    'production_endpoint_exact_root' => (string)($productionResolved['resolved_platform_url'] ?? '') === WLS_PANEL_AUTH_PACK_PRODUCTION_ROOT,
    'local_live_gate_self_test_passed' => $liveGateSelfTestSummary['local_passed'],
    'production_live_gate_self_test_passed' => $liveGateSelfTestSummary['production_passed'],
    'live_gate_self_test_case_counts_ok' => $liveGateSelfTestSummary['case_counts_ok'],
    'preflight_kept_no_live_call' => ($preflightChecks['local_live_gate_no_live_call'] ?? false) === true
        && ($preflightChecks['production_live_gate_no_live_call'] ?? false) === true,
    'premature_allow_is_blocked' => $readyForLive
        || (($preflightChecks['local_live_gate_premature_allow_blocked_no_live_call'] ?? false) === true
            && ($preflightChecks['production_live_gate_premature_allow_blocked_no_live_call'] ?? false) === true),
    'blocked_preflight_no_evidence_files' => $readyForLive
        || (($preflightChecks['blocked_preflight_no_evidence_files'] ?? false) === true),
    'official_manifest_catalog_contract_ok' =>
        ($preflightChecks['official_manifest_template_catalog_contract_ok'] ?? false) === true
        && ($officialManifestCatalogSummary['contract_ok'] ?? false) === true,
    'official_manifest_catalog_app_count_ok' =>
        ($preflightChecks['official_manifest_template_catalog_app_count_ok'] ?? false) === true,
    'official_manifest_catalog_positive_count_ok' =>
        ($preflightChecks['official_manifest_template_catalog_positive_count_ok'] ?? false) === true,
    'official_manifest_catalog_canary_ok' =>
        ($preflightChecks['official_manifest_template_catalog_canary_ok'] ?? false) === true,
    'official_manifest_catalog_source_plan_ok' =>
        ($preflightChecks['official_manifest_template_catalog_source_plan_ok'] ?? false) === true,
    'execution_order_present' => $executionOrder !== [],
    'all_side_effect_steps_deferred' => !$readyForLive ? $allSideEffectStepsDeferred : $onlyLiveStepRunnable,
    'only_live_step_runnable_when_ready' => !$readyForLive || $onlyLiveStepRunnable,
    'drift_rows_bounded_when_requested' => !$includeDriftRows
        || wlsPanelAuthPackDriftRowsBounded($syncManifestDriftRows, $syncManifestDriftTotal),
    'drift_review_fingerprint_present' => $syncManifestDriftFingerprint !== '',
    'rollback_review_parsed_when_requested' => !$includeRollbackReview
        || (($syncManifestRollbackStatus['parsed'] ?? false) === true
            && (int)($syncManifestRollbackStatus['exit_code'] ?? 1) === 0),
    'rollback_review_safe_when_requested' => !$includeRollbackReview
        || wlsPanelAuthPackRollbackReviewSafe($syncManifestRollbackStatus),
];
$checks['no_secret_values'] = wlsPanelAuthPackNoSecretLeak([
    'readiness' => $readinessPayload,
    'preflight_summary' => $preflightPayload['summary'] ?? [],
    'official_manifest_catalog_summary' => $officialManifestCatalogSummary,
    'capture_self_test' => $captureSelfTestPayload,
    'execution_order' => $executionOrder,
]);

$safeToReview = !in_array(false, $checks, true);
$failIfUnsafe = (string)($args['fail-if-unsafe'] ?? '0') === '1';

wlsPanelAuthPackFinish([
    'ok' => $safeToReview,
    'authorization_pack_ready_for_review' => $safeToReview,
    'ready_for_live_local_appstore_e2e' => $readyForLive,
    'current_state' => $readyForLive
        ? 'ready_for_guarded_live_run'
        : 'blocked_before_live_run',
    'marketplace_roots' => [
        'local' => WLS_PANEL_AUTH_PACK_LOCAL_ROOT,
        'production' => WLS_PANEL_AUTH_PACK_PRODUCTION_ROOT,
    ],
    'checks' => $checks,
    'blocked_checks' => wlsPanelAuthPackBlockedChecks($readinessPayload),
    'execution_order' => $executionOrder,
    'tool_results' => [
        'readiness_action_plan' => [
            'exit_code' => $readiness['exit_code'] ?? null,
            'parsed' => $readiness['parsed'] ?? false,
            'ready' => $readinessPayload['ready'] ?? null,
            'app_checkout_identity_ok' => ($readinessChecks['app_checkout_is_framework_official_app'] ?? false) === true
                && ($readinessChecks['app_checkout_has_platform_appstore_module'] ?? false) === true
                && ($readinessChecks['app_checkout_has_appstore_module'] ?? false) === true,
            'app_env_wls_endpoint_locked' =>
                ($readinessChecks['app_env_wls_endpoint_matches_deploy_current'] ?? false) === true
                && ($readinessChecks['app_env_wls_endpoint_matches_probe_endpoint'] ?? false) === true,
            'app_env_wls_endpoint_url' => $readinessAppEnvWlsEndpoint['url'] ?? null,
        ],
        'final_preflight' => [
            'exit_code' => $preflight['exit_code'] ?? null,
            'parsed' => $preflight['parsed'] ?? false,
            'ready_for_live_local_appstore_e2e' => $preflightPayload['ready_for_live_local_appstore_e2e'] ?? null,
            'live_gate_self_tests' => $liveGateSelfTestSummary,
            'blocked_preflight_no_evidence_files' => $preflightChecks['blocked_preflight_no_evidence_files'] ?? null,
            'blocked_preflight_evidence_files' => $preflightPayload['summary']['blocked_preflight_evidence_files'] ?? null,
            'local_app_checkout_identity_ok' => $preflightChecks['local_readiness_app_checkout_identity_ok'] ?? null,
            'local_app_env_wls_endpoint_locked' => $preflightChecks['local_readiness_app_env_wls_endpoint_locked'] ?? null,
            'local_app_env_wls_endpoint_url' => $preflightSummary['readiness_app_env_wls_endpoint_url'] ?? null,
            'official_manifest_catalog_summary' => $officialManifestCatalogSummary,
        ],
        'sync_manifest' => [
            'exit_code' => $syncManifest['exit_code'] ?? null,
            'parsed' => $syncManifest['parsed'] ?? false,
            'ok' => $syncManifestPayload['ok'] ?? null,
            'drifted_count' => $syncManifestDrift['drifted_count'] ?? null,
            'drift_counts' => $syncManifestDrift['counts'] ?? null,
            'drift_summary_only' => $syncManifestDrift['summary_only'] ?? null,
            'drift_row_bound' => WLS_PANEL_AUTH_PACK_MAX_DRIFT_ROWS,
            'drift_row_count' => count($syncManifestDriftRows),
            'drift_total' => $syncManifestDriftTotal,
            'drift_review_fingerprint' => $syncManifestDriftFingerprint,
            'drift_rows_omitted' => $syncManifestDrift['rows_omitted'] ?? null,
            'drift_rows' => $includeDriftRows ? $syncManifestDriftRows : null,
            'rollback_review' => $includeRollbackReview ? $syncManifestRollbackReview : null,
        ],
        'local_deploy_policy' => [
            'exit_code' => $localDeployPolicy['exit_code'] ?? null,
            'parsed' => $localDeployPolicy['parsed'] ?? false,
            'passed' => $localDeployPolicyPayload['passed'] ?? null,
            'resolved_endpoint' => $localResolved['resolved_endpoint'] ?? null,
        ],
        'production_deploy_policy' => [
            'exit_code' => $productionDeployPolicy['exit_code'] ?? null,
            'parsed' => $productionDeployPolicy['parsed'] ?? false,
            'passed' => $productionDeployPolicyPayload['passed'] ?? null,
            'resolved_endpoint' => $productionResolved['resolved_endpoint'] ?? null,
        ],
        'capture_self_test' => [
            'exit_code' => $captureSelfTest['exit_code'] ?? null,
            'parsed' => $captureSelfTest['parsed'] ?? false,
            'passed' => $captureSelfTestPayload['passed'] ?? null,
            'path_traversal_guarded' => wlsPanelAuthPackHasPassingCase($captureSelfTestPayload, 'path_traversal_outside_var_rejected'),
            'consistency_contract_guarded' =>
                wlsPanelAuthPackHasPassingCase($captureSelfTestPayload, 'captured_payload_preserves_consistency_contract')
                && wlsPanelAuthPackHasPassingCase(
                    $captureSelfTestPayload,
                    'production_captured_payload_preserves_consistency_contract'
                ),
        ],
    ],
    'authorization_note' => 'Review this packet before any explicit App checkout sync, manifest write, WLS start, token export, or live API call. Token values must stay outside repository files.',
    'side_effects' => 'read-only authorization packet: no sync, no setup, no WLS start, no manifest writes, no token values, no live API calls',
], (!$safeToReview && $failIfUnsafe) ? WLS_PANEL_AUTH_PACK_EXIT_UNSAFE : 0);
