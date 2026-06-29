<?php
declare(strict_types=1);

/**
 * Validates the source-code contract behind WLS Panel marketplace endpoint
 * resolution.
 *
 * This is a read-only static gate. It does not start WLS, sync AppStore files,
 * read tokens, call the network, or write deployment artifacts.
 */

const WLS_PANEL_ENDPOINT_SOURCE_CONTRACT_EXIT_ASSERTION_FAILED = 1;
const WLS_PANEL_ENDPOINT_SOURCE_CONTRACT_LOCAL_URL = 'https://app.weline.test:9523';
const WLS_PANEL_ENDPOINT_SOURCE_CONTRACT_PRODUCTION_URL = 'https://app.aiweline.com';

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function wlsPanelEndpointSourceParseArgs(array $argv): array
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
function wlsPanelEndpointSourceFinish(array $payload, int $exitCode): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($exitCode);
}

function wlsPanelEndpointSourcePath(string $base, string $relative): string
{
    return rtrim($base, "\\/") . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
}

function wlsPanelEndpointSourceRead(string $path, array &$errors): string
{
    if (!is_file($path) || !is_readable($path)) {
        $errors[] = 'file_unreadable:' . $path;
        return '';
    }

    return (string)file_get_contents($path);
}

/**
 * @param array<string, string> $sources
 */
