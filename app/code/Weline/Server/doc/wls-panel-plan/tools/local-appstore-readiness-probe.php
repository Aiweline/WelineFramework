<?php
declare(strict_types=1);

/**
 * Read-only readiness probe for the local App Store checkout required by the
 * WLS Panel marketplace typed-tag E2E.
 *
 * The probe only reads files, checks a local TCP listener, and optionally reads
 * git status. It does not run setup, start WLS, sync files, or print secrets.
 */

const WLS_PANEL_APPSTORE_READINESS_NOT_READY = 1;

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function wlsPanelReadinessParseArgs(array $argv): array
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
function wlsPanelReadinessFinish(array $payload, int $exitCode): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($exitCode);
}

function wlsPanelReadinessPath(string $base, string $relative): string
{
    return rtrim($base, "\\/") . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
}

function wlsPanelReadinessRead(string $path): string
{
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }

    $content = file_get_contents($path);
    return is_string($content) ? $content : '';
}

function wlsPanelReadinessHasSqliteCompositePkGuard(string $schemaContent): bool
{
    if ($schemaContent === '') {
        return false;
    }

    $hasSqliteFlag = str_contains($schemaContent, '$isSqlite');
    $hasCompositeFlag = str_contains($schemaContent, '$hasCompositePk');
    $hasAutoIncrement = str_contains($schemaContent, 'AUTO_INCREMENT');
    $hasGuard = preg_match('/!\s*\(\s*\$isSqlite\s*&&\s*\$hasCompositePk\s*\)/', $schemaContent) === 1
        || preg_match('/!\s*\$isSqlite\s*\|\|\s*!\s*\$hasCompositePk/', $schemaContent) === 1;

    return $hasSqliteFlag && $hasCompositeFlag && $hasAutoIncrement && $hasGuard;
}

/**
 * @return array<string, mixed>
 */
function wlsPanelReadinessSchemaSyncSummary(
    string $devSchemaPath,
    string $appSchemaPath,
    bool $devGuardPresent,
    bool $appGuardPresent
): array {
    return [
        'source' => [
            'path' => $devSchemaPath,
            'readiness_check' => 'dev_schema_has_sqlite_composite_pk_guard',
            'guard_present' => $devGuardPresent,
        ],
        'target' => [
            'path' => $appSchemaPath,
            'readiness_check' => 'app_schema_has_sqlite_composite_pk_guard',
            'guard_present' => $appGuardPresent,
        ],
        'authorized_sync_required' => $devGuardPresent && !$appGuardPresent,
        'setup_required_after_sync' => $devGuardPresent && !$appGuardPresent,
        'allowed_sync_path' => 'app/code/Weline/Framework/Database/Schema/SchemaMigrationExecutor.php',
        'reason' => $devGuardPresent && !$appGuardPresent
            ? 'DEV source has the sqlite composite primary-key guard; local App checkout must receive it through the authorized scoped sync before setup/start/live E2E.'
            : 'Schema guard state is already aligned or DEV source is not ready.',
    ];
}

function wlsPanelReadinessEnvLooksLocal(string $envContent): bool
{
    if ($envContent === '') {
        return false;
    }

    $hasLocalDeploy = preg_match('/[\'"]deploy[\'"]\s*=>\s*[\'"](dev|local)[\'"]/i', $envContent) === 1
        || str_contains($envContent, 'appstore_environment')
        || str_contains($envContent, 'appstore.platform_url');
    $hasLocalEndpoint = str_contains($envContent, 'app.weline.test')
        || str_contains($envContent, 'appstore_platform_url')
        || str_contains($envContent, 'appstore.platform_url');

    return $hasLocalDeploy && $hasLocalEndpoint;
}

function wlsPanelReadinessEnvDeployMode(string $envContent): string
{
    if ($envContent === '') {
        return '';
    }

    if (preg_match('/[\'"]deploy[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/i', $envContent, $matches) !== 1) {
        return '';
    }

    return strtolower(trim((string)($matches[1] ?? '')));
}

function wlsPanelReadinessComparablePath(string $path): string
{
    return strtolower(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, rtrim($path, "\\/")));
}

function wlsPanelReadinessIsFrameworkOfficialAppCheckout(string $appRoot): bool
{
    $normalized = wlsPanelReadinessComparablePath($appRoot);
    $suffix = strtolower(DIRECTORY_SEPARATOR . 'framework-official' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'weline');

    return $normalized !== '' && str_ends_with($normalized, $suffix);
}

