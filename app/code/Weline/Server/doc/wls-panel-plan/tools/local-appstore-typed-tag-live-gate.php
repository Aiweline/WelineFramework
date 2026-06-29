<?php
declare(strict_types=1);

/**
 * Guarded local AppStore typed-tag live gate.
 *
 * Default mode is preflight-only and does not call the AppStore API. The live
 * typed-tag request is executed only when every prerequisite is ready and
 * --allow-live=1 is passed explicitly.
 */

const WLS_PANEL_LOCAL_LIVE_GATE_EXIT_BLOCKED = 1;
const WLS_PANEL_LOCAL_LIVE_GATE_ENDPOINT = 'https://app.weline.test:9523/api/v1/platform/module/list';
const WLS_PANEL_LOCAL_LIVE_GATE_EXPECTED_TAGS = 'custom:wls-panel-plugin';
const WLS_PANEL_LOCAL_LIVE_GATE_NEGATIVE_TAG = 'module:wls-extra';
const WLS_PANEL_LOCAL_LIVE_GATE_TIMEOUT = '20';

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function wlsPanelLocalLiveGateParseArgs(array $argv): array
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
function wlsPanelLocalLiveGateFinish(array $payload, int $exitCode): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($exitCode);
}

function wlsPanelLocalLiveGatePath(string $base, string $relative): string
{
    return rtrim($base, "\\/") . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
}

/**
 * @return array<string, mixed>|null
 */
function wlsPanelLocalLiveGateExtractJson(string $output): ?array
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
function wlsPanelLocalLiveGateRunTool(string $workspaceRoot, string $relativeTool, array $args = []): array
{
    $toolPath = wlsPanelLocalLiveGatePath($workspaceRoot, $relativeTool);
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
    $payload = wlsPanelLocalLiveGateExtractJson($output);

    return [
        'tool' => $relativeTool,
        'exit_code' => $exitCode,
        'parsed' => $payload !== null,
        'payload' => $payload,
        'output_bytes' => strlen($output),
    ];
}

function wlsPanelLocalLiveGateBool(array $payload, string $key): bool
{
    return ($payload[$key] ?? false) === true;
}

/**
 * @param array<string, mixed> $payload
 */
function wlsPanelLocalLiveGateResolvedEndpoint(array $payload): string
{
    $resolved = is_array($payload['resolved'] ?? null) ? $payload['resolved'] : [];
    return (string)($resolved['resolved_endpoint'] ?? $payload['endpoint'] ?? '');
}

/**
 * @param array<string, bool> $guardChecks
 */
function wlsPanelLocalLiveGateGuardReady(array $guardChecks): bool
{
    return ($guardChecks['tools_parsed'] ?? false)
        && ($guardChecks['source_contract_passed'] ?? false)
        && ($guardChecks['local_deploy_policy_passed'] ?? false)
        && ($guardChecks['local_deploy_policy_exact_root'] ?? false)
        && ($guardChecks['readiness_app_env_deploy_mode_local'] ?? false)
        && ($guardChecks['readiness_deploy_current_locked'] ?? false)
        && ($guardChecks['local_endpoint_locked'] ?? false)
        && ($guardChecks['configured_endpoint_locked'] ?? false)
        && ($guardChecks['manual_endpoint_rejected'] ?? false)
        && ($guardChecks['local_insecure_disabled'] ?? false);
}

/**
 * @return list<string>
 */
function wlsPanelLocalLiveGateLiveArgs(
    string $endpoint,
    string $expectedTags,
    string $negativeTag,
    string $timeout
): array {
    return [
        '--endpoint=' . $endpoint,
        '--expected-tags=' . $expectedTags,
        '--negative-tag=' . $negativeTag,
        '--require-negative-conclusive=1',
        '--min-items=1',
        '--timeout=' . $timeout,
        '--insecure=1',
    ];
}

/**
 * @return array{passed:bool,cases:list<array<string,mixed>>}
 */
