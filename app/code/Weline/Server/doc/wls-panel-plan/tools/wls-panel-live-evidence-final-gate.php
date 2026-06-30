<?php
declare(strict_types=1);

/**
 * Read-only final gate for captured WLS AppStore typed-tag live E2E evidence.
 *
 * This tool is intended for the step after a guarded live run has written
 * var/wls-panel-plan/*.json. It does not call AppStore, read token values,
 * start WLS, or write files; it only delegates to the evidence validator and
 * requires capture-wrapper provenance for final acceptance.
 */

const WLS_PANEL_LIVE_FINAL_GATE_EXIT_NOT_READY = 1;
const WLS_PANEL_LIVE_FINAL_GATE_EXIT_ASSERTION_FAILED = 2;
const WLS_PANEL_LIVE_FINAL_GATE_LOCAL_ROOT = 'https://app.weline.test:9523';
const WLS_PANEL_LIVE_FINAL_GATE_PRODUCTION_ROOT = 'https://app.aiweline.com';
const WLS_PANEL_LIVE_FINAL_GATE_LOCAL_ENDPOINT = 'https://app.weline.test:9523/api/v1/platform/module/list';
const WLS_PANEL_LIVE_FINAL_GATE_PRODUCTION_ENDPOINT = 'https://app.aiweline.com/api/v1/platform/module/list';
const WLS_PANEL_LIVE_FINAL_GATE_LOCAL_CHECKOUT = 'E:\\WelineFramework\\Framework-Official\\App\\weline';
const WLS_PANEL_LIVE_FINAL_GATE_LOCAL_ENV_WLS_ENDPOINT = WLS_PANEL_LIVE_FINAL_GATE_LOCAL_ROOT;

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function wlsPanelLiveFinalGateParseArgs(array $argv): array
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
function wlsPanelLiveFinalGateFinish(array $payload, int $exitCode): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($exitCode);
}

function wlsPanelLiveFinalGatePath(string $base, string $relative): string
{
    return rtrim($base, "\\/") . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
}

/**
 * @return array<string, mixed>|null
 */
function wlsPanelLiveFinalGateExtractJson(string $output): ?array
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
function wlsPanelLiveFinalGateRunTool(string $workspaceRoot, string $relativeTool, array $args): array
{
    $toolPath = wlsPanelLiveFinalGatePath($workspaceRoot, $relativeTool);
    $command = array_merge([PHP_BINARY, $toolPath], $args);
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
    $payload = wlsPanelLiveFinalGateExtractJson($output);

    return [
        'exit_code' => $exitCode,
        'parsed' => $payload !== null,
        'payload' => $payload,
        'output_bytes' => strlen($output),
    ];
}

/**
 * @return list<string>
 */
function wlsPanelLiveFinalGateEnvironments(string $environment): array
{
    return match ($environment) {
        'local' => ['local'],
        'production' => ['production'],
        'both' => ['local', 'production'],
        default => [],
    };
}

function wlsPanelLiveFinalGateExpectedEndpoint(string $environment): string
{
    return $environment === 'production'
        ? WLS_PANEL_LIVE_FINAL_GATE_PRODUCTION_ENDPOINT
        : WLS_PANEL_LIVE_FINAL_GATE_LOCAL_ENDPOINT;
}

function wlsPanelLiveFinalGateAcceptedEndpointSource(string $endpointSource, string $environment): bool
{
    if ($environment === 'production') {
        return str_starts_with($endpointSource, 'deploy-current:');
    }

    return $endpointSource === 'arg:endpoint' || str_starts_with($endpointSource, 'deploy-current:');
}

function wlsPanelLiveFinalGateNormalizeAbsolutePath(string $path): string
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

