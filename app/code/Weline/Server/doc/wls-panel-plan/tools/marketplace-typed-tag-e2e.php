<?php
declare(strict_types=1);

/**
 * Credential-safe WLS marketplace typed-tag verifier.
 *
 * This tool is intentionally kept under the WLS Panel Plan so final acceptance
 * can be repeated without relying on ignored task artifacts.
 */

const DEFAULT_ENDPOINT = 'https://app.aiweline.com/api/v1/platform/module/list';
const DEFAULT_TOKEN_ENV = 'WLS_MARKETPLACE_BEARER_TOKEN';
const DEFAULT_NEGATIVE_TAG = 'module:wls-extra';
const DEFAULT_TIMEOUT = 20;
const EXIT_ASSERTION_FAILED = 1;
const EXIT_BLOCKED = 2;

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function wlsPanelParseArgs(array $argv): array
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
 * @param array<string, mixed> $result
 */
function wlsPanelFinish(array $result, int $exitCode): never
{
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

function wlsPanelNormalizeEndpoint(string $value): string
{
    $value = rtrim(trim($value), '/');
    if ($value === '') {
        return '';
    }

    if (preg_match('#/api/v1/platform/module/list$#', $value) === 1) {
        return $value;
    }

    return $value . '/api/v1/platform/module/list';
}

/**
 * @param array<string, mixed> $payload
 * @return array{endpoint:string,source:string,environment:string,error:string}
 */
function wlsPanelEndpointFromDeployPayload(array $payload, string $source): array
{
    $environment = strtolower(trim((string)($payload['appstore_environment'] ?? '')));
    $platformUrl = rtrim(trim((string)($payload['appstore_platform_url'] ?? '')), '/');
    $platformUrlSource = trim((string)($payload['appstore_platform_url_source'] ?? ''));
    $endpoint = wlsPanelNormalizeEndpoint($platformUrl);
    if ($endpoint === '') {
        return [
            'endpoint' => '',
            'source' => $source,
            'environment' => $environment,
            'error' => 'deploy_current_missing_appstore_platform_url',
        ];
    }

    if ($environment === '') {
        return [
            'endpoint' => '',
            'source' => $source,
            'environment' => '',
            'error' => 'deploy_current_missing_appstore_environment',
        ];
    }

    if ($environment === 'production') {
        if ($platformUrl !== 'https://app.aiweline.com') {
            return [
                'endpoint' => '',
                'source' => $source,
                'environment' => $environment,
                'error' => 'deploy_current_production_appstore_url_mismatch',
            ];
        }

        if ($platformUrlSource !== 'production_default') {
            return [
                'endpoint' => '',
                'source' => $source,
                'environment' => $environment,
                'error' => 'deploy_current_production_appstore_source_mismatch',
            ];
        }
    } elseif ($environment === 'local') {
        if ($platformUrl !== 'https://app.weline.test:9523') {
            return [
                'endpoint' => '',
                'source' => $source,
                'environment' => $environment,
                'error' => 'deploy_current_local_appstore_url_mismatch',
            ];
        }

        if (!in_array($platformUrlSource, ['local_default', 'env:WELINE_APPSTORE_PLATFORM_URL', 'config:appstore.platform_url'], true)) {
            return [
                'endpoint' => '',
                'source' => $source,
                'environment' => $environment,
                'error' => 'deploy_current_local_appstore_source_mismatch',
            ];
        }
    } else {
        return [
            'endpoint' => '',
            'source' => $source,
            'environment' => $environment,
            'error' => 'deploy_current_unsupported_appstore_environment',
        ];
    }

    return [
        'endpoint' => $endpoint,
        'source' => $source,
        'environment' => $environment,
        'error' => '',
    ];
}

/**
 * @return array{endpoint:string,source:string,environment:string,error:string}
 */
function wlsPanelEndpointFromDeployCurrent(string $path): array
{
    if ($path === '') {
        return ['endpoint' => '', 'source' => '', 'environment' => '', 'error' => ''];
    }

    if (!is_file($path) || !is_readable($path)) {
        return [
            'endpoint' => '',
            'source' => 'deploy-current:' . $path,
            'environment' => '',
            'error' => 'deploy_current_unreadable',
        ];
    }

    $json = ltrim((string)file_get_contents($path), "\xEF\xBB\xBF");
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        return [
            'endpoint' => '',
            'source' => 'deploy-current:' . $path,
            'environment' => '',
            'error' => 'deploy_current_invalid_json',
        ];
    }

    return wlsPanelEndpointFromDeployPayload($payload, 'deploy-current:' . $path);
}