function wlsPanelReadinessTopLevelArrayBlock(string $envContent, string $key): string
{
    $lines = preg_split('/\R/', $envContent) ?: [];
    $capturing = false;
    $depth = 0;
    $block = [];
    $pattern = '/^\s*[\'"]' . preg_quote($key, '/') . '[\'"]\s*=>\s*\[/';

    foreach ($lines as $line) {
        if (!$capturing) {
            if (preg_match($pattern, $line) !== 1) {
                continue;
            }

            $capturing = true;
            $offset = strpos($line, '[');
            $block[] = $offset === false ? '' : substr($line, $offset + 1);
            $depth += substr_count($line, '[') - substr_count($line, ']');
            if ($depth <= 0) {
                break;
            }
            continue;
        }

        $depth += substr_count($line, '[') - substr_count($line, ']');
        if ($depth <= 0) {
            break;
        }

        $block[] = $line;
    }

    return trim(implode(PHP_EOL, $block));
}

/**
 * @return array{host:string,port:int,https:?bool,url:string}
 */
function wlsPanelReadinessEnvWlsEndpoint(string $envContent): array
{
    if ($envContent === '') {
        return ['host' => '', 'port' => 0, 'https' => null, 'url' => ''];
    }

    $wlsBlock = wlsPanelReadinessTopLevelArrayBlock($envContent, 'wls');
    if ($wlsBlock === '') {
        $wlsBlock = $envContent;
    }

    $host = '';
    if (preg_match('/[\'"]host[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/i', $wlsBlock, $matches) === 1) {
        $host = strtolower(trim((string)($matches[1] ?? '')));
    }

    $port = 0;
    if (preg_match('/[\'"]port[\'"]\s*=>\s*(\d+)/i', $wlsBlock, $matches) === 1) {
        $port = (int)($matches[1] ?? 0);
    }

    $https = null;
    if (preg_match('/[\'"]https[\'"]\s*=>\s*(true|false|1|0)/i', $wlsBlock, $matches) === 1) {
        $httpsValue = strtolower(trim((string)($matches[1] ?? '')));
        $https = in_array($httpsValue, ['true', '1'], true);
    }

    $url = '';
    if ($host !== '' && $port > 0 && $https !== null) {
        $url = ($https ? 'https' : 'http') . '://' . $host . ':' . (string)$port;
    }

    return ['host' => $host, 'port' => $port, 'https' => $https, 'url' => $url];
}

/**
 * @return array<string, mixed>
 */
function wlsPanelReadinessReadJson(string $path): array
{
    $content = wlsPanelReadinessRead($path);
    if ($content === '') {
        return [];
    }

    $payload = json_decode(ltrim($content, "\xEF\xBB\xBF"), true);
    return is_array($payload) ? $payload : [];
}

/**
 * @return list<string>
 */
function wlsPanelReadinessNormalizeTagValue(mixed $value): array
{
    if (is_scalar($value)) {
        $text = strtolower(trim((string)$value));
        if ($text === '') {
            return [];
        }

        $json = json_decode($text, true);
        if (is_array($json)) {
            return wlsPanelReadinessNormalizeTagValue($json);
        }

        $parts = preg_split('/[,;\s]+/', $text) ?: [];
        return array_values(array_unique(array_filter(array_map(
            static fn(string $part): string => trim($part),
            $parts
        ))));
    }

    if (!is_array($value)) {
        return [];
    }

    $tags = [];
    $code = $value['code'] ?? null;
    if (is_scalar($code)) {
        $tags[] = strtolower(trim((string)$code));
    }

    $type = $value['type'] ?? null;
    $typedValue = $value['value'] ?? $value['name'] ?? null;
    if (is_scalar($type) && is_scalar($typedValue)) {
        $typeText = strtolower(trim((string)$type));
        $valueText = strtolower(trim((string)$typedValue));
        if ($typeText !== '' && $valueText !== '') {
            $tags[] = $typeText . ':' . $valueText;
        }
    }

    $skipKeys = [
        'code',
        'type',
        'value',
        'name',
        'primary',
        'label',
        'labels',
        'description',
        'display_name',
        'version',
        'package',
        'signature',
    ];
    foreach ($value as $key => $item) {
        if (is_string($key) && in_array($key, $skipKeys, true)) {
            continue;
        }
        $tags = array_merge($tags, wlsPanelReadinessNormalizeTagValue($item));
    }

    return array_values(array_unique(array_filter($tags)));
}

/**
 * @param array<string, mixed> $entry
 * @return list<string>
 */
function wlsPanelReadinessEntryTags(array $entry): array
{
    $tags = wlsPanelReadinessNormalizeTagValue($entry['tags'] ?? []);
    $tags = array_merge($tags, wlsPanelReadinessNormalizeTagValue($entry['tags_resolved'] ?? []));
    if (is_array($entry['marketplace_meta'] ?? null)) {
        $tags = array_merge($tags, wlsPanelReadinessNormalizeTagValue($entry['marketplace_meta']['tags'] ?? []));
    }

    return array_values(array_unique(array_filter($tags)));
}

