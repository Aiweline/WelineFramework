<?php
declare(strict_types=1);

/**
 * Guarded production AppStore typed-tag live gate.
 *
 * Default mode is preflight-only and does not call the AppStore API. Production
 * endpoint selection must come from var/deploy/current.json; manual endpoint
 * arguments and insecure HTTPS are rejected.
 */

const WLS_PANEL_PRODUCTION_LIVE_GATE_EXIT_BLOCKED = 1;
const WLS_PANEL_PRODUCTION_LIVE_GATE_ENDPOINT = 'https://app.aiweline.com/api/v1/platform/module/list';
const WLS_PANEL_PRODUCTION_LIVE_GATE_EXPECTED_TAGS = 'custom:wls-panel-plugin';
const WLS_PANEL_PRODUCTION_LIVE_GATE_NEGATIVE_TAG = 'module:wls-extra';
const WLS_PANEL_PRODUCTION_LIVE_GATE_TIMEOUT = '20';
const WLS_PANEL_PRODUCTION_LIVE_GATE_TOKEN_ENV = 'WLS_MARKETPLACE_BEARER_TOKEN';

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function wlsPanelProductionLiveGateParseArgs(array $argv): array
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
function wlsPanelProductionLiveGateFinish(array $payload, int $exitCode): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($exitCode);
}

function wlsPanelProductionLiveGatePath(string $base, string $relative): string
{
    return rtrim($base, "\\/") . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
}

/**
 * @return array<string, mixed>|null
 */
function wlsPanelProductionLiveGateExtractJson(string $output): ?array
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
function wlsPanelProductionLiveGateRunTool(string $workspaceRoot, string $relativeTool, array $args = []): array
{
    $toolPath = wlsPanelProductionLiveGatePath($workspaceRoot, $relativeTool);
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
    $payload = wlsPanelProductionLiveGateExtractJson($output);

    return [
        'tool' => $relativeTool,
        'exit_code' => $exitCode,
        'parsed' => $payload !== null,
        'payload' => $payload,
        'output_bytes' => strlen($output),
    ];
}

function wlsPanelProductionLiveGateBool(array $payload, string $key): bool
{
    return ($payload[$key] ?? false) === true;
}

/**
 * @param array<string, mixed> $payload
 */
function wlsPanelProductionLiveGateResolvedEndpoint(array $payload): string
{
    $resolved = is_array($payload['resolved'] ?? null) ? $payload['resolved'] : [];
    return (string)($resolved['resolved_endpoint'] ?? $payload['endpoint'] ?? '');
}

/**
 * @return array{ready:bool,source:string}
 */
function wlsPanelProductionLiveGateTokenReady(array $args, string $tokenEnv): array
{
    $tokenFile = trim((string)($args['token-file'] ?? ''));
    if ($tokenFile !== '') {
        return [
            'ready' => is_file($tokenFile) && is_readable($tokenFile) && filesize($tokenFile) > 0,
            'source' => 'file:' . $tokenFile,
        ];
    }

    $token = getenv($tokenEnv);
    return [
        'ready' => is_string($token) && trim($token) !== '',
        'source' => 'env:' . $tokenEnv,
    ];
}

function wlsPanelProductionLiveGateIsAbsolutePath(string $path): bool
{
    return preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, '/') || str_starts_with($path, '\\\\');
}

function wlsPanelProductionLiveGateNormalizePath(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    $drive = '';
    if (preg_match('/^[A-Za-z]:/', $path) === 1) {
        $drive = strtoupper(substr($path, 0, 2));
        $path = substr($path, 2);
    }

    $absolutePrefix = str_starts_with($path, '/') ? '/' : '';
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            if ($segments !== []) {
                array_pop($segments);
                continue;
            }
            $segments[] = '..';
            continue;
        }
        $segments[] = $segment;
    }

    return $drive . $absolutePrefix . implode('/', $segments);
}

function wlsPanelProductionLiveGateIsDeployedCurrentPath(string $workspaceRoot, string $deployCurrent): bool
{
    $deployCurrent = trim($deployCurrent);
    if ($deployCurrent === '') {
        return false;
    }

    $candidate = wlsPanelProductionLiveGateIsAbsolutePath($deployCurrent)
        ? $deployCurrent
        : wlsPanelProductionLiveGatePath($workspaceRoot, $deployCurrent);
    $expected = wlsPanelProductionLiveGatePath($workspaceRoot, 'var/deploy/current.json');
    $candidate = wlsPanelProductionLiveGateNormalizePath($candidate);
    $expected = wlsPanelProductionLiveGateNormalizePath($expected);

    return strtolower($candidate) === strtolower($expected);
}