/**
 * @param array<string, string> $args
 * @return array{endpoint:string,source:string,environment:string,error:string}
 */
function wlsPanelConfiguredEndpoint(array $args): array
{
    $explicitEndpoint = trim((string)($args['endpoint'] ?? ''));
    if ($explicitEndpoint !== '') {
        return [
            'endpoint' => wlsPanelNormalizeEndpoint($explicitEndpoint),
            'source' => 'arg:endpoint',
            'environment' => '',
            'error' => '',
        ];
    }

    $deployCurrent = trim((string)($args['deploy-current'] ?? ''));
    if ($deployCurrent !== '') {
        return wlsPanelEndpointFromDeployCurrent($deployCurrent);
    }

    $envEndpoint = trim((string)(getenv('WLS_MARKETPLACE_API_URL') ?: ''));
    if ($envEndpoint !== '') {
        return [
            'endpoint' => wlsPanelNormalizeEndpoint($envEndpoint),
            'source' => 'env:WLS_MARKETPLACE_API_URL',
            'environment' => '',
            'error' => '',
        ];
    }

    return [
        'endpoint' => DEFAULT_ENDPOINT,
        'source' => 'default:production',
        'environment' => 'production',
        'error' => '',
    ];
}

/**
 * @param array<string, string> $args
 * @return array{token:string,source:string,error?:string}
 */
function wlsPanelConfiguredToken(array $args, string $tokenEnv): array
{
    $envToken = trim((string)(getenv($tokenEnv) ?: ''));
    if ($envToken !== '') {
        return ['token' => $envToken, 'source' => 'env:' . $tokenEnv];
    }

    $tokenFile = trim((string)($args['token-file'] ?? ''));
    if ($tokenFile === '') {
        return ['token' => '', 'source' => 'env:' . $tokenEnv];
    }

    if (!is_file($tokenFile) || !is_readable($tokenFile)) {
        return ['token' => '', 'source' => 'file:' . $tokenFile, 'error' => 'token_file_unreadable'];
    }

    return ['token' => trim((string)file_get_contents($tokenFile)), 'source' => 'file:' . $tokenFile];
}

function wlsPanelNormalizeTag(string $tag): string
{
    return strtolower(trim($tag));
}

/**
 * @return array<int, string>
 */
function wlsPanelParseTags(string $value): array
{
    $tags = [];
    foreach (preg_split('/[,;]+/', $value) ?: [] as $tag) {
        $tag = wlsPanelNormalizeTag((string)$tag);
        if ($tag !== '') {
            $tags[] = $tag;
        }
    }

    return array_values(array_unique($tags));
}

/**
 * @return array<int, string>
 */
