<?php
declare(strict_types=1);

/**
 * Aggregates the read-only gates that must be green before the final local
 * AppStore typed-tag API E2E can be counted.
 *
 * This tool does not sync files, start WLS, write manifests, read token values,
 * or call the live AppStore API. It only runs existing read-only helpers.
 */

const WLS_PANEL_FINAL_PREFLIGHT_NOT_READY = 1;

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function wlsPanelFinalPreflightParseArgs(array $argv): array
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
function wlsPanelFinalPreflightFinish(array $payload, int $exitCode): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($exitCode);
}

function wlsPanelFinalPreflightPath(string $base, string $relative): string
{
    return rtrim($base, "\\/") . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
}

/**
 * @return array<string, mixed>|null
 */
function wlsPanelFinalPreflightExtractJson(string $output): ?array
{
    $start = strpos($output, '{');
    $end = strrpos($output, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }

    $json = substr($output, $start, $end - $start + 1);
    $payload = json_decode($json, true);
    return is_array($payload) ? $payload : null;
}

/**
 * @param list<string> $args
 * @return array<string, mixed>
 */
function wlsPanelFinalPreflightRunTool(string $workspaceRoot, string $relativeTool, array $args = []): array
{
    $toolPath = wlsPanelFinalPreflightPath($workspaceRoot, $relativeTool);
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
    $payload = wlsPanelFinalPreflightExtractJson($output);

    return [
        'tool' => $relativeTool,
        'exit_code' => $exitCode,
        'parsed' => $payload !== null,
        'payload' => $payload,
        'output_bytes' => strlen($output),
    ];
}

/**
 * @param list<array<string, mixed>> $rows
 */
function wlsPanelFinalPreflightOnlyMarketplaceIncomplete(array $rows): bool
{
    foreach ($rows as $row) {
        $text = strtolower((string)($row['requirement'] ?? '') . ' ' . (string)($row['remaining_gate'] ?? ''));
        if (
            !str_contains($text, 'appstore')
            && !str_contains($text, 'marketplace')
            && !str_contains($text, 'typed-tag')
        ) {
            return false;
        }
    }

    return true;
}

function wlsPanelFinalPreflightBool(array $payload, string $key): bool
{
    return ($payload[$key] ?? false) === true;
}

/**
 * @param list<array<string, mixed>> $actions
 */
function wlsPanelFinalPreflightFindAction(array $actions, string $id): array
{
    foreach ($actions as $action) {
        if (($action['id'] ?? '') === $id) {
            return $action;
        }
    }

    return [];
}

/**
 * @param list<string> $needles
 */
function wlsPanelFinalPreflightContainsAll(string $text, array $needles): bool
{
    foreach ($needles as $needle) {
        if (!str_contains($text, $needle)) {
            return false;
        }
    }

    return true;
}

$args = wlsPanelFinalPreflightParseArgs($argv);
$reportOnly = (string)($args['report-only'] ?? '0') === '1';
$workspaceRoot = trim((string)($args['workspace-root'] ?? dirname(__DIR__, 7)));
$appRoot = trim((string)($args['app-root'] ?? 'E:\WelineFramework\Framework-Official\App\weline'));
$officialManifestTarget = wlsPanelFinalPreflightPath($appRoot, 'official-apps/manifest.json');
$tools = 'app/code/Weline/Server/doc/wls-panel-plan/tools/';

$completion = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'wls-panel-completion-audit.php');
$readiness = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'local-appstore-readiness-probe.php', ['--action-plan-only=1']);
$drift = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'validate-local-appstore-sync-manifest.php', [
    '--with-drift=1',
    '--drift-summary-only=1',
]);
$syncManifestSelfTest = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'validate-local-appstore-sync-manifest.php', [
    '--self-test=1',
]);
$deployPolicy = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'validate-deploy-appstore-endpoint-policy.php', [
    '--self-test=1',
]);
$endpointSourceContractSelfTest = wlsPanelFinalPreflightRunTool(
    $workspaceRoot,
    $tools . 'validate-appstore-endpoint-source-contract.php',
    [
        '--self-test=1',
    ]
);
$endpointSourceContract = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'validate-appstore-endpoint-source-contract.php');
$localLiveGateSelfTest = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'local-appstore-typed-tag-live-gate.php', [
    '--self-test=1',
]);
$localLiveGate = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'local-appstore-typed-tag-live-gate.php', [
    '--report-only=1',
]);
$localLiveGatePrematureAllow = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'local-appstore-typed-tag-live-gate.php', [
    '--allow-live=1',
    '--report-only=1',
]);
$productionLiveGateSelfTest = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'production-appstore-typed-tag-live-gate.php', [
    '--self-test=1',
]);
$productionLiveGate = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'production-appstore-typed-tag-live-gate.php', [
    '--deploy-current=' . wlsPanelFinalPreflightPath($workspaceRoot, $tools . 'deploy-current-production-default.json'),
    '--report-only=1',
]);
$productionLiveGatePrematureAllow = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'production-appstore-typed-tag-live-gate.php', [
    '--deploy-current=' . wlsPanelFinalPreflightPath($workspaceRoot, $tools . 'deploy-current-production-default.json'),
    '--allow-live=1',
    '--report-only=1',
]);
$localDeployPolicy = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'validate-deploy-appstore-endpoint-policy.php', [
    '--deploy-current=' . wlsPanelFinalPreflightPath($workspaceRoot, $tools . 'deploy-current-local-development.json'),
    '--expect=local',
]);
$productionDeployPolicy = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'validate-deploy-appstore-endpoint-policy.php', [
    '--deploy-current=' . wlsPanelFinalPreflightPath($workspaceRoot, $tools . 'deploy-current-production-default.json'),
    '--expect=production',
]);
$typedTagSelfTest = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'marketplace-typed-tag-e2e.php', [
    '--self-test=1',
]);
$officialManifestSelfTest = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'validate-official-appstore-manifest-contract.php', [
    '--self-test=1',
]);
$authorizationPackSelfTest = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'wls-panel-live-e2e-authorization-pack.php', [
    '--self-test=1',
]);
$finalWorkorderSelfTest = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'wls-panel-final-workorder.php', [
    '--self-test=1',
]);
$deferredActionsValidatorSelfTest = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'validate-final-workorder-deferred-actions.php', [
    '--self-test=1',
]);
$workorderAuthorizationConsistencySelfTest = wlsPanelFinalPreflightRunTool(
    $workspaceRoot,
    $tools . 'wls-panel-workorder-authorization-consistency.php',
    [
        '--self-test=1',
    ]
);
$goalCompletionGateSelfTest = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'wls-panel-goal-completion-gate.php', [
    '--self-test=1',
]);
$liveEvidenceValidatorSelfTest = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'validate-appstore-live-e2e-evidence.php', [
    '--self-test=1',
]);
$liveEvidenceCaptureSelfTest = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'wls-panel-live-e2e-capture.php', [
    '--self-test=1',
]);
$liveEvidenceFinalGateSelfTest = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'wls-panel-live-evidence-final-gate.php', [
    '--self-test=1',
]);
$officialManifestTemplate = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'validate-official-appstore-manifest-contract.php', [
    '--template=1',
    '--template-target=' . $officialManifestTarget,
]);
$productionEndpoint = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'marketplace-typed-tag-e2e.php', [
    '--deploy-current=' . wlsPanelFinalPreflightPath($workspaceRoot, $tools . 'deploy-current-production-default.json'),
    '--resolve-endpoint-only=1',
]);
$localEndpoint = wlsPanelFinalPreflightRunTool($workspaceRoot, $tools . 'marketplace-typed-tag-e2e.php', [
    '--deploy-current=' . wlsPanelFinalPreflightPath($workspaceRoot, $tools . 'deploy-current-local-development.json'),
    '--resolve-endpoint-only=1',
]);