/**
 * @param array<string, mixed> $manifest
 * @return list<array<string, mixed>>
 */
function wlsPanelReadinessManifestEntries(array $manifest): array
{
    $entries = $manifest['apps'] ?? $manifest['modules'] ?? [];
    if (!is_array($entries)) {
        return [];
    }

    $result = [];
    foreach ($entries as $entry) {
        if (is_array($entry)) {
            $result[] = $entry;
        }
    }

    return $result;
}

/**
 * @return array{checks:array<string,bool>,summary:array<string,mixed>}
 */
function wlsPanelReadinessOfficialManifestChecks(string $manifestPath): array
{
    $manifest = wlsPanelReadinessReadJson($manifestPath);
    $entries = wlsPanelReadinessManifestEntries($manifest);
    $positiveWlsModules = [];
    $negativeCanaryModules = [];
    $badNegativeCanaryModules = [];

    foreach ($entries as $entry) {
        $name = trim((string)($entry['name'] ?? $entry['module_name'] ?? $entry['slug'] ?? 'unnamed'));
        $tags = wlsPanelReadinessEntryTags($entry);
        $hasWlsTag = in_array('module:wls', $tags, true);
        $hasCanaryTag = in_array('module:wls-extra', $tags, true);

        if ($hasWlsTag) {
            $positiveWlsModules[] = $name;
        }
        if ($hasCanaryTag) {
            $negativeCanaryModules[] = $name;
            if ($hasWlsTag) {
                $badNegativeCanaryModules[] = $name;
            }
        }
    }

    return [
        'checks' => [
            'official_manifest_readable' => $manifest !== [],
            'official_manifest_has_wls_positive' => $positiveWlsModules !== [],
            'official_manifest_has_negative_canary' => $negativeCanaryModules !== [],
            'official_manifest_negative_canary_exact' => $negativeCanaryModules !== [] && $badNegativeCanaryModules === [],
        ],
        'summary' => [
            'path' => $manifestPath,
            'entry_count' => count($entries),
            'positive_wls_modules' => array_values(array_unique($positiveWlsModules)),
            'negative_canary_modules' => array_values(array_unique($negativeCanaryModules)),
            'bad_negative_canary_modules' => array_values(array_unique($badNegativeCanaryModules)),
        ],
    ];
}

function wlsPanelReadinessIsManifestTargetAcceptable(string $appRoot, string $manifestPath): bool
{
    $normalizedAppRoot = strtolower(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, rtrim($appRoot, "\\/")));
    $normalizedManifestPath = strtolower(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $manifestPath));
    $requiredSuffix = strtolower(DIRECTORY_SEPARATOR . 'official-apps' . DIRECTORY_SEPARATOR . 'manifest.json');

    return $normalizedAppRoot !== ''
        && str_starts_with($normalizedManifestPath, $normalizedAppRoot . DIRECTORY_SEPARATOR)
        && str_ends_with($normalizedManifestPath, $requiredSuffix);
}

function wlsPanelReadinessShellArg(string $value): string
{
    return escapeshellarg($value);
}

/**
 * @return array{checks:array<string,bool>,summary:array<string,mixed>}
 */
function wlsPanelReadinessManifestMaterializePlan(
    string $workspaceRoot,
    string $appRoot,
    string $manifestPath
): array {
    $validatorRelative = 'app/code/Weline/Server/doc/wls-panel-plan/tools/validate-official-appstore-manifest-contract.php';
    $validatorPath = wlsPanelReadinessPath($workspaceRoot, $validatorRelative);
    $confirmPhrase = 'WRITE_WLS_OFFICIAL_MANIFEST';
    $sourceConfirmPhrase = 'WRITE_WLS_OFFICIAL_SOURCES';
    $targetAcceptable = wlsPanelReadinessIsManifestTargetAcceptable($appRoot, $manifestPath);
    $validatorReadable = is_file($validatorPath) && is_readable($validatorPath);

    $dryRunCommand = 'php ' . str_replace('/', DIRECTORY_SEPARATOR, $validatorRelative)
        . ' --template=1 --template-target=' . wlsPanelReadinessShellArg($manifestPath);
    $authorizedWriteCommand = $dryRunCommand
        . ' --write=1 --create-dir=1 --confirm=' . $confirmPhrase;
    $authorizedSourceWriteCommand = $dryRunCommand
        . ' --write-sources=1 --create-source-dirs=1 --confirm-sources=' . $sourceConfirmPhrase;
    $authorizedCatalogWriteCommand = $authorizedWriteCommand
        . ' --write-sources=1 --create-source-dirs=1 --confirm-sources=' . $sourceConfirmPhrase;

    return [
        'checks' => [
            'official_manifest_validator_available' => $validatorReadable,
            'official_manifest_materialize_target' => $targetAcceptable,
            'official_manifest_materialize_dry_run_available' => $validatorReadable && $targetAcceptable,
        ],
        'summary' => [
            'validator' => $validatorPath,
            'target' => $manifestPath,
            'confirm_phrase' => $confirmPhrase,
            'source_confirm_phrase' => $sourceConfirmPhrase,
            'dry_run_available' => $validatorReadable && $targetAcceptable,
            'dry_run_command' => $dryRunCommand,
            'authorized_write_command' => $authorizedWriteCommand,
            'authorized_source_write_command' => $authorizedSourceWriteCommand,
            'authorized_catalog_write_command' => $authorizedCatalogWriteCommand,
            'write_requires' => [
                'explicit user authorization',
                '--write=1',
                '--confirm=' . $confirmPhrase,
                '--create-dir=1 when official-apps is missing',
            ],
            'source_write_requires' => [
                'explicit user authorization',
                '--write-sources=1',
                '--confirm-sources=' . $sourceConfirmPhrase,
                '--create-source-dirs=1 when official-apps/modules is missing',
            ],
        ],
    ];
}