function wlsPanelNormalizeTagValue(mixed $value): array
{
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }

        if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return wlsPanelNormalizeTagValue($decoded);
            }
        }

        return wlsPanelParseTags($trimmed);
    }

    if (!is_array($value)) {
        return [];
    }

    if (isset($value['type'])) {
        $type = trim((string)$value['type']);
        $tagValue = $value['value'] ?? $value['code'] ?? $value['name'] ?? null;
        if ($type !== '' && is_scalar($tagValue) && trim((string)$tagValue) !== '') {
            return [wlsPanelNormalizeTag($type . ':' . trim((string)$tagValue))];
        }
    }

    if (array_is_list($value)) {
        $tags = [];
        foreach ($value as $entry) {
            $tags = array_merge($tags, wlsPanelNormalizeTagValue($entry));
        }

        return array_values(array_unique($tags));
    }

    $tags = [];
    foreach (['tag', 'name', 'value', 'code'] as $key) {
        if (isset($value[$key]) && is_scalar($value[$key])) {
            $tags = array_merge($tags, wlsPanelNormalizeTagValue((string)$value[$key]));
        }
    }

    if ($tags === []) {
        foreach ($value as $entry) {
            if (is_array($entry)) {
                $tags = array_merge($tags, wlsPanelNormalizeTagValue($entry));
            }
        }
    }

    return array_values(array_unique($tags));
}

/**
 * @param array<string, mixed> $item
 * @return array<int, string>
 */
function wlsPanelExtractTags(array $item): array
{
    $tags = [];
    foreach (['tags', 'tags_resolved', 'tag_list', 'typed_tags'] as $key) {
        if (array_key_exists($key, $item)) {
            $tags = array_merge($tags, wlsPanelNormalizeTagValue($item[$key]));
        }
    }

    foreach (['marketplace_meta', 'marketplaceMeta', 'meta', 'module_meta', 'moduleMeta'] as $key) {
        $meta = $item[$key] ?? null;
        if (!is_array($meta)) {
            continue;
        }

        foreach (['tags', 'tags_resolved', 'tag_list', 'typed_tags'] as $tagKey) {
            if (array_key_exists($tagKey, $meta)) {
                $tags = array_merge($tags, wlsPanelNormalizeTagValue($meta[$tagKey]));
            }
        }
    }

    return array_values(array_unique($tags));
}

/**
 * @param array<string, mixed> $response
 * @return array<int, mixed>
 */
function wlsPanelExtractItems(array $response): array
{
    $candidates = [
        $response['data']['items'] ?? null,
        $response['data']['list'] ?? null,
        $response['data']['modules'] ?? null,
        $response['items'] ?? null,
        $response['list'] ?? null,
        $response['modules'] ?? null,
        $response['data'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_array($candidate) && array_is_list($candidate)) {
            return $candidate;
        }
    }

    return [];
}

/**
 * @param array<string, mixed> $item
 */