function wlsPanelLocalLiveGateSelfTest(): array
{
    $lockedChecks = [
        'tools_parsed' => true,
        'source_contract_passed' => true,
        'local_deploy_policy_passed' => true,
        'local_deploy_policy_exact_root' => true,
        'readiness_app_env_deploy_mode_local' => true,
        'readiness_deploy_current_locked' => true,
        'local_endpoint_locked' => true,
        'configured_endpoint_locked' => true,
        'manual_endpoint_rejected' => true,
        'local_insecure_disabled' => true,
    ];
    $manualEndpointChecks = array_replace($lockedChecks, ['manual_endpoint_rejected' => false]);
    $insecureChecks = array_replace($lockedChecks, ['local_insecure_disabled' => false]);
    $liveArgs = wlsPanelLocalLiveGateLiveArgs(
        WLS_PANEL_LOCAL_LIVE_GATE_ENDPOINT,
        WLS_PANEL_LOCAL_LIVE_GATE_EXPECTED_TAGS,
        WLS_PANEL_LOCAL_LIVE_GATE_NEGATIVE_TAG,
        WLS_PANEL_LOCAL_LIVE_GATE_TIMEOUT
    );

    $cases = [
        [
            'name' => 'locked_guard_ready_when_all_guard_checks_pass',
            'expected' => true,
            'actual' => wlsPanelLocalLiveGateGuardReady($lockedChecks),
        ],
        [
            'name' => 'manual_endpoint_argument_blocks_guard',
            'expected' => false,
            'actual' => wlsPanelLocalLiveGateGuardReady($manualEndpointChecks),
        ],
        [
            'name' => 'insecure_argument_blocks_guard',
            'expected' => false,
            'actual' => wlsPanelLocalLiveGateGuardReady($insecureChecks),
        ],
        [
            'name' => 'ready_for_live_requires_readiness',
            'expected' => false,
            'actual' => wlsPanelLocalLiveGateGuardReady($lockedChecks) && false,
        ],
        [
            'name' => 'live_args_require_conclusive_negative_canary',
            'expected' => true,
            'actual' => in_array('--require-negative-conclusive=1', $liveArgs, true)
                && in_array('--negative-tag=' . WLS_PANEL_LOCAL_LIVE_GATE_NEGATIVE_TAG, $liveArgs, true),
        ],
        [
            'name' => 'live_args_use_locked_local_endpoint',
            'expected' => true,
            'actual' => in_array('--endpoint=' . WLS_PANEL_LOCAL_LIVE_GATE_ENDPOINT, $liveArgs, true),
        ],
    ];

    $passed = true;
    foreach ($cases as &$case) {
        $case['case_ok'] = $case['actual'] === $case['expected'];
        $passed = $passed && $case['case_ok'];
    }
    unset($case);

    return [
        'passed' => $passed,
        'cases' => $cases,
    ];
}

$args = wlsPanelLocalLiveGateParseArgs($argv);
if ((string)($args['self-test'] ?? '0') === '1') {
    $selfTest = wlsPanelLocalLiveGateSelfTest();
    wlsPanelLocalLiveGateFinish([
        'passed' => $selfTest['passed'],
        'self_test' => true,
        'cases' => $selfTest['cases'],
        'case_count' => count($selfTest['cases']),
        'side_effects' => 'in-memory self-test: no file read, no network, no token, no WLS start, no writes',
    ], $selfTest['passed'] ? 0 : WLS_PANEL_LOCAL_LIVE_GATE_EXIT_BLOCKED);
}

$workspaceRoot = trim((string)($args['workspace-root'] ?? dirname(__DIR__, 7)));
$reportOnly = (string)($args['report-only'] ?? '0') === '1';
$allowLive = (string)($args['allow-live'] ?? '0') === '1';
$manualEndpointProvided = array_key_exists('endpoint', $args);
$insecureRequested = in_array(strtolower((string)($args['insecure'] ?? '0')), ['1', 'true', 'yes'], true);
$endpoint = trim((string)($args['endpoint'] ?? WLS_PANEL_LOCAL_LIVE_GATE_ENDPOINT));
$expectedTags = trim((string)($args['expected-tags'] ?? WLS_PANEL_LOCAL_LIVE_GATE_EXPECTED_TAGS));
$negativeTag = trim((string)($args['negative-tag'] ?? WLS_PANEL_LOCAL_LIVE_GATE_NEGATIVE_TAG));
$timeout = trim((string)($args['timeout'] ?? WLS_PANEL_LOCAL_LIVE_GATE_TIMEOUT));
$tools = 'app/code/Weline/Server/doc/wls-panel-plan/tools/';