/**
 * @return array{checks:array<string,bool>,summary:array<string,mixed>}
 */
function wlsPanelReadinessLocalDeployCurrent(string $deployCurrentPath): array
{
    $payload = wlsPanelReadinessReadJson($deployCurrentPath);
    $rawPlatformUrl = rtrim(trim((string)($payload['appstore_platform_url'] ?? '')), '/');
    $parts = $rawPlatformUrl !== '' ? parse_url($rawPlatformUrl) : [];
    $scheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';
    $host = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
    $port = is_array($parts) ? (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80)) : 0;
    $endpoint = $rawPlatformUrl === '' ? '' : $rawPlatformUrl . '/api/v1/platform/module/list';
    $environment = strtolower(trim((string)($payload['appstore_environment'] ?? '')));

    return [
        'checks' => [
            'local_deploy_current_readable' => $payload !== [],
            'local_deploy_current_environment_local' => $environment === 'local',
            'local_deploy_current_exact_appstore_root' => $rawPlatformUrl === 'https://app.weline.test:9523',
            'local_deploy_current_resolves_expected_endpoint' =>
                $endpoint === 'https://app.weline.test:9523/api/v1/platform/module/list',
            'local_deploy_current_not_www_host' => $host !== '' && !str_starts_with($host, 'www.'),
        ],
        'summary' => [
            'path' => $deployCurrentPath,
            'environment' => $environment,
            'raw_platform_url' => $rawPlatformUrl,
            'url_source' => trim((string)($payload['appstore_platform_url_source'] ?? '')),
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'endpoint' => $endpoint,
        ],
    ];
}

/**
 * @return array{listening:bool,error:string|null}
 */
function wlsPanelReadinessTcp(string $host, int $port, float $timeout): array
{
    $errorNumber = 0;
    $errorMessage = '';
    $connection = @fsockopen($host, $port, $errorNumber, $errorMessage, $timeout);
    if (is_resource($connection)) {
        fclose($connection);
        return ['listening' => true, 'error' => null];
    }

    $message = trim($errorMessage);
    if ($message === '') {
        $message = 'tcp_connect_failed';
    }

    return ['listening' => false, 'error' => $message . ($errorNumber ? ':' . $errorNumber : '')];
}

/**
 * @param list<string> $paths
 * @return list<string>
 */
function wlsPanelReadinessGitStatus(string $repoRoot, array $paths): array
{
    if (!function_exists('exec') || !is_dir(wlsPanelReadinessPath($repoRoot, '.git'))) {
        return [];
    }

    $command = 'git -C ' . escapeshellarg($repoRoot) . ' status --short --';
    foreach ($paths as $path) {
        $command .= ' ' . escapeshellarg($path);
    }
    $command .= PHP_OS_FAMILY === 'Windows' ? ' 2>NUL' : ' 2>/dev/null';

    $output = [];
    $exitCode = 0;
    @exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        return [];
    }

    return array_values(array_filter(array_map(
        static fn(string $line): string => trim($line),
        $output
    )));
}

