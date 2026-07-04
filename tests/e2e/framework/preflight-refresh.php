<?php
declare(strict_types=1);

use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Registry\Service\RegistryUpdateService;
use Weline\Framework\Router\Service\RouteUpdateService;
use Weline\Framework\UnitTest\Service\TestCollectionService;

require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

function preflightOut(string $message): void
{
    fwrite(STDOUT, "[e2e preflight] {$message}\n");
}

function preflightErr(string $message): void
{
    fwrite(STDERR, "[e2e preflight] {$message}\n");
}

function isVerbosePreflight(): bool
{
    return (getenv('PLAYWRIGHT_PREFLIGHT_VERBOSE') ?: '') === '1';
}

function runBufferedStep(callable $callback): void
{
    ob_start();

    try {
        $callback();
        $output = trim((string)ob_get_clean());
        if ($output !== '' && isVerbosePreflight()) {
            fwrite(STDOUT, $output . PHP_EOL);
        }
    } catch (Throwable $throwable) {
        $output = trim((string)ob_get_clean());
        if ($output !== '') {
            fwrite(STDERR, $output . PHP_EOL);
        }

        throw $throwable;
    }
}

/**
 * @return string[]
 */
function normalizeModuleNames(mixed $value): array
{
    if (is_string($value)) {
        $value = preg_split('/[\s,]+/', $value) ?: [];
    }

    if (!is_array($value)) {
        return [];
    }

    $moduleNames = [];
    foreach ($value as $item) {
        if (!is_scalar($item)) {
            continue;
        }

        $moduleName = trim((string)$item);
        if ($moduleName === '') {
            continue;
        }

        $moduleNames[$moduleName] = $moduleName;
    }

    return array_values($moduleNames);
}

/**
 * @return string[]
 */
function resolveRequestedModules(): array
{
    return normalizeModuleNames(getenv('PLAYWRIGHT_PREFLIGHT_MODULES') ?: []);
}

/**
 * @param string[] $requestedModules
 * @return string[]
 */
function resolveActiveModules(array $requestedModules): array
{
    $env = Env::getInstance();
    $activeModules = array_keys($env->getActiveModules(true));
    if ($requestedModules === []) {
        return [];
    }

    return array_values(array_intersect($requestedModules, $activeModules));
}

