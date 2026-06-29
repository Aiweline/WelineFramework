<?php
declare(strict_types=1);

/**
 * Validates the deploy current.json App Store endpoint policy used by WLS Panel
 * marketplace checks.
 *
 * This is a read-only release gate: it reads a deployment artifact or fixture
 * and prints JSON. It does not contact App Store, read tokens, start WLS, or
 * write files.
 */

const WLS_PANEL_DEPLOY_POLICY_EXIT_ASSERTION_FAILED = 1;
const WLS_PANEL_DEPLOY_POLICY_LOCAL_URL = 'https://app.weline.test:9523';
const WLS_PANEL_DEPLOY_POLICY_PRODUCTION_URL = 'https://app.aiweline.com';

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function wlsPanelDeployPolicyParseArgs(array $argv): array
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
function wlsPanelDeployPolicyFinish(array $payload, int $exitCode): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($exitCode);
}

function wlsPanelDeployPolicyNormalizePlatformUrl(string $url): string
{
    $url = rtrim(trim($url), '/');
    if (preg_match('#/api/v1/platform/module/list$#', $url) === 1) {
        return preg_replace('#/api/v1/platform/module/list$#', '', $url) ?: '';
    }

    return $url;
}

function wlsPanelDeployPolicyEndpoint(string $platformUrl): string
{
    $platformUrl = wlsPanelDeployPolicyNormalizePlatformUrl($platformUrl);
    return $platformUrl === '' ? '' : $platformUrl . '/api/v1/platform/module/list';
}

/**
 * @return array<string, mixed>
 */
function wlsPanelDeployPolicyReadPayload(string $path, array &$errors): array
{
    if ($path === '') {
        $errors[] = 'deploy_current_path_empty';
        return [];
    }

    if (!is_file($path) || !is_readable($path)) {
        $errors[] = 'deploy_current_unreadable';
        return [];
    }

    $json = ltrim((string)file_get_contents($path), "\xEF\xBB\xBF");
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        $errors[] = 'deploy_current_invalid_json';
        return [];
    }

    return $payload;
}

/**
 * @param array<string, mixed> $payload
 * @return array{environment:string,deploy_mode:string,raw_platform_url:string,platform_url:string,platform_url_source:string,deploy_mode_source:string,resolved_platform_url:string,resolved_endpoint:string}
 */