function wlsPanelLiveFinalGateEvidencePathInsideVar(string $workspaceRoot, string $absolutePath): bool
{
    if (trim($absolutePath) === '') {
        return false;
    }

    $normalizedPath = wlsPanelLiveFinalGateNormalizeAbsolutePath($absolutePath);
    $normalizedVarRoot = wlsPanelLiveFinalGateNormalizeAbsolutePath(
        wlsPanelLiveFinalGatePath($workspaceRoot, 'var/wls-panel-plan')
    );
    $prefix = rtrim($normalizedVarRoot, '/') . '/';

    $caseInsensitive = preg_match('/^[A-Z]:\//', $normalizedPath) === 1;
    if ($caseInsensitive) {
        return str_starts_with(strtolower($normalizedPath), strtolower($prefix));
    }

    return str_starts_with($normalizedPath, $prefix);
}

/**
 * @return array{accepted:bool,checks:array<string,bool>,errors:list<string>}
 */
function wlsPanelLiveFinalGateAssessValidatorPayload(array $payload, string $environment): array
{
    $checks = is_array($payload['checks'] ?? null) ? $payload['checks'] : [];
    $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
    $expectedEndpoint = wlsPanelLiveFinalGateExpectedEndpoint($environment);
    $productionEvidenceSource = $environment !== 'production'
        || ($checks['production_evidence_source_is_deployed_current_json'] ?? false) === true;
    $productionCaptureSource = $environment !== 'production'
        || ($checks['production_capture_source_is_deployed_current_json'] ?? false) === true;
    $endpointSource = (string)($summary['endpoint_source'] ?? '');
    $captureEndpointSource = (string)($summary['capture_metadata_endpoint_source'] ?? '');

    $gateChecks = [
        'validator_valid' => ($payload['valid'] ?? false) === true,
        'validator_expect_matches' => ($payload['expect'] ?? '') === $environment
            || ($summary['expect'] ?? '') === $environment,
        'endpoint_exact' => ($summary['endpoint'] ?? '') === $expectedEndpoint
            && ($summary['expected_endpoint'] ?? '') === $expectedEndpoint,
        'endpoint_source_deploy_current' => ($checks['evidence_endpoint_source_deploy_current'] ?? false) === true
            && wlsPanelLiveFinalGateAcceptedEndpointSource($endpointSource, $environment),
        'production_evidence_source_is_deployed_current_json' => $productionEvidenceSource,
        'wrapper_payload_required' => ($summary['wrapper_payload'] ?? false) === true,
        'wrapper_live_executed' => ($checks['wrapper_live_executed'] ?? false) === true,
        'wrapper_live_passed' => ($checks['wrapper_live_passed'] ?? false) === true,
        'wrapper_guard_passed' => ($checks['wrapper_guard_passed'] ?? false) === true,
        'capture_metadata_present' => ($checks['capture_metadata_present'] ?? false) === true
            && ($summary['capture_metadata_present'] ?? false) === true,
        'capture_metadata_environment' => ($checks['capture_metadata_environment'] ?? false) === true
            && ($summary['capture_metadata_environment'] ?? '') === $environment,
        'capture_metadata_source_gate' => ($checks['capture_metadata_source_gate'] ?? false) === true
            && ($summary['capture_metadata_source_gate'] ?? '') !== '',
        'capture_metadata_endpoint_exact' => ($checks['capture_metadata_endpoint_exact'] ?? false) === true,
        'capture_metadata_endpoint_source_deploy_current' =>
            ($checks['capture_metadata_endpoint_source_deploy_current'] ?? false) === true
            && $captureEndpointSource === $endpointSource
            && wlsPanelLiveFinalGateAcceptedEndpointSource($captureEndpointSource, $environment),
        'production_capture_source_is_deployed_current_json' => $productionCaptureSource,
        'capture_metadata_output_inside_var' => ($checks['capture_metadata_output_inside_var'] ?? false) === true,
        'capture_metadata_output_path_present' => ($checks['capture_metadata_output_path_present'] ?? false) === true
            && ($summary['capture_metadata_output_path'] ?? null) !== null,
        'capture_metadata_output_path_matches_file' =>
            ($checks['capture_metadata_output_path_matches_file'] ?? false) === true
            && ($summary['evidence_path'] ?? null) !== null,
        'capture_consistency_metadata_present' =>
            ($checks['capture_consistency_metadata_present'] ?? false) === true
            && ($summary['capture_consistency_present'] ?? false) === true,
        'capture_consistency_passed' => ($checks['capture_consistency_passed'] ?? false) === true,
        'capture_consistency_drift_fingerprints_match' =>
            ($checks['capture_consistency_drift_fingerprints_match'] ?? false) === true,
        'capture_consistency_all_roots_and_endpoints_locked' =>
            ($checks['capture_consistency_preflight_local_endpoint_locked'] ?? false) === true
            && ($checks['capture_consistency_preflight_production_endpoint_locked'] ?? false) === true
            && ($checks['capture_consistency_workorder_local_root_locked'] ?? false) === true
            && ($checks['capture_consistency_workorder_production_root_locked'] ?? false) === true
            && ($checks['capture_consistency_authorization_local_root_locked'] ?? false) === true
            && ($checks['capture_consistency_authorization_production_root_locked'] ?? false) === true,
        'capture_consistency_local_app_identity_locked' =>
            ($checks['capture_consistency_preflight_local_app_checkout_identity_ok'] ?? false) === true
            && ($checks['capture_consistency_workorder_local_app_checkout_identity_ok'] ?? false) === true
            && ($checks['capture_consistency_authorization_local_app_checkout_identity_ok'] ?? false) === true
            && ($checks['capture_consistency_local_app_checkout_identity_consistent'] ?? false) === true,
        'capture_consistency_local_app_env_endpoint_locked' =>
            ($checks['capture_consistency_preflight_local_app_env_wls_endpoint_locked'] ?? false) === true
            && ($checks['capture_consistency_workorder_local_app_env_wls_endpoint_locked'] ?? false) === true
            && ($checks['capture_consistency_authorization_local_app_env_wls_endpoint_locked'] ?? false) === true
            && ($checks['capture_consistency_local_app_env_wls_endpoint_consistent'] ?? false) === true,
        'capture_consistency_local_root_exact' =>
            ($checks['capture_consistency_local_root_exact'] ?? false) === true
            && ($summary['capture_consistency_local_root'] ?? '') === WLS_PANEL_LIVE_FINAL_GATE_LOCAL_ROOT,
        'capture_consistency_local_checkout_exact' =>
            ($checks['capture_consistency_local_checkout_exact'] ?? false) === true
            && ($summary['capture_consistency_local_checkout'] ?? '') === WLS_PANEL_LIVE_FINAL_GATE_LOCAL_CHECKOUT,
        'capture_consistency_local_env_wls_endpoint_exact' =>
            ($checks['capture_consistency_local_env_wls_endpoint_exact'] ?? false) === true
            && ($summary['capture_consistency_local_env_wls_endpoint'] ?? '')
                === WLS_PANEL_LIVE_FINAL_GATE_LOCAL_ENV_WLS_ENDPOINT,
        'capture_consistency_production_root_exact' =>
            ($checks['capture_consistency_production_root_exact'] ?? false) === true
            && ($summary['capture_consistency_production_root'] ?? '') === WLS_PANEL_LIVE_FINAL_GATE_PRODUCTION_ROOT,
        'capture_consistency_endpoint_contract_exact' =>
            ($checks['capture_consistency_local_endpoint_exact'] ?? false) === true
            && ($checks['capture_consistency_production_endpoint_exact'] ?? false) === true,
        'capture_consistency_drift_fingerprint_present' =>
            ($checks['capture_consistency_drift_fingerprint_present'] ?? false) === true
            && ($summary['capture_consistency_drift_fingerprint'] ?? null) !== null,
        'no_secret_values' => ($checks['no_secret_values'] ?? false) === true,
    ];

    $errors = [];
    foreach ($gateChecks as $label => $passed) {
        if (!$passed) {
            $errors[] = 'check_failed:' . $label;
        }
    }

    return [
        'accepted' => $errors === [],
        'checks' => $gateChecks,
        'errors' => $errors,
    ];
}