$args = wlsPanelReadinessParseArgs($argv);
$appRoot = trim((string)($args['app-root'] ?? 'E:\WelineFramework\Framework-Official\App\weline'));
$timeout = (float)($args['timeout'] ?? 1.5);
$gitStatusEnabled = (string)($args['git-status'] ?? '0') === '1';
$actionPlanOnly = (string)($args['action-plan-only'] ?? '0') === '1';
$workspaceRoot = dirname(__DIR__, 7);
$toolPrefix = 'app/code/Weline/Server/doc/wls-panel-plan/tools/';
$deployCurrentPath = trim((string)($args['deploy-current'] ?? wlsPanelReadinessPath($workspaceRoot, $toolPrefix . 'deploy-current-local-development.json')));
$localDeployCurrent = wlsPanelReadinessLocalDeployCurrent($deployCurrentPath);
$localDeploySummary = $localDeployCurrent['summary'];
$hasEndpointOverride = array_key_exists('host', $args) || array_key_exists('port', $args);
$host = trim((string)($args['host'] ?? ($localDeploySummary['host'] ?: 'app.weline.test')));
$port = (int)($args['port'] ?? ($localDeploySummary['port'] ?: 9523));
$probeRoot = 'https://' . $host . ':' . (string)$port;
$endpointSource = $hasEndpointOverride ? 'deploy-current-checked-with-override' : 'deploy-current';

$envPath = wlsPanelReadinessPath($appRoot, 'app/etc/env.php');
$appSchemaPath = wlsPanelReadinessPath($appRoot, 'app/code/Weline/Framework/Database/Schema/SchemaMigrationExecutor.php');
$devSchemaPath = wlsPanelReadinessPath($workspaceRoot, 'app/code/Weline/Framework/Database/Schema/SchemaMigrationExecutor.php');
$officialManifestPath = wlsPanelReadinessPath($appRoot, 'official-apps/manifest.json');
$platformAppStorePath = wlsPanelReadinessPath($appRoot, 'app/code/Weline/PlatformAppStore');
$appStorePath = wlsPanelReadinessPath($appRoot, 'app/code/Weline/AppStore');

$envContent = wlsPanelReadinessRead($envPath);
$envDeployMode = wlsPanelReadinessEnvDeployMode($envContent);
$envWlsEndpoint = wlsPanelReadinessEnvWlsEndpoint($envContent);
$appSchemaContent = wlsPanelReadinessRead($appSchemaPath);
$devSchemaContent = wlsPanelReadinessRead($devSchemaPath);
$tcp = wlsPanelReadinessTcp($host, $port, $timeout);
$officialManifest = wlsPanelReadinessOfficialManifestChecks($officialManifestPath);
$officialManifestMaterialize = wlsPanelReadinessManifestMaterializePlan($workspaceRoot, $appRoot, $officialManifestPath);
$adminStatus = $gitStatusEnabled ? wlsPanelReadinessGitStatus($appRoot, [
    'app/code/Weline/Admin/Service/BackendLoginReturnUrlService.php',
    'app/code/Weline/Admin/Test/Unit/Service/BackendLoginReturnUrlServiceTest.php',
]) : [];

$checks = [
    'app_root_exists' => is_dir($appRoot),
    'app_checkout_is_framework_official_app' => wlsPanelReadinessIsFrameworkOfficialAppCheckout($appRoot),
    'app_checkout_has_platform_appstore_module' => is_dir($platformAppStorePath),
    'app_checkout_has_appstore_module' => is_dir($appStorePath),
    'app_env_readable' => $envContent !== '',
    'app_env_deploy_mode_local' => in_array($envDeployMode, ['dev', 'local'], true),
    'app_env_mentions_local_appstore' => wlsPanelReadinessEnvLooksLocal($envContent),
    'app_env_wls_host_matches_local_appstore' => ($envWlsEndpoint['host'] ?? '') === 'app.weline.test',
    'app_env_wls_port_matches_local_appstore' => ($envWlsEndpoint['port'] ?? 0) === 9523,
    'app_env_wls_https_enabled' => ($envWlsEndpoint['https'] ?? null) === true,
    'app_env_wls_endpoint_matches_deploy_current' => ($envWlsEndpoint['url'] ?? '') === ($localDeploySummary['raw_platform_url'] ?? ''),
    'app_env_wls_endpoint_matches_probe_endpoint' => ($envWlsEndpoint['url'] ?? '') === $probeRoot,
    'app_schema_readable' => $appSchemaContent !== '',
    'app_schema_has_sqlite_composite_pk_guard' => wlsPanelReadinessHasSqliteCompositePkGuard($appSchemaContent),
    'dev_schema_has_sqlite_composite_pk_guard' => wlsPanelReadinessHasSqliteCompositePkGuard($devSchemaContent),
    'local_deploy_current_matches_probe_endpoint' => ($localDeploySummary['raw_platform_url'] ?? '') === $probeRoot,
    'app_port_listening' => $tcp['listening'],
    'bearer_token_env_present' => trim((string)getenv('WLS_MARKETPLACE_BEARER_TOKEN')) !== '',
] + $localDeployCurrent['checks'] + $officialManifest['checks'] + $officialManifestMaterialize['checks'];

$schemaSync = wlsPanelReadinessSchemaSyncSummary(
    $devSchemaPath,
    $appSchemaPath,
    $checks['dev_schema_has_sqlite_composite_pk_guard'],
    $checks['app_schema_has_sqlite_composite_pk_guard'],
);

