<?php
declare(strict_types=1);

/**
 * Cross-checks the final preflight, final workorder, and authorization packet
 * before any App checkout sync, WLS start, token export, or live AppStore call.
 *
 * The tool is read-only. It verifies that local development stays on the local
 * App Store, deployed production stays on app.aiweline.com, and all three
 * operator-facing reports describe the same App checkout drift review.
 */

const WLS_PANEL_AUTH_CONSISTENCY_LOCAL_ROOT = 'https://app.weline.test:9523';
const WLS_PANEL_AUTH_CONSISTENCY_PRODUCTION_ROOT = 'https://app.aiweline.com';
const WLS_PANEL_AUTH_CONSISTENCY_LOCAL_ENDPOINT = 'https://app.weline.test:9523/api/v1/platform/module/list';
const WLS_PANEL_AUTH_CONSISTENCY_PRODUCTION_ENDPOINT = 'https://app.aiweline.com/api/v1/platform/module/list';
const WLS_PANEL_AUTH_CONSISTENCY_LOCAL_CHECKOUT = 'E:\\WelineFramework\\Framework-Official\\App\\weline';
const WLS_PANEL_AUTH_CONSISTENCY_EXIT_FAILED = 1;

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function wlsPanelAuthConsistencyParseArgs(array $argv): array
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
function wlsPanelAuthConsistencyFinish(array $payload, int $exitCode): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($exitCode);
}

function wlsPanelAuthConsistencyPath(string $base, string $relative): string
{
    return rtrim($base, "\\/") . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
}

/**
 * @return array<string, mixed>|null
 */
function wlsPanelAuthConsistencyExtractJson(string $output): ?array
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
function wlsPanelAuthConsistencyRunTool(string $workspaceRoot, string $relativeTool, array $args = []): array
{
    $command = array_merge([PHP_BINARY, wlsPanelAuthConsistencyPath($workspaceRoot, $relativeTool)], $args);
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
    $payload = wlsPanelAuthConsistencyExtractJson($output);

    return [
        'tool' => $relativeTool,
        'exit_code' => $exitCode,
        'parsed' => $payload !== null,
        'payload' => $payload,
        'output_bytes' => strlen($output),
    ];
}

function wlsPanelAuthConsistencyNoSecretLeak(mixed $payload): bool
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return false;
    }

    $lower = strtolower($json);
    if (preg_match('/authorization:\s*bearer\s+[a-z0-9._~+\/=-]{12,}/i', $json) === 1) {
        return false;
    }

    if (preg_match('/bearer\s+[a-z0-9._~+\/=-]{12,}/i', $json) === 1) {
        return false;
    }

    if (preg_match('/wls_marketplace_bearer_token\s*=?\s*[a-z0-9._~+\/=-]{12,}/i', $json) === 1) {
        return false;
    }

    return !str_contains($lower, 'private_key')
        && !str_contains($lower, '-----begin')
        && !str_contains($lower, 'cookie:');
}

/**
 * @param array<string, bool> $checks
 * @return list<string>
 */
function wlsPanelAuthConsistencyErrors(array $checks): array
{
    $errors = [];
    foreach ($checks as $name => $passed) {
        if ($passed !== true) {
            $errors[] = 'check_failed:' . $name;
        }
    }

    return $errors;
}

/**
 * @return array<string, mixed>
 */