function wlsPanelDeployPolicyResolve(array $payload): array
{
    $environment = strtolower(trim((string)($payload['appstore_environment'] ?? '')));
    $deployMode = strtolower(trim((string)($payload['deploy_mode'] ?? '')));
    $rawPlatformUrl = rtrim(trim((string)($payload['appstore_platform_url'] ?? '')), '/');
    $platformUrl = wlsPanelDeployPolicyNormalizePlatformUrl($rawPlatformUrl);

    $resolved = in_array($environment, ['local', 'production'], true) ? $platformUrl : '';

    return [
        'environment' => $environment,
        'deploy_mode' => $deployMode,
        'raw_platform_url' => $rawPlatformUrl,
        'platform_url' => $platformUrl,
        'platform_url_source' => trim((string)($payload['appstore_platform_url_source'] ?? '')),
        'deploy_mode_source' => trim((string)($payload['deploy_mode_source'] ?? '')),
        'resolved_platform_url' => $resolved,
        'resolved_endpoint' => wlsPanelDeployPolicyEndpoint($resolved),
    ];
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, bool>
 */
function wlsPanelDeployPolicyChecks(array $payload, array $resolved, string $expect): array
{
    $requiredKeys = [
        'appstore_environment',
        'appstore_platform_url',
        'appstore_platform_url_source',
        'deploy_mode_source',
    ];
    $hasRequiredKeys = true;
    foreach ($requiredKeys as $key) {
        $hasRequiredKeys = $hasRequiredKeys && array_key_exists($key, $payload);
    }

    $environment = (string)$resolved['environment'];
    $url = (string)$resolved['resolved_platform_url'];
    $deployMode = (string)$resolved['deploy_mode'];
    $platformUrlSource = (string)$resolved['platform_url_source'];

    return [
        'has_required_keys' => $hasRequiredKeys,
        'environment_known' => in_array($environment, ['local', 'production'], true),
        'matches_expected_environment' => $expect === '' || $environment === $expect,
        'local_uses_local_appstore' => $environment !== 'local' || $url === WLS_PANEL_DEPLOY_POLICY_LOCAL_URL,
        'local_records_exact_app_weline_platform_url' => $environment !== 'local'
            || (string)$resolved['raw_platform_url'] === WLS_PANEL_DEPLOY_POLICY_LOCAL_URL,
        'production_uses_app_aiweline' => $environment !== 'production' || $url === WLS_PANEL_DEPLOY_POLICY_PRODUCTION_URL,
        'production_records_app_aiweline_url' => $environment !== 'production'
            || (string)$resolved['platform_url'] === WLS_PANEL_DEPLOY_POLICY_PRODUCTION_URL,
        'production_records_exact_app_aiweline_platform_url' => $environment !== 'production'
            || (string)$resolved['raw_platform_url'] === WLS_PANEL_DEPLOY_POLICY_PRODUCTION_URL,
        'production_records_production_default_source' => $environment !== 'production'
            || $platformUrlSource === 'production_default',
        'production_not_local_appstore' => $environment !== 'production' || !str_contains($url, 'app.weline.test'),
        'marketplace_not_www_host' => !str_contains($url, 'www.weline.test') && !str_contains($url, 'www.aiweline.com'),
        'local_records_known_appstore_source' => $environment !== 'local'
            || in_array($platformUrlSource, [
                'local_default',
                'env:WELINE_APPSTORE_PLATFORM_URL',
                'config:appstore.platform_url',
            ], true),
        'local_mode_is_dev_or_local' => $environment !== 'local' || in_array($deployMode, ['dev', 'local'], true),
        'production_mode_is_not_dev_or_local' => $environment !== 'production' || !in_array($deployMode, ['dev', 'local'], true),
    ];
}

/**
 * @param array<string, mixed> $payload
 * @param list<string> $preErrors
 * @return array{passed:bool,resolved:array<string,string>,checks:array<string,bool>,errors:list<string>,warnings:list<string>}
 */
function wlsPanelDeployPolicyEvaluate(array $payload, string $expect, array $preErrors = []): array
{
    $errors = $preErrors;
    $warnings = [];
    if ($expect !== '' && !in_array($expect, ['local', 'production'], true)) {
        $errors[] = 'invalid_expect:' . $expect;
    }

    $resolved = $payload !== [] ? wlsPanelDeployPolicyResolve($payload) : [
        'environment' => '',
        'deploy_mode' => '',
        'raw_platform_url' => '',
        'platform_url' => '',
        'platform_url_source' => '',
        'deploy_mode_source' => '',
        'resolved_platform_url' => '',
        'resolved_endpoint' => '',
    ];

    $checks = wlsPanelDeployPolicyChecks($payload, $resolved, $expect);
    foreach ($checks as $label => $passed) {
        if (!$passed) {
            $errors[] = 'check_failed:' . $label;
        }
    }

    if (($resolved['raw_platform_url'] ?? '') !== '' && ($resolved['raw_platform_url'] ?? '') !== ($resolved['platform_url'] ?? '')) {
        $warnings[] = 'platform_url_contains_api_path';
    }

    return [
        'passed' => $errors === [],
        'resolved' => $resolved,
        'checks' => $checks,
        'errors' => $errors,
        'warnings' => $warnings,
    ];
}

/**
 * @return array{passed:bool,cases:list<array<string,mixed>>}
 */
function wlsPanelDeployPolicySelfTest(): array
{
    $base = [
        'release_id' => 'self-test',
        'deploy_version' => 'self-test',
        'deploy_mode_source' => 'self-test',
    ];
    $cases = [
        [
            'name' => 'local_dev_uses_local_appstore',
            'expect' => 'local',
            'want_passed' => true,
            'payload' => $base + [
                'deploy_mode' => 'dev',
                'appstore_environment' => 'local',
                'appstore_platform_url' => WLS_PANEL_DEPLOY_POLICY_LOCAL_URL,
                'appstore_platform_url_source' => 'local_default',
            ],
        ],
        [
            'name' => 'production_current_records_app_aiweline',
            'expect' => 'production',
            'want_passed' => true,
            'payload' => $base + [
                'deploy_mode' => 'prod',
                'appstore_environment' => 'production',
                'appstore_platform_url' => WLS_PANEL_DEPLOY_POLICY_PRODUCTION_URL,
                'appstore_platform_url_source' => 'production_default',
            ],
        ],
        [
            'name' => 'production_rejects_empty_appstore_url',
            'expect' => 'production',
            'want_passed' => false,
            'payload' => $base + [
                'deploy_mode' => 'prod',
                'appstore_environment' => 'production',
                'appstore_platform_url' => '',
                'appstore_platform_url_source' => 'production_default',
            ],
        ],
        [
            'name' => 'production_rejects_local_appstore',
            'expect' => 'production',
            'want_passed' => false,
            'payload' => $base + [
                'deploy_mode' => 'prod',
                'appstore_environment' => 'production',
                'appstore_platform_url' => WLS_PANEL_DEPLOY_POLICY_LOCAL_URL,
                'appstore_platform_url_source' => 'production_default',
            ],
        ],
        [
            'name' => 'production_rejects_www_aiweline',
            'expect' => 'production',
            'want_passed' => false,
            'payload' => $base + [
                'deploy_mode' => 'prod',
                'appstore_environment' => 'production',
                'appstore_platform_url' => 'https://www.aiweline.com',
                'appstore_platform_url_source' => 'production_default',
            ],
        ],
        [
            'name' => 'production_rejects_api_endpoint_in_platform_url',
            'expect' => 'production',
            'want_passed' => false,
            'payload' => $base + [
                'deploy_mode' => 'prod',
                'appstore_environment' => 'production',
                'appstore_platform_url' => WLS_PANEL_DEPLOY_POLICY_PRODUCTION_URL . '/api/v1/platform/module/list',
                'appstore_platform_url_source' => 'production_default',
            ],
        ],
        [
            'name' => 'production_rejects_config_source',
            'expect' => 'production',
            'want_passed' => false,
            'payload' => $base + [
                'deploy_mode' => 'prod',
                'appstore_environment' => 'production',
                'appstore_platform_url' => WLS_PANEL_DEPLOY_POLICY_PRODUCTION_URL,
                'appstore_platform_url_source' => 'config:appstore.platform_url',
            ],
        ],
        [
            'name' => 'local_rejects_api_endpoint_in_platform_url',
            'expect' => 'local',
            'want_passed' => false,
            'payload' => $base + [
                'deploy_mode' => 'dev',
                'appstore_environment' => 'local',
                'appstore_platform_url' => WLS_PANEL_DEPLOY_POLICY_LOCAL_URL . '/api/v1/platform/module/list',
                'appstore_platform_url_source' => 'local_default',
            ],
        ],
        [
            'name' => 'local_rejects_production_mode',
            'expect' => 'local',
            'want_passed' => false,
            'payload' => $base + [
                'deploy_mode' => 'prod',
                'appstore_environment' => 'local',
                'appstore_platform_url' => WLS_PANEL_DEPLOY_POLICY_LOCAL_URL,
                'appstore_platform_url_source' => 'local_default',
            ],
        ],
        [
            'name' => 'local_rejects_unknown_appstore_source',
            'expect' => 'local',
            'want_passed' => false,
            'payload' => $base + [
                'deploy_mode' => 'dev',
                'appstore_environment' => 'local',
                'appstore_platform_url' => WLS_PANEL_DEPLOY_POLICY_LOCAL_URL,
                'appstore_platform_url_source' => 'production_default',
            ],
        ],
    ];

    $results = [];
    $allPassed = true;
    foreach ($cases as $case) {
        $result = wlsPanelDeployPolicyEvaluate((array)$case['payload'], (string)$case['expect']);
        $casePassed = $result['passed'] === $case['want_passed'];
        $allPassed = $allPassed && $casePassed;
        $results[] = [
            'name' => $case['name'],
            'want_passed' => $case['want_passed'],
            'actual_passed' => $result['passed'],
            'case_ok' => $casePassed,
            'resolved_endpoint' => $result['resolved']['resolved_endpoint'] ?? '',
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
        ];
    }

    return [
        'passed' => $allPassed,
        'cases' => $results,
    ];
}

$args = wlsPanelDeployPolicyParseArgs($argv);
$path = trim((string)($args['deploy-current'] ?? ''));
$expect = strtolower(trim((string)($args['expect'] ?? '')));

if ((string)($args['self-test'] ?? '0') === '1') {
    $selfTest = wlsPanelDeployPolicySelfTest();
    wlsPanelDeployPolicyFinish([
        'passed' => $selfTest['passed'],
        'self_test' => true,
        'cases' => $selfTest['cases'],
        'side_effects' => 'in-memory self-test: no file read, no network, no token, no WLS start, no writes',
    ], $selfTest['passed'] ? 0 : WLS_PANEL_DEPLOY_POLICY_EXIT_ASSERTION_FAILED);
}

$errors = [];
$payload = wlsPanelDeployPolicyReadPayload($path, $errors);
$result = wlsPanelDeployPolicyEvaluate($payload, $expect, $errors);
wlsPanelDeployPolicyFinish([
    'passed' => $result['passed'],
    'deploy_current' => $path,
    'expect' => $expect,
    'resolved' => $result['resolved'],
    'checks' => $result['checks'],
    'errors' => $result['errors'],
    'warnings' => $result['warnings'],
    'side_effects' => 'read-only policy check: no network, no token, no WLS start, no writes',
], $result['passed'] ? 0 : WLS_PANEL_DEPLOY_POLICY_EXIT_ASSERTION_FAILED);
