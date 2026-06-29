<?php
declare(strict_types=1);

/**
 * Validates captured WLS AppStore typed-tag live E2E evidence.
 *
 * This checker is intentionally read-only. It accepts either the raw
 * marketplace-typed-tag-e2e.php JSON payload or the sanitized live_evidence
 * payload emitted by the guarded local/production live-gate wrappers.
 */

const WLS_PANEL_LIVE_EVIDENCE_EXIT_ASSERTION_FAILED = 1;
const WLS_PANEL_LIVE_EVIDENCE_LOCAL_ROOT = 'https://app.weline.test:9523';
const WLS_PANEL_LIVE_EVIDENCE_PRODUCTION_ROOT = 'https://app.aiweline.com';
const WLS_PANEL_LIVE_EVIDENCE_LOCAL_ENDPOINT = 'https://app.weline.test:9523/api/v1/platform/module/list';
const WLS_PANEL_LIVE_EVIDENCE_PRODUCTION_ENDPOINT = 'https://app.aiweline.com/api/v1/platform/module/list';
const WLS_PANEL_LIVE_EVIDENCE_LOCAL_CHECKOUT = 'E:\\WelineFramework\\Framework-Official\\App\\weline';
const WLS_PANEL_LIVE_EVIDENCE_LOCAL_ENV_WLS_ENDPOINT = WLS_PANEL_LIVE_EVIDENCE_LOCAL_ROOT;
const WLS_PANEL_LIVE_EVIDENCE_POSITIVE_TAG = 'module:wls';
const WLS_PANEL_LIVE_EVIDENCE_NEGATIVE_TAG = 'module:wls-extra';

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function wlsPanelLiveEvidenceParseArgs(array $argv): array
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
function wlsPanelLiveEvidenceFinish(array $payload, int $exitCode): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($exitCode);
}

/**
 * @return array<string, mixed>|null
 */
