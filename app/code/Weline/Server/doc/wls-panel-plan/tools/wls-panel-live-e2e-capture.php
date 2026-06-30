<?php
declare(strict_types=1);

/**
 * Captures and validates guarded WLS AppStore typed-tag live E2E output.
 *
 * Default mode is preflight-only and writes nothing. A live request can only be
 * attempted with --allow-live=1; when the guarded live gate actually executes,
 * the sanitized JSON payload is written under var/wls-panel-plan and validated
 * by validate-appstore-live-e2e-evidence.php.
 */

const WLS_PANEL_CAPTURE_EXIT_BLOCKED = 1;
const WLS_PANEL_CAPTURE_EXIT_ASSERTION_FAILED = 2;
const WLS_PANEL_CAPTURE_DEFAULT_TOKEN_ENV = 'WLS_MARKETPLACE_BEARER_TOKEN';

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function wlsPanelCaptureParseArgs(array $argv): array
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
function wlsPanelCaptureFinish(array $payload, int $exitCode): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($exitCode);
}

function wlsPanelCaptureJoin(string $base, string $relative): string
{
    return rtrim($base, "\\/") . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
}

function wlsPanelCaptureIsAbsolutePath(string $path): bool
{
    return preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, '/') || str_starts_with($path, '\\\\');
}

function wlsPanelCaptureAbsolutePath(string $workspaceRoot, string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    if (!wlsPanelCaptureIsAbsolutePath($path)) {
        $path = wlsPanelCaptureJoin($workspaceRoot, $path);
    }

    return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}

function wlsPanelCaptureNormalizePathSegments(string $path): string
{
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $prefix = '';

    if (str_starts_with($path, DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR)) {
        $prefix = DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR;
        $path = substr($path, 2);
    } elseif (preg_match('/^[A-Za-z]:' . preg_quote(DIRECTORY_SEPARATOR, '/') . '/', $path) === 1) {
        $prefix = substr($path, 0, 3);
        $path = substr($path, 3);
    } elseif (str_starts_with($path, DIRECTORY_SEPARATOR)) {
        $prefix = DIRECTORY_SEPARATOR;
        $path = ltrim($path, DIRECTORY_SEPARATOR);
    }

    $parts = preg_split('/' . preg_quote(DIRECTORY_SEPARATOR, '/') . '+/', $path) ?: [];
    $stack = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }

        if ($part === '..') {
            if ($stack !== [] && end($stack) !== '..') {
                array_pop($stack);
                continue;
            }

            if ($prefix === '') {
                $stack[] = $part;
            }
            continue;
        }

        $stack[] = $part;
    }

    return $prefix . implode(DIRECTORY_SEPARATOR, $stack);
}

function wlsPanelCaptureNormalizeForCompare(string $path): string
{
    return strtolower(rtrim(wlsPanelCaptureNormalizePathSegments($path), DIRECTORY_SEPARATOR));
}

/**
 * @return array{path:string,inside_var:bool,expected_root:string}
 */