function wlsPanelAuthConsistencyAssess(array $preflight, array $workorder, array $authorization): array
{
    $preflightChecks = is_array($preflight['checks'] ?? null) ? $preflight['checks'] : [];
    $preflightSummary = is_array($preflight['summary'] ?? null) ? $preflight['summary'] : [];
    $workorderChecks = is_array($workorder['preflight_checks'] ?? null) ? $workorder['preflight_checks'] : [];
    $workorderPolicy = is_array($workorder['environment_policy'] ?? null) ? $workorder['environment_policy'] : [];
    $workorderLocalPolicy = is_array($workorderPolicy['local_development'] ?? null)
        ? $workorderPolicy['local_development']
        : [];
    $workorderSourceSummary = is_array($workorder['source_summary'] ?? null) ? $workorder['source_summary'] : [];
    $authorizationChecks = is_array($authorization['checks'] ?? null) ? $authorization['checks'] : [];
    $goalComplete = ($preflight['goal_complete'] ?? false) === true
        || ($preflightSummary['goal_complete'] ?? false) === true
        || ($workorder['goal_complete'] ?? false) === true;

    $preflightFingerprint = (string)($preflightSummary['drift_review_fingerprint'] ?? '');
    $workorderFingerprint = (string)($workorderSourceSummary['drift_review_fingerprint'] ?? '');
    $authorizationFingerprint = (string)($authorization['tool_results']['sync_manifest']['drift_review_fingerprint'] ?? '');

    $preflightLocalEndpoint = (string)($preflightSummary['local_endpoint'] ?? '');
    $preflightProductionEndpoint = (string)($preflightSummary['production_endpoint'] ?? '');
    $preflightLocalEnvWlsEndpoint = (string)($preflightSummary['readiness_app_env_wls_endpoint_url'] ?? '');
    $workorderLocalRoot = (string)($workorderLocalPolicy['root'] ?? '');
    $workorderLocalCheckout = (string)($workorderLocalPolicy['checkout'] ?? '');
    $workorderLocalEnvWlsEndpoint = (string)($workorderLocalPolicy['env_wls_endpoint'] ?? '');
    $workorderProductionPolicy = is_array($workorderPolicy['production_deployed'] ?? null)
        ? $workorderPolicy['production_deployed']
        : [];
    $workorderProductionRoot = (string)($workorderProductionPolicy['root'] ?? '');
    $authorizationLocalRoot = (string)($authorization['marketplace_roots']['local'] ?? '');
    $authorizationProductionRoot = (string)($authorization['marketplace_roots']['production'] ?? '');
    $authorizationLocalEnvWlsEndpoint = (string)($authorization['tool_results']['final_preflight']['local_app_env_wls_endpoint_url'] ?? '');

    $checks = [
        'preflight_has_drift_fingerprint' => $preflightFingerprint !== ''
            && ($preflightChecks['sync_manifest_drift_review_fingerprint_present'] ?? false) === true,
        'workorder_has_drift_fingerprint' => $workorderFingerprint !== ''
            && ($workorderChecks['sync_manifest_drift_review_fingerprint_present'] ?? false) === true,
        'authorization_has_drift_fingerprint' => $authorizationFingerprint !== ''
            && ($authorizationChecks['drift_review_fingerprint_present'] ?? false) === true,
        'drift_fingerprints_match' => $preflightFingerprint !== ''
            && $preflightFingerprint === $workorderFingerprint
            && $preflightFingerprint === $authorizationFingerprint,
        'preflight_local_endpoint_locked' => $preflightLocalEndpoint === WLS_PANEL_AUTH_CONSISTENCY_LOCAL_ENDPOINT,
        'preflight_production_endpoint_locked' =>
            $preflightProductionEndpoint === WLS_PANEL_AUTH_CONSISTENCY_PRODUCTION_ENDPOINT,
        'workorder_local_root_locked' => $workorderLocalRoot === WLS_PANEL_AUTH_CONSISTENCY_LOCAL_ROOT,
        'workorder_production_root_locked' => $workorderProductionRoot === WLS_PANEL_AUTH_CONSISTENCY_PRODUCTION_ROOT,
        'authorization_local_root_locked' => $authorizationLocalRoot === WLS_PANEL_AUTH_CONSISTENCY_LOCAL_ROOT,
        'authorization_production_root_locked' =>
            $authorizationProductionRoot === WLS_PANEL_AUTH_CONSISTENCY_PRODUCTION_ROOT,
        'preflight_local_app_checkout_identity_ok' =>
            ($preflightChecks['local_readiness_app_checkout_identity_ok'] ?? false) === true,
        'preflight_local_app_env_wls_endpoint_locked' =>
            ($preflightChecks['local_readiness_app_env_wls_endpoint_locked'] ?? false) === true
            && $preflightLocalEnvWlsEndpoint === WLS_PANEL_AUTH_CONSISTENCY_LOCAL_ROOT,
        'workorder_local_app_checkout_identity_ok' =>
            $workorderLocalCheckout === WLS_PANEL_AUTH_CONSISTENCY_LOCAL_CHECKOUT
            && ($workorderChecks['local_readiness_app_checkout_identity_ok'] ?? false) === true
            && ($workorderSourceSummary['local_app_checkout_identity_ok'] ?? false) === true,
        'workorder_local_app_env_wls_endpoint_locked' =>
            $workorderLocalEnvWlsEndpoint === WLS_PANEL_AUTH_CONSISTENCY_LOCAL_ROOT
            && ($workorderChecks['local_readiness_app_env_wls_endpoint_locked'] ?? false) === true
            && ($workorderSourceSummary['local_app_env_wls_endpoint_locked'] ?? false) === true
            && ($workorderSourceSummary['local_app_env_wls_endpoint_url'] ?? '') === WLS_PANEL_AUTH_CONSISTENCY_LOCAL_ROOT,
        'authorization_local_app_checkout_identity_ok' =>
            ($authorizationChecks['local_app_checkout_identity_ok'] ?? false) === true,
        'authorization_local_app_env_wls_endpoint_locked' =>
            ($authorizationChecks['local_app_env_wls_endpoint_locked'] ?? false) === true
            && ($authorizationChecks['local_app_env_wls_endpoint_exact_root'] ?? false) === true
            && $authorizationLocalEnvWlsEndpoint === WLS_PANEL_AUTH_CONSISTENCY_LOCAL_ROOT,
        'local_app_checkout_identity_consistent' =>
            $workorderLocalCheckout === WLS_PANEL_AUTH_CONSISTENCY_LOCAL_CHECKOUT
            && ($preflightChecks['local_readiness_app_checkout_identity_ok'] ?? false) === true
            && ($workorderChecks['local_readiness_app_checkout_identity_ok'] ?? false) === true
            && ($authorizationChecks['local_app_checkout_identity_ok'] ?? false) === true,
        'local_app_env_wls_endpoint_consistent' =>
            $preflightLocalEnvWlsEndpoint === WLS_PANEL_AUTH_CONSISTENCY_LOCAL_ROOT
            && $workorderLocalEnvWlsEndpoint === WLS_PANEL_AUTH_CONSISTENCY_LOCAL_ROOT
            && $authorizationLocalEnvWlsEndpoint === WLS_PANEL_AUTH_CONSISTENCY_LOCAL_ROOT,
        'workorder_ready' => ($workorder['workorder_ready'] ?? false) === true,
        'authorization_pack_ready_for_review' =>
            $goalComplete || ($authorization['authorization_pack_ready_for_review'] ?? false) === true,
        'no_secret_values' => wlsPanelAuthConsistencyNoSecretLeak([
            'preflight_summary' => $preflight['summary'] ?? [],
            'workorder_environment_policy' => $workorder['environment_policy'] ?? [],
            'workorder_source_summary' => $workorder['source_summary'] ?? [],
            'authorization_marketplace_roots' => $authorization['marketplace_roots'] ?? [],
            'authorization_sync_manifest' => $authorization['tool_results']['sync_manifest'] ?? [],
        ]),
    ];
    $errors = wlsPanelAuthConsistencyErrors($checks);

    return [
        'passed' => $errors === [],
        'checks' => $checks,
        'errors' => $errors,
        'contract' => [
            'local_development_root' => WLS_PANEL_AUTH_CONSISTENCY_LOCAL_ROOT,
            'local_development_endpoint' => WLS_PANEL_AUTH_CONSISTENCY_LOCAL_ENDPOINT,
            'local_development_checkout' => WLS_PANEL_AUTH_CONSISTENCY_LOCAL_CHECKOUT,
            'local_development_env_wls_endpoint' => WLS_PANEL_AUTH_CONSISTENCY_LOCAL_ROOT,
            'production_deployed_root' => WLS_PANEL_AUTH_CONSISTENCY_PRODUCTION_ROOT,
            'production_deployed_endpoint' => WLS_PANEL_AUTH_CONSISTENCY_PRODUCTION_ENDPOINT,
        ],
        'source_summary' => [
            'preflight_drift_review_fingerprint' => $preflightFingerprint,
            'workorder_drift_review_fingerprint' => $workorderFingerprint,
            'authorization_drift_review_fingerprint' => $authorizationFingerprint,
            'preflight_local_endpoint' => $preflightLocalEndpoint,
            'preflight_production_endpoint' => $preflightProductionEndpoint,
            'preflight_local_app_env_wls_endpoint' => $preflightLocalEnvWlsEndpoint,
            'workorder_local_root' => $workorderLocalRoot,
            'workorder_local_checkout' => $workorderLocalCheckout,
            'workorder_local_env_wls_endpoint' => $workorderLocalEnvWlsEndpoint,
            'workorder_production_root' => $workorderProductionRoot,
            'authorization_local_root' => $authorizationLocalRoot,
            'authorization_local_env_wls_endpoint' => $authorizationLocalEnvWlsEndpoint,
            'authorization_production_root' => $authorizationProductionRoot,
        ],
        'side_effects' => 'read-only consistency check: no sync, no setup, no WLS start, no manifest writes, no token values, no live API calls',
    ];
}