$completionPayload = is_array($completion['payload'] ?? null) ? $completion['payload'] : [];
$readinessPayload = is_array($readiness['payload'] ?? null) ? $readiness['payload'] : [];
$driftPayload = is_array($drift['payload'] ?? null) ? $drift['payload'] : [];
$syncManifestSelfTestPayload = is_array($syncManifestSelfTest['payload'] ?? null) ? $syncManifestSelfTest['payload'] : [];
$deployPolicyPayload = is_array($deployPolicy['payload'] ?? null) ? $deployPolicy['payload'] : [];
$endpointSourceContractSelfTestPayload = is_array($endpointSourceContractSelfTest['payload'] ?? null)
    ? $endpointSourceContractSelfTest['payload']
    : [];
$endpointSourceContractPayload = is_array($endpointSourceContract['payload'] ?? null) ? $endpointSourceContract['payload'] : [];
$localLiveGateSelfTestPayload = is_array($localLiveGateSelfTest['payload'] ?? null) ? $localLiveGateSelfTest['payload'] : [];
$localLiveGatePayload = is_array($localLiveGate['payload'] ?? null) ? $localLiveGate['payload'] : [];
$localLiveGatePrematureAllowPayload = is_array($localLiveGatePrematureAllow['payload'] ?? null) ? $localLiveGatePrematureAllow['payload'] : [];
$productionLiveGateSelfTestPayload = is_array($productionLiveGateSelfTest['payload'] ?? null) ? $productionLiveGateSelfTest['payload'] : [];
$productionLiveGatePayload = is_array($productionLiveGate['payload'] ?? null) ? $productionLiveGate['payload'] : [];
$productionLiveGatePrematureAllowPayload = is_array($productionLiveGatePrematureAllow['payload'] ?? null) ? $productionLiveGatePrematureAllow['payload'] : [];
$localDeployPolicyPayload = is_array($localDeployPolicy['payload'] ?? null) ? $localDeployPolicy['payload'] : [];
$productionDeployPolicyPayload = is_array($productionDeployPolicy['payload'] ?? null) ? $productionDeployPolicy['payload'] : [];
$localDeployPolicyChecks = is_array($localDeployPolicyPayload['checks'] ?? null) ? $localDeployPolicyPayload['checks'] : [];
$productionDeployPolicyChecks = is_array($productionDeployPolicyPayload['checks'] ?? null) ? $productionDeployPolicyPayload['checks'] : [];
$readinessChecks = is_array($readinessPayload['checks'] ?? null) ? $readinessPayload['checks'] : [];
$readinessEndpoint = is_array($readinessPayload['endpoint'] ?? null) ? $readinessPayload['endpoint'] : [];
$readinessDeployCurrent = is_array($readinessPayload['local_deploy_current'] ?? null) ? $readinessPayload['local_deploy_current'] : [];
$readinessAppEnv = is_array($readinessPayload['app_env'] ?? null) ? $readinessPayload['app_env'] : [];
$readinessAppEnvWlsEndpoint = is_array($readinessAppEnv['wls_endpoint'] ?? null) ? $readinessAppEnv['wls_endpoint'] : [];
$readinessNextActions = is_array($readinessPayload['next_actions'] ?? null) ? $readinessPayload['next_actions'] : [];
$authorizedSyncAction = wlsPanelFinalPreflightFindAction($readinessNextActions, 'authorized_app_checkout_sync');
$authorizedSyncSelfTestCommand = (string)($authorizedSyncAction['preflight_self_test_command'] ?? '');
$authorizedSyncPreflightCommand = (string)($authorizedSyncAction['preflight_command'] ?? '');
$authorizedSyncReviewCommand = (string)($authorizedSyncAction['pre_authorization_review_command'] ?? '');
$authorizedSyncRollbackCommand = (string)($authorizedSyncAction['rollback_review_command'] ?? '');
$authorizedSyncPostGateCommand = (string)($authorizedSyncAction['post_sync_gate_command'] ?? '');
$authorizedSyncActionPresent = $authorizedSyncAction !== [];
$authorizedSyncActionHasSelfTest = wlsPanelFinalPreflightContainsAll($authorizedSyncSelfTestCommand, [
    'validate-local-appstore-sync-manifest.php',
    '--self-test=1',
]);
$authorizedSyncActionHasDriftPreflight = wlsPanelFinalPreflightContainsAll($authorizedSyncPreflightCommand, [
    'validate-local-appstore-sync-manifest.php',
    '--with-drift=1',
    '--drift-summary-only=1',
]);
$authorizedSyncActionHasAuthorizationReview = wlsPanelFinalPreflightContainsAll($authorizedSyncReviewCommand, [
    'wls-panel-live-e2e-authorization-pack.php',
    '--include-drift-rows=1',
    '--include-rollback-review=1',
    '--fail-if-unsafe=1',
]);
$authorizedSyncActionHasRollbackReview = wlsPanelFinalPreflightContainsAll($authorizedSyncRollbackCommand, [
    'validate-local-appstore-sync-manifest.php',
    '--with-drift=1',
    '--rollback-review=1',
]);
$authorizedSyncActionHasPostSyncGate = wlsPanelFinalPreflightContainsAll($authorizedSyncPostGateCommand, [
    'validate-local-appstore-sync-manifest.php',
    '--fail-on-drift=1',
    '--drift-summary-only=1',
]);
$authorizedSyncActionContractOk = wlsPanelFinalPreflightBool($readinessPayload, 'ready')
    || (
        $authorizedSyncActionPresent
        && ($authorizedSyncAction['requires_user_authorization'] ?? false) === true
        && ($authorizedSyncAction['safe_to_run_now'] ?? true) === false
        && $authorizedSyncActionHasSelfTest
        && $authorizedSyncActionHasDriftPreflight
        && $authorizedSyncActionHasAuthorizationReview
        && $authorizedSyncActionHasRollbackReview
        && $authorizedSyncActionHasPostSyncGate
    );