function wlsPanelCaptureEvidencePath(string $workspaceRoot, string $environment, string $configuredPath): array
{
    $defaultName = $environment === 'production'
        ? 'production-appstore-live-e2e.json'
        : 'local-appstore-live-e2e.json';
    $path = $configuredPath !== ''
        ? wlsPanelCaptureAbsolutePath($workspaceRoot, $configuredPath)
        : wlsPanelCaptureJoin($workspaceRoot, 'var/wls-panel-plan/' . $defaultName);
    $expectedRoot = wlsPanelCaptureJoin($workspaceRoot, 'var/wls-panel-plan');
    $path = wlsPanelCaptureNormalizePathSegments($path);
    $expectedRoot = wlsPanelCaptureNormalizePathSegments($expectedRoot);
    $normalizedPath = wlsPanelCaptureNormalizeForCompare($path);
    $normalizedRoot = wlsPanelCaptureNormalizeForCompare($expectedRoot);

    return [
        'path' => $path,
        'inside_var' => $normalizedPath === $normalizedRoot || str_starts_with($normalizedPath, $normalizedRoot . DIRECTORY_SEPARATOR),
        'expected_root' => $expectedRoot,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function wlsPanelCaptureExtractJson(string $output): ?array
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
function wlsPanelCaptureRunTool(string $workspaceRoot, string $relativeTool, array $args = []): array
{
    $toolPath = wlsPanelCaptureJoin($workspaceRoot, $relativeTool);
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
    $payload = wlsPanelCaptureExtractJson($output);

    return [
        'tool' => $relativeTool,
        'exit_code' => $exitCode,
        'parsed' => $payload !== null,
        'payload' => $payload,
        'output_bytes' => strlen($output),
    ];
}

/**
 * @param array<string, string> $args
 * @return list<string>
 */
function wlsPanelCaptureGateArgs(array $args, string $environment, bool $allowLive, string $deployCurrent): array
{
    $gateArgs = [];
    if ($environment === 'production') {
        $gateArgs[] = '--deploy-current=' . $deployCurrent;
    }

    if ($allowLive) {
        $gateArgs[] = '--allow-live=1';
    } else {
        $gateArgs[] = '--report-only=1';
    }

    $tokenEnv = trim((string)($args['token-env'] ?? ''));
    if ($tokenEnv !== '') {
        $gateArgs[] = '--token-env=' . $tokenEnv;
    }

    $tokenFile = trim((string)($args['token-file'] ?? ''));
    if ($tokenFile !== '') {
        $gateArgs[] = '--token-file=' . $tokenFile;
    }

    return $gateArgs;
}

/**
 * @return list<string>
 */
function wlsPanelCaptureFinalGateArgs(string $environment, string $evidencePath): array
{
    $gateArgs = ['--environment=' . $environment];
    $gateArgs[] = $environment === 'production'
        ? '--production-evidence=' . $evidencePath
        : '--local-evidence=' . $evidencePath;

    return $gateArgs;
}

function wlsPanelCaptureBool(array $payload, string $key): bool
{
    return ($payload[$key] ?? false) === true;
}

function wlsPanelCaptureConsistencyPassed(array $result): bool
{
    $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];

    return (int)($result['exit_code'] ?? 1) === 0
        && ($result['parsed'] ?? false) === true
        && ($payload['passed'] ?? false) === true
        && ($payload['checks']['drift_fingerprints_match'] ?? false) === true
        && ($payload['checks']['preflight_local_endpoint_locked'] ?? false) === true
        && ($payload['checks']['preflight_production_endpoint_locked'] ?? false) === true
        && ($payload['checks']['workorder_local_root_locked'] ?? false) === true
        && ($payload['checks']['workorder_production_root_locked'] ?? false) === true
        && ($payload['checks']['authorization_local_root_locked'] ?? false) === true
        && ($payload['checks']['authorization_production_root_locked'] ?? false) === true
        && ($payload['checks']['preflight_local_app_checkout_identity_ok'] ?? false) === true
        && ($payload['checks']['preflight_local_app_env_wls_endpoint_locked'] ?? false) === true
        && ($payload['checks']['workorder_local_app_checkout_identity_ok'] ?? false) === true
        && ($payload['checks']['workorder_local_app_env_wls_endpoint_locked'] ?? false) === true
        && ($payload['checks']['authorization_local_app_checkout_identity_ok'] ?? false) === true
        && ($payload['checks']['authorization_local_app_env_wls_endpoint_locked'] ?? false) === true
        && ($payload['checks']['local_app_checkout_identity_consistent'] ?? false) === true
        && ($payload['checks']['local_app_env_wls_endpoint_consistent'] ?? false) === true;
}

/**
 * @return array<string, mixed>
 */
function wlsPanelCaptureConsistencyMetadata(array $consistencyPayload): array
{
    $checks = is_array($consistencyPayload['checks'] ?? null) ? $consistencyPayload['checks'] : [];
    $contract = is_array($consistencyPayload['contract'] ?? null) ? $consistencyPayload['contract'] : [];
    $sourceSummary = is_array($consistencyPayload['source_summary'] ?? null)
        ? $consistencyPayload['source_summary']
        : [];

    return [
        'passed' => ($consistencyPayload['passed'] ?? false) === true,
        'drift_fingerprints_match' => ($checks['drift_fingerprints_match'] ?? false) === true,
        'preflight_local_endpoint_locked' => ($checks['preflight_local_endpoint_locked'] ?? false) === true,
        'preflight_production_endpoint_locked' => ($checks['preflight_production_endpoint_locked'] ?? false) === true,
        'workorder_local_root_locked' => ($checks['workorder_local_root_locked'] ?? false) === true,
        'workorder_production_root_locked' => ($checks['workorder_production_root_locked'] ?? false) === true,
        'authorization_local_root_locked' => ($checks['authorization_local_root_locked'] ?? false) === true,
        'authorization_production_root_locked' => ($checks['authorization_production_root_locked'] ?? false) === true,
        'preflight_local_app_checkout_identity_ok' =>
            ($checks['preflight_local_app_checkout_identity_ok'] ?? false) === true,
        'preflight_local_app_env_wls_endpoint_locked' =>
            ($checks['preflight_local_app_env_wls_endpoint_locked'] ?? false) === true,
        'workorder_local_app_checkout_identity_ok' =>
            ($checks['workorder_local_app_checkout_identity_ok'] ?? false) === true,
        'workorder_local_app_env_wls_endpoint_locked' =>
            ($checks['workorder_local_app_env_wls_endpoint_locked'] ?? false) === true,
        'authorization_local_app_checkout_identity_ok' =>
            ($checks['authorization_local_app_checkout_identity_ok'] ?? false) === true,
        'authorization_local_app_env_wls_endpoint_locked' =>
            ($checks['authorization_local_app_env_wls_endpoint_locked'] ?? false) === true,
        'local_app_checkout_identity_consistent' =>
            ($checks['local_app_checkout_identity_consistent'] ?? false) === true,
        'local_app_env_wls_endpoint_consistent' =>
            ($checks['local_app_env_wls_endpoint_consistent'] ?? false) === true,
        'local_development_root' => (string)($contract['local_development_root'] ?? ''),
        'local_development_endpoint' => (string)($contract['local_development_endpoint'] ?? ''),
        'local_development_checkout' => (string)($contract['local_development_checkout'] ?? ''),
        'local_development_env_wls_endpoint' =>
            (string)($contract['local_development_env_wls_endpoint'] ?? ''),
        'production_deployed_root' => (string)($contract['production_deployed_root'] ?? ''),
        'production_deployed_endpoint' => (string)($contract['production_deployed_endpoint'] ?? ''),
        'drift_review_fingerprint' => (string)($sourceSummary['preflight_drift_review_fingerprint'] ?? ''),
    ];
}

/**
 * @param array<string, mixed> $gatePayload
 * @param array{path:string,inside_var:bool,expected_root:string} $evidencePath
 * @return array<string, mixed>
 */
function wlsPanelCaptureEvidencePayload(
    array $gatePayload,
    string $environment,
    string $gateTool,
    array $evidencePath,
    array $consistencyPayload = []
): array {
    $liveEvidence = is_array($gatePayload['live_evidence'] ?? null) ? $gatePayload['live_evidence'] : [];

    return $gatePayload + [
        'capture_metadata' => [
            'schema' => 1,
            'capture_tool' => 'wls-panel-live-e2e-capture.php',
            'captured_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'environment' => $environment,
            'source_gate' => $gateTool,
            'endpoint' => (string)($gatePayload['endpoint'] ?? ''),
            'endpoint_source' => (string)($liveEvidence['endpoint_source'] ?? $gatePayload['endpoint_source'] ?? ''),
            'evidence_output_inside_var' => $evidencePath['inside_var'],
            'evidence_output_path' => $evidencePath['path'],
            'workorder_authorization_consistency' => wlsPanelCaptureConsistencyMetadata($consistencyPayload),
        ],
    ];
}

/**
 * @return array{passed:bool,cases:list<array<string,mixed>>}
 */
function wlsPanelCaptureSelfTest(): array
{
    $workspaceRoot = 'E:' . DIRECTORY_SEPARATOR . 'WelineFramework' . DIRECTORY_SEPARATOR . 'DEV-workspace';
    $cases = [];
    $localDefault = wlsPanelCaptureEvidencePath($workspaceRoot, 'local', '');
    $productionDefault = wlsPanelCaptureEvidencePath($workspaceRoot, 'production', '');
    $outside = wlsPanelCaptureEvidencePath($workspaceRoot, 'local', '..' . DIRECTORY_SEPARATOR . 'bad.json');
    $customInside = wlsPanelCaptureEvidencePath($workspaceRoot, 'local', 'var/wls-panel-plan/custom-live.json');
    $pathTraversal = wlsPanelCaptureEvidencePath($workspaceRoot, 'local', 'var/wls-panel-plan/../leak.json');
    $localFinalGateArgs = wlsPanelCaptureFinalGateArgs('local', $localDefault['path']);
    $productionFinalGateArgs = wlsPanelCaptureFinalGateArgs('production', $productionDefault['path']);
    $fixtureConsistencyPayload = [
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
        ],
        'contract' => [
            'local_development_root' => 'https://app.weline.test:9523',
            'local_development_endpoint' => 'https://app.weline.test:9523/api/v1/platform/module/list',
            'local_development_checkout' => 'E:\\WelineFramework\\Framework-Official\\App\\weline',
            'local_development_env_wls_endpoint' => 'https://app.weline.test:9523',
            'production_deployed_root' => 'https://app.aiweline.com',
            'production_deployed_endpoint' => 'https://app.aiweline.com/api/v1/platform/module/list',
        ],
        'source_summary' => [
            'preflight_drift_review_fingerprint' => '1234567890abcdef',
        ],
    ];
    $payloadWithMetadata = wlsPanelCaptureEvidencePayload(
        [
            'endpoint' => 'https://app.weline.test:9523/api/v1/platform/module/list',
            'live_evidence' => [
                'endpoint_source' => 'deploy-current:tools/deploy-current-local-development.json',
            ],
        ],
        'local',
        'local-appstore-typed-tag-live-gate.php',
        $localDefault,
        $fixtureConsistencyPayload
    );
    $productionPayloadWithMetadata = wlsPanelCaptureEvidencePayload(
        [
            'endpoint' => 'https://app.aiweline.com/api/v1/platform/module/list',
            'live_evidence' => [
                'endpoint_source' => 'deploy-current:var/deploy/current.json',
            ],
        ],
        'production',
        'production-appstore-typed-tag-live-gate.php',
        $productionDefault,
        $fixtureConsistencyPayload
    );
    $passingConsistency = [
        'exit_code' => 0,
        'parsed' => true,
        'payload' => [
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
            ],
        ],
    ];
    $failingConsistency = $passingConsistency;
    $failingConsistency['payload']['checks']['drift_fingerprints_match'] = false;

    $cases[] = [
        'name' => 'local_default_inside_var',
        'case_ok' => $localDefault['inside_var'] === true
            && str_ends_with($localDefault['path'], 'local-appstore-live-e2e.json'),
        'path' => $localDefault['path'],
    ];
    $cases[] = [
        'name' => 'production_default_inside_var',
        'case_ok' => $productionDefault['inside_var'] === true
            && str_ends_with($productionDefault['path'], 'production-appstore-live-e2e.json'),
        'path' => $productionDefault['path'],
    ];
    $cases[] = [
        'name' => 'custom_inside_var_allowed',
        'case_ok' => $customInside['inside_var'] === true,
        'path' => $customInside['path'],
    ];
    $cases[] = [
        'name' => 'outside_var_rejected',
        'case_ok' => $outside['inside_var'] === false,
        'path' => $outside['path'],
    ];
    $cases[] = [
        'name' => 'path_traversal_outside_var_rejected',
        'case_ok' => $pathTraversal['inside_var'] === false
            && !str_contains($pathTraversal['path'], '..'),
        'path' => $pathTraversal['path'],
    ];
    $cases[] = [
        'name' => 'production_gate_uses_deploy_current',
        'case_ok' => in_array('--deploy-current=var/deploy/current.json', wlsPanelCaptureGateArgs([], 'production', true, 'var/deploy/current.json'), true),
    ];
    $cases[] = [
        'name' => 'local_gate_has_no_production_deploy_current',
        'case_ok' => !in_array('--deploy-current=var/deploy/current.json', wlsPanelCaptureGateArgs([], 'local', true, 'var/deploy/current.json'), true),
    ];
    $cases[] = [
        'name' => 'captured_payload_has_metadata',
        'case_ok' => is_array($payloadWithMetadata['capture_metadata'] ?? null)
            && ($payloadWithMetadata['capture_metadata']['schema'] ?? null) === 1
            && ($payloadWithMetadata['capture_metadata']['capture_tool'] ?? '') === 'wls-panel-live-e2e-capture.php'
            && ($payloadWithMetadata['capture_metadata']['environment'] ?? '') === 'local'
            && ($payloadWithMetadata['capture_metadata']['source_gate'] ?? '') === 'local-appstore-typed-tag-live-gate.php'
            && ($payloadWithMetadata['capture_metadata']['endpoint_source'] ?? '') === 'deploy-current:tools/deploy-current-local-development.json'
            && ($payloadWithMetadata['capture_metadata']['evidence_output_inside_var'] ?? false) === true
            && ($payloadWithMetadata['capture_metadata']['evidence_output_path'] ?? '') === $localDefault['path'],
    ];
    $localConsistencyMetadata = is_array($payloadWithMetadata['capture_metadata']['workorder_authorization_consistency'] ?? null)
        ? $payloadWithMetadata['capture_metadata']['workorder_authorization_consistency']
        : [];
    $productionConsistencyMetadata =
        is_array($productionPayloadWithMetadata['capture_metadata']['workorder_authorization_consistency'] ?? null)
            ? $productionPayloadWithMetadata['capture_metadata']['workorder_authorization_consistency']
            : [];
    $cases[] = [
        'name' => 'captured_payload_preserves_consistency_contract',
        'case_ok' => ($localConsistencyMetadata['passed'] ?? false) === true
            && ($localConsistencyMetadata['drift_fingerprints_match'] ?? false) === true
            && ($localConsistencyMetadata['local_app_checkout_identity_consistent'] ?? false) === true
            && ($localConsistencyMetadata['local_app_env_wls_endpoint_consistent'] ?? false) === true
            && ($localConsistencyMetadata['local_development_root'] ?? '') === 'https://app.weline.test:9523'
            && ($localConsistencyMetadata['local_development_endpoint'] ?? '') === 'https://app.weline.test:9523/api/v1/platform/module/list'
            && ($localConsistencyMetadata['local_development_checkout'] ?? '') === 'E:\\WelineFramework\\Framework-Official\\App\\weline'
            && ($localConsistencyMetadata['local_development_env_wls_endpoint'] ?? '') === 'https://app.weline.test:9523'
            && ($localConsistencyMetadata['production_deployed_root'] ?? '') === 'https://app.aiweline.com'
            && ($localConsistencyMetadata['production_deployed_endpoint'] ?? '') === 'https://app.aiweline.com/api/v1/platform/module/list'
            && ($localConsistencyMetadata['drift_review_fingerprint'] ?? '') === '1234567890abcdef',
    ];
    $cases[] = [
        'name' => 'production_captured_payload_uses_deploy_current_metadata',
        'case_ok' => is_array($productionPayloadWithMetadata['capture_metadata'] ?? null)
            && ($productionPayloadWithMetadata['capture_metadata']['environment'] ?? '') === 'production'
            && ($productionPayloadWithMetadata['capture_metadata']['source_gate'] ?? '') === 'production-appstore-typed-tag-live-gate.php'
            && ($productionPayloadWithMetadata['capture_metadata']['endpoint'] ?? '') === 'https://app.aiweline.com/api/v1/platform/module/list'
            && ($productionPayloadWithMetadata['capture_metadata']['endpoint_source'] ?? '') === 'deploy-current:var/deploy/current.json'
            && ($productionPayloadWithMetadata['capture_metadata']['evidence_output_inside_var'] ?? false) === true
            && ($productionPayloadWithMetadata['capture_metadata']['evidence_output_path'] ?? '') === $productionDefault['path'],
    ];
    $cases[] = [
        'name' => 'production_captured_payload_preserves_consistency_contract',
        'case_ok' => ($productionConsistencyMetadata['passed'] ?? false) === true
            && ($productionConsistencyMetadata['drift_fingerprints_match'] ?? false) === true
            && ($productionConsistencyMetadata['local_app_checkout_identity_consistent'] ?? false) === true
            && ($productionConsistencyMetadata['local_app_env_wls_endpoint_consistent'] ?? false) === true
            && ($productionConsistencyMetadata['production_deployed_root'] ?? '') === 'https://app.aiweline.com'
            && ($productionConsistencyMetadata['production_deployed_endpoint'] ?? '') === 'https://app.aiweline.com/api/v1/platform/module/list'
            && ($productionConsistencyMetadata['local_development_checkout'] ?? '') === 'E:\\WelineFramework\\Framework-Official\\App\\weline'
            && ($productionConsistencyMetadata['local_development_env_wls_endpoint'] ?? '') === 'https://app.weline.test:9523'
            && ($productionConsistencyMetadata['local_development_root'] ?? '') === 'https://app.weline.test:9523',
    ];
    $cases[] = [
        'name' => 'local_final_gate_uses_local_evidence_arg',
        'case_ok' => in_array('--environment=local', $localFinalGateArgs, true)
            && in_array('--local-evidence=' . $localDefault['path'], $localFinalGateArgs, true)
            && !in_array('--production-evidence=' . $localDefault['path'], $localFinalGateArgs, true),
    ];
    $cases[] = [
        'name' => 'production_final_gate_uses_production_evidence_arg',
        'case_ok' => in_array('--environment=production', $productionFinalGateArgs, true)
            && in_array('--production-evidence=' . $productionDefault['path'], $productionFinalGateArgs, true)
            && !in_array('--local-evidence=' . $productionDefault['path'], $productionFinalGateArgs, true),
    ];
    $cases[] = [
        'name' => 'live_capture_requires_consistency_gate_pass',
        'case_ok' => wlsPanelCaptureConsistencyPassed($passingConsistency),
    ];
    $cases[] = [
        'name' => 'live_capture_rejects_consistency_gate_drift_mismatch',
        'case_ok' => !wlsPanelCaptureConsistencyPassed($failingConsistency),
    ];

    return [
        'passed' => count(array_filter($cases, static fn(array $case): bool => !($case['case_ok'] ?? false))) === 0,
        'cases' => $cases,
    ];
}

