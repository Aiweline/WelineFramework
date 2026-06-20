<?php

declare(strict_types=1);

namespace Weline\Framework\UnitTest\Console\E2e;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

class Run extends CommandAbstract
{
    private const COLLECT_SCRIPT = 'collect-tests.js';
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
        $collected = [];
        if ($control['module'] !== '' || $control['list_modules']) {
            $collected = $this->loadCollectedTests($e2eDir, $control['refresh_collection']);
        }
        if ($control['list_modules']) {
            return $this->printModules($collected);
        }

        $extraEnv = [];
        if ($control['module'] !== '' && $control['spec'] === '') {
            $moduleFiles = $this->resolveModuleFiles($control['module'], $collected);
            // Fallback: if no dedicated tests, search shared specs by module name pattern
            if ($moduleFiles === [] && isset($collected['all_test_files']) && is_array($collected['all_test_files'])) {
                $escapedModule = preg_quote($control['module'], '/');
                $pattern = "/(?:^|[\\/\\\\]){$escapedModule}(?:$|[-.\/\\\\])/i";
                $moduleFiles = array_values(array_filter(
                    $collected['all_test_files'],
                    static fn(string $f) => preg_match($pattern, $f) === 1
                ));
            }
            if ($moduleFiles === []) {
                $this->printer->warning(__('模块 %{1} 未找到 E2E 用例，退出。', [$control['module']]));
                return 1;
            }
            $extraEnv['MODULE_FILTER'] = $control['module'];
        }

        $playwrightArgs = $this->buildPlaywrightArgs($args, $control);
        if ($control['headless']) {
            $extraEnv['PLAYWRIGHT_HEADLESS'] = '1';
        }

        if (!$this->hasAnyHeadMode($playwrightArgs, $control['headless'])) {
            $playwrightArgs[] = '--headed';
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
                '[spec]' => __('可选，指定测试文件或目录，例如 specs/backend/WeShop_Cart-smoke-backend.spec.js'),
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
                __('运行单个后端用例') => 'php bin/w e2e:run specs/backend/WeShop_Cart-smoke-backend.spec.js --project=chromium',
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

            if ($value === true || $value === 'true' || $value === 1 || $value === '1') {
                $result[] = '--' . $normalizedKey;
                if ($normalizedKey === 'grep') {
                    $hasGrep = true;
                }
                continue;
            }

            if ($value === false || $value === 'false' || $value === 0 || $value === '0' || $value === null) {
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
        foreach ($envList as $key => $value) {
            $envPrefix .= 'set "' . $key . '=' . $value . '" && ';
        }
        if (PHP_OS_FAMILY === 'Windows') {
            return 'cd /d ' . escapeshellarg($e2eDir) . ' && ' . $envPrefix . $playwrightCommand;
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

    private function buildCollectCommand(string $e2eDir): string
    {
        $nodeBinary = $this->resolveNodeBinary();
        $collectParts = [$nodeBinary, self::COLLECT_SCRIPT];
        $collectCommand = implode(' ', array_map(static fn(string $part): string => escapeshellarg($part), $collectParts));
        $envPrefix = PHP_OS_FAMILY === 'Windows'
            ? 'set "PATH=' . $this->buildWindowsE2ePath($nodeBinary) . '" && '
            : '';
        if (PHP_OS_FAMILY === 'Windows') {
            return 'cd /d ' . escapeshellarg($e2eDir) . ' && ' . $envPrefix . $collectCommand;
        }
        return 'cd ' . escapeshellarg($e2eDir) . ' && ' . $collectCommand;
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

        return [
            'module' => $pick($args, ['module', 'm']),
            'headless' => isset($args['headless']),
            'case' => $pick($args, ['case']),
            'case_id' => $pick($args, ['case_id', 'case-id']),
            'spec' => $pick($args, ['spec', 'file']),
            'list_modules' => isset($args['list_modules']) || isset($args['list-modules']),
            'refresh_collection' => isset($args['refresh_collection']) || isset($args['refresh-collection']) || isset($args['rc']),
        ];
    }

    private function loadCollectedTests(string $e2eDir, bool $refresh): array
    {
        $file = $e2eDir . DS . self::COLLECTED_TESTS_FILE;
        if ($refresh || !is_file($file)) {
            $collectCommand = $this->buildCollectCommand($e2eDir);
            $code = 1;
            passthru($collectCommand, $code);
            if ($code !== 0) {
                $this->printer->warning(__('测试收集脚本执行失败，模块过滤可能不可用。'));
            }
        }

        if (!is_file($file)) {
            return [];
        }

        $content = @file_get_contents($file);
        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
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