$blockers = [];
foreach ([
    'app_root_exists',
    'app_checkout_is_framework_official_app',
    'app_checkout_has_platform_appstore_module',
    'app_checkout_has_appstore_module',
    'app_env_readable',
    'app_env_deploy_mode_local',
    'app_env_mentions_local_appstore',
    'app_env_wls_host_matches_local_appstore',
    'app_env_wls_port_matches_local_appstore',
    'app_env_wls_https_enabled',
    'app_env_wls_endpoint_matches_deploy_current',
    'app_env_wls_endpoint_matches_probe_endpoint',
    'app_schema_readable',
    'app_schema_has_sqlite_composite_pk_guard',
    'dev_schema_has_sqlite_composite_pk_guard',
    'local_deploy_current_readable',
    'local_deploy_current_environment_local',
    'local_deploy_current_exact_appstore_root',
    'local_deploy_current_resolves_expected_endpoint',
    'local_deploy_current_not_www_host',
    'local_deploy_current_matches_probe_endpoint',
    'app_port_listening',
    'official_manifest_readable',
    'official_manifest_has_wls_positive',
    'official_manifest_has_negative_canary',
    'official_manifest_negative_canary_exact',
] as $requiredCheck) {
    if (!$checks[$requiredCheck]) {
        $blockers[] = $requiredCheck;
    }
}

if (!$checks['bearer_token_env_present']) {
    $blockers[] = 'bearer_token_env_present';
}

$ready = $blockers === [];
$syncManifestCommand = 'php ' . str_replace('/', DIRECTORY_SEPARATOR, $toolPrefix . 'validate-local-appstore-sync-manifest.php');
$syncSelfTestCommand = $syncManifestCommand . ' --self-test=1';
$syncDriftCommand = $syncManifestCommand . ' --with-drift=1 --drift-summary-only=1';
$syncRollbackReviewCommand = $syncManifestCommand . ' --with-drift=1 --rollback-review=1';
$syncFailCommand = $syncManifestCommand . ' --fail-on-drift=1 --drift-summary-only=1';
$authorizationReviewCommand = 'php ' . str_replace('/', DIRECTORY_SEPARATOR, $toolPrefix . 'wls-panel-live-e2e-authorization-pack.php')
    . ' --include-drift-rows=1 --include-rollback-review=1 --fail-if-unsafe=1';
$deployCurrentPolicyCommand = 'php ' . str_replace('/', DIRECTORY_SEPARATOR, $toolPrefix . 'validate-deploy-appstore-endpoint-policy.php')
    . ' --deploy-current=' . wlsPanelReadinessShellArg($deployCurrentPath)
    . ' --expect=local';
$officialManifestValidateCommand = 'php ' . str_replace('/', DIRECTORY_SEPARATOR, $toolPrefix . 'validate-official-appstore-manifest-contract.php')
    . ' --manifest=' . wlsPanelReadinessShellArg($officialManifestPath);
$appSetupCommand = 'php bin/w setup:upgrade --route --skip-env-check --skip-composer-dump';
$wlsStartCommand = 'php bin/w server:start wls --host ' . $host
    . ' --port ' . (string)$port
    . ' --ssl-domain ' . $host;
$liveE2eCommand = 'php ' . str_replace('/', DIRECTORY_SEPARATOR, $toolPrefix . 'local-appstore-typed-tag-live-gate.php')
    . ' --allow-live=1';