$args = wlsPanelCaptureParseArgs($argv);
if ((string)($args['self-test'] ?? '0') === '1') {
    $selfTest = wlsPanelCaptureSelfTest();
    wlsPanelCaptureFinish([
        'passed' => $selfTest['passed'],
        'self_test' => true,
        'cases' => $selfTest['cases'],
        'side_effects' => 'in-memory self-test: no file read, no network, no token, no WLS start, no writes',
    ], $selfTest['passed'] ? 0 : WLS_PANEL_CAPTURE_EXIT_ASSERTION_FAILED);
}

$workspaceRoot = trim((string)($args['workspace-root'] ?? dirname(__DIR__, 7)));
$environment = strtolower(trim((string)($args['environment'] ?? 'local')));
$allowLive = (string)($args['allow-live'] ?? '0') === '1';
$deployCurrent = trim((string)($args['deploy-current'] ?? 'var/deploy/current.json'));
$evidencePath = wlsPanelCaptureEvidencePath($workspaceRoot, $environment, trim((string)($args['evidence-output'] ?? '')));
$manualEndpointProvided = trim((string)($args['endpoint'] ?? '')) !== '';
$insecureRequested = in_array(strtolower((string)($args['insecure'] ?? '0')), ['1', 'true', 'yes'], true);
$tokenEnv = trim((string)($args['token-env'] ?? WLS_PANEL_CAPTURE_DEFAULT_TOKEN_ENV));
$tools = 'app/code/Weline/Server/doc/wls-panel-plan/tools/';