$typedTagPayload = is_array($typedTagSelfTest['payload'] ?? null) ? $typedTagSelfTest['payload'] : [];
$officialManifestSelfTestPayload = is_array($officialManifestSelfTest['payload'] ?? null) ? $officialManifestSelfTest['payload'] : [];
$authorizationPackSelfTestPayload = is_array($authorizationPackSelfTest['payload'] ?? null) ? $authorizationPackSelfTest['payload'] : [];
$finalWorkorderSelfTestPayload = is_array($finalWorkorderSelfTest['payload'] ?? null) ? $finalWorkorderSelfTest['payload'] : [];
$deferredActionsValidatorSelfTestPayload = is_array($deferredActionsValidatorSelfTest['payload'] ?? null) ? $deferredActionsValidatorSelfTest['payload'] : [];
$workorderAuthorizationConsistencySelfTestPayload = is_array($workorderAuthorizationConsistencySelfTest['payload'] ?? null)
    ? $workorderAuthorizationConsistencySelfTest['payload']
    : [];
$goalCompletionGateSelfTestPayload = is_array($goalCompletionGateSelfTest['payload'] ?? null) ? $goalCompletionGateSelfTest['payload'] : [];
$liveEvidenceValidatorSelfTestPayload = is_array($liveEvidenceValidatorSelfTest['payload'] ?? null) ? $liveEvidenceValidatorSelfTest['payload'] : [];
$liveEvidenceCaptureSelfTestPayload = is_array($liveEvidenceCaptureSelfTest['payload'] ?? null) ? $liveEvidenceCaptureSelfTest['payload'] : [];
$liveEvidenceFinalGateSelfTestPayload = is_array($liveEvidenceFinalGateSelfTest['payload'] ?? null) ? $liveEvidenceFinalGateSelfTest['payload'] : [];
$officialManifestTemplatePayload = is_array($officialManifestTemplate['payload'] ?? null) ? $officialManifestTemplate['payload'] : [];
$officialManifestCatalogSummary = is_array($officialManifestTemplatePayload['catalog_summary'] ?? null)
    ? $officialManifestTemplatePayload['catalog_summary']
    : [];
$productionPayload = is_array($productionEndpoint['payload'] ?? null) ? $productionEndpoint['payload'] : [];
$localPayload = is_array($localEndpoint['payload'] ?? null) ? $localEndpoint['payload'] : [];

$completionRows = array_merge(
    is_array($completionPayload['completion_incomplete_rows'] ?? null) ? $completionPayload['completion_incomplete_rows'] : [],
    is_array($completionPayload['traceability_incomplete_rows'] ?? null) ? $completionPayload['traceability_incomplete_rows'] : []
);
$completionScopeOk = wlsPanelFinalPreflightBool($completionPayload, 'ok')
    && ($completionPayload['errors'] ?? []) === []
    && ($completionPayload['missing_files'] ?? []) === []
    && wlsPanelFinalPreflightOnlyMarketplaceIncomplete($completionRows);

$driftSummary = is_array($driftPayload['drift'] ?? null) ? $driftPayload['drift'] : [];
$driftReviewFingerprint = is_string($driftSummary['review_fingerprint'] ?? null)
    ? (string)$driftSummary['review_fingerprint']
    : '';
$driftClear = ($driftSummary['drifted_count'] ?? null) === 0;
$productionEndpointUrl = (string)($productionPayload['endpoint'] ?? '');
$localEndpointUrl = (string)($localPayload['endpoint'] ?? '');
$productionEndpointLocked = $productionEndpointUrl === 'https://app.aiweline.com/api/v1/platform/module/list';
$localEndpointLocked = $localEndpointUrl === 'https://app.weline.test:9523/api/v1/platform/module/list';
$blockedPreflightEvidenceFiles = [
    'local_live_evidence' => [
        'path' => wlsPanelFinalPreflightPath($workspaceRoot, 'var/wls-panel-plan/local-appstore-live-e2e.json'),
    ],
    'production_live_evidence' => [
        'path' => wlsPanelFinalPreflightPath($workspaceRoot, 'var/wls-panel-plan/production-appstore-live-e2e.json'),
    ],
    'path_traversal_leak' => [
        'path' => wlsPanelFinalPreflightPath($workspaceRoot, 'var/leak.json'),
    ],
];
foreach ($blockedPreflightEvidenceFiles as $key => $file) {
    $blockedPreflightEvidenceFiles[$key]['exists'] = is_file((string)$file['path']);
}
$blockedPreflightNoEvidenceFiles = true;
foreach ($blockedPreflightEvidenceFiles as $file) {
    if (($file['exists'] ?? false) === true) {
        $blockedPreflightNoEvidenceFiles = false;
        break;
    }
}
$officialManifestMaterialize = is_array($officialManifestTemplatePayload['materialize'] ?? null)
    ? $officialManifestTemplatePayload['materialize']
    : [];
$officialManifestSourcePlan = is_array($officialManifestTemplatePayload['source_plan'] ?? null)
    ? $officialManifestTemplatePayload['source_plan']
    : [];
$officialManifestTemplateWouldWrite = ($officialManifestMaterialize['target'] ?? '') === $officialManifestTarget
    && ($officialManifestMaterialize['would_write'] ?? false) === true
    && ($officialManifestMaterialize['wrote'] ?? true) === false;