/**
 * @return array<string, mixed>
 */
function wlsPanelAuthConsistencyFixturePreflight(string $fingerprint): array
{
    return [
        'ok' => true,
        'checks' => [
            'sync_manifest_drift_review_fingerprint_present' => true,
            'local_readiness_app_checkout_identity_ok' => true,
            'local_readiness_app_env_wls_endpoint_locked' => true,
        ],
        'summary' => [
            'drift_review_fingerprint' => $fingerprint,
            'local_endpoint' => WLS_PANEL_AUTH_CONSISTENCY_LOCAL_ENDPOINT,
            'production_endpoint' => WLS_PANEL_AUTH_CONSISTENCY_PRODUCTION_ENDPOINT,
            'readiness_app_env_wls_endpoint_url' => WLS_PANEL_AUTH_CONSISTENCY_LOCAL_ROOT,
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function wlsPanelAuthConsistencyFixtureWorkorder(string $fingerprint): array
{
    return [
        'workorder_ready' => true,
        'environment_policy' => [
            'local_development' => [
                'root' => WLS_PANEL_AUTH_CONSISTENCY_LOCAL_ROOT,
                'checkout' => WLS_PANEL_AUTH_CONSISTENCY_LOCAL_CHECKOUT,
                'env_wls_endpoint' => WLS_PANEL_AUTH_CONSISTENCY_LOCAL_ROOT,
            ],
            'production_deployed' => [
                'root' => WLS_PANEL_AUTH_CONSISTENCY_PRODUCTION_ROOT,
            ],
        ],
        'preflight_checks' => [
            'sync_manifest_drift_review_fingerprint_present' => true,
            'local_readiness_app_checkout_identity_ok' => true,
            'local_readiness_app_env_wls_endpoint_locked' => true,
        ],
        'source_summary' => [
            'drift_review_fingerprint' => $fingerprint,
            'local_app_checkout_identity_ok' => true,
            'local_app_env_wls_endpoint_locked' => true,
            'local_app_env_wls_endpoint_url' => WLS_PANEL_AUTH_CONSISTENCY_LOCAL_ROOT,
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function wlsPanelAuthConsistencyFixtureAuthorization(string $fingerprint): array
{
    return [
        'authorization_pack_ready_for_review' => true,
        'marketplace_roots' => [
            'local' => WLS_PANEL_AUTH_CONSISTENCY_LOCAL_ROOT,
            'production' => WLS_PANEL_AUTH_CONSISTENCY_PRODUCTION_ROOT,
        ],
        'checks' => [
            'drift_review_fingerprint_present' => true,
            'local_app_checkout_identity_ok' => true,
            'local_app_env_wls_endpoint_locked' => true,
            'local_app_env_wls_endpoint_exact_root' => true,
        ],
        'tool_results' => [
            'sync_manifest' => [
                'drift_review_fingerprint' => $fingerprint,
            ],
            'final_preflight' => [
                'local_app_env_wls_endpoint_url' => WLS_PANEL_AUTH_CONSISTENCY_LOCAL_ROOT,
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function wlsPanelAuthConsistencySelfTest(): array
{
    $fingerprint = '1234567890abcdef';
    $matching = wlsPanelAuthConsistencyAssess(
        wlsPanelAuthConsistencyFixturePreflight($fingerprint),
        wlsPanelAuthConsistencyFixtureWorkorder($fingerprint),
        wlsPanelAuthConsistencyFixtureAuthorization($fingerprint)
    );

    $mismatchedAuthorization = wlsPanelAuthConsistencyFixtureAuthorization('fedcba0987654321');
    $mismatched = wlsPanelAuthConsistencyAssess(
        wlsPanelAuthConsistencyFixturePreflight($fingerprint),
        wlsPanelAuthConsistencyFixtureWorkorder($fingerprint),
        $mismatchedAuthorization
    );

    $badProductionRoot = wlsPanelAuthConsistencyFixtureWorkorder($fingerprint);
    $badProductionRoot['environment_policy']['production_deployed']['root'] = 'https://www.aiweline.com';
    $badProduction = wlsPanelAuthConsistencyAssess(
        wlsPanelAuthConsistencyFixturePreflight($fingerprint),
        $badProductionRoot,
        wlsPanelAuthConsistencyFixtureAuthorization($fingerprint)
    );

    $missingFingerprintCheck = wlsPanelAuthConsistencyFixtureAuthorization($fingerprint);
    $missingFingerprintCheck['checks']['drift_review_fingerprint_present'] = false;
    $missingFingerprint = wlsPanelAuthConsistencyAssess(
        wlsPanelAuthConsistencyFixturePreflight($fingerprint),
        wlsPanelAuthConsistencyFixtureWorkorder($fingerprint),
        $missingFingerprintCheck
    );

    $badLocalCheckout = wlsPanelAuthConsistencyFixtureWorkorder($fingerprint);
    $badLocalCheckout['environment_policy']['local_development']['checkout'] =
        'E:\\WelineFramework\\Framework-Official\\Official\\weline';
    $badCheckout = wlsPanelAuthConsistencyAssess(
        wlsPanelAuthConsistencyFixturePreflight($fingerprint),
        $badLocalCheckout,
        wlsPanelAuthConsistencyFixtureAuthorization($fingerprint)
    );

    $missingEnvEndpointLock = wlsPanelAuthConsistencyFixtureAuthorization($fingerprint);
    $missingEnvEndpointLock['checks']['local_app_env_wls_endpoint_locked'] = false;
    $missingEndpointLock = wlsPanelAuthConsistencyAssess(
        wlsPanelAuthConsistencyFixturePreflight($fingerprint),
        wlsPanelAuthConsistencyFixtureWorkorder($fingerprint),
        $missingEnvEndpointLock
    );

    $secretLeak = wlsPanelAuthConsistencyFixtureAuthorization($fingerprint);
    $secretLeak['tool_results']['sync_manifest']['debug_header'] = 'Authorization: Bearer abcdefghijklmnop';
    $secret = wlsPanelAuthConsistencyAssess(
        wlsPanelAuthConsistencyFixturePreflight($fingerprint),
        wlsPanelAuthConsistencyFixtureWorkorder($fingerprint),
        $secretLeak
    );

    $cases = [
        [
            'name' => 'matching_preflight_workorder_authorization_passes',
            'case_ok' => ($matching['passed'] ?? false) === true,
        ],
        [
            'name' => 'mismatched_drift_fingerprint_fails',
            'case_ok' => ($mismatched['passed'] ?? true) === false
                && in_array('check_failed:drift_fingerprints_match', (array)($mismatched['errors'] ?? []), true),
        ],
        [
            'name' => 'official_website_production_root_fails',
            'case_ok' => ($badProduction['passed'] ?? true) === false
                && in_array('check_failed:workorder_production_root_locked', (array)($badProduction['errors'] ?? []), true),
        ],
        [
            'name' => 'missing_authorization_fingerprint_check_fails',
            'case_ok' => ($missingFingerprint['passed'] ?? true) === false
                && in_array('check_failed:authorization_has_drift_fingerprint', (array)($missingFingerprint['errors'] ?? []), true),
        ],
        [
            'name' => 'wrong_local_app_checkout_identity_fails',
            'case_ok' => ($badCheckout['passed'] ?? true) === false
                && in_array('check_failed:workorder_local_app_checkout_identity_ok', (array)($badCheckout['errors'] ?? []), true)
                && in_array('check_failed:local_app_checkout_identity_consistent', (array)($badCheckout['errors'] ?? []), true),
        ],
        [
            'name' => 'missing_authorization_env_endpoint_lock_fails',
            'case_ok' => ($missingEndpointLock['passed'] ?? true) === false
                && in_array('check_failed:authorization_local_app_env_wls_endpoint_locked', (array)($missingEndpointLock['errors'] ?? []), true),
        ],
        [
            'name' => 'secret_values_fail',
            'case_ok' => ($secret['passed'] ?? true) === false
                && in_array('check_failed:no_secret_values', (array)($secret['errors'] ?? []), true),
        ],
    ];

    return [
        'passed' => !in_array(false, array_column($cases, 'case_ok'), true),
        'self_test' => true,
        'cases' => $cases,
        'side_effects' => 'in-memory self-test: no file read, no network, no token values, no WLS start, no writes',
    ];
}

$args = wlsPanelAuthConsistencyParseArgs($argv);
if (($args['self-test'] ?? '0') === '1') {
    $result = wlsPanelAuthConsistencySelfTest();
    wlsPanelAuthConsistencyFinish($result, ($result['passed'] ?? false) === true ? 0 : WLS_PANEL_AUTH_CONSISTENCY_EXIT_FAILED);
}

$workspaceRoot = trim((string)($args['workspace-root'] ?? dirname(__DIR__, 7)));
$tools = 'app/code/Weline/Server/doc/wls-panel-plan/tools/';

$preflightResult = wlsPanelAuthConsistencyRunTool($workspaceRoot, $tools . 'wls-panel-final-preflight.php', [
    '--report-only=1',
]);
$workorderResult = wlsPanelAuthConsistencyRunTool($workspaceRoot, $tools . 'wls-panel-final-workorder.php');
$authorizationResult = wlsPanelAuthConsistencyRunTool($workspaceRoot, $tools . 'wls-panel-live-e2e-authorization-pack.php', [
    '--include-drift-rows=1',
    '--include-rollback-review=1',
    '--fail-if-unsafe=1',
]);

$preflight = is_array($preflightResult['payload'] ?? null) ? $preflightResult['payload'] : [];
$workorder = is_array($workorderResult['payload'] ?? null) ? $workorderResult['payload'] : [];
$authorization = is_array($authorizationResult['payload'] ?? null) ? $authorizationResult['payload'] : [];
$assessment = wlsPanelAuthConsistencyAssess($preflight, $workorder, $authorization);
$goalComplete = ($preflight['goal_complete'] ?? false) === true
    || (is_array($preflight['summary'] ?? null) && ($preflight['summary']['goal_complete'] ?? false) === true)
    || ($workorder['goal_complete'] ?? false) === true;

$toolChecks = [
    'tools_parsed' => ($preflightResult['parsed'] ?? false) === true
        && ($workorderResult['parsed'] ?? false) === true
        && ($authorizationResult['parsed'] ?? false) === true,
    'tool_exit_codes_usable' => (int)($preflightResult['exit_code'] ?? 1) === 0
        && (int)($workorderResult['exit_code'] ?? 1) === 0
        && ($goalComplete || (int)($authorizationResult['exit_code'] ?? 1) === 0),
];
$checks = array_merge($toolChecks, is_array($assessment['checks'] ?? null) ? $assessment['checks'] : []);
$errors = wlsPanelAuthConsistencyErrors($checks);

$payload = [
    'ok' => $errors === [],
    'passed' => $errors === [],
    'checks' => $checks,
    'errors' => $errors,
    'contract' => $assessment['contract'] ?? [],
    'source_summary' => $assessment['source_summary'] ?? [],
    'tool_results' => [
        'final_preflight' => [
            'exit_code' => $preflightResult['exit_code'] ?? null,
            'parsed' => $preflightResult['parsed'] ?? false,
            'ready_for_live_local_appstore_e2e' => $preflight['ready_for_live_local_appstore_e2e'] ?? null,
            'goal_complete' => $preflight['goal_complete'] ?? null,
            'output_bytes' => $preflightResult['output_bytes'] ?? null,
        ],
        'final_workorder' => [
            'exit_code' => $workorderResult['exit_code'] ?? null,
            'parsed' => $workorderResult['parsed'] ?? false,
            'workorder_ready' => $workorder['workorder_ready'] ?? null,
            'current_state' => $workorder['current_state'] ?? null,
            'output_bytes' => $workorderResult['output_bytes'] ?? null,
        ],
        'authorization_pack' => [
            'exit_code' => $authorizationResult['exit_code'] ?? null,
            'parsed' => $authorizationResult['parsed'] ?? false,
            'authorization_pack_ready_for_review' => $authorization['authorization_pack_ready_for_review'] ?? null,
            'current_state' => $authorization['current_state'] ?? null,
            'output_bytes' => $authorizationResult['output_bytes'] ?? null,
        ],
    ],
    'side_effects' => 'read-only consistency check: no sync, no setup, no WLS start, no manifest writes, no token values, no live API calls',
];

wlsPanelAuthConsistencyFinish($payload, $errors === [] ? 0 : WLS_PANEL_AUTH_CONSISTENCY_EXIT_FAILED);