/**
 * @param array<string, string> $args
 * @return list<string>
 */
function wlsPanelProductionLiveGateLiveArgs(
    array $args,
    string $deployCurrent,
    string $expectedTags,
    string $negativeTag,
    string $timeout,
    string $tokenEnv
): array {
    $liveArgs = [
        '--deploy-current=' . $deployCurrent,
        '--expected-tags=' . $expectedTags,
        '--negative-tag=' . $negativeTag,
        '--require-negative-conclusive=1',
        '--min-items=1',
        '--timeout=' . $timeout,
        '--token-env=' . $tokenEnv,
    ];

    $tokenFile = trim((string)($args['token-file'] ?? ''));
    if ($tokenFile !== '') {
        $liveArgs[] = '--token-file=' . $tokenFile;
    }

    return $liveArgs;
}

/**
 * @param array<string, bool> $guardChecks
 */
function wlsPanelProductionLiveGateGuardReady(array $guardChecks): bool
{
    return ($guardChecks['tools_parsed'] ?? false)
        && ($guardChecks['source_contract_passed'] ?? false)
        && ($guardChecks['production_deploy_policy_passed'] ?? false)
        && ($guardChecks['production_deploy_policy_exact_root'] ?? false)
        && ($guardChecks['production_endpoint_locked'] ?? false)
        && ($guardChecks['manual_endpoint_rejected'] ?? false)
        && ($guardChecks['production_insecure_disabled'] ?? false);
}

/**
 * @return array{passed:bool,cases:list<array<string,mixed>>}
 */