function wlsPanelItemName(array $item): string
{
    foreach (['name', 'module_name', 'module', 'code', 'title'] as $key) {
        $value = $item[$key] ?? null;
        if (is_scalar($value) && trim((string)$value) !== '') {
            return trim((string)$value);
        }
    }

    return '(unnamed)';
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function wlsPanelPostJson(
    string $endpoint,
    array $payload,
    string $token,
    int $timeout,
    bool $insecure,
    string $domain
): array {
    $query = http_build_query($payload);
    $requestEndpoint = $query === ''
        ? $endpoint
        : $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . $query;
    $ch = curl_init($requestEndpoint);
    if ($ch === false) {
        return ['curl_errno' => -1, 'curl_error' => 'curl_init_failed', 'http_code' => 0, 'body' => '', 'json' => null];
    }

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ];
    if ($domain !== '') {
        $headers[] = 'Host: ' . $domain;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HEADER => false,
        CURLOPT_SSL_VERIFYPEER => !$insecure,
        CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $body = is_string($body) ? $body : '';
    $decoded = $body !== '' ? json_decode($body, true) : null;

    return [
        'curl_errno' => $errno,
        'curl_error' => $error,
        'http_code' => $httpCode,
        'body' => $body,
        'json' => is_array($decoded) ? $decoded : null,
        'json_error' => $body === '' ? 'empty_body' : json_last_error_msg(),
    ];
}

/**
 * @param array<string, mixed> $response
 */
function wlsPanelApiSuccess(array $response): bool
{
    if (array_key_exists('success', $response)) {
        return $response['success'] === true || $response['success'] === 1 || $response['success'] === '1';
    }

    if (array_key_exists('code', $response)) {
        $code = (int)$response['code'];
        return $code >= 200 && $code < 300;
    }

    return true;
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function wlsPanelRunCase(
    string $name,
    string $endpoint,
    array $payload,
    string $requiredTag,
    string $token,
    int $timeout,
    bool $insecure,
    string $domain,
    int $minItems,
    bool $negative = false
): array {
    $http = wlsPanelPostJson($endpoint, $payload, $token, $timeout, $insecure, $domain);
    $case = [
        'name' => $name,
        'passed' => false,
        'blocked' => false,
        'http_code' => $http['http_code'],
        'payload' => $payload,
        'item_count' => 0,
        'sample_items' => [],
        'failures' => [],
    ];

    if ((int)$http['curl_errno'] !== 0) {
        $case['blocked'] = true;
        $case['failures'][] = [
            'reason' => 'endpoint_unreachable',
            'curl_errno' => (int)$http['curl_errno'],
            'curl_error' => (string)$http['curl_error'],
        ];
        return $case;
    }

    if ($http['http_code'] < 200 || $http['http_code'] >= 300) {
        $case['failures'][] = ['reason' => 'unexpected_http_status', 'body_sample' => substr((string)$http['body'], 0, 300)];
        return $case;
    }

    if (!is_array($http['json'])) {
        $case['failures'][] = ['reason' => 'non_json_response', 'json_error' => (string)($http['json_error'] ?? '')];
        return $case;
    }

    if (!wlsPanelApiSuccess($http['json'])) {
        $case['failures'][] = ['reason' => 'api_success_false', 'body_sample' => substr((string)$http['body'], 0, 300)];
        return $case;
    }

    $items = wlsPanelExtractItems($http['json']);
    $case['item_count'] = count($items);
    $case['sample_items'] = array_slice(array_map(
        static fn(mixed $item): string => is_array($item) ? wlsPanelItemName($item) : '(non-object-item)',
        $items
    ), 0, 5);

    if (count($items) < $minItems) {
        $case['failures'][] = ['reason' => 'too_few_items', 'min_items' => $minItems];
        return $case;
    }

    $badItems = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            $badItems[] = '(non-object-item)';
            continue;
        }

        if (!in_array($requiredTag, wlsPanelExtractTags($item), true)) {
            $badItems[] = wlsPanelItemName($item);
        }
    }

    if ($badItems !== []) {
        $case['failures'][] = [
            'reason' => $negative ? 'negative_filter_returned_items_without_exact_tag' : 'positive_filter_returned_items_without_exact_tag',
            'required_tag' => $requiredTag,
            'bad_items' => array_slice($badItems, 0, 10),
        ];
        return $case;
    }

    $case['negative_conclusive'] = $negative ? count($items) > 0 : null;
    $case['passed'] = true;
    return $case;
}

/**
 * @param array<string, mixed> $case
 * @return array<string, mixed>
 */
function wlsPanelRequireNegativeConclusive(array $case, bool $require): array
{
    if (!$require || !($case['passed'] ?? false)) {
        return $case;
    }

    if (($case['negative_conclusive'] ?? null) === true) {
        return $case;
    }

    $case['passed'] = false;
    $case['failures'][] = [
        'reason' => 'negative_filter_not_conclusive',
        'message' => 'The negative tag query returned no canary item, so exact-match behavior was not proven live.',
    ];

    return $case;
}

/**
 * @param array<string, mixed> $response
 * @return array<string, mixed>
 */