$officialManifestSourcePlanReady = ($officialManifestSourcePlan['ready'] ?? false) === true;
$officialManifestSourcePlanWouldWrite = ($officialManifestSourcePlan['target_root'] ?? '') === dirname($officialManifestTarget)
    && ($officialManifestSourcePlan['would_write'] ?? false) === true
    && ($officialManifestSourcePlan['wrote'] ?? true) === false;

$checks = [
    'tools_parsed' => ($completion['parsed'] ?? false)
        && ($readiness['parsed'] ?? false)
        && ($drift['parsed'] ?? false)
        && ($syncManifestSelfTest['parsed'] ?? false)
        && ($deployPolicy['parsed'] ?? false)
        && ($endpointSourceContractSelfTest['parsed'] ?? false)
        && ($endpointSourceContract['parsed'] ?? false)
        && ($localLiveGateSelfTest['parsed'] ?? false)
        && ($localLiveGate['parsed'] ?? false)
        && ($localLiveGatePrematureAllow['parsed'] ?? false)
        && ($productionLiveGateSelfTest['parsed'] ?? false)
        && ($productionLiveGate['parsed'] ?? false)
        && ($productionLiveGatePrematureAllow['parsed'] ?? false)
        && ($localDeployPolicy['parsed'] ?? false)
        && ($productionDeployPolicy['parsed'] ?? false)
        && ($typedTagSelfTest['parsed'] ?? false)
        && ($officialManifestSelfTest['parsed'] ?? false)
        && ($authorizationPackSelfTest['parsed'] ?? false)
        && ($finalWorkorderSelfTest['parsed'] ?? false)
        && ($deferredActionsValidatorSelfTest['parsed'] ?? false)
        && ($workorderAuthorizationConsistencySelfTest['parsed'] ?? false)
        && ($goalCompletionGateSelfTest['parsed'] ?? false)
        && ($liveEvidenceValidatorSelfTest['parsed'] ?? false)
        && ($liveEvidenceCaptureSelfTest['parsed'] ?? false)
        && ($liveEvidenceFinalGateSelfTest['parsed'] ?? false)
        && ($officialManifestTemplate['parsed'] ?? false)
        && ($productionEndpoint['parsed'] ?? false)
        && ($localEndpoint['parsed'] ?? false),
    'completion_scope_ok' => $completionScopeOk,
    'goal_complete' => wlsPanelFinalPreflightBool($completionPayload, 'complete'),
    'local_appstore_ready' => wlsPanelFinalPreflightBool($readinessPayload, 'ready'),
    'local_readiness_app_checkout_identity_ok' =>
        ($readinessChecks['app_checkout_is_framework_official_app'] ?? false) === true
        && ($readinessChecks['app_checkout_has_platform_appstore_module'] ?? false) === true
        && ($readinessChecks['app_checkout_has_appstore_module'] ?? false) === true,
    'local_readiness_app_env_deploy_mode_local' =>
        ($readinessChecks['app_env_deploy_mode_local'] ?? false) === true,
    'local_readiness_app_env_wls_endpoint_locked' =>
        ($readinessChecks['app_env_wls_host_matches_local_appstore'] ?? false) === true
        && ($readinessChecks['app_env_wls_port_matches_local_appstore'] ?? false) === true
        && ($readinessChecks['app_env_wls_https_enabled'] ?? false) === true
        && ($readinessChecks['app_env_wls_endpoint_matches_deploy_current'] ?? false) === true
        && ($readinessChecks['app_env_wls_endpoint_matches_probe_endpoint'] ?? false) === true
        && ($readinessAppEnvWlsEndpoint['url'] ?? '') === 'https://app.weline.test:9523',
    'local_readiness_deploy_current_locked' =>
        ($readinessEndpoint['url'] ?? '') === 'https://app.weline.test:9523'
        && ($readinessEndpoint['source'] ?? '') === 'deploy-current'
        && ($readinessDeployCurrent['raw_platform_url'] ?? '') === 'https://app.weline.test:9523'
        && ($readinessDeployCurrent['endpoint'] ?? '') === 'https://app.weline.test:9523/api/v1/platform/module/list',
    'readiness_action_authorized_sync_present' => $authorizedSyncActionPresent,
    'readiness_action_authorized_sync_requires_user_authorization' =>
        ($authorizedSyncAction['requires_user_authorization'] ?? false) === true,
    'readiness_action_authorized_sync_safe_to_run_false' =>
        ($authorizedSyncAction['safe_to_run_now'] ?? true) === false,
    'readiness_action_has_sync_manifest_self_test' => $authorizedSyncActionHasSelfTest,
    'readiness_action_has_compact_drift_preflight' => $authorizedSyncActionHasDriftPreflight,
    'readiness_action_has_authorization_review' => $authorizedSyncActionHasAuthorizationReview,
    'readiness_action_has_rollback_review' => $authorizedSyncActionHasRollbackReview,
    'readiness_action_has_post_sync_gate' => $authorizedSyncActionHasPostSyncGate,
    'readiness_action_contract_ok' => $authorizedSyncActionContractOk,
    'local_appstore_drift_clear' => $driftClear,
    'sync_manifest_drift_review_fingerprint_present' => $driftReviewFingerprint !== '',
    'sync_manifest_self_test_passed' => wlsPanelFinalPreflightBool($syncManifestSelfTestPayload, 'passed'),
    'deploy_endpoint_policy_passed' => wlsPanelFinalPreflightBool($deployPolicyPayload, 'passed'),
    'endpoint_source_contract_self_test_passed' => wlsPanelFinalPreflightBool($endpointSourceContractSelfTestPayload, 'passed'),
    'endpoint_source_contract_self_test_case_count' =>
        count(is_array($endpointSourceContractSelfTestPayload['cases'] ?? null) ? $endpointSourceContractSelfTestPayload['cases'] : []),
    'endpoint_source_contract_passed' => wlsPanelFinalPreflightBool($endpointSourceContractPayload, 'passed'),
    'local_live_gate_self_test_passed' => wlsPanelFinalPreflightBool($localLiveGateSelfTestPayload, 'passed'),
    'local_live_gate_guard_passed' => wlsPanelFinalPreflightBool($localLiveGatePayload, 'guard_passed'),
    'local_live_gate_no_live_call' => ($localLiveGatePayload['live_executed'] ?? true) === false,
    'local_live_gate_premature_allow_blocked_no_live_call' =>
        ($localLiveGatePrematureAllowPayload['live_allowed'] ?? false) === true
        && ($localLiveGatePrematureAllowPayload['ready_for_live'] ?? true) === false
            && ($localLiveGatePrematureAllowPayload['status'] ?? '') === 'blocked'
            && ($localLiveGatePrematureAllowPayload['live_executed'] ?? true) === false,
    'production_live_gate_self_test_passed' => wlsPanelFinalPreflightBool($productionLiveGateSelfTestPayload, 'passed'),
    'production_live_gate_guard_passed' => wlsPanelFinalPreflightBool($productionLiveGatePayload, 'guard_passed'),
    'production_live_gate_no_live_call' => ($productionLiveGatePayload['live_executed'] ?? true) === false,
    'production_live_gate_premature_allow_blocked_no_live_call' =>
        ($productionLiveGatePrematureAllowPayload['live_allowed'] ?? false) === true
        && ($productionLiveGatePrematureAllowPayload['ready_for_live'] ?? true) === false
        && ($productionLiveGatePrematureAllowPayload['status'] ?? '') === 'blocked'
        && ($productionLiveGatePrematureAllowPayload['live_executed'] ?? true) === false,
    'blocked_preflight_no_evidence_files' => $blockedPreflightNoEvidenceFiles,
    'local_deploy_endpoint_policy_passed' => wlsPanelFinalPreflightBool($localDeployPolicyPayload, 'passed'),
    'local_deploy_endpoint_policy_exact_root' =>
        ($localDeployPolicyChecks['local_records_exact_app_weline_platform_url'] ?? false) === true,
    'production_deploy_endpoint_policy_passed' => wlsPanelFinalPreflightBool($productionDeployPolicyPayload, 'passed'),
    'production_deploy_endpoint_policy_exact_root' =>
        ($productionDeployPolicyChecks['production_records_exact_app_aiweline_platform_url'] ?? false) === true,
    'typed_tag_self_test_passed' => wlsPanelFinalPreflightBool($typedTagPayload, 'passed'),
    'official_manifest_self_test_passed' => wlsPanelFinalPreflightBool($officialManifestSelfTestPayload, 'passed'),
    'authorization_pack_self_test_passed' => wlsPanelFinalPreflightBool($authorizationPackSelfTestPayload, 'passed'),
    'final_workorder_self_test_passed' => wlsPanelFinalPreflightBool($finalWorkorderSelfTestPayload, 'passed'),
    'deferred_actions_validator_self_test_passed' => wlsPanelFinalPreflightBool($deferredActionsValidatorSelfTestPayload, 'passed'),
    'workorder_authorization_consistency_self_test_passed' =>
        wlsPanelFinalPreflightBool($workorderAuthorizationConsistencySelfTestPayload, 'passed'),
    'goal_completion_gate_self_test_passed' => wlsPanelFinalPreflightBool($goalCompletionGateSelfTestPayload, 'passed'),
    'live_e2e_evidence_validator_self_test_passed' => wlsPanelFinalPreflightBool($liveEvidenceValidatorSelfTestPayload, 'passed'),
    'live_e2e_capture_self_test_passed' => wlsPanelFinalPreflightBool($liveEvidenceCaptureSelfTestPayload, 'passed'),
    'live_e2e_final_gate_self_test_passed' => wlsPanelFinalPreflightBool($liveEvidenceFinalGateSelfTestPayload, 'passed'),
    'official_manifest_template_dry_run_passed' => wlsPanelFinalPreflightBool($officialManifestTemplatePayload, 'passed'),
    'official_manifest_template_catalog_contract_ok' =>
        ($officialManifestCatalogSummary['contract_ok'] ?? false) === true,
    'official_manifest_template_catalog_app_count_ok' =>
        ($officialManifestCatalogSummary['app_count'] ?? null) === ($officialManifestCatalogSummary['expected_app_count'] ?? false),
    'official_manifest_template_catalog_positive_count_ok' =>
        ($officialManifestCatalogSummary['positive_count'] ?? null) === ($officialManifestCatalogSummary['expected_positive_count'] ?? false),
    'official_manifest_template_catalog_canary_ok' =>
        ($officialManifestCatalogSummary['negative_canary_count'] ?? 0) >= 1
        && in_array('Weline_WlsTagCanary', (array)($officialManifestCatalogSummary['negative_canary_modules'] ?? []), true),
    'official_manifest_template_catalog_source_plan_ok' =>
        ($officialManifestCatalogSummary['source_entry_count'] ?? null) === ($officialManifestCatalogSummary['expected_source_entry_count'] ?? false)
        && ($officialManifestCatalogSummary['source_entries_ready'] ?? false) === true
        && ($officialManifestCatalogSummary['source_targets_acceptable'] ?? false) === true,
    'official_manifest_template_would_write' => $officialManifestTemplateWouldWrite,
    'official_manifest_source_plan_ready' => $officialManifestSourcePlanReady,
    'official_manifest_source_plan_would_write' => $officialManifestSourcePlanWouldWrite,
    'local_endpoint_locked' => $localEndpointLocked,
    'production_endpoint_locked' => $productionEndpointLocked,
];
$readyForLiveE2e = $checks['tools_parsed']
    && $checks['completion_scope_ok']
    && $checks['local_appstore_ready']
    && $checks['local_readiness_app_checkout_identity_ok']
    && $checks['local_readiness_app_env_deploy_mode_local']
    && $checks['local_readiness_app_env_wls_endpoint_locked']
    && $checks['local_readiness_deploy_current_locked']
    && $checks['readiness_action_contract_ok']
    && $checks['local_appstore_drift_clear']
    && $checks['sync_manifest_drift_review_fingerprint_present']
    && $checks['sync_manifest_self_test_passed']
    && $checks['deploy_endpoint_policy_passed']
    && $checks['endpoint_source_contract_self_test_passed']
    && $checks['endpoint_source_contract_passed']
    && $checks['local_live_gate_self_test_passed']
    && $checks['local_live_gate_guard_passed']
    && $checks['local_live_gate_no_live_call']
    && $checks['local_live_gate_premature_allow_blocked_no_live_call']
    && $checks['production_live_gate_self_test_passed']
    && $checks['production_live_gate_guard_passed']
    && $checks['production_live_gate_no_live_call']
    && $checks['production_live_gate_premature_allow_blocked_no_live_call']
    && $checks['blocked_preflight_no_evidence_files']
    && $checks['local_deploy_endpoint_policy_passed']
    && $checks['local_deploy_endpoint_policy_exact_root']
    && $checks['production_deploy_endpoint_policy_passed']
    && $checks['production_deploy_endpoint_policy_exact_root']
    && $checks['typed_tag_self_test_passed']
    && $checks['official_manifest_self_test_passed']
    && $checks['authorization_pack_self_test_passed']
    && $checks['final_workorder_self_test_passed']
    && $checks['deferred_actions_validator_self_test_passed']
    && $checks['workorder_authorization_consistency_self_test_passed']
    && $checks['goal_completion_gate_self_test_passed']
    && $checks['live_e2e_evidence_validator_self_test_passed']
    && $checks['live_e2e_capture_self_test_passed']
    && $checks['live_e2e_final_gate_self_test_passed']
    && $checks['official_manifest_template_dry_run_passed']
    && $checks['official_manifest_template_catalog_contract_ok']
    && $checks['official_manifest_template_catalog_app_count_ok']
    && $checks['official_manifest_template_catalog_positive_count_ok']
    && $checks['official_manifest_template_catalog_canary_ok']
    && $checks['official_manifest_template_catalog_source_plan_ok']
    && $checks['official_manifest_template_would_write']
    && $checks['official_manifest_source_plan_ready']
    && $checks['official_manifest_source_plan_would_write']
    && $checks['local_endpoint_locked']
    && $checks['production_endpoint_locked'];