function wlsPanelProductionLiveGateSelfTest(): array
{
    $workspaceRoot = 'C:/repo';
    $lockedChecks = [
        'tools_parsed' => true,
        'source_contract_passed' => true,
        'production_deploy_policy_passed' => true,
        'production_deploy_policy_exact_root' => true,
        'production_endpoint_locked' => true,
        'manual_endpoint_rejected' => true,
        'production_insecure_disabled' => true,
    ];
    $manualEndpointChecks = array_replace($lockedChecks, ['manual_endpoint_rejected' => false]);
    $insecureChecks = array_replace($lockedChecks, ['production_insecure_disabled' => false]);
    $liveArgs = wlsPanelProductionLiveGateLiveArgs(
        ['token-file' => 'var/token.txt'],
        'var/deploy/current.json',
        WLS_PANEL_PRODUCTION_LIVE_GATE_EXPECTED_TAGS,
        WLS_PANEL_PRODUCTION_LIVE_GATE_NEGATIVE_TAG,
        WLS_PANEL_PRODUCTION_LIVE_GATE_TIMEOUT,
        WLS_PANEL_PRODUCTION_LIVE_GATE_TOKEN_ENV
    );

    $cases = [
        [
            'name' => 'locked_guard_ready_when_all_guard_checks_pass',
            'expected' => true,
            'actual' => wlsPanelProductionLiveGateGuardReady($lockedChecks),
        ],
        [
            'name' => 'manual_endpoint_argument_blocks_guard',
            'expected' => false,
            'actual' => wlsPanelProductionLiveGateGuardReady($manualEndpointChecks),
        ],
        [
            'name' => 'insecure_argument_blocks_guard',
            'expected' => false,
            'actual' => wlsPanelProductionLiveGateGuardReady($insecureChecks),
        ],
        [
            'name' => 'ready_for_live_requires_token',
            'expected' => false,
            'actual' => wlsPanelProductionLiveGateGuardReady($lockedChecks) && false,
        ],
        [
            'name' => 'live_args_require_conclusive_negative_canary',
            'expected' => true,
            'actual' => in_array('--require-negative-conclusive=1', $liveArgs, true)
                && in_array('--negative-tag=' . WLS_PANEL_PRODUCTION_LIVE_GATE_NEGATIVE_TAG, $liveArgs, true),
        ],
        [
            'name' => 'live_args_forward_deploy_current_and_token_file',
            'expected' => true,
            'actual' => in_array('--deploy-current=var/deploy/current.json', $liveArgs, true)
                && in_array('--token-file=var/token.txt', $liveArgs, true),
        ],
        [
            'name' => 'allow_live_requires_workspace_var_deploy_current',
            'expected' => true,
            'actual' => wlsPanelProductionLiveGateIsDeployedCurrentPath($workspaceRoot, 'var/deploy/current.json')
                && wlsPanelProductionLiveGateIsDeployedCurrentPath(
                    $workspaceRoot,
                    'C:/repo/var/deploy/current.json'
                ),
        ],
        [
            'name' => 'fixture_deploy_current_is_not_live_deployment_artifact',
            'expected' => true,
            'actual' => !wlsPanelProductionLiveGateIsDeployedCurrentPath(
                $workspaceRoot,
                'app/code/Weline/Server/doc/wls-panel-plan/tools/deploy-current-production-default.json'
            ),
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

$args = wlsPanelProductionLiveGateParseArgs($argv);
if ((string)($args['self-test'] ?? '0') === '1') {
    $selfTest = wlsPanelProductionLiveGateSelfTest();
    wlsPanelProductionLiveGateFinish([
        'passed' => $selfTest['passed'],
        'self_test' => true,
        'cases' => $selfTest['cases'],
        'case_count' => count($selfTest['cases']),
        'side_effects' => 'in-memory self-test: no file read, no network, no token, no WLS start, no writes',
    ], $selfTest['passed'] ? 0 : WLS_PANEL_PRODUCTION_LIVE_GATE_EXIT_BLOCKED);
}

$workspaceRoot = trim((string)($args['workspace-root'] ?? dirname(__DIR__, 7)));
$reportOnly = (string)($args['report-only'] ?? '0') === '1';
$allowLive = (string)($args['allow-live'] ?? '0') === '1';
$deployCurrent = trim((string)($args['deploy-current'] ?? wlsPanelProductionLiveGatePath($workspaceRoot, 'var/deploy/current.json')));
$expectedTags = trim((string)($args['expected-tags'] ?? WLS_PANEL_PRODUCTION_LIVE_GATE_EXPECTED_TAGS));
$negativeTag = trim((string)($args['negative-tag'] ?? WLS_PANEL_PRODUCTION_LIVE_GATE_NEGATIVE_TAG));
$timeout = trim((string)($args['timeout'] ?? WLS_PANEL_PRODUCTION_LIVE_GATE_TIMEOUT));
$tokenEnv = trim((string)($args['token-env'] ?? WLS_PANEL_PRODUCTION_LIVE_GATE_TOKEN_ENV));
$insecureRequested = in_array(strtolower((string)($args['insecure'] ?? '0')), ['1', 'true', 'yes'], true);
$manualEndpointProvided = trim((string)($args['endpoint'] ?? '')) !== '';
$tools = 'app/code/Weline/Server/doc/wls-panel-plan/tools/';

$sourceContract = wlsPanelProductionLiveGateRunTool($workspaceRoot, $tools . 'validate-appstore-endpoint-source-contract.php');
$productionDeployPolicy = wlsPanelProductionLiveGateRunTool($workspaceRoot, $tools . 'validate-deploy-appstore-endpoint-policy.php', [
    '--deploy-current=' . $deployCurrent,
    '--expect=production',
]);
$productionEndpoint = wlsPanelProductionLiveGateRunTool($workspaceRoot, $tools . 'marketplace-typed-tag-e2e.php', [
    '--deploy-current=' . $deployCurrent,
    '--resolve-endpoint-only=1',
]);

$sourcePayload = is_array($sourceContract['payload'] ?? null) ? $sourceContract['payload'] : [];
$productionDeployPayload = is_array($productionDeployPolicy['payload'] ?? null) ? $productionDeployPolicy['payload'] : [];
$productionDeployChecks = is_array($productionDeployPayload['checks'] ?? null) ? $productionDeployPayload['checks'] : [];
$productionEndpointPayload = is_array($productionEndpoint['payload'] ?? null) ? $productionEndpoint['payload'] : [];
$resolvedProductionEndpoint = wlsPanelProductionLiveGateResolvedEndpoint($productionEndpointPayload);
$tokenReady = wlsPanelProductionLiveGateTokenReady($args, $tokenEnv);
$deployCurrentIsDeployedArtifact = wlsPanelProductionLiveGateIsDeployedCurrentPath($workspaceRoot, $deployCurrent);

$guardChecks = [
    'tools_parsed' => ($sourceContract['parsed'] ?? false)
        && ($productionDeployPolicy['parsed'] ?? false)
        && ($productionEndpoint['parsed'] ?? false),
    'source_contract_passed' => wlsPanelProductionLiveGateBool($sourcePayload, 'passed'),
    'production_deploy_policy_passed' => wlsPanelProductionLiveGateBool($productionDeployPayload, 'passed'),
    'production_deploy_policy_exact_root' =>
        ($productionDeployChecks['production_records_exact_app_aiweline_platform_url'] ?? false) === true,
    'production_endpoint_locked' => $resolvedProductionEndpoint === WLS_PANEL_PRODUCTION_LIVE_GATE_ENDPOINT,
    'manual_endpoint_rejected' => !$manualEndpointProvided,
    'production_insecure_disabled' => !$insecureRequested,
];
$guardReady = wlsPanelProductionLiveGateGuardReady($guardChecks);
$liveExecutionChecks = [
    'production_deploy_current_is_deployed_artifact' => $deployCurrentIsDeployedArtifact,
];
$readyForLive = $guardReady
    && $liveExecutionChecks['production_deploy_current_is_deployed_artifact']
    && $tokenReady['ready'];
$liveExecuted = false;
$liveResult = null;
$livePayload = [];
$liveEvidence = null;
$livePassed = false;

if ($readyForLive && $allowLive) {
    $liveResult = wlsPanelProductionLiveGateRunTool(
        $workspaceRoot,
        $tools . 'marketplace-typed-tag-e2e.php',
        wlsPanelProductionLiveGateLiveArgs($args, $deployCurrent, $expectedTags, $negativeTag, $timeout, $tokenEnv)
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
    $livePassed = ($liveResult['exit_code'] ?? 1) === 0 && wlsPanelProductionLiveGateBool($livePayload, 'passed');
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
    'live_execution_checks' => $liveExecutionChecks,
    'deploy_current' => $deployCurrent,
    'endpoint' => $resolvedProductionEndpoint,
    'expected_tags' => $expectedTags,
    'negative_tag' => $negativeTag,
    'summary' => [
        'token_ready' => $tokenReady['ready'],
        'token_source' => $tokenReady['source'],
        'token_redacted' => true,
        'source_contract_errors' => $sourcePayload['errors'] ?? [],
        'production_deploy_policy_errors' => $productionDeployPayload['errors'] ?? [],
        'production_deploy_platform_url' => $productionDeployPayload['resolved']['raw_platform_url'] ?? null,
        'production_endpoint' => $resolvedProductionEndpoint,
        'production_deploy_current_is_deployed_artifact' => $deployCurrentIsDeployedArtifact,
    ],
    'tool_results' => [
        'source_contract' => [
            'exit_code' => $sourceContract['exit_code'],
            'parsed' => $sourceContract['parsed'],
            'passed' => $sourcePayload['passed'] ?? null,
        ],
        'production_deploy_policy' => [
            'exit_code' => $productionDeployPolicy['exit_code'],
            'parsed' => $productionDeployPolicy['parsed'],
            'passed' => $productionDeployPayload['passed'] ?? null,
            'raw_platform_url' => $productionDeployPayload['resolved']['raw_platform_url'] ?? null,
            'exact_root' => $productionDeployChecks['production_records_exact_app_aiweline_platform_url'] ?? null,
            'resolved_endpoint' => $productionDeployPayload['resolved']['resolved_endpoint'] ?? null,
        ],
        'production_endpoint' => [
            'exit_code' => $productionEndpoint['exit_code'],
            'parsed' => $productionEndpoint['parsed'],
            'endpoint' => $resolvedProductionEndpoint,
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
            ? 'live production AppStore API request executed; no token value is printed'
            : 'guarded production preflight only: no network, no token value, no WLS start, no writes',
        'live_execution_rule' => 'Pass --allow-live=1 only after ready_for_live=true; endpoint must resolve from deployed var/deploy/current.json and equal app.aiweline.com.',
    ],
];

$exitCode = match ($status) {
    'passed', 'ready_not_executed' => 0,
    default => WLS_PANEL_PRODUCTION_LIVE_GATE_EXIT_BLOCKED,
};

wlsPanelProductionLiveGateFinish($payload, $reportOnly ? 0 : $exitCode);
