<?php

declare(strict_types=1);

namespace Weline\Framework\UnitTest\Console\E2e;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\Service\TestCollectionService;

class Run extends CommandAbstract
{
    private const COLLECTED_TESTS_FILE = 'collected-tests.json';

    public function execute(array $args = [], array $data = []): int
    {
        if (Env::system('deploy') !== 'dev') {
            $this->printer->setup(__('非开发环境禁止运行！如你确认是dev环境，请运行 php bin/w deploy:model:set dev 后重试。'));
            return 1;
        }

        $e2eDir = BP . 'tests' . DS . 'e2e';
        if (!is_dir($e2eDir)) {
            $this->printer->error(__('未找到 E2E 目录：%{1}', [$e2eDir]));
            return 1;
        }

        $control = $this->parseControlOptions($args);
        $collected = $this->loadCollectedTests($e2eDir, $control['refresh_collection']);
        if ($control['list_modules']) {
            return $this->printModules($collected);
        }

        $extraEnv = [];
        if ($control['spec'] === '') {
            $filesToRun = [];
            if ($control['module'] !== '') {
                $filesToRun = $this->resolveModuleFiles($control['module'], $collected);
                if ($filesToRun === []) {
                    $this->printer->warning(__('模块 %{1} 未找到 E2E 用例，退出。', [$control['module']]));
                    return 1;
                }
                $extraEnv['MODULE_FILTER'] = $control['module'];
            } elseif (isset($collected['all_test_files']) && is_array($collected['all_test_files'])) {
                $filesToRun = array_values(array_filter(
                    $collected['all_test_files'],
                    static fn($file): bool => is_string($file) && $file !== ''
                ));
            }

            if ($filesToRun === []) {
                $this->printer->warning(__('未找到可运行的 E2E 用例，退出。'));
                return 1;
            }
            $extraEnv['PLAYWRIGHT_TEST_FILES'] = json_encode(array_values($filesToRun), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
        }

        $playwrightArgs = $this->buildPlaywrightArgs($args, $control);
        if ($control['headless']) {
            $extraEnv['PLAYWRIGHT_HEADLESS'] = '1';
        }

        if (!$this->hasAnyHeadMode($playwrightArgs, $control['headless'])) {
            $playwrightArgs[] = '--headed';
        }

        if (!$this->ensurePlaywrightRuntime($e2eDir)) {
            return 1;
        }

        $command = $this->buildPlaywrightCommand($e2eDir, $playwrightArgs, $extraEnv);

        $this->printer->note(__('工作目录：%{1}', [$e2eDir]));
        $this->printer->note(__('执行命令：%{1}', [$command]));
        echo "\n";

        $exitCode = $this->runPlaywrightCommand($e2eDir, $playwrightArgs, $extraEnv, $command);

        echo "\n";
        if ($exitCode === 0) {
            $this->printer->success(__('E2E 测试执行成功。'));
        } else {
            $this->printer->error(__('E2E 测试执行失败，退出码：%{1}', [$exitCode]));
        }

        return (int)$exitCode;
    }

    public function tip(): string
    {
        return __('运行 Playwright E2E（自动在 tests/e2e 目录执行）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'e2e:run [spec] [options]',
            __('直接通过框架命令驱动 Playwright E2E，无需手动 cd tests/e2e。'),
            [
                '[spec]' => __('可选，指定测试文件或目录，例如 app/code/Weline/Theme/test/e2e/backend/theme-editor-preview.spec.js'),
                '--project=NAME' => __('指定 Playwright project，如 chromium/firefox/webkit'),
                '--module=Vendor_Module' => __('只运行指定模块（基于 collected-tests.json 精准过滤）'),
                '--case="用例标题关键字"' => __('按测试标题关键词筛选（映射为 --grep）'),
                '--case-id=ID' => __('按 `[case:ID]` 标签筛选，推荐新用例使用该风格'),
                '--list-modules' => __('列出可运行模块及文件数量'),
                '--refresh-collection' => __('强制刷新测试收集映射'),
                '--headed' => __('有界面模式（默认会自动追加）'),
                '--headless' => __('无界面模式（设置 PLAYWRIGHT_HEADLESS=1）'),
                '--ui' => __('Playwright UI 模式'),
                '--grep=PATTERN' => __('按测试名过滤'),
                '--workers=N' => __('并发 worker 数'),
            ],
            [],
            [
                __('运行全部 E2E') => 'php bin/w e2e:run',
                __('运行单个后端用例') => 'php bin/w e2e:run app/code/Weline/Theme/test/e2e/backend/theme-editor-preview.spec.js --project=chromium',
                __('按模块运行') => 'php bin/w e2e:run --module=WeShop_Cart --project=chromium',
                __('按用例标题运行') => 'php bin/w e2e:run --module=WeShop_Cart --case="remove item" --project=chromium',
                __('按用例 ID 运行') => 'php bin/w e2e:run --module=WeShop_Cart --case-id=CART-REMOVE-001 --project=chromium',
                __('列模块') => 'php bin/w e2e:run --list-modules',
                __('UI 调试') => 'php bin/w e2e:run --ui --project=chromium',
            ]
        );
    }

    /**
     * 将框架命令参数透传为 Playwright CLI 参数。
     */
    private function buildPlaywrightArgs(array $args, array $control): array
    {
        $result = [];
        $aliasMap = [
            'p' => 'project',
            'g' => 'grep',
            'w' => 'workers',
        ];
        $skipKeys = [
            'h' => true,
            'help' => true,
            'command' => true,
            'module' => true,
            'm' => true,
            'headless' => true,
            'case' => true,
            'case_id' => true,
            'case-id' => true,
            'spec' => true,
            'file' => true,
            'list_modules' => true,
            'list-modules' => true,
            'refresh_collection' => true,
            'refresh-collection' => true,
            'rc' => true,
        ];
        $hasGrep = false;

        foreach ($args as $key => $value) {
            if (is_int($key)) {
                if (is_string($value) && $control['spec'] !== '' && trim($value) === $control['spec']) {
                    continue;
                }
                if (is_string($value) && $value !== '' && !str_starts_with($value, '-') && !str_contains($value, ':')) {
                    $result[] = $value;
                }
                continue;
            }

            if (!is_string($key)) {
                continue;
            }

            $normalizedKey = ltrim($key, '-');
            if (isset($skipKeys[$normalizedKey])) {
                continue;
            }
            if ($normalizedKey === '' || str_contains($normalizedKey, ':')) {
                continue;
            }
            if (isset($aliasMap[$normalizedKey])) {
                $normalizedKey = $aliasMap[$normalizedKey];
            }

            if ($value === true || $value === 'true') {
                $result[] = '--' . $normalizedKey;
                if ($normalizedKey === 'grep') {
                    $hasGrep = true;
                }
                continue;
            }

            if ($value === false || $value === 'false' || $value === null) {
                continue;
            }

            $result[] = '--' . $normalizedKey . '=' . (string)$value;
            if ($normalizedKey === 'grep') {
                $hasGrep = true;
            }
        }

        if ($control['spec'] !== '') {
            $result[] = $control['spec'];
        }

        $grepPattern = $this->buildCaseGrep($control);
        if ($grepPattern !== '' && !$hasGrep) {
            $result[] = '--grep=' . $grepPattern;
        } elseif ($grepPattern !== '' && $hasGrep) {
            $this->printer->warning(__('检测到 --grep 与 --case/--case-id 同时使用，已保留你显式传入的 --grep。'));
        }

        return array_values(array_unique($result));
    }

    private function hasAnyHeadMode(array $args, bool $headless): bool
    {
        if ($headless) {
            return true;
        }
        foreach ($args as $arg) {
            if ($arg === '--headed' || $arg === '--ui') {
                return true;
            }
        }
        return false;
    }

    private function buildPlaywrightCommand(string $e2eDir, array $playwrightArgs, array $extraEnv = []): string
    {
        $nodeBinary = $this->resolveNodeBinary();
        $playwrightParts = array_merge([$nodeBinary, 'node_modules/playwright/cli.js', 'test'], $playwrightArgs);
        $playwrightCommand = implode(' ', array_map(static fn(string $part): string => escapeshellarg($part), $playwrightParts));
        $envList = [];
        if (PHP_OS_FAMILY === 'Windows') {
            $envList['PATH'] = $this->buildWindowsE2ePath($nodeBinary);
        }
        foreach ($extraEnv as $key => $value) {
            $envList[(string)$key] = (string)$value;
        }
        $envPrefix = '';
        if (PHP_OS_FAMILY === 'Windows') {
            foreach ($envList as $key => $value) {
                $envPrefix .= 'set "' . $key . '=' . $value . '" && ';
            }
            return 'cd /d ' . escapeshellarg($e2eDir) . ' && ' . $envPrefix . $playwrightCommand;
        }
        foreach ($envList as $key => $value) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$key)) {
                continue;
            }
            $envPrefix .= $key . '=' . escapeshellarg((string)$value) . ' ';
        }
        return 'cd ' . escapeshellarg($e2eDir) . ' && ' . $envPrefix . $playwrightCommand;
    }

    private function runPlaywrightCommand(string $e2eDir, array $playwrightArgs, array $extraEnv, string $fallbackCommand): int
    {
        if (PHP_OS_FAMILY !== 'Windows' || !function_exists('proc_open')) {
            $exitCode = 1;
            passthru($fallbackCommand, $exitCode);
            return (int)$exitCode;
        }

        $nodeBinary = $this->resolveNodeBinary();
        $argv = array_merge([$nodeBinary, 'node_modules/playwright/cli.js', 'test'], $playwrightArgs);
        $env = $this->buildProcessEnvironment($extraEnv);
        $env['PATH'] = $this->buildWindowsE2ePath($nodeBinary);
        $env['Path'] = $env['PATH'];

        $descriptors = [
            0 => ['file', 'php://stdin', 'r'],
            1 => ['file', 'php://stdout', 'w'],
            2 => ['file', 'php://stderr', 'w'],
        ];

        $process = @proc_open($argv, $descriptors, $pipes, $e2eDir, $env, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            $exitCode = 1;
            passthru($fallbackCommand, $exitCode);
            return (int)$exitCode;
        }

        return (int)proc_close($process);
    }

    private function ensurePlaywrightRuntime(string $e2eDir): bool
    {
        $localPlaywright = $this->joinPath($this->joinPath($e2eDir, 'node_modules'), 'playwright');
        $localPlaywrightTest = $this->joinPath($this->joinPath($this->joinPath($e2eDir, 'node_modules'), '@playwright'), 'test');
        if (is_file($this->joinPath($localPlaywright, 'cli.js')) && is_file($this->joinPath($localPlaywrightTest, 'index.js'))) {
            return true;
        }

        $runtimePlaywright = $this->resolvePlaywrightPackageDir($e2eDir);
        if ($runtimePlaywright === null) {
            $this->printer->error(__('未找到 Playwright 运行时。请在 tests/e2e 安装依赖，或设置 WELINE_E2E_PLAYWRIGHT_DIR 指向包含 cli.js 的 playwright 包目录。'));
            return false;
        }

        $nodeModules = $this->joinPath($e2eDir, 'node_modules');
        if (!is_dir($nodeModules) && !@mkdir($nodeModules, 0775, true) && !is_dir($nodeModules)) {
            $this->printer->error(__('无法创建 E2E node_modules 目录：%{1}', [$nodeModules]));
            return false;
        }

        if (!$this->preparePlaywrightPackageShim($localPlaywright, $runtimePlaywright)) {
            return false;
        }

        if (!is_dir($localPlaywrightTest) && !@mkdir($localPlaywrightTest, 0775, true) && !is_dir($localPlaywrightTest)) {
            $this->printer->error(__('无法创建 @playwright/test shim 目录：%{1}', [$localPlaywrightTest]));
            return false;
        }

        $packageJson = $this->joinPath($localPlaywrightTest, 'package.json');
        $indexJs = $this->joinPath($localPlaywrightTest, 'index.js');
        if (!is_file($packageJson)) {
            @file_put_contents($packageJson, "{\"name\":\"@playwright/test\",\"main\":\"index.js\"}\n");
        }
        if (!is_file($indexJs)) {
            @file_put_contents($indexJs, "module.exports = require('playwright/test');\n");
        }

        return is_file($this->joinPath($localPlaywright, 'cli.js')) && is_file($indexJs);
    }

    private function preparePlaywrightPackageShim(string $localPlaywright, string $runtimePlaywright): bool
    {
        if (is_file($this->joinPath($localPlaywright, 'cli.js'))) {
            return true;
        }

        if (!file_exists($localPlaywright)) {
            if (@symlink($runtimePlaywright, $localPlaywright)) {
                return true;
            }
        }

        if (!is_dir($localPlaywright) && !@mkdir($localPlaywright, 0775, true) && !is_dir($localPlaywright)) {
            $this->printer->error(__('无法创建 playwright shim 目录：%{1}', [$localPlaywright]));
            return false;
        }

        $targetCli = $this->joinPath($runtimePlaywright, 'cli.js');
        $targetTest = $this->joinPath($runtimePlaywright, 'test.js');
        $targetIndex = $this->joinPath($runtimePlaywright, 'index.js');
        $shims = [
            'cli.js' => $targetCli,
            'test.js' => $targetTest,
            'index.js' => $targetIndex,
        ];
        foreach ($shims as $file => $target) {
            if (!is_file($target)) {
                continue;
            }
            $content = "module.exports = require(" . json_encode($target, JSON_UNESCAPED_SLASHES) . ");\n";
            @file_put_contents($this->joinPath($localPlaywright, $file), $content);
        }

        return is_file($this->joinPath($localPlaywright, 'cli.js'));
    }

    private function resolvePlaywrightPackageDir(string $e2eDir): ?string
    {
        $candidates = [];
        foreach (['WELINE_E2E_PLAYWRIGHT_DIR', 'PLAYWRIGHT_PACKAGE_DIR'] as $envName) {
            $configured = trim((string)getenv($envName));
            if ($configured !== '') {
                $candidates[] = $configured;
            }
        }

        $candidates[] = $this->joinPath($this->joinPath($e2eDir, 'node_modules'), 'playwright');
        $candidates[] = BP . 'node_modules' . DS . 'playwright';
        $home = trim((string)getenv('HOME'));
        if ($home !== '') {
            $candidates[] = $home . DS . '.cache' . DS . 'codex-runtimes' . DS . 'codex-primary-runtime' . DS . 'dependencies' . DS . 'node' . DS . 'node_modules' . DS . 'playwright';
        }

        foreach ($candidates as $candidate) {
            $candidate = rtrim($candidate, "\\/");
            if ($candidate !== '' && is_file($this->joinPath($candidate, 'cli.js'))) {
                return $candidate;
            }
        }

        return null;
    }

    private function buildProcessEnvironment(array $extraEnv): array
    {
        $env = [];
        $current = getenv();
        if (is_array($current)) {
            foreach ($current as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $env[$key] = (string)$value;
                }
            }
        }

        foreach ($_ENV as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $env[$key] = (string)$value;
            }
        }

        foreach ($extraEnv as $key => $value) {
            $env[(string)$key] = (string)$value;
        }

        return $env;
    }

    private function buildWindowsE2ePath(string $nodeBinary): string
    {
        $paths = [];
        $systemRoot = rtrim((string)(getenv('SystemRoot') ?: 'C:\\WINDOWS'), "\\/");
        $paths[] = $this->joinPath($systemRoot, 'System32');
        $paths[] = $systemRoot;
        if ($nodeBinary !== '' && is_file($nodeBinary)) {
            $paths[] = dirname($nodeBinary);
        }
        $paths[] = BP . 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'php';
        $paths[] = BP . 'bin';

        $unique = [];
        foreach ($paths as $path) {
            $path = trim((string)$path);
            if ($path === '' || isset($unique[strtolower($path)])) {
                continue;
            }
            $unique[strtolower($path)] = $path;
        }

        return implode(PATH_SEPARATOR, array_values($unique));
    }

    private function resolveNodeBinary(): string
    {
        foreach (['WELINE_E2E_NODE', 'PLAYWRIGHT_NODE'] as $envName) {
            $configured = trim((string)getenv($envName));
            if ($this->isUsableNodeBinary($configured)) {
                return $configured;
            }
        }

        $binaryName = PHP_OS_FAMILY === 'Windows' ? 'node.exe' : 'node';
        $pathNode = $this->resolveCommandFromPath($binaryName);
        if ($pathNode !== null) {
            return $pathNode;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $windowsCandidates = [
                $this->joinPath((string)getenv('NVM_SYMLINK'), 'node.exe'),
                $this->joinPath('C:\\nvm4w\\nodejs', 'node.exe'),
                $this->joinPath('C:\\Program Files\\nodejs', 'node.exe'),
            ];
            foreach ($windowsCandidates as $candidate) {
                if ($this->isUsableNodeBinary($candidate)) {
                    return $candidate;
                }
            }
        }

        return $binaryName;
    }

    private function resolveCommandFromPath(string $binaryName): ?string
    {
        $path = (string)(getenv('PATH') ?: getenv('Path') ?: '');
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $dir = trim($this->expandEnvPlaceholders($dir), " \t\n\r\0\x0B\"'");
            if ($dir === '' || str_contains($dir, '%PATH%')) {
                continue;
            }

            $candidate = $this->joinPath($dir, $binaryName);
            if ($this->isUsableNodeBinary($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function expandEnvPlaceholders(string $path): string
    {
        if (PHP_OS_FAMILY !== 'Windows' || !str_contains($path, '%')) {
            return $path;
        }

        return (string)preg_replace_callback(
            '/%([^%]+)%/',
            static function (array $matches): string {
                $value = getenv($matches[1]);
                return is_string($value) && $value !== '' ? $value : $matches[0];
            },
            $path
        );
    }

    private function joinPath(string $dir, string $file): string
    {
        $dir = trim($dir);
        if ($dir === '') {
            return '';
        }

        return rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . $file;
    }

    private function isUsableNodeBinary(string $candidate): bool
    {
        return $candidate !== '' && is_file($candidate);
    }

    private function parseControlOptions(array $args): array
    {
        $pick = static function (array $source, array $keys): string {
            foreach ($keys as $key) {
                if (!isset($source[$key])) {
                    continue;
                }
                $value = trim((string)$source[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
            return '';
        };

        $spec = $pick($args, ['spec', 'file']);
        if ($spec === '') {
            foreach ($args as $value) {
                if (!is_string($value)) {
                    continue;
                }
                $candidate = trim($value);
                if ($candidate === '' || str_starts_with($candidate, '-')) {
                    continue;
                }
                $absolute = str_starts_with($candidate, DIRECTORY_SEPARATOR)
                    ? $candidate
                    : BP . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate), DIRECTORY_SEPARATOR);
                if (str_ends_with($candidate, '.spec.js') || is_file($absolute) || is_dir($absolute)) {
                    $spec = $candidate;
                    break;
                }
            }
        }

        return [
            'module' => $pick($args, ['module', 'm']),
            'headless' => isset($args['headless']),
            'case' => $pick($args, ['case']),
            'case_id' => $pick($args, ['case_id', 'case-id']),
            'spec' => $spec,
            'list_modules' => isset($args['list_modules']) || isset($args['list-modules']),
            'refresh_collection' => isset($args['refresh_collection']) || isset($args['refresh-collection']) || isset($args['rc']),
        ];
    }

    private function loadCollectedTests(string $e2eDir, bool $refresh): array
    {
        $file = $e2eDir . DS . self::COLLECTED_TESTS_FILE;
        /** @var TestCollectionService $collector */
        $collector = ObjectManager::getInstance(TestCollectionService::class);
        $manifest = $collector->collectE2eManifest();
        if (!$collector->writeJson($manifest, $file)) {
            $this->printer->warning(__('测试清单写入失败，模块过滤可能不可用。'));
            return $manifest;
        }

        return $manifest;
    }

    private function resolveModuleFiles(string $module, array $collected): array
    {
        if (!isset($collected['modules']) || !is_array($collected['modules'])) {
            return [];
        }
        $moduleNode = $collected['modules'][$module] ?? null;
        if (!is_array($moduleNode)) {
            return [];
        }
        $files = $moduleNode['test_files'] ?? [];
        if (!is_array($files)) {
            return [];
        }
        return array_values(array_filter($files, static fn($item): bool => is_string($item) && $item !== ''));
    }

    private function buildCaseGrep(array $control): string
    {
        if ($control['case_id'] !== '') {
            return '\[case:' . preg_quote($control['case_id'], '/') . '\]';
        }
        if ($control['case'] !== '') {
            return $control['case'];
        }
        return '';
    }

    private function printModules(array $collected): int
    {
        $modules = $collected['modules'] ?? null;
        if (!is_array($modules) || $modules === []) {
            $this->printer->warning(__('未发现模块测试映射，请先执行：php bin/w e2e:run --refresh-collection --list-modules'));
            return 1;
        }

        $this->printer->note(__('可用 E2E 模块列表：'));
        foreach ($modules as $name => $meta) {
            $count = is_array($meta) ? (int)($meta['count'] ?? 0) : 0;
            $this->printer->note(' - ' . $name . ' (' . $count . ')');
        }
        return 0;
    }
}