function wlsPanelLiveEvidenceDecodeJson(string $text): ?array
{
    $trimmed = trim(ltrim($text, "\xEF\xBB\xBF"));
    $decoded = json_decode($trimmed, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $start = strpos($trimmed, '{');
    $end = strrpos($trimmed, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }

    $decoded = json_decode(substr($trimmed, $start, $end - $start + 1), true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * @return array<string, mixed>
 */
function wlsPanelLiveEvidenceReadPayload(string $path, array &$errors): array
{
    if ($path === '') {
        $errors[] = 'evidence_path_empty';
        return [];
    }

    if (!is_file($path) || !is_readable($path)) {
        $errors[] = 'evidence_unreadable';
        return [];
    }

    $payload = wlsPanelLiveEvidenceDecodeJson((string)file_get_contents($path));
    if (!is_array($payload)) {
        $errors[] = 'evidence_invalid_json';
        return [];
    }

    return $payload;
}

/**
 * @param array<string, mixed> $payload
 * @return array{wrapper:array<string,mixed>,evidence:array<string,mixed>}
 */
function wlsPanelLiveEvidenceNormalize(array $payload): array
{
    $liveEvidence = is_array($payload['live_evidence'] ?? null) ? $payload['live_evidence'] : [];
    if ($liveEvidence !== []) {
        return [
            'wrapper' => $payload,
            'evidence' => $liveEvidence,
        ];
    }

    return [
        'wrapper' => [],
        'evidence' => $payload,
    ];
}

/**
 * @return array<string, mixed>
 */
function wlsPanelLiveEvidenceCaptureMetadata(array $wrapper): array
{
    $metadata = $wrapper['capture_metadata'] ?? [];
    return is_array($metadata) ? $metadata : [];
}

/**
 * @return array<string, mixed>
 */
function wlsPanelLiveEvidenceConsistencyMetadata(array $captureMetadata): array
{
    $consistency = $captureMetadata['workorder_authorization_consistency'] ?? [];
    return is_array($consistency) ? $consistency : [];
}

function wlsPanelLiveEvidenceExpectedGate(string $expect): string
{
    return $expect === 'production'
        ? 'production-appstore-typed-tag-live-gate.php'
        : 'local-appstore-typed-tag-live-gate.php';
}

function wlsPanelLiveEvidenceValidCapturedAt(string $value): bool
{
    return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) === 1;
}

function wlsPanelLiveEvidenceIsAbsolutePath(string $path): bool
{
    return preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, '/') || str_starts_with($path, '\\\\');
}

function wlsPanelLiveEvidenceAbsolutePath(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    if (wlsPanelLiveEvidenceIsAbsolutePath($path)) {
        return $path;
    }

    return rtrim(getcwd() ?: '.', "\\/") . DIRECTORY_SEPARATOR . $path;
}

function wlsPanelLiveEvidenceNormalizePath(string $path): string
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

function wlsPanelLiveEvidencePathsMatch(string $left, string $right): bool
{
    if (trim($left) === '' || trim($right) === '') {
        return false;
    }

    $normalizedLeft = wlsPanelLiveEvidenceNormalizePath($left);
    $normalizedRight = wlsPanelLiveEvidenceNormalizePath($right);
    $caseInsensitive = preg_match('/^[A-Z]:\//', $normalizedLeft) === 1
        || preg_match('/^[A-Z]:\//', $normalizedRight) === 1;

    return $caseInsensitive
        ? strtolower($normalizedLeft) === strtolower($normalizedRight)
        : $normalizedLeft === $normalizedRight;
}

function wlsPanelLiveEvidenceProductionDeployCurrentSource(string $endpointSource): bool
{
    $prefix = 'deploy-current:';
    if (!str_starts_with($endpointSource, $prefix)) {
        return false;
    }

    $path = wlsPanelLiveEvidenceNormalizePath(substr($endpointSource, strlen($prefix)));
    $path = strtolower(str_replace('\\', '/', $path));

    return $path === 'var/deploy/current.json'
        || str_ends_with($path, '/var/deploy/current.json');
}

function wlsPanelLiveEvidenceAcceptedEndpointSource(string $endpointSource, string $expect): bool
{
    if ($expect === 'production') {
        return wlsPanelLiveEvidenceProductionDeployCurrentSource($endpointSource);
    }

    return $endpointSource === 'arg:endpoint'
        || wlsPanelLiveEvidenceProductionDeployCurrentSource($endpointSource);
}

/**
 * @return list<array<string, mixed>>
 */
function wlsPanelLiveEvidenceCases(array $evidence): array
{
    $cases = $evidence['cases'] ?? [];
    return is_array($cases) && array_is_list($cases) ? $cases : [];
}

/**
 * @param list<array<string, mixed>> $cases
 */
function wlsPanelLiveEvidenceFindCase(array $cases, string $name): ?array
{
    foreach ($cases as $case) {
        if ((string)($case['name'] ?? '') === $name) {
            return $case;
        }
    }

    return null;
}

/**
 * @param list<array<string, mixed>> $cases
 */
function wlsPanelLiveEvidenceFindNegativeCase(array $cases): ?array
{
    foreach ($cases as $case) {
        $name = (string)($case['name'] ?? '');
        $payload = is_array($case['payload'] ?? null) ? $case['payload'] : [];
        if (str_starts_with($name, 'negative_exact_match_') || ($payload['tag'] ?? '') === WLS_PANEL_LIVE_EVIDENCE_NEGATIVE_TAG) {
            return $case;
        }
    }

    return null;
}

/**
 * @return list<string>
 */
function wlsPanelLiveEvidenceRecursiveStrings(mixed $value): array
{
    if (is_scalar($value)) {
        return [(string)$value];
    }

    if (!is_array($value)) {
        return [];
    }

    $strings = [];
    foreach ($value as $key => $entry) {
        if (is_scalar($key)) {
            $strings[] = (string)$key;
        }
        $strings = array_merge($strings, wlsPanelLiveEvidenceRecursiveStrings($entry));
    }

    return $strings;
}

/**
 * @return list<string>
 */
function wlsPanelLiveEvidenceSecretFindings(array $payload): array
{
    $findings = [];
    foreach (wlsPanelLiveEvidenceRecursiveStrings($payload) as $text) {
        $normalized = trim($text);
        if ($normalized === '') {
            continue;
        }

        if (preg_match('/authorization\s*:\s*bearer\s+\S+/i', $normalized) === 1) {
            $findings[] = 'authorization_bearer_header';
        }
        if (preg_match('/\bbearer\s+[A-Za-z0-9._~+\/=-]{12,}/i', $normalized) === 1) {
            $findings[] = 'bearer_token_value';
        }
        if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $normalized) === 1) {
            $findings[] = 'private_key_marker';
        }
        if (preg_match('/\b(cookie|set-cookie)\s*:/i', $normalized) === 1) {
            $findings[] = 'cookie_header';
        }
        if (preg_match('/WLS_MARKETPLACE_BEARER_TOKEN\s*=/', $normalized) === 1) {
            $findings[] = 'bearer_token_env_assignment';
        }
    }

    return array_values(array_unique($findings));
}