function wlsPanelOfflineCase(
    string $name,
    array $response,
    string $requiredTag,
    int $minItems,
    bool $wantPassed
): array {
    $items = wlsPanelExtractItems($response);
    $badItems = [];
    $sampleItems = [];
    $sampleTags = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            $badItems[] = '(non-object-item)';
            continue;
        }

        $itemName = wlsPanelItemName($item);
        $tags = wlsPanelExtractTags($item);
        $sampleItems[] = $itemName;
        $sampleTags[$itemName] = $tags;

        if (!in_array($requiredTag, $tags, true)) {
            $badItems[] = $itemName;
        }
    }

    $failures = [];
    if (count($items) < $minItems) {
        $failures[] = ['reason' => 'too_few_items', 'min_items' => $minItems];
    }

    if ($badItems !== []) {
        $failures[] = [
            'reason' => 'items_without_exact_tag',
            'required_tag' => $requiredTag,
            'bad_items' => array_slice($badItems, 0, 10),
        ];
    }

    $actualPassed = $failures === [];

    return [
        'name' => $name,
        'want_passed' => $wantPassed,
        'actual_passed' => $actualPassed,
        'case_ok' => $actualPassed === $wantPassed,
        'required_tag' => $requiredTag,
        'item_count' => count($items),
        'sample_items' => array_slice($sampleItems, 0, 5),
        'sample_tags' => array_slice($sampleTags, 0, 5, true),
        'failures' => $failures,
    ];
}

/**
 * @return array<string, mixed>
 */