$payload = [
    'ok' => true,
    'ready_for_live_local_appstore_e2e' => $readyForLiveE2e,
    'goal_complete' => $checks['goal_complete'],
    'checks' => $checks,
    'summary' => [
        'ready_for_live_local_appstore_e2e' => $readyForLiveE2e,
        'goal_complete' => $checks['goal_complete'],
        'completion_open_rows' => count($completionRows),
        'readiness_blockers' => $readinessPayload['blockers'] ?? [],
        'readiness_next_actions' => $readinessNextActions,
        'readiness_action_plan_contract_ok' => $authorizedSyncActionContractOk,
        'readiness_action_authorized_sync' => [
            'present' => $authorizedSyncActionPresent,
            'requires_user_authorization' => $authorizedSyncAction['requires_user_authorization'] ?? null,
            'safe_to_run_now' => $authorizedSyncAction['safe_to_run_now'] ?? null,
            'has_sync_manifest_self_test' => $authorizedSyncActionHasSelfTest,
            'has_compact_drift_preflight' => $authorizedSyncActionHasDriftPreflight,
            'has_authorization_review' => $authorizedSyncActionHasAuthorizationReview,
            'has_rollback_review' => $authorizedSyncActionHasRollbackReview,
            'has_post_sync_gate' => $authorizedSyncActionHasPostSyncGate,
        ],
        'readiness_app_checkout_identity_ok' => $checks['local_readiness_app_checkout_identity_ok'],
        'readiness_app_env_deploy_mode_local' => $readinessChecks['app_env_deploy_mode_local'] ?? null,
        'readiness_app_env_wls_endpoint_url' => $readinessAppEnvWlsEndpoint['url'] ?? null,
        'readiness_app_env_wls_endpoint_locked' => $checks['local_readiness_app_env_wls_endpoint_locked'],
        'readiness_deploy_current_url' => $readinessDeployCurrent['raw_platform_url'] ?? null,
        'drifted_count' => $driftSummary['drifted_count'] ?? null,
        'drift_review_fingerprint' => $driftReviewFingerprint,
        'sync_manifest_self_test_passed' => $syncManifestSelfTestPayload['passed'] ?? null,
        'endpoint_source_contract_self_test_passed' => $endpointSourceContractSelfTestPayload['passed'] ?? null,
        'endpoint_source_contract_self_test_case_count' =>
            count(is_array($endpointSourceContractSelfTestPayload['cases'] ?? null) ? $endpointSourceContractSelfTestPayload['cases'] : []),
        'endpoint_source_contract_errors' => $endpointSourceContractPayload['errors'] ?? [],
        'local_live_gate_self_test_passed' => $localLiveGateSelfTestPayload['passed'] ?? null,
        'local_live_gate_self_test_case_count' => $localLiveGateSelfTestPayload['case_count'] ?? null,
        'local_live_gate_status' => $localLiveGatePayload['status'] ?? null,
        'local_live_gate_ready_for_live' => $localLiveGatePayload['ready_for_live'] ?? null,
        'local_live_gate_premature_allow_status' => $localLiveGatePrematureAllowPayload['status'] ?? null,
        'local_live_gate_premature_allow_live_executed' => $localLiveGatePrematureAllowPayload['live_executed'] ?? null,
        'production_live_gate_self_test_passed' => $productionLiveGateSelfTestPayload['passed'] ?? null,
        'production_live_gate_self_test_case_count' => $productionLiveGateSelfTestPayload['case_count'] ?? null,
        'production_live_gate_status' => $productionLiveGatePayload['status'] ?? null,
        'production_live_gate_ready_for_live' => $productionLiveGatePayload['ready_for_live'] ?? null,
        'production_live_gate_deploy_current_is_deployed_artifact' =>
            $productionLiveGatePayload['live_execution_checks']['production_deploy_current_is_deployed_artifact'] ?? null,
        'production_live_gate_premature_allow_status' => $productionLiveGatePrematureAllowPayload['status'] ?? null,
        'production_live_gate_premature_allow_live_executed' => $productionLiveGatePrematureAllowPayload['live_executed'] ?? null,
        'production_live_gate_premature_allow_deploy_current_is_deployed_artifact' =>
            $productionLiveGatePrematureAllowPayload['live_execution_checks']['production_deploy_current_is_deployed_artifact'] ?? null,
        'blocked_preflight_evidence_files' => $blockedPreflightEvidenceFiles,
        'official_manifest_template_target' => $officialManifestTarget,
        'official_manifest_source_target_root' => $officialManifestSourcePlan['target_root'] ?? null,
        'official_manifest_catalog_summary' => $officialManifestCatalogSummary,
        'live_e2e_evidence_validator_self_test_passed' => $liveEvidenceValidatorSelfTestPayload['passed'] ?? null,
        'final_workorder_self_test_passed' => $finalWorkorderSelfTestPayload['passed'] ?? null,
        'deferred_actions_validator_self_test_passed' => $deferredActionsValidatorSelfTestPayload['passed'] ?? null,
        'workorder_authorization_consistency_self_test_passed' =>
            $workorderAuthorizationConsistencySelfTestPayload['passed'] ?? null,
        'goal_completion_gate_self_test_passed' => $goalCompletionGateSelfTestPayload['passed'] ?? null,
        'live_e2e_capture_self_test_passed' => $liveEvidenceCaptureSelfTestPayload['passed'] ?? null,
        'live_e2e_final_gate_self_test_passed' => $liveEvidenceFinalGateSelfTestPayload['passed'] ?? null,
        'local_deploy_platform_url' => $localDeployPolicyPayload['resolved']['raw_platform_url'] ?? null,
        'production_deploy_platform_url' => $productionDeployPolicyPayload['resolved']['raw_platform_url'] ?? null,
        'local_endpoint' => $localEndpointUrl,
        'production_endpoint' => $productionEndpointUrl,
    ],
    'tool_results' => [
        'completion_audit' => [
            'exit_code' => $completion['exit_code'],
            'parsed' => $completion['parsed'],
            'complete' => $completionPayload['complete'] ?? null,
        ],
        'readiness_action_plan' => [
            'exit_code' => $readiness['exit_code'],
            'parsed' => $readiness['parsed'],
            'ready' => $readinessPayload['ready'] ?? null,
            'app_checkout_identity_ok' => $checks['local_readiness_app_checkout_identity_ok'],
            'app_env_deploy_mode_local' => $readinessChecks['app_env_deploy_mode_local'] ?? null,
            'app_env_wls_endpoint_locked' => $checks['local_readiness_app_env_wls_endpoint_locked'],
            'app_env_wls_endpoint_url' => $readinessAppEnvWlsEndpoint['url'] ?? null,
            'endpoint_url' => $readinessEndpoint['url'] ?? null,
            'deploy_current' => $readinessEndpoint['deploy_current'] ?? null,
            'deploy_current_url' => $readinessDeployCurrent['raw_platform_url'] ?? null,
        ],
        'sync_drift' => [
            'exit_code' => $drift['exit_code'],
            'parsed' => $drift['parsed'],
            'drifted_count' => $driftSummary['drifted_count'] ?? null,
        ],
        'sync_manifest_self_test' => [
            'exit_code' => $syncManifestSelfTest['exit_code'],
            'parsed' => $syncManifestSelfTest['parsed'],
            'passed' => $syncManifestSelfTestPayload['passed'] ?? null,
        ],
        'deploy_endpoint_policy' => [
            'exit_code' => $deployPolicy['exit_code'],
            'parsed' => $deployPolicy['parsed'],
            'passed' => $deployPolicyPayload['passed'] ?? null,
        ],
        'endpoint_source_contract_self_test' => [
            'exit_code' => $endpointSourceContractSelfTest['exit_code'],
            'parsed' => $endpointSourceContractSelfTest['parsed'],
            'passed' => $endpointSourceContractSelfTestPayload['passed'] ?? null,
            'case_count' => count(is_array($endpointSourceContractSelfTestPayload['cases'] ?? null) ? $endpointSourceContractSelfTestPayload['cases'] : []),
            'side_effects' => $endpointSourceContractSelfTestPayload['side_effects'] ?? null,
        ],
        'endpoint_source_contract' => [
            'exit_code' => $endpointSourceContract['exit_code'],
            'parsed' => $endpointSourceContract['parsed'],
            'passed' => $endpointSourceContractPayload['passed'] ?? null,
            'errors' => $endpointSourceContractPayload['errors'] ?? null,
        ],
        'local_live_gate_self_test' => [
            'exit_code' => $localLiveGateSelfTest['exit_code'],
            'parsed' => $localLiveGateSelfTest['parsed'],
            'passed' => $localLiveGateSelfTestPayload['passed'] ?? null,
            'case_count' => $localLiveGateSelfTestPayload['case_count'] ?? null,
            'side_effects' => $localLiveGateSelfTestPayload['side_effects'] ?? null,
        ],
        'local_live_gate' => [
            'exit_code' => $localLiveGate['exit_code'],
            'parsed' => $localLiveGate['parsed'],
            'status' => $localLiveGatePayload['status'] ?? null,
            'guard_passed' => $localLiveGatePayload['guard_passed'] ?? null,
            'ready_for_live' => $localLiveGatePayload['ready_for_live'] ?? null,
            'live_executed' => $localLiveGatePayload['live_executed'] ?? null,
        ],
        'local_live_gate_premature_allow' => [
            'exit_code' => $localLiveGatePrematureAllow['exit_code'],
            'parsed' => $localLiveGatePrematureAllow['parsed'],
            'status' => $localLiveGatePrematureAllowPayload['status'] ?? null,
            'live_allowed' => $localLiveGatePrematureAllowPayload['live_allowed'] ?? null,
            'ready_for_live' => $localLiveGatePrematureAllowPayload['ready_for_live'] ?? null,
            'live_executed' => $localLiveGatePrematureAllowPayload['live_executed'] ?? null,
        ],
        'production_live_gate_self_test' => [
            'exit_code' => $productionLiveGateSelfTest['exit_code'],
            'parsed' => $productionLiveGateSelfTest['parsed'],
            'passed' => $productionLiveGateSelfTestPayload['passed'] ?? null,
            'case_count' => $productionLiveGateSelfTestPayload['case_count'] ?? null,
            'side_effects' => $productionLiveGateSelfTestPayload['side_effects'] ?? null,
        ],
        'production_live_gate' => [
            'exit_code' => $productionLiveGate['exit_code'],
            'parsed' => $productionLiveGate['parsed'],
            'status' => $productionLiveGatePayload['status'] ?? null,
            'guard_passed' => $productionLiveGatePayload['guard_passed'] ?? null,
            'ready_for_live' => $productionLiveGatePayload['ready_for_live'] ?? null,
            'live_executed' => $productionLiveGatePayload['live_executed'] ?? null,
            'production_deploy_current_is_deployed_artifact' =>
                $productionLiveGatePayload['live_execution_checks']['production_deploy_current_is_deployed_artifact'] ?? null,
        ],
        'production_live_gate_premature_allow' => [
            'exit_code' => $productionLiveGatePrematureAllow['exit_code'],
            'parsed' => $productionLiveGatePrematureAllow['parsed'],
            'status' => $productionLiveGatePrematureAllowPayload['status'] ?? null,
            'live_allowed' => $productionLiveGatePrematureAllowPayload['live_allowed'] ?? null,
            'ready_for_live' => $productionLiveGatePrematureAllowPayload['ready_for_live'] ?? null,
            'live_executed' => $productionLiveGatePrematureAllowPayload['live_executed'] ?? null,
            'production_deploy_current_is_deployed_artifact' =>
                $productionLiveGatePrematureAllowPayload['live_execution_checks']['production_deploy_current_is_deployed_artifact'] ?? null,
        ],
        'blocked_preflight_evidence_files' => [
            'passed' => $blockedPreflightNoEvidenceFiles,
            'files' => $blockedPreflightEvidenceFiles,
        ],
        'local_deploy_endpoint_policy' => [
            'exit_code' => $localDeployPolicy['exit_code'],
            'parsed' => $localDeployPolicy['parsed'],
            'passed' => $localDeployPolicyPayload['passed'] ?? null,
            'raw_platform_url' => $localDeployPolicyPayload['resolved']['raw_platform_url'] ?? null,
            'exact_root' => $localDeployPolicyChecks['local_records_exact_app_weline_platform_url'] ?? null,
            'resolved_endpoint' => $localDeployPolicyPayload['resolved']['resolved_endpoint'] ?? null,
        ],
        'production_deploy_endpoint_policy' => [
            'exit_code' => $productionDeployPolicy['exit_code'],
            'parsed' => $productionDeployPolicy['parsed'],
            'passed' => $productionDeployPolicyPayload['passed'] ?? null,
            'raw_platform_url' => $productionDeployPolicyPayload['resolved']['raw_platform_url'] ?? null,
            'exact_root' => $productionDeployPolicyChecks['production_records_exact_app_aiweline_platform_url'] ?? null,
            'resolved_endpoint' => $productionDeployPolicyPayload['resolved']['resolved_endpoint'] ?? null,
        ],
        'typed_tag_self_test' => [
            'exit_code' => $typedTagSelfTest['exit_code'],
            'parsed' => $typedTagSelfTest['parsed'],
            'passed' => $typedTagPayload['passed'] ?? null,
        ],
        'official_manifest_self_test' => [
            'exit_code' => $officialManifestSelfTest['exit_code'],
            'parsed' => $officialManifestSelfTest['parsed'],
            'passed' => $officialManifestSelfTestPayload['passed'] ?? null,
        ],
        'authorization_pack_self_test' => [
            'exit_code' => $authorizationPackSelfTest['exit_code'],
            'parsed' => $authorizationPackSelfTest['parsed'],
            'passed' => $authorizationPackSelfTestPayload['passed'] ?? null,
        ],
        'final_workorder_self_test' => [
            'exit_code' => $finalWorkorderSelfTest['exit_code'],
            'parsed' => $finalWorkorderSelfTest['parsed'],
            'passed' => $finalWorkorderSelfTestPayload['passed'] ?? null,
        ],
        'deferred_actions_validator_self_test' => [
            'exit_code' => $deferredActionsValidatorSelfTest['exit_code'],
            'parsed' => $deferredActionsValidatorSelfTest['parsed'],
            'passed' => $deferredActionsValidatorSelfTestPayload['passed'] ?? null,
        ],
        'workorder_authorization_consistency_self_test' => [
            'exit_code' => $workorderAuthorizationConsistencySelfTest['exit_code'],
            'parsed' => $workorderAuthorizationConsistencySelfTest['parsed'],
            'passed' => $workorderAuthorizationConsistencySelfTestPayload['passed'] ?? null,
        ],
        'goal_completion_gate_self_test' => [
            'exit_code' => $goalCompletionGateSelfTest['exit_code'],
            'parsed' => $goalCompletionGateSelfTest['parsed'],
            'passed' => $goalCompletionGateSelfTestPayload['passed'] ?? null,
        ],
        'live_e2e_evidence_validator_self_test' => [
            'exit_code' => $liveEvidenceValidatorSelfTest['exit_code'],
            'parsed' => $liveEvidenceValidatorSelfTest['parsed'],
            'passed' => $liveEvidenceValidatorSelfTestPayload['passed'] ?? null,
        ],
        'live_e2e_capture_self_test' => [
            'exit_code' => $liveEvidenceCaptureSelfTest['exit_code'],
            'parsed' => $liveEvidenceCaptureSelfTest['parsed'],
            'passed' => $liveEvidenceCaptureSelfTestPayload['passed'] ?? null,
        ],
        'live_e2e_final_gate_self_test' => [
            'exit_code' => $liveEvidenceFinalGateSelfTest['exit_code'],
            'parsed' => $liveEvidenceFinalGateSelfTest['parsed'],
            'passed' => $liveEvidenceFinalGateSelfTestPayload['passed'] ?? null,
        ],
        'official_manifest_template' => [
            'exit_code' => $officialManifestTemplate['exit_code'],
            'parsed' => $officialManifestTemplate['parsed'],
            'passed' => $officialManifestTemplatePayload['passed'] ?? null,
            'target' => $officialManifestMaterialize['target'] ?? null,
            'would_write' => $officialManifestMaterialize['would_write'] ?? null,
            'wrote' => $officialManifestMaterialize['wrote'] ?? null,
            'source_plan_ready' => $officialManifestSourcePlan['ready'] ?? null,
            'source_plan_target_root' => $officialManifestSourcePlan['target_root'] ?? null,
            'source_plan_would_write' => $officialManifestSourcePlan['would_write'] ?? null,
            'source_plan_wrote' => $officialManifestSourcePlan['wrote'] ?? null,
            'catalog_summary' => $officialManifestCatalogSummary,
        ],
        'production_endpoint' => [
            'exit_code' => $productionEndpoint['exit_code'],
            'parsed' => $productionEndpoint['parsed'],
            'endpoint' => $productionEndpointUrl,
        ],
        'local_endpoint' => [
            'exit_code' => $localEndpoint['exit_code'],
            'parsed' => $localEndpoint['parsed'],
            'endpoint' => $localEndpointUrl,
        ],
    ],
    'notes' => [
        'report_only' => $reportOnly,
        'side_effects' => 'read-only aggregate: no sync, no setup, no WLS start, no manifest writes, no token values, no live API calls',
    ],
];

wlsPanelFinalPreflightFinish(
    $payload,
    $reportOnly || $readyForLiveE2e || $checks['goal_complete'] ? 0 : WLS_PANEL_FINAL_PREFLIGHT_NOT_READY
);