/**
 * @return array{valid:bool,checks:array<string,bool>,errors:list<string>,summary:array<string,mixed>}
 */
function wlsPanelLiveEvidenceEvaluate(array $payload, string $expect, array $preErrors = [], string $evidencePath = ''): array
{
    $errors = $preErrors;
    if (!in_array($expect, ['local', 'production'], true)) {
        $errors[] = 'invalid_expect:' . $expect;
    }

    $normalized = wlsPanelLiveEvidenceNormalize($payload);
    $wrapper = $normalized['wrapper'];
    $evidence = $normalized['evidence'];
    $cases = wlsPanelLiveEvidenceCases($evidence);
    $singleCase = wlsPanelLiveEvidenceFindCase($cases, 'single_tag_module_wls');
    $structuredCase = wlsPanelLiveEvidenceFindCase($cases, 'structured_tags_all_match');
    $negativeCase = wlsPanelLiveEvidenceFindNegativeCase($cases);
    $expectedEndpoint = $expect === 'production'
        ? WLS_PANEL_LIVE_EVIDENCE_PRODUCTION_ENDPOINT
        : WLS_PANEL_LIVE_EVIDENCE_LOCAL_ENDPOINT;
    $endpoint = (string)($evidence['endpoint'] ?? $wrapper['endpoint'] ?? '');
    $captureMetadata = wlsPanelLiveEvidenceCaptureMetadata($wrapper);
    $endpointSource = (string)($evidence['endpoint_source'] ?? $wrapper['endpoint_source'] ?? '');
    $captureEndpointSource = (string)($captureMetadata['endpoint_source'] ?? '');
    $consistencyMetadata = wlsPanelLiveEvidenceConsistencyMetadata($captureMetadata);
    $consistencyFingerprint = (string)($consistencyMetadata['drift_review_fingerprint'] ?? '');
    $productionEvidenceSource = $expect !== 'production'
        || wlsPanelLiveEvidenceProductionDeployCurrentSource($endpointSource);
    $productionCaptureSource = $expect !== 'production'
        || wlsPanelLiveEvidenceProductionDeployCurrentSource($captureEndpointSource);
    $acceptedEndpointSource = wlsPanelLiveEvidenceAcceptedEndpointSource($endpointSource, $expect);
    $acceptedCaptureEndpointSource = $captureEndpointSource === $endpointSource
        && wlsPanelLiveEvidenceAcceptedEndpointSource($captureEndpointSource, $expect);
    $captureOutputPath = (string)($captureMetadata['evidence_output_path'] ?? '');
    $absoluteEvidencePath = wlsPanelLiveEvidenceAbsolutePath($evidencePath);
    $secretFindings = wlsPanelLiveEvidenceSecretFindings($payload);

    $singlePayload = is_array($singleCase['payload'] ?? null) ? $singleCase['payload'] : [];
    $structuredPayload = is_array($structuredCase['payload'] ?? null) ? $structuredCase['payload'] : [];
    $negativePayload = is_array($negativeCase['payload'] ?? null) ? $negativeCase['payload'] : [];
    $structuredTags = $structuredPayload['tags'] ?? [];

    $checks = [
        'evidence_present' => $evidence !== [],
        'endpoint_exact' => $endpoint === $expectedEndpoint,
        'not_www_marketplace_host' => !str_contains($endpoint, 'www.weline.test') && !str_contains($endpoint, 'www.aiweline.com'),
        'payload_passed' => ($evidence['passed'] ?? false) === true,
        'payload_not_blocked' => ($evidence['blocked'] ?? false) === false,
        'token_redacted' => ($evidence['token_redacted'] ?? false) === true,
        'negative_conclusive_required' => ($evidence['require_negative_conclusive'] ?? false) === true,
        'single_tag_case_passed' => is_array($singleCase) && ($singleCase['passed'] ?? false) === true,
        'single_tag_case_uses_module_wls' => ($singlePayload['tag'] ?? '') === WLS_PANEL_LIVE_EVIDENCE_POSITIVE_TAG,
        'single_tag_case_has_items' => (int)($singleCase['item_count'] ?? 0) >= 1,
        'structured_case_passed' => is_array($structuredCase) && ($structuredCase['passed'] ?? false) === true,
        'structured_case_includes_module_wls' => is_array($structuredTags)
            && in_array(WLS_PANEL_LIVE_EVIDENCE_POSITIVE_TAG, $structuredTags, true),
        'negative_case_passed' => is_array($negativeCase) && ($negativeCase['passed'] ?? false) === true,
        'negative_case_uses_canary_tag' => ($negativePayload['tag'] ?? '') === WLS_PANEL_LIVE_EVIDENCE_NEGATIVE_TAG,
        'negative_case_conclusive' => ($negativeCase['negative_conclusive'] ?? false) === true
            && (int)($negativeCase['item_count'] ?? 0) >= 1,
        'production_evidence_source_is_deployed_current_json' => $productionEvidenceSource,
        'no_secret_values' => $secretFindings === [],
    ];

    if ($wrapper !== []) {
        $checks += [
            'wrapper_status_passed' => ($wrapper['status'] ?? '') === 'passed',
            'wrapper_live_allowed' => ($wrapper['live_allowed'] ?? false) === true,
            'wrapper_live_executed' => ($wrapper['live_executed'] ?? false) === true,
            'wrapper_live_passed' => ($wrapper['live_passed'] ?? false) === true,
            'wrapper_guard_passed' => ($wrapper['guard_passed'] ?? false) === true,
            'capture_metadata_present' => $captureMetadata !== [],
            'capture_metadata_schema' => ($captureMetadata['schema'] ?? null) === 1,
            'capture_metadata_tool' => ($captureMetadata['capture_tool'] ?? '') === 'wls-panel-live-e2e-capture.php',
            'capture_metadata_timestamp_utc' => wlsPanelLiveEvidenceValidCapturedAt((string)($captureMetadata['captured_at_utc'] ?? '')),
            'capture_metadata_environment' => ($captureMetadata['environment'] ?? '') === $expect,
            'capture_metadata_source_gate' => ($captureMetadata['source_gate'] ?? '') === wlsPanelLiveEvidenceExpectedGate($expect),
            'capture_metadata_endpoint_exact' => ($captureMetadata['endpoint'] ?? '') === $endpoint
                && $endpoint === $expectedEndpoint,
            'evidence_endpoint_source_deploy_current' => $acceptedEndpointSource,
            'capture_metadata_endpoint_source_deploy_current' => $acceptedCaptureEndpointSource,
            'production_capture_source_is_deployed_current_json' => $productionCaptureSource,
            'capture_metadata_output_inside_var' => ($captureMetadata['evidence_output_inside_var'] ?? false) === true,
            'capture_metadata_output_path_present' => $captureOutputPath !== '',
            'capture_metadata_output_path_matches_file' => $absoluteEvidencePath !== ''
                && wlsPanelLiveEvidencePathsMatch($captureOutputPath, $absoluteEvidencePath),
            'capture_consistency_metadata_present' => $consistencyMetadata !== [],
            'capture_consistency_passed' => ($consistencyMetadata['passed'] ?? false) === true,
            'capture_consistency_drift_fingerprints_match' =>
                ($consistencyMetadata['drift_fingerprints_match'] ?? false) === true,
            'capture_consistency_preflight_local_endpoint_locked' =>
                ($consistencyMetadata['preflight_local_endpoint_locked'] ?? false) === true,
            'capture_consistency_preflight_production_endpoint_locked' =>
                ($consistencyMetadata['preflight_production_endpoint_locked'] ?? false) === true,
            'capture_consistency_workorder_local_root_locked' =>
                ($consistencyMetadata['workorder_local_root_locked'] ?? false) === true,
            'capture_consistency_workorder_production_root_locked' =>
                ($consistencyMetadata['workorder_production_root_locked'] ?? false) === true,
            'capture_consistency_authorization_local_root_locked' =>
                ($consistencyMetadata['authorization_local_root_locked'] ?? false) === true,
            'capture_consistency_authorization_production_root_locked' =>
                ($consistencyMetadata['authorization_production_root_locked'] ?? false) === true,
            'capture_consistency_preflight_local_app_checkout_identity_ok' =>
                ($consistencyMetadata['preflight_local_app_checkout_identity_ok'] ?? false) === true,
            'capture_consistency_preflight_local_app_env_wls_endpoint_locked' =>
                ($consistencyMetadata['preflight_local_app_env_wls_endpoint_locked'] ?? false) === true,
            'capture_consistency_workorder_local_app_checkout_identity_ok' =>
                ($consistencyMetadata['workorder_local_app_checkout_identity_ok'] ?? false) === true,
            'capture_consistency_workorder_local_app_env_wls_endpoint_locked' =>
                ($consistencyMetadata['workorder_local_app_env_wls_endpoint_locked'] ?? false) === true,
            'capture_consistency_authorization_local_app_checkout_identity_ok' =>
                ($consistencyMetadata['authorization_local_app_checkout_identity_ok'] ?? false) === true,
            'capture_consistency_authorization_local_app_env_wls_endpoint_locked' =>
                ($consistencyMetadata['authorization_local_app_env_wls_endpoint_locked'] ?? false) === true,
            'capture_consistency_local_app_checkout_identity_consistent' =>
                ($consistencyMetadata['local_app_checkout_identity_consistent'] ?? false) === true,
            'capture_consistency_local_app_env_wls_endpoint_consistent' =>
                ($consistencyMetadata['local_app_env_wls_endpoint_consistent'] ?? false) === true,
            'capture_consistency_local_root_exact' =>
                ($consistencyMetadata['local_development_root'] ?? '') === WLS_PANEL_LIVE_EVIDENCE_LOCAL_ROOT,
            'capture_consistency_local_endpoint_exact' =>
                ($consistencyMetadata['local_development_endpoint'] ?? '') === WLS_PANEL_LIVE_EVIDENCE_LOCAL_ENDPOINT,
            'capture_consistency_local_checkout_exact' =>
                ($consistencyMetadata['local_development_checkout'] ?? '') === WLS_PANEL_LIVE_EVIDENCE_LOCAL_CHECKOUT,
            'capture_consistency_local_env_wls_endpoint_exact' =>
                ($consistencyMetadata['local_development_env_wls_endpoint'] ?? '')
                    === WLS_PANEL_LIVE_EVIDENCE_LOCAL_ENV_WLS_ENDPOINT,
            'capture_consistency_production_root_exact' =>
                ($consistencyMetadata['production_deployed_root'] ?? '') === WLS_PANEL_LIVE_EVIDENCE_PRODUCTION_ROOT,
            'capture_consistency_production_endpoint_exact' =>
                ($consistencyMetadata['production_deployed_endpoint'] ?? '') === WLS_PANEL_LIVE_EVIDENCE_PRODUCTION_ENDPOINT,
            'capture_consistency_drift_fingerprint_present' => $consistencyFingerprint !== '',
        ];
    }

    foreach ($checks as $label => $passed) {
        if (!$passed) {
            $errors[] = 'check_failed:' . $label;
        }
    }
    foreach ($secretFindings as $finding) {
        $errors[] = 'secret_value_detected:' . $finding;
    }

    return [
        'valid' => $errors === [],
        'checks' => $checks,
        'errors' => array_values(array_unique($errors)),
        'summary' => [
            'expect' => $expect,
            'endpoint' => $endpoint,
            'endpoint_source' => $endpointSource,
            'expected_endpoint' => $expectedEndpoint,
            'case_count' => count($cases),
            'single_tag_item_count' => is_array($singleCase) ? (int)($singleCase['item_count'] ?? 0) : null,
            'negative_item_count' => is_array($negativeCase) ? (int)($negativeCase['item_count'] ?? 0) : null,
            'wrapper_payload' => $wrapper !== [],
            'capture_metadata_present' => $captureMetadata !== [],
            'capture_metadata_environment' => $captureMetadata['environment'] ?? null,
            'capture_metadata_source_gate' => $captureMetadata['source_gate'] ?? null,
            'capture_metadata_endpoint_source' => $captureMetadata['endpoint_source'] ?? null,
            'capture_metadata_captured_at_utc' => $captureMetadata['captured_at_utc'] ?? null,
            'capture_metadata_output_path' => $captureOutputPath !== '' ? $captureOutputPath : null,
            'capture_consistency_present' => $consistencyMetadata !== [],
            'capture_consistency_drift_fingerprint' =>
                $consistencyFingerprint !== '' ? $consistencyFingerprint : null,
            'capture_consistency_local_root' => $consistencyMetadata['local_development_root'] ?? null,
            'capture_consistency_local_checkout' => $consistencyMetadata['local_development_checkout'] ?? null,
            'capture_consistency_local_env_wls_endpoint' =>
                $consistencyMetadata['local_development_env_wls_endpoint'] ?? null,
            'capture_consistency_production_root' => $consistencyMetadata['production_deployed_root'] ?? null,
            'evidence_path' => $absoluteEvidencePath !== '' ? $absoluteEvidencePath : null,
            'secret_findings' => $secretFindings,
        ],
    ];
}