function wlsPanelSelfTest(): array
{
    $cases = [];
    $cases[] = wlsPanelOfflineCase(
        'string_csv_tags_include_module_wls',
        ['data' => ['items' => [[
            'name' => 'WLS File Manager',
            'tags' => 'module:wls, custom:wls-file-manager; system:false',
        ]]]],
        'module:wls',
        1,
        true
    );
    $cases[] = wlsPanelOfflineCase(
        'json_array_string_tags_include_custom_plugin',
        ['data' => ['items' => [[
            'name' => 'WLS PHP Manager',
            'tags' => '[{"code":"module:wls"},{"type":"custom","value":"wls-php-manager"}]',
        ]]]],
        'custom:wls-php-manager',
        1,
        true
    );
    $cases[] = wlsPanelOfflineCase(
        'marketplace_meta_type_value_tags_include_system_false',
        ['data' => ['items' => [[
            'name' => 'WLS Deploy',
            'marketplace_meta' => [
                'tags' => [
                    ['type' => 'module', 'value' => 'wls'],
                    ['type' => 'system', 'value' => 'false'],
                ],
            ],
        ]]]],
        'system:false',
        1,
        true
    );
    $cases[] = wlsPanelOfflineCase(
        'locale_grouped_tags_resolved_include_module_wls',
        ['data' => ['items' => [[
            'name' => 'WLS Database Manager',
            'tags_resolved' => [
                'zh_Hans_CN' => [
                    ['code' => 'module:wls', 'label' => 'WLS Panel'],
                ],
                'en_US' => [
                    ['type' => 'custom', 'value' => 'wls-database-manager'],
                ],
            ],
        ]]]],
        'module:wls',
        1,
        true
    );
    $cases[] = wlsPanelOfflineCase(
        'module_wls_extra_does_not_match_module_wls',
        ['data' => ['items' => [[
            'name' => 'Wrong Tag Shape',
            'tags' => ['module:wls-extra'],
        ]]]],
        'module:wls',
        1,
        false
    );
    $cases[] = wlsPanelOfflineCase(
        'missing_required_tag_fails',
        ['data' => ['items' => [[
            'name' => 'Plain Backend Tool',
            'tags' => ['surface:backend', 'category:server-tools'],
        ]]]],
        'module:wls',
        1,
        false
    );
    $conclusiveNegative = wlsPanelRequireNegativeConclusive([
        'name' => 'negative_canary_present',
        'passed' => true,
        'negative_conclusive' => true,
        'failures' => [],
    ], true);
    $emptyNegative = wlsPanelRequireNegativeConclusive([
        'name' => 'negative_canary_missing',
        'passed' => true,
        'negative_conclusive' => false,
        'failures' => [],
    ], true);
    $cases[] = [
        'name' => 'require_negative_conclusive_accepts_canary',
        'want_passed' => true,
        'actual_passed' => (bool)$conclusiveNegative['passed'],
        'case_ok' => (bool)$conclusiveNegative['passed'] === true,
        'required_tag' => 'module:wls-extra',
        'item_count' => 1,
        'sample_items' => ['negative_canary_present'],
        'sample_tags' => ['negative_canary_present' => ['module:wls-extra']],
        'failures' => $conclusiveNegative['failures'],
    ];
    $cases[] = [
        'name' => 'require_negative_conclusive_rejects_empty_negative_query',
        'want_passed' => false,
        'actual_passed' => (bool)$emptyNegative['passed'],
        'case_ok' => (bool)$emptyNegative['passed'] === false,
        'required_tag' => 'module:wls-extra',
        'item_count' => 0,
        'sample_items' => [],
        'sample_tags' => [],
        'failures' => $emptyNegative['failures'],
    ];

    $productionMissingEndpoint = wlsPanelEndpointFromDeployPayload([
        'appstore_environment' => 'production',
        'appstore_platform_url' => '',
        'appstore_platform_url_source' => 'production_default',
    ], 'deploy-current:self-test-production-missing');
    $productionExplicitEndpoint = wlsPanelEndpointFromDeployPayload([
        'appstore_environment' => 'production',
        'appstore_platform_url' => 'https://app.aiweline.com',
        'appstore_platform_url_source' => 'production_default',
    ], 'deploy-current:self-test-production-explicit');
    $productionWrongSourceEndpoint = wlsPanelEndpointFromDeployPayload([
        'appstore_environment' => 'production',
        'appstore_platform_url' => 'https://app.aiweline.com',
        'appstore_platform_url_source' => 'config:appstore.platform_url',
    ], 'deploy-current:self-test-production-wrong-source');
    $productionApiPathEndpoint = wlsPanelEndpointFromDeployPayload([
        'appstore_environment' => 'production',
        'appstore_platform_url' => 'https://app.aiweline.com/api/v1/platform/module/list',
        'appstore_platform_url_source' => 'production_default',
    ], 'deploy-current:self-test-production-api-path');
    $localExplicitEndpoint = wlsPanelEndpointFromDeployPayload([
        'appstore_environment' => 'local',
        'appstore_platform_url' => 'https://app.weline.test:9523',
        'appstore_platform_url_source' => 'local_default',
    ], 'deploy-current:self-test-local-explicit');
    $localWrongSourceEndpoint = wlsPanelEndpointFromDeployPayload([
        'appstore_environment' => 'local',
        'appstore_platform_url' => 'https://app.weline.test:9523',
        'appstore_platform_url_source' => 'production_default',
    ], 'deploy-current:self-test-local-wrong-source');
    $cases[] = [
        'name' => 'production_deploy_current_requires_explicit_platform_url',
        'want_passed' => true,
        'actual_passed' => $productionMissingEndpoint['endpoint'] === ''
            && $productionMissingEndpoint['error'] === 'deploy_current_missing_appstore_platform_url',
        'case_ok' => $productionMissingEndpoint['endpoint'] === ''
            && $productionMissingEndpoint['error'] === 'deploy_current_missing_appstore_platform_url',
        'required_tag' => '',
        'item_count' => 0,
        'sample_items' => [],
        'sample_tags' => [],
        'failures' => [],
    ];
    $cases[] = [
        'name' => 'production_deploy_current_explicit_app_aiweline_endpoint_and_source',
        'want_passed' => true,
        'actual_passed' => $productionExplicitEndpoint['endpoint'] === 'https://app.aiweline.com/api/v1/platform/module/list'
            && $productionExplicitEndpoint['environment'] === 'production',
        'case_ok' => $productionExplicitEndpoint['endpoint'] === 'https://app.aiweline.com/api/v1/platform/module/list'
            && $productionExplicitEndpoint['environment'] === 'production',
        'required_tag' => '',
        'item_count' => 0,
        'sample_items' => [],
        'sample_tags' => [],
        'failures' => [],
    ];
    $cases[] = [
        'name' => 'production_deploy_current_rejects_non_production_default_source',
        'want_passed' => true,
        'actual_passed' => $productionWrongSourceEndpoint['endpoint'] === ''
            && $productionWrongSourceEndpoint['error'] === 'deploy_current_production_appstore_source_mismatch',
        'case_ok' => $productionWrongSourceEndpoint['endpoint'] === ''
            && $productionWrongSourceEndpoint['error'] === 'deploy_current_production_appstore_source_mismatch',
        'required_tag' => '',
        'item_count' => 0,
        'sample_items' => [],
        'sample_tags' => [],
        'failures' => [],
    ];
    $cases[] = [
        'name' => 'production_deploy_current_rejects_api_path_platform_url',
        'want_passed' => true,
        'actual_passed' => $productionApiPathEndpoint['endpoint'] === ''
            && $productionApiPathEndpoint['error'] === 'deploy_current_production_appstore_url_mismatch',
        'case_ok' => $productionApiPathEndpoint['endpoint'] === ''
            && $productionApiPathEndpoint['error'] === 'deploy_current_production_appstore_url_mismatch',
        'required_tag' => '',
        'item_count' => 0,
        'sample_items' => [],
        'sample_tags' => [],
        'failures' => [],
    ];
    $cases[] = [
        'name' => 'local_deploy_current_explicit_app_weline_endpoint_and_source',
        'want_passed' => true,
        'actual_passed' => $localExplicitEndpoint['endpoint'] === 'https://app.weline.test:9523/api/v1/platform/module/list'
            && $localExplicitEndpoint['environment'] === 'local',
        'case_ok' => $localExplicitEndpoint['endpoint'] === 'https://app.weline.test:9523/api/v1/platform/module/list'
            && $localExplicitEndpoint['environment'] === 'local',
        'required_tag' => '',
        'item_count' => 0,
        'sample_items' => [],
        'sample_tags' => [],
        'failures' => [],
    ];
    $cases[] = [
        'name' => 'local_deploy_current_rejects_production_default_source',
        'want_passed' => true,
        'actual_passed' => $localWrongSourceEndpoint['endpoint'] === ''
            && $localWrongSourceEndpoint['error'] === 'deploy_current_local_appstore_source_mismatch',
        'case_ok' => $localWrongSourceEndpoint['endpoint'] === ''
            && $localWrongSourceEndpoint['error'] === 'deploy_current_local_appstore_source_mismatch',
        'required_tag' => '',
        'item_count' => 0,
        'sample_items' => [],
        'sample_tags' => [],
        'failures' => [],
    ];

    return [
        'passed' => count(array_filter($cases, static fn(array $case): bool => !(bool)$case['case_ok'])) === 0,
        'self_test' => true,
        'cases' => $cases,
        'side_effects' => 'in-memory self-test: no file read, no network, no token, no WLS start, no writes',
    ];
}