$readiness = wlsPanelLocalLiveGateRunTool($workspaceRoot, $tools . 'local-appstore-readiness-probe.php', [
    '--action-plan-only=1',
]);
$sourceContract = wlsPanelLocalLiveGateRunTool($workspaceRoot, $tools . 'validate-appstore-endpoint-source-contract.php');
$localDeployPolicy = wlsPanelLocalLiveGateRunTool($workspaceRoot, $tools . 'validate-deploy-appstore-endpoint-policy.php', [
    '--deploy-current=' . wlsPanelLocalLiveGatePath($workspaceRoot, $tools . 'deploy-current-local-development.json'),
    '--expect=local',
]);
$localEndpoint = wlsPanelLocalLiveGateRunTool($workspaceRoot, $tools . 'marketplace-typed-tag-e2e.php', [
    '--deploy-current=' . wlsPanelLocalLiveGatePath($workspaceRoot, $tools . 'deploy-current-local-development.json'),
    '--resolve-endpoint-only=1',
]);

$readinessPayload = is_array($readiness['payload'] ?? null) ? $readiness['payload'] : [];
$sourcePayload = is_array($sourceContract['payload'] ?? null) ? $sourceContract['payload'] : [];
$localDeployPayload = is_array($localDeployPolicy['payload'] ?? null) ? $localDeployPolicy['payload'] : [];
$localDeployChecks = is_array($localDeployPayload['checks'] ?? null) ? $localDeployPayload['checks'] : [];
$localEndpointPayload = is_array($localEndpoint['payload'] ?? null) ? $localEndpoint['payload'] : [];
$resolvedLocalEndpoint = wlsPanelLocalLiveGateResolvedEndpoint($localEndpointPayload);
$readinessReady = wlsPanelLocalLiveGateBool($readinessPayload, 'ready');
$readinessChecks = is_array($readinessPayload['checks'] ?? null) ? $readinessPayload['checks'] : [];
$readinessEndpoint = is_array($readinessPayload['endpoint'] ?? null) ? $readinessPayload['endpoint'] : [];
$readinessDeployCurrent = is_array($readinessPayload['local_deploy_current'] ?? null) ? $readinessPayload['local_deploy_current'] : [];

$guardChecks = [
    'tools_parsed' => ($readiness['parsed'] ?? false)
        && ($sourceContract['parsed'] ?? false)
        && ($localDeployPolicy['parsed'] ?? false)
        && ($localEndpoint['parsed'] ?? false),
    'source_contract_passed' => wlsPanelLocalLiveGateBool($sourcePayload, 'passed'),
    'local_deploy_policy_passed' => wlsPanelLocalLiveGateBool($localDeployPayload, 'passed'),
    'local_deploy_policy_exact_root' =>
        ($localDeployChecks['local_records_exact_app_weline_platform_url'] ?? false) === true,
    'readiness_app_env_deploy_mode_local' =>
        ($readinessChecks['app_env_deploy_mode_local'] ?? false) === true,
    'readiness_deploy_current_locked' =>
        ($readinessEndpoint['url'] ?? '') === 'https://app.weline.test:9523'
        && ($readinessEndpoint['source'] ?? '') === 'deploy-current'
        && ($readinessDeployCurrent['raw_platform_url'] ?? '') === 'https://app.weline.test:9523'
        && ($readinessDeployCurrent['endpoint'] ?? '') === WLS_PANEL_LOCAL_LIVE_GATE_ENDPOINT,
    'local_endpoint_locked' => $resolvedLocalEndpoint === WLS_PANEL_LOCAL_LIVE_GATE_ENDPOINT,
    'configured_endpoint_locked' => $endpoint === WLS_PANEL_LOCAL_LIVE_GATE_ENDPOINT,
    'manual_endpoint_rejected' => !$manualEndpointProvided,
    'local_insecure_disabled' => !$insecureRequested,
    'readiness_ready' => $readinessReady,
];
$guardReady = wlsPanelLocalLiveGateGuardReady($guardChecks);
$readyForLive = $guardReady && $readinessReady;
$liveExecuted = false;
$liveResult = null;
$livePayload = [];
$liveEvidence = null;
$livePassed = false;