$checks = [
    'environment_valid' => in_array($environment, ['local', 'production'], true),
    'manual_endpoint_rejected' => !$manualEndpointProvided,
    'insecure_rejected' => !$insecureRequested,
    'evidence_output_inside_var' => $evidencePath['inside_var'],
];
$preErrors = [];
foreach ($checks as $label => $passed) {
    if (!$passed) {
        $preErrors[] = 'check_failed:' . $label;
    }
}

if ($preErrors !== []) {
    wlsPanelCaptureFinish([
        'ok' => false,
        'status' => 'blocked',
        'environment' => $environment,
        'allow_live' => $allowLive,
        'checks' => $checks,
        'errors' => $preErrors,
        'evidence_output' => $evidencePath,
        'side_effects' => 'blocked before any child tool call: no network, no token value, no WLS start, no writes',
    ], WLS_PANEL_CAPTURE_EXIT_BLOCKED);
}

$gateTool = $environment === 'production'
    ? 'production-appstore-typed-tag-live-gate.php'
    : 'local-appstore-typed-tag-live-gate.php';
$consistency = null;
$consistencyPayload = [];
$consistencyPassed = true;
if ($allowLive) {
    $consistency = wlsPanelCaptureRunTool($workspaceRoot, $tools . 'wls-panel-workorder-authorization-consistency.php');
    $consistencyPayload = is_array($consistency['payload'] ?? null) ? $consistency['payload'] : [];
    $consistencyPassed = wlsPanelCaptureConsistencyPassed($consistency);
    $checks['workorder_authorization_consistency_passed_before_live'] = $consistencyPassed;

    if (!$consistencyPassed) {
        wlsPanelCaptureFinish([
            'ok' => false,
            'status' => 'blocked_before_live',
            'environment' => $environment,
            'allow_live' => true,
            'live_executed' => false,
            'live_passed' => false,
            'evidence_written' => false,
            'evidence_output' => [
                'path' => $evidencePath['path'],
                'inside_var' => $evidencePath['inside_var'],
                'expected_root' => $evidencePath['expected_root'],
            ],
            'checks' => $checks,
            'errors' => ['workorder_authorization_consistency_failed'],
            'tool_results' => [
                'workorder_authorization_consistency' => [
                    'exit_code' => $consistency['exit_code'] ?? null,
                    'parsed' => $consistency['parsed'] ?? false,
                    'passed' => $consistencyPayload['passed'] ?? null,
                    'checks' => $consistencyPayload['checks'] ?? null,
                    'errors' => $consistencyPayload['errors'] ?? null,
                ],
                'live_gate' => null,
                'evidence_validator' => null,
                'final_evidence_gate' => null,
            ],
            'notes' => [
                'token_source' => 'env:' . $tokenEnv,
                'token_redacted' => true,
                'side_effects' => 'blocked before live gate: consistency check is read-only; no AppStore call, no token value, no WLS start, no writes',
            ],
        ], WLS_PANEL_CAPTURE_EXIT_BLOCKED);
    }
} else {
    $checks['workorder_authorization_consistency_passed_before_live'] = true;
}
$gate = wlsPanelCaptureRunTool(
    $workspaceRoot,
    $tools . $gateTool,
    wlsPanelCaptureGateArgs($args + ['token-env' => $tokenEnv], $environment, $allowLive, $deployCurrent)
);
$gatePayload = is_array($gate['payload'] ?? null) ? $gate['payload'] : [];
$liveExecuted = wlsPanelCaptureBool($gatePayload, 'live_executed');
$livePassed = wlsPanelCaptureBool($gatePayload, 'live_passed');
$wroteEvidence = false;
$writeError = '';
$validator = null;
$validatorPayload = [];
$finalGate = null;
$finalGatePayload = [];