$args = wlsPanelParseArgs($argv);
if (array_key_exists('token', $args)) {
    wlsPanelFinish([
        'passed' => false,
        'blocked' => true,
        'reason' => 'unsafe_token_argument_rejected',
        'message' => 'Use an environment variable or --token-file instead of --token=.',
    ], EXIT_BLOCKED);
}

if (in_array(strtolower((string)($args['self-test'] ?? '0')), ['1', 'true', 'yes'], true)) {
    $selfTest = wlsPanelSelfTest();
    wlsPanelFinish($selfTest, (bool)$selfTest['passed'] ? 0 : EXIT_ASSERTION_FAILED);
}

$endpointInfo = wlsPanelConfiguredEndpoint($args);
$endpoint = (string)$endpointInfo['endpoint'];
$tokenEnv = trim((string)($args['token-env'] ?? DEFAULT_TOKEN_ENV));
$tokenInfo = wlsPanelConfiguredToken($args, $tokenEnv);
$result = [
    'passed' => false,
    'blocked' => false,
    'endpoint' => $endpoint,
    'endpoint_source' => (string)$endpointInfo['source'],
    'appstore_environment' => (string)$endpointInfo['environment'],
    'token_source' => (string)$tokenInfo['source'],
    'token_redacted' => true,
    'cases' => [],
];