$nextActions = [];
if (
    !$checks['app_checkout_is_framework_official_app']
    || !$checks['app_checkout_has_platform_appstore_module']
    || !$checks['app_checkout_has_appstore_module']
) {
    $nextActions[] = [
        'id' => 'select_local_appstore_checkout',
        'phase' => 'local_appstore_checkout_identity',
        'blocked_checks' => array_values(array_intersect($blockers, [
            'app_checkout_is_framework_official_app',
            'app_checkout_has_platform_appstore_module',
            'app_checkout_has_appstore_module',
        ])),
        'requires_user_authorization' => false,
        'safe_to_run_now' => false,
        'required_app_root' => 'E:\\WelineFramework\\Framework-Official\\App\\weline',
        'required_modules' => [
            'app/code/Weline/PlatformAppStore',
            'app/code/Weline/AppStore',
        ],
        'side_effects' => 'read-only identity gate: local live E2E must use the local App marketplace checkout, not the official website project',
    ];
}
if (
    !$checks['app_env_deploy_mode_local']
    || !$checks['app_env_mentions_local_appstore']
    || !$checks['app_env_wls_host_matches_local_appstore']
    || !$checks['app_env_wls_port_matches_local_appstore']
    || !$checks['app_env_wls_https_enabled']
    || !$checks['app_env_wls_endpoint_matches_deploy_current']
    || !$checks['app_env_wls_endpoint_matches_probe_endpoint']
    || !$checks['local_deploy_current_readable']
    || !$checks['local_deploy_current_environment_local']
    || !$checks['local_deploy_current_exact_appstore_root']
    || !$checks['local_deploy_current_resolves_expected_endpoint']
    || !$checks['local_deploy_current_not_www_host']
    || !$checks['local_deploy_current_matches_probe_endpoint']
) {
    $nextActions[] = [
        'id' => 'fix_local_deploy_current_marketplace_metadata',
        'phase' => 'local_appstore_endpoint_metadata',
        'blocked_checks' => array_values(array_intersect($blockers, [
            'app_env_deploy_mode_local',
            'app_env_mentions_local_appstore',
            'app_env_wls_host_matches_local_appstore',
            'app_env_wls_port_matches_local_appstore',
            'app_env_wls_https_enabled',
            'app_env_wls_endpoint_matches_deploy_current',
            'app_env_wls_endpoint_matches_probe_endpoint',
            'local_deploy_current_readable',
            'local_deploy_current_environment_local',
            'local_deploy_current_exact_appstore_root',
            'local_deploy_current_resolves_expected_endpoint',
            'local_deploy_current_not_www_host',
            'local_deploy_current_matches_probe_endpoint',
        ])),
        'requires_user_authorization' => false,
        'safe_to_run_now' => false,
        'env_path' => $envPath,
        'deploy_current' => $deployCurrentPath,
        'policy_gate_command' => $deployCurrentPolicyCommand,
        'required_deploy_mode' => 'dev or local',
        'required_platform_url' => 'https://app.weline.test:9523',
        'side_effects' => 'configuration gate: local live E2E must not run until env.php and deploy-current both identify the local AppStore target',
    ];
}
if (!$checks['app_schema_has_sqlite_composite_pk_guard']) {
    $nextActions[] = [
        'id' => 'authorized_app_checkout_sync',
        'phase' => 'sync_dev_to_local_appstore',
        'blocked_checks' => ['app_schema_has_sqlite_composite_pk_guard'],
        'requires_user_authorization' => true,
        'safe_to_run_now' => false,
        'preflight_self_test_command' => $syncSelfTestCommand,
        'preflight_command' => $syncDriftCommand,
        'pre_authorization_review_command' => $authorizationReviewCommand,
        'rollback_review_command' => $syncRollbackReviewCommand,
        'post_sync_gate_command' => $syncFailCommand,
        'command_reference' => '92-local-appstore-sync-manifest.md',
        'schema_sync' => $schemaSync,
        'side_effects' => 'deferred: only run the scoped App checkout sync after explicit user authorization',
    ];
    $nextActions[] = [
        'id' => 'run_local_app_setup_after_sync',
        'phase' => 'local_appstore_setup',
        'blocked_checks' => ['app_schema_has_sqlite_composite_pk_guard'],
        'requires_user_authorization' => false,
        'safe_to_run_now' => false,
        'working_directory' => $appRoot,
        'command' => $appSetupCommand,
        'precondition' => 'run only after the scoped App checkout sync and post-sync drift gate both pass',
        'side_effects' => 'deferred: updates App checkout routes/schema/cache after the authorized sync; no token use and no API call',
        'schema_sync' => $schemaSync,
    ];
}
if (
    !$checks['official_manifest_readable']
    || !$checks['official_manifest_has_wls_positive']
    || !$checks['official_manifest_has_negative_canary']
    || !$checks['official_manifest_negative_canary_exact']
) {
    $nextActions[] = [
        'id' => 'prepare_official_manifest',
        'phase' => 'official_appstore_manifest',
        'blocked_checks' => array_values(array_intersect($blockers, [
            'official_manifest_readable',
            'official_manifest_has_wls_positive',
            'official_manifest_has_negative_canary',
            'official_manifest_negative_canary_exact',
        ])),
        'requires_user_authorization' => true,
        'safe_to_run_now' => false,
        'dry_run_command' => (string)($officialManifestMaterialize['summary']['dry_run_command'] ?? ''),
        'authorized_write_command' => (string)($officialManifestMaterialize['summary']['authorized_write_command'] ?? ''),
        'authorized_source_write_command' => (string)($officialManifestMaterialize['summary']['authorized_source_write_command'] ?? ''),
        'authorized_catalog_write_command' => (string)($officialManifestMaterialize['summary']['authorized_catalog_write_command'] ?? ''),
        'post_write_gate_command' => $officialManifestValidateCommand,
        'side_effects' => 'deferred: the dry-run is read-only; the write commands create only official-apps/manifest.json and official-apps/modules/* after authorization',
    ];
}
if (!$checks['app_port_listening']) {
    $nextActions[] = [
        'id' => 'start_local_app_wls',
        'phase' => 'local_appstore_runtime',
        'blocked_checks' => ['app_port_listening'],
        'requires_user_authorization' => false,
        'safe_to_run_now' => false,
        'working_directory' => $appRoot,
        'command' => $wlsStartCommand,
        'side_effects' => 'deferred: start only after the App checkout sync, setup, and official manifest gates pass',
    ];
}
if (!$checks['bearer_token_env_present']) {
    $nextActions[] = [
        'id' => 'set_local_marketplace_bearer_token',
        'phase' => 'local_appstore_auth',
        'blocked_checks' => ['bearer_token_env_present'],
        'requires_user_secret' => true,
        'safe_to_run_now' => false,
        'command' => '$env:WLS_MARKETPLACE_BEARER_TOKEN = \'<set outside docs>\'',
        'side_effects' => 'operator action: set token in the shell environment only; never write it to repository files',
    ];
}
if ($ready) {
    $nextActions[] = [
        'id' => 'run_live_typed_tag_e2e',
        'phase' => 'local_appstore_api_e2e',
        'blocked_checks' => [],
        'requires_user_authorization' => false,
        'safe_to_run_now' => true,
        'working_directory' => $workspaceRoot,
        'command' => $liveE2eCommand,
        'side_effects' => 'network-only local API verification with redacted token source',
    ];
} else {
    $nextActions[] = [
        'id' => 'run_live_typed_tag_e2e',
        'phase' => 'local_appstore_api_e2e',
        'blocked_checks' => $blockers,
        'requires_user_authorization' => false,
        'safe_to_run_now' => false,
        'working_directory' => $workspaceRoot,
        'command' => $liveE2eCommand,
        'side_effects' => 'blocked until all readiness checks pass',
    ];
}