/**
 * @return array{passed:bool,cases:list<array<string,mixed>>}
 */
function wlsPanelLiveFinalGateSelfTest(): array
{
    $workspaceRoot = 'C:/repo';
    $validLocal = [
        'valid' => true,
        'expect' => 'local',
        'checks' => [
            'wrapper_live_executed' => true,
            'wrapper_live_passed' => true,
            'wrapper_guard_passed' => true,
            'capture_metadata_present' => true,
            'capture_metadata_environment' => true,
            'capture_metadata_source_gate' => true,
            'capture_metadata_endpoint_exact' => true,
            'evidence_endpoint_source_deploy_current' => true,
            'capture_metadata_endpoint_source_deploy_current' => true,
            'capture_metadata_output_inside_var' => true,
            'capture_metadata_output_path_present' => true,
            'capture_metadata_output_path_matches_file' => true,
            'capture_consistency_metadata_present' => true,
            'capture_consistency_passed' => true,
            'capture_consistency_drift_fingerprints_match' => true,
            'capture_consistency_preflight_local_endpoint_locked' => true,
            'capture_consistency_preflight_production_endpoint_locked' => true,
            'capture_consistency_workorder_local_root_locked' => true,
            'capture_consistency_workorder_production_root_locked' => true,
            'capture_consistency_authorization_local_root_locked' => true,
            'capture_consistency_authorization_production_root_locked' => true,
            'capture_consistency_preflight_local_app_checkout_identity_ok' => true,
            'capture_consistency_preflight_local_app_env_wls_endpoint_locked' => true,
            'capture_consistency_workorder_local_app_checkout_identity_ok' => true,
            'capture_consistency_workorder_local_app_env_wls_endpoint_locked' => true,
            'capture_consistency_authorization_local_app_checkout_identity_ok' => true,
            'capture_consistency_authorization_local_app_env_wls_endpoint_locked' => true,
            'capture_consistency_local_app_checkout_identity_consistent' => true,
            'capture_consistency_local_app_env_wls_endpoint_consistent' => true,
            'capture_consistency_local_root_exact' => true,
            'capture_consistency_local_endpoint_exact' => true,
            'capture_consistency_local_checkout_exact' => true,
            'capture_consistency_local_env_wls_endpoint_exact' => true,
            'capture_consistency_production_root_exact' => true,
            'capture_consistency_production_endpoint_exact' => true,
            'capture_consistency_drift_fingerprint_present' => true,
            'no_secret_values' => true,
        ],
        'summary' => [
            'expect' => 'local',
            'endpoint' => WLS_PANEL_LIVE_FINAL_GATE_LOCAL_ENDPOINT,
            'endpoint_source' => 'deploy-current:tools/deploy-current-local-development.json',
            'expected_endpoint' => WLS_PANEL_LIVE_FINAL_GATE_LOCAL_ENDPOINT,
            'wrapper_payload' => true,
            'capture_metadata_present' => true,
            'capture_metadata_environment' => 'local',
            'capture_metadata_source_gate' => 'local-appstore-typed-tag-live-gate.php',
            'capture_metadata_endpoint_source' => 'deploy-current:tools/deploy-current-local-development.json',
            'capture_metadata_output_path' => 'C:/repo/var/wls-panel-plan/local-appstore-live-e2e.json',
            'capture_consistency_present' => true,
            'capture_consistency_drift_fingerprint' => '1234567890abcdef',
            'capture_consistency_local_root' => WLS_PANEL_LIVE_FINAL_GATE_LOCAL_ROOT,
            'capture_consistency_local_checkout' => WLS_PANEL_LIVE_FINAL_GATE_LOCAL_CHECKOUT,
            'capture_consistency_local_env_wls_endpoint' => WLS_PANEL_LIVE_FINAL_GATE_LOCAL_ENV_WLS_ENDPOINT,
            'capture_consistency_production_root' => WLS_PANEL_LIVE_FINAL_GATE_PRODUCTION_ROOT,
            'evidence_path' => 'C:/repo/var/wls-panel-plan/local-appstore-live-e2e.json',
        ],
    ];
    $validProduction = $validLocal;
    $validProduction['expect'] = 'production';
    $validProduction['checks']['production_evidence_source_is_deployed_current_json'] = true;
    $validProduction['checks']['production_capture_source_is_deployed_current_json'] = true;
    $validProduction['summary']['expect'] = 'production';
    $validProduction['summary']['endpoint'] = WLS_PANEL_LIVE_FINAL_GATE_PRODUCTION_ENDPOINT;
    $validProduction['summary']['endpoint_source'] = 'deploy-current:var/deploy/current.json';
    $validProduction['summary']['expected_endpoint'] = WLS_PANEL_LIVE_FINAL_GATE_PRODUCTION_ENDPOINT;
    $validProduction['summary']['capture_metadata_environment'] = 'production';
    $validProduction['summary']['capture_metadata_source_gate'] = 'production-appstore-typed-tag-live-gate.php';
    $validProduction['summary']['capture_metadata_endpoint_source'] = 'deploy-current:var/deploy/current.json';
    $validProduction['summary']['capture_metadata_output_path'] = 'C:/repo/var/wls-panel-plan/production-appstore-live-e2e.json';
    $validProduction['summary']['evidence_path'] = 'C:/repo/var/wls-panel-plan/production-appstore-live-e2e.json';

    $rawRunner = $validLocal;
    $rawRunner['summary']['wrapper_payload'] = false;
    $missingMetadata = $validLocal;
    $missingMetadata['checks']['capture_metadata_present'] = false;
    $missingMetadata['summary']['capture_metadata_present'] = false;
    $wrongEndpoint = $validLocal;
    $wrongEndpoint['summary']['endpoint'] = WLS_PANEL_LIVE_FINAL_GATE_PRODUCTION_ENDPOINT;
    $defaultSource = $validLocal;
    $defaultSource['checks']['evidence_endpoint_source_deploy_current'] = false;
    $defaultSource['checks']['capture_metadata_endpoint_source_deploy_current'] = false;
    $defaultSource['summary']['endpoint_source'] = 'default:production';
    $defaultSource['summary']['capture_metadata_endpoint_source'] = 'default:production';
    $missingOutputPathMatch = $validLocal;
    $missingOutputPathMatch['checks']['capture_metadata_output_path_matches_file'] = false;
    unset($missingOutputPathMatch['summary']['evidence_path']);
    $productionFixtureSource = $validProduction;
    $productionFixtureSource['checks']['production_evidence_source_is_deployed_current_json'] = false;
    $productionFixtureSource['checks']['production_capture_source_is_deployed_current_json'] = false;
    $productionFixtureSource['summary']['endpoint_source'] = 'deploy-current:tools/deploy-current-production-default.json';
    $productionFixtureSource['summary']['capture_metadata_endpoint_source'] = 'deploy-current:tools/deploy-current-production-default.json';
    $missingConsistency = $validLocal;
    $missingConsistency['checks']['capture_consistency_metadata_present'] = false;
    $missingConsistency['summary']['capture_consistency_present'] = false;
    $badConsistencyProductionRoot = $validProduction;
    $badConsistencyProductionRoot['checks']['capture_consistency_production_root_exact'] = false;
    $badConsistencyProductionRoot['summary']['capture_consistency_production_root'] = 'https://www.aiweline.com';
    $badConsistencyLocalCheckout = $validLocal;
    $badConsistencyLocalCheckout['checks']['capture_consistency_local_checkout_exact'] = false;
    $badConsistencyLocalCheckout['summary']['capture_consistency_local_checkout'] =
        'E:\\WelineFramework\\Framework-Official\\Official\\weline';
    $missingConsistencyEnvEndpointLock = $validLocal;
    $missingConsistencyEnvEndpointLock['checks']['capture_consistency_authorization_local_app_env_wls_endpoint_locked'] =
        false;
    $missingConsistencyFingerprint = $validLocal;
    $missingConsistencyFingerprint['checks']['capture_consistency_drift_fingerprint_present'] = false;
    unset($missingConsistencyFingerprint['summary']['capture_consistency_drift_fingerprint']);

    $cases = [
        [
            'name' => 'accepts_valid_local_capture_wrapper_evidence',
            'expected' => true,
            'actual' => wlsPanelLiveFinalGateAssessValidatorPayload($validLocal, 'local')['accepted'],
        ],
        [
            'name' => 'accepts_valid_production_capture_wrapper_deployed_current',
            'expected' => true,
            'actual' => wlsPanelLiveFinalGateAssessValidatorPayload($validProduction, 'production')['accepted'],
        ],
        [
            'name' => 'rejects_raw_runner_payload_for_final_gate',
            'expected' => false,
            'actual' => wlsPanelLiveFinalGateAssessValidatorPayload($rawRunner, 'local')['accepted'],
        ],
        [
            'name' => 'rejects_missing_capture_metadata',
            'expected' => false,
            'actual' => wlsPanelLiveFinalGateAssessValidatorPayload($missingMetadata, 'local')['accepted'],
        ],
        [
            'name' => 'rejects_wrong_endpoint_for_environment',
            'expected' => false,
            'actual' => wlsPanelLiveFinalGateAssessValidatorPayload($wrongEndpoint, 'local')['accepted'],
        ],
        [
            'name' => 'rejects_non_deploy_current_endpoint_source',
            'expected' => false,
            'actual' => wlsPanelLiveFinalGateAssessValidatorPayload($defaultSource, 'local')['accepted'],
        ],
        [
            'name' => 'rejects_missing_validator_output_path_match',
            'expected' => false,
            'actual' => wlsPanelLiveFinalGateAssessValidatorPayload($missingOutputPathMatch, 'local')['accepted'],
        ],
        [
            'name' => 'rejects_production_fixture_deploy_current_source',
            'expected' => false,
            'actual' => wlsPanelLiveFinalGateAssessValidatorPayload($productionFixtureSource, 'production')['accepted'],
        ],
        [
            'name' => 'rejects_missing_consistency_metadata',
            'expected' => false,
            'actual' => wlsPanelLiveFinalGateAssessValidatorPayload($missingConsistency, 'local')['accepted'],
        ],
        [
            'name' => 'rejects_consistency_www_aiweline_production_root',
            'expected' => false,
            'actual' => wlsPanelLiveFinalGateAssessValidatorPayload($badConsistencyProductionRoot, 'production')['accepted'],
        ],
        [
            'name' => 'rejects_consistency_wrong_local_checkout',
            'expected' => false,
            'actual' => wlsPanelLiveFinalGateAssessValidatorPayload($badConsistencyLocalCheckout, 'local')['accepted'],
        ],
        [
            'name' => 'rejects_consistency_missing_env_endpoint_lock',
            'expected' => false,
            'actual' => wlsPanelLiveFinalGateAssessValidatorPayload($missingConsistencyEnvEndpointLock, 'local')['accepted'],
        ],
        [
            'name' => 'rejects_missing_consistency_fingerprint',
            'expected' => false,
            'actual' => wlsPanelLiveFinalGateAssessValidatorPayload($missingConsistencyFingerprint, 'local')['accepted'],
        ],
        [
            'name' => 'accepts_evidence_path_inside_var',
            'expected' => true,
            'actual' => wlsPanelLiveFinalGateEvidencePathInsideVar(
                $workspaceRoot,
                'C:/repo/var/wls-panel-plan/local-appstore-live-e2e.json'
            ),
        ],
        [
            'name' => 'rejects_path_traversal_outside_var',
            'expected' => false,
            'actual' => wlsPanelLiveFinalGateEvidencePathInsideVar(
                $workspaceRoot,
                'C:/repo/var/wls-panel-plan/../leak.json'
            ),
        ],
        [
            'name' => 'rejects_evidence_path_outside_var',
            'expected' => false,
            'actual' => wlsPanelLiveFinalGateEvidencePathInsideVar(
                $workspaceRoot,
                'C:/repo/tmp/local-appstore-live-e2e.json'
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

$args = wlsPanelLiveFinalGateParseArgs($argv);
if ((string)($args['self-test'] ?? '0') === '1') {
    $selfTest = wlsPanelLiveFinalGateSelfTest();
    wlsPanelLiveFinalGateFinish([
        'passed' => $selfTest['passed'],
        'self_test' => true,
        'cases' => $selfTest['cases'],
        'side_effects' => 'in-memory self-test: no file read, no network, no token, no WLS start, no writes',
    ], $selfTest['passed'] ? 0 : WLS_PANEL_LIVE_FINAL_GATE_EXIT_ASSERTION_FAILED);
}

$workspaceRoot = realpath((string)($args['workspace-root'] ?? getcwd()));
if (!is_string($workspaceRoot) || $workspaceRoot === '') {
    wlsPanelLiveFinalGateFinish([
        'ok' => false,
        'ready' => false,
        'errors' => ['workspace_not_found'],
    ], WLS_PANEL_LIVE_FINAL_GATE_EXIT_NOT_READY);
}

$environment = strtolower(trim((string)($args['environment'] ?? 'local')));
$environments = wlsPanelLiveFinalGateEnvironments($environment);
$reportOnly = (string)($args['report-only'] ?? '0') === '1';
$tools = 'app/code/Weline/Server/doc/wls-panel-plan/tools/';
$paths = [
    'local' => trim((string)($args['local-evidence'] ?? 'var/wls-panel-plan/local-appstore-live-e2e.json')),
    'production' => trim((string)($args['production-evidence'] ?? 'var/wls-panel-plan/production-appstore-live-e2e.json')),
];

$results = [];
$errors = [];
if ($environments === []) {
    $errors[] = 'invalid_environment:' . $environment;
}

foreach ($environments as $env) {
    $evidencePath = $paths[$env] ?? '';
    $absolutePath = $evidencePath === ''
        ? ''
        : (preg_match('/^[A-Za-z]:[\\\\\\/]/', $evidencePath) === 1
            ? $evidencePath
            : wlsPanelLiveFinalGatePath($workspaceRoot, $evidencePath));
    $insideEvidenceVar = wlsPanelLiveFinalGateEvidencePathInsideVar($workspaceRoot, $absolutePath);
    $exists = $absolutePath !== '' && is_file($absolutePath);
    $validator = $exists
        ? wlsPanelLiveFinalGateRunTool($workspaceRoot, $tools . 'validate-appstore-live-e2e-evidence.php', [
            '--evidence=' . $absolutePath,
            '--expect=' . $env,
        ])
        : [
            'exit_code' => WLS_PANEL_LIVE_FINAL_GATE_EXIT_NOT_READY,
            'parsed' => false,
            'payload' => null,
            'output_bytes' => 0,
        ];

    $validatorPayload = is_array($validator['payload'] ?? null) ? $validator['payload'] : [];
    $assessment = $validatorPayload !== []
        ? wlsPanelLiveFinalGateAssessValidatorPayload($validatorPayload, $env)
        : [
            'accepted' => false,
            'checks' => [],
            'errors' => ['evidence_missing_or_unparsed'],
        ];

    if (!$exists) {
        $assessment['errors'][] = 'evidence_file_missing';
    }
    if (!$insideEvidenceVar) {
        $assessment['errors'][] = 'evidence_path_outside_var';
        $assessment['accepted'] = false;
    }

    if (!$assessment['accepted']) {
        $errors[] = $env . '_evidence_not_accepted';
    }

    $results[$env] = [
        'required' => true,
        'path' => $absolutePath,
        'exists' => $exists,
        'inside_var' => $insideEvidenceVar,
        'validator' => [
            'exit_code' => $validator['exit_code'],
            'parsed' => $validator['parsed'],
            'valid' => $validatorPayload['valid'] ?? null,
            'summary' => $validatorPayload['summary'] ?? null,
        ],
        'accepted' => $assessment['accepted'],
        'checks' => $assessment['checks'],
        'errors' => array_values(array_unique($assessment['errors'])),
    ];
}

$ready = $errors === [] && $results !== [];
wlsPanelLiveFinalGateFinish([
    'ok' => true,
    'ready' => $ready,
    'environment' => $environment,
    'required_environments' => $environments,
    'results' => $results,
    'errors' => array_values(array_unique($errors)),
    'notes' => [
        'report_only' => $reportOnly,
        'side_effects' => 'read-only final evidence gate: no network, no token, no WLS start, no writes',
    ],
], $ready || $reportOnly ? 0 : WLS_PANEL_LIVE_FINAL_GATE_EXIT_NOT_READY);