if (($endpointInfo['error'] ?? '') !== '') {
    $result['blocked'] = true;
    $result['reason'] = (string)$endpointInfo['error'];
    wlsPanelFinish($result, EXIT_BLOCKED);
}

$resolveOnly = in_array(strtolower((string)($args['resolve-endpoint-only'] ?? '0')), ['1', 'true', 'yes'], true);
if ($resolveOnly) {
    $result['passed'] = $endpoint !== '';
    $result['resolve_endpoint_only'] = true;
    wlsPanelFinish($result, $result['passed'] ? 0 : EXIT_BLOCKED);
}

if (($tokenInfo['error'] ?? '') !== '') {
    $result['blocked'] = true;
    $result['reason'] = (string)$tokenInfo['error'];
    wlsPanelFinish($result, EXIT_BLOCKED);
}

if ((string)$tokenInfo['token'] === '') {
    $result['blocked'] = true;
    $result['reason'] = 'missing_token';
    $result['message'] = 'Set ' . $tokenEnv . ' or pass --token-file=/path/to/token before running live E2E.';
    wlsPanelFinish($result, EXIT_BLOCKED);
}

if (!extension_loaded('curl')) {
    $result['blocked'] = true;
    $result['reason'] = 'curl_extension_missing';
    wlsPanelFinish($result, EXIT_BLOCKED);
}

$timeout = max(1, (int)($args['timeout'] ?? DEFAULT_TIMEOUT));
$insecure = in_array(strtolower((string)($args['insecure'] ?? '0')), ['1', 'true', 'yes'], true);
$domain = trim((string)($args['domain'] ?? ''));
$minItems = max(0, (int)($args['min-items'] ?? 1));
$negativeTag = wlsPanelNormalizeTag((string)($args['negative-tag'] ?? DEFAULT_NEGATIVE_TAG));
$requireNegativeConclusive = in_array(strtolower((string)($args['require-negative-conclusive'] ?? '0')), ['1', 'true', 'yes'], true);
$expectedTags = wlsPanelParseTags((string)($args['expected-tag'] ?? $args['expected-tags'] ?? ''));
$structuredTags = array_values(array_unique(array_merge(['module:wls'], $expectedTags)));

$cases = [];
$cases[] = wlsPanelRunCase('single_tag_module_wls', $endpoint, ['tag' => 'module:wls', 'tag_match' => 'all'], 'module:wls', (string)$tokenInfo['token'], $timeout, $insecure, $domain, $minItems);
$cases[] = wlsPanelRunCase('structured_tags_all_match', $endpoint, ['tags' => $structuredTags, 'tag_match' => 'all'], 'module:wls', (string)$tokenInfo['token'], $timeout, $insecure, $domain, $minItems);
$cases[] = wlsPanelRequireNegativeConclusive(
    wlsPanelRunCase('negative_exact_match_' . str_replace(':', '_', $negativeTag), $endpoint, ['tag' => $negativeTag, 'tag_match' => 'all'], $negativeTag, (string)$tokenInfo['token'], $timeout, $insecure, $domain, 0, true),
    $requireNegativeConclusive
);

$result['cases'] = $cases;
$result['require_negative_conclusive'] = $requireNegativeConclusive;
$result['blocked'] = count(array_filter($cases, static fn(array $case): bool => (bool)$case['blocked'])) > 0;
$result['passed'] = !$result['blocked']
    && count(array_filter($cases, static fn(array $case): bool => !(bool)$case['passed'])) === 0;

if ($result['blocked']) {
    $result['reason'] = 'endpoint_unreachable';
    wlsPanelFinish($result, EXIT_BLOCKED);
}

wlsPanelFinish($result, $result['passed'] ? 0 : EXIT_ASSERTION_FAILED);