$payload = [
    'ok' => true,
    'ready' => $ready,
    'app_root' => $appRoot,
    'workspace_root' => $workspaceRoot,
    'endpoint' => [
        'host' => $host,
        'port' => $port,
        'url' => 'https://' . $host . ':' . $port,
        'source' => $endpointSource,
        'deploy_current' => $deployCurrentPath,
        'tcp_error' => $tcp['error'],
    ],
        'paths' => [
            'env' => $envPath,
            'app_schema' => $appSchemaPath,
            'dev_schema' => $devSchemaPath,
            'official_manifest' => $officialManifestPath,
            'platform_appstore' => $platformAppStorePath,
            'appstore' => $appStorePath,
        ],
        'checks' => $checks,
        'app_env' => [
            'deploy_mode' => $envDeployMode,
            'is_local' => $checks['app_env_deploy_mode_local'],
            'wls_endpoint' => $envWlsEndpoint,
        ],
    'local_deploy_current' => $localDeploySummary,
    'schema_sync' => $schemaSync,
    'official_manifest' => $officialManifest['summary'],
    'official_manifest_materialize' => $officialManifestMaterialize['summary'],
    'blockers' => $blockers,
    'next_actions' => $nextActions,
    'warnings' => [
        'unrelated_admin_status_present' => $adminStatus !== [],
    ],
    'notes' => [
        'git_status_enabled' => $gitStatusEnabled,
        'unrelated_admin_status' => $adminStatus,
        'token_value' => $checks['bearer_token_env_present'] ? '<redacted>' : '<not set>',
        'side_effects' => 'read-only probe: no setup, no WLS start, no sync, no repository writes',
    ],
];

if ($actionPlanOnly) {
    $payload = [
        'ok' => true,
        'ready' => $ready,
        'mode' => 'action-plan-only',
        'app_root' => $appRoot,
        'workspace_root' => $workspaceRoot,
        'endpoint' => [
            'host' => $host,
            'port' => $port,
            'url' => 'https://' . $host . ':' . $port,
            'source' => $endpointSource,
            'deploy_current' => $deployCurrentPath,
        ],
        'checks' => $checks,
        'app_env' => [
            'deploy_mode' => $envDeployMode,
            'is_local' => $checks['app_env_deploy_mode_local'],
            'wls_endpoint' => $envWlsEndpoint,
        ],
        'local_deploy_current' => $localDeploySummary,
        'schema_sync' => $schemaSync,
        'blockers' => $blockers,
        'next_actions' => $nextActions,
        'notes' => [
            'git_status_enabled' => $gitStatusEnabled,
            'token_value' => $checks['bearer_token_env_present'] ? '<redacted>' : '<not set>',
            'side_effects' => 'read-only action plan: no setup, no WLS start, no sync, no token writes, no API calls, no repository writes',
        ],
    ];
}

wlsPanelReadinessFinish($payload, $ready ? 0 : WLS_PANEL_APPSTORE_READINESS_NOT_READY);