/**
 * @return array{passed:bool,cases:list<array<string,mixed>>}
 */
function wlsPanelLiveEvidenceSelfTest(): array
{
    $productionEvidencePath = 'C:/repo/var/wls-panel-plan/production-appstore-live-e2e.json';
    $consistencyMetadata = [
        'passed' => true,
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
        'local_development_root' => WLS_PANEL_LIVE_EVIDENCE_LOCAL_ROOT,
        'local_development_endpoint' => WLS_PANEL_LIVE_EVIDENCE_LOCAL_ENDPOINT,
        'local_development_checkout' => WLS_PANEL_LIVE_EVIDENCE_LOCAL_CHECKOUT,
        'local_development_env_wls_endpoint' => WLS_PANEL_LIVE_EVIDENCE_LOCAL_ENV_WLS_ENDPOINT,
        'production_deployed_root' => WLS_PANEL_LIVE_EVIDENCE_PRODUCTION_ROOT,
        'production_deployed_endpoint' => WLS_PANEL_LIVE_EVIDENCE_PRODUCTION_ENDPOINT,
        'drift_review_fingerprint' => '1234567890abcdef',
    ];
    $runnerEvidence = [
        'passed' => true,
        'blocked' => false,
        'endpoint' => WLS_PANEL_LIVE_EVIDENCE_LOCAL_ENDPOINT,
        'token_redacted' => true,
        'require_negative_conclusive' => true,
        'cases' => [
            [
                'name' => 'single_tag_module_wls',
                'passed' => true,
                'payload' => ['tag' => 'module:wls', 'tag_match' => 'all'],
                'item_count' => 2,
            ],
            [
                'name' => 'structured_tags_all_match',
                'passed' => true,
                'payload' => ['tags' => ['module:wls', 'custom:wls-panel-plugin'], 'tag_match' => 'all'],
                'item_count' => 2,
            ],
            [
                'name' => 'negative_exact_match_module_wls-extra',
                'passed' => true,
                'payload' => ['tag' => 'module:wls-extra', 'tag_match' => 'all'],
                'item_count' => 1,
                'negative_conclusive' => true,
            ],
        ],
    ];
    $wrapperEvidence = [
        'status' => 'passed',
        'live_allowed' => true,
        'live_executed' => true,
        'live_passed' => true,
        'guard_passed' => true,
        'endpoint' => WLS_PANEL_LIVE_EVIDENCE_PRODUCTION_ENDPOINT,
        'capture_metadata' => [
            'schema' => 1,
            'capture_tool' => 'wls-panel-live-e2e-capture.php',
            'captured_at_utc' => '2026-06-23T00:00:00Z',
            'environment' => 'production',
            'source_gate' => 'production-appstore-typed-tag-live-gate.php',
            'endpoint' => WLS_PANEL_LIVE_EVIDENCE_PRODUCTION_ENDPOINT,
            'endpoint_source' => 'deploy-current:var/deploy/current.json',
            'evidence_output_inside_var' => true,
            'evidence_output_path' => $productionEvidencePath,
            'workorder_authorization_consistency' => $consistencyMetadata,
        ],
        'live_evidence' => array_replace($runnerEvidence, [
            'endpoint' => WLS_PANEL_LIVE_EVIDENCE_PRODUCTION_ENDPOINT,
            'endpoint_source' => 'deploy-current:var/deploy/current.json',
        ]),
    ];
    $wrapperEvidenceWithoutMetadata = $wrapperEvidence;
    unset($wrapperEvidenceWithoutMetadata['capture_metadata']);
    $wrapperEvidenceWithDefaultSource = $wrapperEvidence;
    $wrapperEvidenceWithDefaultSource['capture_metadata']['endpoint_source'] = 'default:production';
    $wrapperEvidenceWithDefaultSource['live_evidence']['endpoint_source'] = 'default:production';
    $wrapperEvidenceWithFixtureDeployCurrent = $wrapperEvidence;
    $wrapperEvidenceWithFixtureDeployCurrent['capture_metadata']['endpoint_source'] =
        'deploy-current:tools/deploy-current-production-default.json';
    $wrapperEvidenceWithFixtureDeployCurrent['live_evidence']['endpoint_source'] =
        'deploy-current:tools/deploy-current-production-default.json';
    $wrapperEvidenceWithMismatchedOutputPath = $wrapperEvidence;
    $wrapperEvidenceWithMismatchedOutputPath['capture_metadata']['evidence_output_path'] = 'C:/repo/var/wls-panel-plan/other-live-e2e.json';
    $wrapperEvidenceWithoutConsistency = $wrapperEvidence;
    unset($wrapperEvidenceWithoutConsistency['capture_metadata']['workorder_authorization_consistency']);
    $wrapperEvidenceWithBadConsistencyRoot = $wrapperEvidence;
    $wrapperEvidenceWithBadConsistencyRoot['capture_metadata']['workorder_authorization_consistency']['production_deployed_root'] =
        'https://www.aiweline.com';
    $wrapperEvidenceWithBadLocalCheckout = $wrapperEvidence;
    $wrapperEvidenceWithBadLocalCheckout['capture_metadata']['workorder_authorization_consistency']['local_development_checkout'] =
        'E:\\WelineFramework\\Framework-Official\\Official\\weline';
    $wrapperEvidenceWithMissingEnvEndpointLock = $wrapperEvidence;
    $wrapperEvidenceWithMissingEnvEndpointLock['capture_metadata']['workorder_authorization_consistency']
        ['authorization_local_app_env_wls_endpoint_locked'] = false;
    $wrapperEvidenceWithMissingConsistencyFingerprint = $wrapperEvidence;
    $wrapperEvidenceWithMissingConsistencyFingerprint['capture_metadata']['workorder_authorization_consistency']['drift_review_fingerprint'] =
        '';

    $cases = [
        [
            'name' => 'accepts_local_runner_evidence',
            'expect' => 'local',
            'payload' => $runnerEvidence,
            'want_valid' => true,
        ],
        [
            'name' => 'accepts_production_wrapper_evidence',
            'expect' => 'production',
            'payload' => $wrapperEvidence,
            'want_valid' => true,
            'evidence_path' => $productionEvidencePath,
        ],
        [
            'name' => 'rejects_production_using_local_endpoint',
            'expect' => 'production',
            'payload' => $runnerEvidence,
            'want_valid' => false,
        ],
        [
            'name' => 'rejects_missing_negative_conclusive_canary',
            'expect' => 'local',
            'payload' => array_replace_recursive($runnerEvidence, [
                'cases' => [
                    2 => [
                        'negative_conclusive' => false,
                        'item_count' => 0,
                    ],
                ],
            ]),
            'want_valid' => false,
        ],
        [
            'name' => 'rejects_secret_value',
            'expect' => 'local',
            'payload' => $runnerEvidence + [
                'debug_header' => 'Authorization: Bearer abcdefghijklmnopqrstuvwxyz',
            ],
            'want_valid' => false,
        ],
        [
            'name' => 'rejects_wrapper_missing_capture_metadata',
            'expect' => 'production',
            'payload' => $wrapperEvidenceWithoutMetadata,
            'want_valid' => false,
            'evidence_path' => $productionEvidencePath,
        ],
        [
            'name' => 'rejects_wrapper_non_deploy_current_endpoint_source',
            'expect' => 'production',
            'payload' => $wrapperEvidenceWithDefaultSource,
            'want_valid' => false,
            'evidence_path' => $productionEvidencePath,
        ],
        [
            'name' => 'rejects_production_wrapper_fixture_deploy_current_source',
            'expect' => 'production',
            'payload' => $wrapperEvidenceWithFixtureDeployCurrent,
            'want_valid' => false,
            'evidence_path' => $productionEvidencePath,
        ],
        [
            'name' => 'rejects_wrapper_output_path_mismatch',
            'expect' => 'production',
            'payload' => $wrapperEvidenceWithMismatchedOutputPath,
            'want_valid' => false,
            'evidence_path' => $productionEvidencePath,
        ],
        [
            'name' => 'rejects_wrapper_missing_consistency_metadata',
            'expect' => 'production',
            'payload' => $wrapperEvidenceWithoutConsistency,
            'want_valid' => false,
            'evidence_path' => $productionEvidencePath,
        ],
        [
            'name' => 'rejects_wrapper_consistency_www_aiweline_root',
            'expect' => 'production',
            'payload' => $wrapperEvidenceWithBadConsistencyRoot,
            'want_valid' => false,
            'evidence_path' => $productionEvidencePath,
        ],
        [
            'name' => 'rejects_wrapper_consistency_wrong_local_checkout',
            'expect' => 'production',
            'payload' => $wrapperEvidenceWithBadLocalCheckout,
            'want_valid' => false,
            'evidence_path' => $productionEvidencePath,
        ],
        [
            'name' => 'rejects_wrapper_consistency_missing_env_endpoint_lock',
            'expect' => 'production',
            'payload' => $wrapperEvidenceWithMissingEnvEndpointLock,
            'want_valid' => false,
            'evidence_path' => $productionEvidencePath,
        ],
        [
            'name' => 'rejects_wrapper_missing_consistency_fingerprint',
            'expect' => 'production',
            'payload' => $wrapperEvidenceWithMissingConsistencyFingerprint,
            'want_valid' => false,
            'evidence_path' => $productionEvidencePath,
        ],
    ];

    $results = [];
    $passed = true;
    foreach ($cases as $case) {
        $result = wlsPanelLiveEvidenceEvaluate(
            (array)$case['payload'],
            (string)$case['expect'],
            [],
            (string)($case['evidence_path'] ?? '')
        );
        $caseOk = $result['valid'] === $case['want_valid'];
        $passed = $passed && $caseOk;
        $results[] = [
            'name' => $case['name'],
            'want_valid' => $case['want_valid'],
            'actual_valid' => $result['valid'],
            'case_ok' => $caseOk,
            'errors' => $result['errors'],
            'summary' => $result['summary'],
        ];
    }

    return [
        'passed' => $passed,
        'cases' => $results,
    ];
}