function resolveModuleE2eTestPath(string $basePath): ?string
{
    $candidates = [
        $basePath . DIRECTORY_SEPARATOR . 'test' . DIRECTORY_SEPARATOR . 'e2e',
        $basePath . DIRECTORY_SEPARATOR . 'Test' . DIRECTORY_SEPARATOR . 'e2e',
        $basePath . DIRECTORY_SEPARATOR . 'test' . DIRECTORY_SEPARATOR . 'E2E',
        $basePath . DIRECTORY_SEPARATOR . 'Test' . DIRECTORY_SEPARATOR . 'E2E',
    ];

    foreach ($candidates as $candidate) {
        if (is_dir($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function generateModulesJson(): int
{
    $modules = Env::getInstance()->getModuleList(true);
    $modulesData = [
        'generated_at' => date('c'),
        'modules' => [],
    ];

    foreach ($modules as $moduleName => $module) {
        $basePath = (string)($module['base_path'] ?? '');
        if ($basePath === '') {
            continue;
        }

        $testPath = resolveModuleE2eTestPath($basePath);
        $hasTests = $testPath !== null;
        $relativeBasePath = rtrim(str_replace([BP . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], ['', '/'], $basePath), '/');
        $relativeTestPath = $hasTests
            ? rtrim(str_replace([BP . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], ['', '/'], (string)$testPath), '/')
            : null;

        $modulesData['modules'][$moduleName] = [
            'name' => $moduleName,
            'base_path' => $relativeBasePath,
            'test_path' => $relativeTestPath,
            'status' => (bool)($module['status'] ?? false),
            'version' => (string)($module['version'] ?? '1.0.0'),
            'has_tests' => $hasTests,
        ];
    }

    $e2eDir = BP . 'tests' . DIRECTORY_SEPARATOR . 'e2e';
    if (!is_dir($e2eDir)) {
        mkdir($e2eDir, 0755, true);
    }

    $jsonFile = $e2eDir . DIRECTORY_SEPARATOR . 'modules.json';
    $jsonContent = json_encode($modulesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($jsonContent === false) {
        throw new RuntimeException('Failed to encode tests/e2e/modules.json.');
    }

    file_put_contents($jsonFile, $jsonContent);

    /** @var TestCollectionService $testCollector */
    $testCollector = ObjectManager::getInstance(TestCollectionService::class);
    $testCollector->writeJson(
        $testCollector->collectE2eManifest(),
        $e2eDir . DIRECTORY_SEPARATOR . 'collected-tests.json'
    );

    return count($modulesData['modules']);
}

/**
 * @param string[] $moduleNames
 */
function refreshRegistries(array $moduleNames): void
{
    /** @var RegistryUpdateService $registryService */
    $registryService = ObjectManager::getInstance(RegistryUpdateService::class);

    preflightOut(
        $moduleNames === []
            ? 'Refreshing framework registries.'
            : 'Refreshing framework registries for modules: ' . implode(', ', $moduleNames)
    );

    $ok = false;
    runBufferedStep(static function () use ($registryService, $moduleNames, &$ok): void {
        $ok = $moduleNames === []
            ? $registryService->updateAllRegistries(true, false, true)
            : $registryService->updateModuleRegistriesIncremental($moduleNames, true);
    });

    if (!$ok) {
        throw new RuntimeException('Registry refresh reported partial failure.');
    }

    if ($moduleNames === []) {
        /** @var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $taglibEventData = [
            'module_names' => [],
            'skip_template_cache_clear' => false,
            'result' => null,
        ];

        preflightOut('Collecting taglib registry.');
        runBufferedStep(static function () use ($eventsManager, &$taglibEventData): void {
            $eventsManager->dispatch('Weline_Framework_Setup::collect_taglib_registry', $taglibEventData);
        });

        $taglibResult = $taglibEventData['result'] ?? null;
        if (is_array($taglibResult) && isset($taglibResult['success']) && !$taglibResult['success']) {
            $message = (string)($taglibResult['message'] ?? 'Unknown taglib collection error.');
            throw new RuntimeException('Taglib registry collection failed: ' . $message);
        }
    }
}

/**
 * @param string[] $moduleNames
 */
function refreshRoutes(array $moduleNames): void
{
    /** @var EventsManager $eventsManager */
    $eventsManager = ObjectManager::getInstance(EventsManager::class);
    /** @var RouteUpdateService $routeService */
    $routeService = ObjectManager::getInstance(RouteUpdateService::class);

    preflightOut(
        $moduleNames === []
            ? 'Refreshing routes, menus, and ACL.'
            : 'Refreshing routes, menus, and ACL for modules: ' . implode(', ', $moduleNames)
    );

    runBufferedStep(static function () use ($eventsManager, $routeService, $moduleNames): void {
        $beforeEventData = [];
        $eventsManager->dispatch('Weline_Framework_Setup::before_route_collection', $beforeEventData);
        $routeService->updateRoutes($moduleNames);
        $afterEventData = [];
        $eventsManager->dispatch('Weline_Framework_Setup::after_route_collection', $afterEventData);
    });
}

try {
    $requestedModules = resolveRequestedModules();
    $moduleNames = resolveActiveModules($requestedModules);

    if ($requestedModules !== [] && $moduleNames === []) {
        throw new RuntimeException(
            'No active modules matched PLAYWRIGHT_PREFLIGHT_MODULES=' . implode(',', $requestedModules)
        );
    }

    $env = Env::getInstance();
    $env->getModuleList(true);
    $env->getActiveModules(true);

    refreshRegistries($moduleNames);
    refreshRoutes($moduleNames);

    $moduleCount = generateModulesJson();
    preflightOut("Refreshed tests/e2e/modules.json for {$moduleCount} modules.");
    preflightOut('Framework preflight refresh completed.');
    exit(0);
} catch (Throwable $throwable) {
    preflightErr($throwable->getMessage());
    if ((getenv('PLAYWRIGHT_PREFLIGHT_DEBUG') ?: '') === '1') {
        preflightErr($throwable->getTraceAsString());
    }
    exit(1);
}