if ($allowLive && $liveExecuted) {
    $dir = dirname($evidencePath['path']);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        $writeError = 'evidence_dir_create_failed';
    } else {
        $json = json_encode(
            wlsPanelCaptureEvidencePayload($gatePayload, $environment, $gateTool, $evidencePath, $consistencyPayload),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false || file_put_contents($evidencePath['path'], $json . PHP_EOL) === false) {
            $writeError = 'evidence_write_failed';
        } else {
            $wroteEvidence = true;
        }
    }

    if ($wroteEvidence) {
        $validator = wlsPanelCaptureRunTool($workspaceRoot, $tools . 'validate-appstore-live-e2e-evidence.php', [
            '--evidence=' . $evidencePath['path'],
            '--expect=' . $environment,
        ]);
        $validatorPayload = is_array($validator['payload'] ?? null) ? $validator['payload'] : [];
        $finalGate = wlsPanelCaptureRunTool(
            $workspaceRoot,
            $tools . 'wls-panel-live-evidence-final-gate.php',
            wlsPanelCaptureFinalGateArgs($environment, $evidencePath['path'])
        );
        $finalGatePayload = is_array($finalGate['payload'] ?? null) ? $finalGate['payload'] : [];
    }
}

$validatorValid = $validator !== null && wlsPanelCaptureBool($validatorPayload, 'valid');
$finalGateReady = $finalGate !== null && wlsPanelCaptureBool($finalGatePayload, 'ready');
$status = 'preflight_only';
if ($allowLive && !$liveExecuted) {
    $status = 'blocked_before_live';
} elseif ($allowLive && $liveExecuted && !$wroteEvidence) {
    $status = 'capture_failed';
} elseif ($allowLive && $liveExecuted && $validatorValid && $finalGateReady) {
    $status = 'captured_valid';
} elseif ($allowLive && $liveExecuted) {
    $status = 'captured_invalid';
}