function wlsPanelEndpointSourceHasNoWwwMarketplaceHost(array $sources): bool
{
    foreach ($sources as $source) {
        if (str_contains($source, 'www.aiweline.com') || str_contains($source, 'www.weline.test:9518')) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string, string> $sources
 * @return array<string, bool>
 */
function wlsPanelEndpointSourceChecks(array $sources): array
{
    $deploy = $sources['deploy_orchestrator'] ?? '';
    $resolver = $sources['appstore_resolver'] ?? '';
    $account = $sources['account_bind'] ?? '';
    $installer = $sources['module_installer'] ?? '';
    $panel = $sources['wls_panel'] ?? '';
    $appStoreIndexController = $sources['appstore_index_controller'] ?? '';
    $appStoreInstalledController = $sources['appstore_installed_controller'] ?? '';
    $appStoreIndexTemplate = $sources['appstore_index_template'] ?? '';
    $appStoreInstalledTemplate = $sources['appstore_installed_template'] ?? '';

    return [
        'deploy_has_local_appstore_constant' => str_contains($deploy, WLS_PANEL_ENDPOINT_SOURCE_CONTRACT_LOCAL_URL),
        'deploy_has_production_appstore_constant' => str_contains($deploy, WLS_PANEL_ENDPOINT_SOURCE_CONTRACT_PRODUCTION_URL),
        'deploy_writes_release_current_metadata' => substr_count($deploy, "'appstore_platform_url'") >= 2
            && substr_count($deploy, "'appstore_environment'") >= 2
            && substr_count($deploy, "'deploy_mode_source'") >= 2
            && substr_count($deploy, "'appstore_platform_url_source'") >= 2,
        'deploy_resolves_mode_and_appstore_info' => str_contains($deploy, 'resolveDeployModeInfo()')
            && str_contains($deploy, 'resolveAppStoreMarketplaceInfo('),
        'deploy_local_mode_allows_local_sources' => str_contains($deploy, "getenv('WELINE_APPSTORE_PLATFORM_URL')")
            && str_contains($deploy, "Env::get('appstore.platform_url')")
            && str_contains($deploy, "'source' => 'local_default'"),
        'deploy_local_mode_locks_to_app_weline' => str_contains($deploy, 'normalizeLocalAppStoreMarketplacePlatformUrl(')
            && str_contains($deploy, 'normalizeAppStoreMarketplacePlatformUrl(')
            && str_contains($deploy, 'self::LOCAL_APPSTORE_PLATFORM_URL'),
        'deploy_rejects_official_website_marketplace_sources' => str_contains($deploy, 'normalizeAppStoreMarketplacePlatformUrl(')
            && str_contains($deploy, 'isOfficialWebsiteAppStorePlatformUrl('),
        'deploy_non_local_records_production_default' => str_contains($deploy, "'source' => 'production_default'")
            && str_contains($deploy, "'source' => 'production_default_missing_config'")
            && str_contains($deploy, "'environment' => 'production'"),
        'resolver_has_locked_defaults' => str_contains($resolver, "DEFAULT_PLATFORM_URL = '" . WLS_PANEL_ENDPOINT_SOURCE_CONTRACT_PRODUCTION_URL . "'")
            && str_contains($resolver, "LOCAL_PLATFORM_URL = '" . WLS_PANEL_ENDPOINT_SOURCE_CONTRACT_LOCAL_URL . "'"),
        'resolver_local_mode_is_explicit_only' => str_contains($resolver, 'hasExplicitLocalDeployMode()')
            && str_contains($resolver, 'resolveLocalPlatformUrl()')
            && str_contains($resolver, "\$mode === 'dev' || \$mode === 'local'"),
        'resolver_reads_deployed_production_current' => str_contains($resolver, 'readProductionDeployPlatformUrl()')
            && str_contains($resolver, "'deploy' . DS . 'current.json'")
            && str_contains($resolver, "appstore_environment")
            && str_contains($resolver, "appstore_platform_url")
            && str_contains($resolver, "deploy:var/deploy/current.json"),
        'resolver_production_current_requires_locked_app_aiweline' => str_contains($resolver, 'appstore_platform_url_source')
            && str_contains($resolver, "platformUrl !== self::DEFAULT_PLATFORM_URL")
            && str_contains($resolver, "platformUrlSource !== 'production_default'"),
        'resolver_ignores_non_production_current' => str_contains($resolver, "\$environment !== 'production'"),
        'resolver_rejects_official_website_marketplace_sources' => str_contains($resolver, 'normalizeMarketplacePlatformUrl(')
            && str_contains($resolver, 'isOfficialWebsitePlatformUrl('),
        'resolver_local_mode_locks_to_app_weline' => str_contains($resolver, 'normalizeLocalMarketplacePlatformUrl(')
            && str_contains($resolver, 'normalizeMarketplacePlatformUrl(')
            && str_contains($resolver, 'self::LOCAL_PLATFORM_URL'),
        'account_bind_uses_resolver' => str_contains($account, 'AppStorePlatformUrlResolver::DEFAULT_PLATFORM_URL')
            && str_contains($account, 'getPlatformResolution()')
            && str_contains($account, '(new AppStorePlatformUrlResolver())->resolve()'),
        'module_installer_uses_resolver' => str_contains($installer, 'AppStorePlatformUrlResolver::DEFAULT_PLATFORM_URL')
            && str_contains($installer, '(new AppStorePlatformUrlResolver())->resolveUrl()')
            && str_contains($installer, 'normalizePlatformApiBaseUrl($this->resolvePlatformUrl())'),
        'panel_exposes_resolver_state' => str_contains($panel, 'appStorePlatformResolution')
            && str_contains($panel, 'AppStorePlatformUrlResolver')
            && str_contains($panel, WLS_PANEL_ENDPOINT_SOURCE_CONTRACT_PRODUCTION_URL),
        'panel_has_locked_fallback_defaults' => str_contains($panel, "APPSTORE_PRODUCTION_PLATFORM_URL = '" . WLS_PANEL_ENDPOINT_SOURCE_CONTRACT_PRODUCTION_URL . "'")
            && str_contains($panel, "APPSTORE_LOCAL_PLATFORM_URL = '" . WLS_PANEL_ENDPOINT_SOURCE_CONTRACT_LOCAL_URL . "'"),
        'panel_fallback_keeps_panel_available' => str_contains($panel, 'resolveFallbackAppStorePlatformResolution()')
            && str_contains($panel, 'Keep the standalone panel available even when AppStore is disabled or mid-upgrade.')
            && str_contains($panel, 'Keep the WLS Panel usable even if the config layer is unavailable.'),
        'panel_fallback_local_mode_is_explicit_only' => str_contains($panel, 'hasExplicitLocalDeployMode()')
            && str_contains($panel, 'resolveFallbackLocalAppStorePlatformResolution()')
            && str_contains($panel, "\$mode === 'dev' || \$mode === 'local'"),
        'panel_fallback_allows_local_sources' => str_contains($panel, "getenv('WELINE_APPSTORE_PLATFORM_URL')")
            && str_contains($panel, "Env::get('appstore.platform_url')")
            && str_contains($panel, "'source' => 'local_default'"),
        'panel_fallback_rejects_official_website_marketplace_sources' => str_contains($panel, 'normalizeFallbackAppStorePlatformUrl(')
            && str_contains($panel, 'isOfficialWebsiteAppStorePlatformUrl('),
        'panel_fallback_local_mode_locks_to_app_weline' => str_contains($panel, 'normalizeFallbackLocalAppStorePlatformUrl(')
            && str_contains($panel, 'normalizeFallbackAppStorePlatformUrl(')
            && str_contains($panel, 'self::APPSTORE_LOCAL_PLATFORM_URL'),
        'panel_fallback_reads_deployed_production_current' => str_contains($panel, 'readProductionDeployAppStorePlatformResolution()')
            && str_contains($panel, "'deploy' . DIRECTORY_SEPARATOR . 'current.json'")
            && str_contains($panel, "appstore_environment")
            && str_contains($panel, "appstore_platform_url")
            && str_contains($panel, "deploy:var/deploy/current.json"),
        'panel_fallback_production_current_requires_locked_app_aiweline' => str_contains($panel, 'appstore_platform_url_source')
            && str_contains($panel, "platformUrl !== self::APPSTORE_PRODUCTION_PLATFORM_URL")
            && str_contains($panel, "platformUrlSource !== 'production_default'"),
        'panel_fallback_ignores_non_production_current' => str_contains($panel, "\$environment !== 'production'"),
        'appstore_index_controller_exposes_resolution_and_wls_return' => str_contains($appStoreIndexController, "assign('platform_url_resolution'")
            && str_contains($appStoreIndexController, 'getPlatformResolution()')
            && str_contains($appStoreIndexController, 'isWlsPanelReturnRequested()')
            && str_contains($appStoreIndexController, 'getWlsPanelMarketplaceUrl(')
            && str_contains($appStoreIndexController, 'redirectToWlsPanelMarketplace(')
            && str_contains($appStoreIndexController, "'wls_panel_return' => 1")
            && str_contains($appStoreIndexController, "'panel_auto_refresh' => 'plugins'"),
        'appstore_installed_controller_preserves_wls_return_on_update' => str_contains($appStoreInstalledController, 'isWlsPanelReturnRequested()')
            && str_contains($appStoreInstalledController, 'getWlsPanelMarketplaceUrl(')
            && str_contains($appStoreInstalledController, 'redirectToWlsPanelMarketplace(')
            && str_contains($appStoreInstalledController, "'wls_panel_return' => 1")
            && str_contains($appStoreInstalledController, "'panel_auto_refresh' => 'plugins'"),
        'appstore_index_template_displays_endpoint_source' => str_contains($appStoreIndexTemplate, 'data-appstore-platform-resolution')
            && str_contains($appStoreIndexTemplate, 'data-appstore-platform-url')
            && str_contains($appStoreIndexTemplate, 'data-appstore-platform-source')
            && str_contains($appStoreIndexTemplate, WLS_PANEL_ENDPOINT_SOURCE_CONTRACT_LOCAL_URL)
            && str_contains($appStoreIndexTemplate, WLS_PANEL_ENDPOINT_SOURCE_CONTRACT_PRODUCTION_URL)
            && str_contains($appStoreIndexTemplate, 'Back To WLS Panel'),
        'appstore_installed_template_preserves_wls_return_forms' => str_contains($appStoreInstalledTemplate, 'Back To WLS Panel')
            && str_contains($appStoreInstalledTemplate, 'name="wls_panel_return"')
            && str_contains($appStoreInstalledTemplate, 'value="1"')
            && str_contains($appStoreInstalledTemplate, '$wlsPanelReturn'),
        'source_has_no_www_marketplace_host' => wlsPanelEndpointSourceHasNoWwwMarketplaceHost($sources),
    ];
}

/**
 * @param array<string, bool> $checks
 * @return list<string>
 */
function wlsPanelEndpointSourceFailedChecks(array $checks): array
{
    $failed = [];
    foreach ($checks as $name => $passed) {
        if (!$passed) {
            $failed[] = 'check_failed:' . $name;
        }
    }

    return $failed;
}

/**
 * @param array<string, string> $sources
 * @return array{passed:bool,cases:list<array{name:string,passed:bool,details?:array<string,mixed>}>}
 */
function wlsPanelEndpointSourceSelfTest(array $sources): array
{
    $cases = [];

    $baselineChecks = wlsPanelEndpointSourceChecks($sources);
    $baselineErrors = wlsPanelEndpointSourceFailedChecks($baselineChecks);
    $cases[] = [
        'name' => 'current_sources_satisfy_endpoint_contract',
        'passed' => $baselineErrors === [],
        'details' => [
            'failed_checks' => $baselineErrors,
        ],
    ];

    $wwwSources = $sources;
    $wwwSources['appstore_index_template'] = ($wwwSources['appstore_index_template'] ?? '') . "\nhttps://www.aiweline.com\n";
    $wwwChecks = wlsPanelEndpointSourceChecks($wwwSources);
    $cases[] = [
        'name' => 'rejects_official_www_marketplace_host',
        'passed' => ($wwwChecks['source_has_no_www_marketplace_host'] ?? true) === false,
    ];

    $missingTemplateStrip = $sources;
    $missingTemplateStrip['appstore_index_template'] = str_replace(
        'data-appstore-platform-resolution',
        'data-panel-resolution-removed',
        $missingTemplateStrip['appstore_index_template'] ?? ''
    );
    $templateChecks = wlsPanelEndpointSourceChecks($missingTemplateStrip);
    $cases[] = [
        'name' => 'rejects_appstore_template_without_endpoint_strip',
        'passed' => ($templateChecks['appstore_index_template_displays_endpoint_source'] ?? true) === false,
    ];

    $missingIndexReturn = $sources;
    $missingIndexReturn['appstore_index_controller'] = str_replace(
        'getWlsPanelMarketplaceUrl(',
        'getPanelMarketplaceUrlRemoved(',
        $missingIndexReturn['appstore_index_controller'] ?? ''
    );
    $indexChecks = wlsPanelEndpointSourceChecks($missingIndexReturn);
    $cases[] = [
        'name' => 'rejects_appstore_index_without_wls_return_url',
        'passed' => ($indexChecks['appstore_index_controller_exposes_resolution_and_wls_return'] ?? true) === false,
    ];

    $missingInstalledReturn = $sources;
    $missingInstalledReturn['appstore_installed_controller'] = str_replace(
        'redirectToWlsPanelMarketplace(',
        'redirectToPanelMarketplaceRemoved(',
        $missingInstalledReturn['appstore_installed_controller'] ?? ''
    );
    $installedChecks = wlsPanelEndpointSourceChecks($missingInstalledReturn);
    $cases[] = [
        'name' => 'rejects_installed_update_without_wls_return_redirect',
        'passed' => ($installedChecks['appstore_installed_controller_preserves_wls_return_on_update'] ?? true) === false,
    ];

    $missingInstallerResolver = $sources;
    $missingInstallerResolver['module_installer'] = str_replace(
        '(new AppStorePlatformUrlResolver())->resolveUrl()',
        'self::DEFAULT_PLATFORM_URL',
        $missingInstallerResolver['module_installer'] ?? ''
    );
    $installerChecks = wlsPanelEndpointSourceChecks($missingInstallerResolver);
    $cases[] = [
        'name' => 'rejects_module_installer_without_resolver',
        'passed' => ($installerChecks['module_installer_uses_resolver'] ?? true) === false,
    ];

    $missingLocalLocks = $sources;
    $missingLocalLocks['deploy_orchestrator'] = str_replace(
        'normalizeLocalAppStoreMarketplacePlatformUrl(',
        'normalizeAppStoreMarketplacePlatformUrl(',
        $missingLocalLocks['deploy_orchestrator'] ?? ''
    );
    $missingLocalLocks['appstore_resolver'] = str_replace(
        'normalizeLocalMarketplacePlatformUrl(',
        'normalizeMarketplacePlatformUrl(',
        $missingLocalLocks['appstore_resolver'] ?? ''
    );
    $missingLocalLocks['wls_panel'] = str_replace(
        'normalizeFallbackLocalAppStorePlatformUrl(',
        'normalizeFallbackAppStorePlatformUrl(',
        $missingLocalLocks['wls_panel'] ?? ''
    );
    $localLockChecks = wlsPanelEndpointSourceChecks($missingLocalLocks);
    $cases[] = [
        'name' => 'rejects_runtime_without_locked_local_appstore_root',
        'passed' => ($localLockChecks['deploy_local_mode_locks_to_app_weline'] ?? true) === false
            && ($localLockChecks['resolver_local_mode_locks_to_app_weline'] ?? true) === false
            && ($localLockChecks['panel_fallback_local_mode_locks_to_app_weline'] ?? true) === false,
    ];

    $passed = $cases !== [];
    foreach ($cases as $case) {
        if (empty($case['passed'])) {
            $passed = false;
            break;
        }
    }

    return [
        'passed' => $passed,
        'cases' => $cases,
    ];
}

$args = wlsPanelEndpointSourceParseArgs($argv);
$workspaceRoot = trim((string)($args['workspace-root'] ?? dirname(__DIR__, 7)));

$relativeFiles = [
    'deploy_orchestrator' => 'app/code/Weline/Deploy/Service/DeployOrchestratorService.php',
    'appstore_resolver' => 'app/code/Weline/AppStore/Service/AppStorePlatformUrlResolver.php',
    'account_bind' => 'app/code/Weline/AppStore/Service/AccountBindService.php',
    'module_installer' => 'app/code/Weline/AppStore/Service/ModuleInstallerService.php',
    'wls_panel' => 'app/code/Weline/Server/Controller/Backend/WlsPanel.php',
    'appstore_index_controller' => 'app/code/Weline/AppStore/Controller/Backend/Index.php',
    'appstore_installed_controller' => 'app/code/Weline/AppStore/Controller/Backend/Installed.php',
    'appstore_index_template' => 'app/code/Weline/AppStore/view/templates/Backend/Index/index.phtml',
    'appstore_installed_template' => 'app/code/Weline/AppStore/view/templates/Backend/Installed/index.phtml',
];

$errors = [];
$files = [];
$sources = [];
foreach ($relativeFiles as $key => $relativeFile) {
    $path = wlsPanelEndpointSourcePath($workspaceRoot, $relativeFile);
    $files[$key] = $path;
    $sources[$key] = wlsPanelEndpointSourceRead($path, $errors);
}

if (($args['self-test'] ?? '') === '1') {
    $selfTest = wlsPanelEndpointSourceSelfTest($sources);
    wlsPanelEndpointSourceFinish([
        'passed' => $selfTest['passed'],
        'self_test' => true,
        'cases' => $selfTest['cases'],
        'files' => $files,
        'errors' => $errors,
        'side_effects' => 'read-only self-test: no network, no token, no WLS start, no writes',
    ], $selfTest['passed'] && $errors === [] ? 0 : WLS_PANEL_ENDPOINT_SOURCE_CONTRACT_EXIT_ASSERTION_FAILED);
}

$checks = wlsPanelEndpointSourceChecks($sources);
$errors = array_values(array_unique(array_merge($errors, wlsPanelEndpointSourceFailedChecks($checks))));
$passed = $errors === [];

wlsPanelEndpointSourceFinish([
    'passed' => $passed,
    'workspace_root' => $workspaceRoot,
    'files' => $files,
    'checks' => $checks,
    'errors' => $errors,
    'contract' => [
        'local_development_appstore' => WLS_PANEL_ENDPOINT_SOURCE_CONTRACT_LOCAL_URL,
        'production_deployed_appstore' => WLS_PANEL_ENDPOINT_SOURCE_CONTRACT_PRODUCTION_URL,
        'deployment_artifact' => 'var/deploy/current.json',
        'required_deployment_fields' => [
            'deploy_mode_source',
            'appstore_environment',
            'appstore_platform_url',
            'appstore_platform_url_source',
        ],
    ],
    'side_effects' => 'read-only source contract check: no network, no token, no WLS start, no writes',
], $passed ? 0 : WLS_PANEL_ENDPOINT_SOURCE_CONTRACT_EXIT_ASSERTION_FAILED);