$args = wlsPanelLiveEvidenceParseArgs($argv);
if ((string)($args['self-test'] ?? '0') === '1') {
    $selfTest = wlsPanelLiveEvidenceSelfTest();
    wlsPanelLiveEvidenceFinish([
        'passed' => $selfTest['passed'],
        'self_test' => true,
        'cases' => $selfTest['cases'],
        'side_effects' => 'in-memory self-test: no file read, no network, no token, no WLS start, no writes',
    ], $selfTest['passed'] ? 0 : WLS_PANEL_LIVE_EVIDENCE_EXIT_ASSERTION_FAILED);
}

$errors = [];
$expect = strtolower(trim((string)($args['expect'] ?? '')));
$evidencePath = trim((string)($args['evidence'] ?? ''));
$payload = wlsPanelLiveEvidenceReadPayload($evidencePath, $errors);
$result = wlsPanelLiveEvidenceEvaluate($payload, $expect, $errors, $evidencePath);
wlsPanelLiveEvidenceFinish([
    'valid' => $result['valid'],
    'expect' => $expect,
    'summary' => $result['summary'],
    'checks' => $result['checks'],
    'errors' => $result['errors'],
    'side_effects' => 'read-only evidence check: no network, no token, no WLS start, no writes',
], $result['valid'] ? 0 : WLS_PANEL_LIVE_EVIDENCE_EXIT_ASSERTION_FAILED);