$success = match ($status) {
    'preflight_only' => ($gate['parsed'] ?? false) === true,
    'captured_valid' => true,
    default => false,
};
$errors = [];
if ($writeError !== '') {
    $errors[] = $writeError;
}
if ($allowLive && $liveExecuted && $wroteEvidence && !$validatorValid) {
    $errors[] = 'evidence_validator_failed';
}
if ($allowLive && $liveExecuted && $wroteEvidence && !$finalGateReady) {
    $errors[] = 'final_evidence_gate_failed';
}

wlsPanelCaptureFinish([
    'ok' => true,
    'status' => $status,
    'environment' => $environment,
    'allow_live' => $allowLive,
    'live_executed' => $liveExecuted,
    'live_passed' => $livePassed,
    'evidence_written' => $wroteEvidence,
    'evidence_output' => [
        'path' => $evidencePath['path'],
        'inside_var' => $evidencePath['inside_var'],
        'expected_root' => $evidencePath['expected_root'],
    ],
    'checks' => $checks + [
        'gate_parsed' => ($gate['parsed'] ?? false) === true,
        'evidence_written_only_after_live' => !$wroteEvidence || $liveExecuted,
        'validator_passed_when_written' => !$wroteEvidence || $validatorValid,
        'final_gate_passed_when_written' => !$wroteEvidence || $finalGateReady,
    ],
    'errors' => array_values(array_unique($errors)),
    'tool_results' => [
        'workorder_authorization_consistency' => $consistency === null ? null : [
            'exit_code' => $consistency['exit_code'],
            'parsed' => $consistency['parsed'],
            'passed' => $consistencyPayload['passed'] ?? null,
            'drift_fingerprints_match' => $consistencyPayload['checks']['drift_fingerprints_match'] ?? null,
            'preflight_local_endpoint_locked' => $consistencyPayload['checks']['preflight_local_endpoint_locked'] ?? null,
            'preflight_production_endpoint_locked' => $consistencyPayload['checks']['preflight_production_endpoint_locked'] ?? null,
            'workorder_local_root_locked' => $consistencyPayload['checks']['workorder_local_root_locked'] ?? null,
            'workorder_production_root_locked' => $consistencyPayload['checks']['workorder_production_root_locked'] ?? null,
            'authorization_local_root_locked' => $consistencyPayload['checks']['authorization_local_root_locked'] ?? null,
            'authorization_production_root_locked' => $consistencyPayload['checks']['authorization_production_root_locked'] ?? null,
            'preflight_local_app_checkout_identity_ok' =>
                $consistencyPayload['checks']['preflight_local_app_checkout_identity_ok'] ?? null,
            'preflight_local_app_env_wls_endpoint_locked' =>
                $consistencyPayload['checks']['preflight_local_app_env_wls_endpoint_locked'] ?? null,
            'workorder_local_app_checkout_identity_ok' =>
                $consistencyPayload['checks']['workorder_local_app_checkout_identity_ok'] ?? null,
            'workorder_local_app_env_wls_endpoint_locked' =>
                $consistencyPayload['checks']['workorder_local_app_env_wls_endpoint_locked'] ?? null,
            'authorization_local_app_checkout_identity_ok' =>
                $consistencyPayload['checks']['authorization_local_app_checkout_identity_ok'] ?? null,
            'authorization_local_app_env_wls_endpoint_locked' =>
                $consistencyPayload['checks']['authorization_local_app_env_wls_endpoint_locked'] ?? null,
            'local_app_checkout_identity_consistent' =>
                $consistencyPayload['checks']['local_app_checkout_identity_consistent'] ?? null,
            'local_app_env_wls_endpoint_consistent' =>
                $consistencyPayload['checks']['local_app_env_wls_endpoint_consistent'] ?? null,
            'drift_review_fingerprint' =>
                $consistencyPayload['source_summary']['preflight_drift_review_fingerprint'] ?? null,
            'local_development_root' => $consistencyPayload['contract']['local_development_root'] ?? null,
            'local_development_checkout' => $consistencyPayload['contract']['local_development_checkout'] ?? null,
            'local_development_env_wls_endpoint' =>
                $consistencyPayload['contract']['local_development_env_wls_endpoint'] ?? null,
            'production_deployed_root' => $consistencyPayload['contract']['production_deployed_root'] ?? null,
        ],
        'live_gate' => [
            'tool' => $gateTool,
            'exit_code' => $gate['exit_code'],
            'parsed' => $gate['parsed'],
            'status' => $gatePayload['status'] ?? null,
            'ready_for_live' => $gatePayload['ready_for_live'] ?? null,
            'live_executed' => $gatePayload['live_executed'] ?? null,
            'live_passed' => $gatePayload['live_passed'] ?? null,
            'endpoint' => $gatePayload['endpoint'] ?? null,
        ],
        'evidence_validator' => $validator === null ? null : [
            'exit_code' => $validator['exit_code'],
            'parsed' => $validator['parsed'],
            'valid' => $validatorPayload['valid'] ?? null,
            'summary' => $validatorPayload['summary'] ?? null,
        ],
        'final_evidence_gate' => $finalGate === null ? null : [
            'exit_code' => $finalGate['exit_code'],
            'parsed' => $finalGate['parsed'],
            'ready' => $finalGatePayload['ready'] ?? null,
            'environment' => $finalGatePayload['environment'] ?? null,
            'errors' => $finalGatePayload['errors'] ?? null,
        ],
    ],
    'notes' => [
        'token_source' => 'env:' . $tokenEnv,
        'token_redacted' => true,
        'side_effects' => $allowLive
            ? 'guarded capture: live gate may call AppStore only after its own readiness checks; evidence is written only under var/wls-panel-plan after live execution'
            : 'preflight only: no network, no token value, no WLS start, no writes',
    ],
], $success ? 0 : WLS_PANEL_CAPTURE_EXIT_BLOCKED);