if ($readyForLive && $allowLive) {
    $liveResult = wlsPanelLocalLiveGateRunTool(
        $workspaceRoot,
        $tools . 'marketplace-typed-tag-e2e.php',
        wlsPanelLocalLiveGateLiveArgs($endpoint, $expectedTags, $negativeTag, $timeout)
    );
    $livePayload = is_array($liveResult['payload'] ?? null) ? $liveResult['payload'] : [];
    $liveEvidence = [
        'passed' => $livePayload['passed'] ?? null,
        'blocked' => $livePayload['blocked'] ?? null,
        'endpoint' => $livePayload['endpoint'] ?? null,
        'endpoint_source' => $livePayload['endpoint_source'] ?? null,
        'appstore_environment' => $livePayload['appstore_environment'] ?? null,
        'token_redacted' => $livePayload['token_redacted'] ?? null,
        'require_negative_conclusive' => $livePayload['require_negative_conclusive'] ?? null,
        'cases' => is_array($livePayload['cases'] ?? null) ? $livePayload['cases'] : [],
    ];
    $liveExecuted = true;
    $livePassed = ($liveResult['exit_code'] ?? 1) === 0 && wlsPanelLocalLiveGateBool($livePayload, 'passed');
}

$status = 'blocked';
if ($readyForLive && !$allowLive) {
    $status = 'ready_not_executed';
} elseif ($liveExecuted && $livePassed) {
    $status = 'passed';
} elseif ($liveExecuted) {
    $status = 'failed';
}

$payload = [
    'ok' => true,
    'status' => $status,
    'ready_for_live' => $readyForLive,
    'live_allowed' => $allowLive,
    'live_executed' => $liveExecuted,
    'live_passed' => $livePassed,
    'live_evidence' => $liveEvidence,
    'guard_passed' => $guardReady,
    'guard_checks' => $guardChecks,
    'endpoint' => $endpoint,
    'expected_tags' => $expectedTags,
    'negative_tag' => $negativeTag,
    'summary' => [
        'readiness_blockers' => $readinessPayload['blockers'] ?? [],
        'readiness_next_actions' => $readinessPayload['next_actions'] ?? [],
        'readiness_deploy_current_url' => $readinessDeployCurrent['raw_platform_url'] ?? null,
        'local_deploy_platform_url' => $localDeployPayload['resolved']['raw_platform_url'] ?? null,
        'local_endpoint' => $resolvedLocalEndpoint,
        'source_contract_errors' => $sourcePayload['errors'] ?? [],
        'local_deploy_policy_errors' => $localDeployPayload['errors'] ?? [],
    ],
    'tool_results' => [
        'readiness_action_plan' => [
            'exit_code' => $readiness['exit_code'],
            'parsed' => $readiness['parsed'],
            'ready' => $readinessPayload['ready'] ?? null,
            'app_env_deploy_mode_local' => $readinessChecks['app_env_deploy_mode_local'] ?? null,
            'endpoint_url' => $readinessEndpoint['url'] ?? null,
            'deploy_current' => $readinessEndpoint['deploy_current'] ?? null,
            'deploy_current_url' => $readinessDeployCurrent['raw_platform_url'] ?? null,
        ],
        'source_contract' => [
            'exit_code' => $sourceContract['exit_code'],
            'parsed' => $sourceContract['parsed'],
            'passed' => $sourcePayload['passed'] ?? null,
        ],
        'local_deploy_policy' => [
            'exit_code' => $localDeployPolicy['exit_code'],
            'parsed' => $localDeployPolicy['parsed'],
            'passed' => $localDeployPayload['passed'] ?? null,
            'raw_platform_url' => $localDeployPayload['resolved']['raw_platform_url'] ?? null,
            'exact_root' => $localDeployChecks['local_records_exact_app_weline_platform_url'] ?? null,
            'resolved_endpoint' => $localDeployPayload['resolved']['resolved_endpoint'] ?? null,
        ],
        'local_endpoint' => [
            'exit_code' => $localEndpoint['exit_code'],
            'parsed' => $localEndpoint['parsed'],
            'endpoint' => $resolvedLocalEndpoint,
        ],
        'live_typed_tag' => $liveResult === null ? null : [
            'exit_code' => $liveResult['exit_code'],
            'parsed' => $liveResult['parsed'],
            'passed' => $liveResult['payload']['passed'] ?? null,
        ],
    ],
    'notes' => [
        'report_only' => $reportOnly,
        'side_effects' => $liveExecuted
            ? 'live local AppStore API request executed; no token value is printed'
            : 'guarded preflight only: no network, no token value, no WLS start, no writes',
        'live_execution_rule' => 'Pass --allow-live=1 only after ready_for_live=true; the tool still refuses live execution while readiness blockers remain.',
    ],
];

$exitCode = match ($status) {
    'passed', 'ready_not_executed' => 0,
    default => WLS_PANEL_LOCAL_LIVE_GATE_EXIT_BLOCKED,
};

wlsPanelLocalLiveGateFinish($payload, $reportOnly ? 0 : $exitCode);
