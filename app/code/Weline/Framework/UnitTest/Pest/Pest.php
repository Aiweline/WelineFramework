<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\UnitTest\Pest;

use Weline\Framework\App\Exception;
use Weline\Framework\App\Env;

/**
 * Pest PHP 测试框架集成类
 */
class Pest
{
    private static function isCoverageRequested(array $options): bool
    {
        foreach (['coverage', 'c', 'min', 'coverage-html', 'coverage-text', 'coverage-xml', 'coverage-clover'] as $key) {
            if (isset($options[$key])) {
                return true;
            }
        }

        return false;
    }

    private static function resolvePhpCommandForTests(bool $coverageRequested): string
    {
        if (!$coverageRequested) {
            return escapeshellarg(PHP_BINARY);
        }

        if (extension_loaded('xdebug')) {
            putenv('XDEBUG_MODE=coverage');
            $_SERVER['XDEBUG_MODE'] = 'coverage';
            return escapeshellarg(PHP_BINARY);
        }

        if (extension_loaded('pcov')) {
            return escapeshellarg(PHP_BINARY);
        }

        $phpDir = dirname(PHP_BINARY);
        $phpdbg = PHP_OS_FAMILY === 'Windows'
            ? $phpDir . DIRECTORY_SEPARATOR . 'phpdbg.exe'
            : $phpDir . DIRECTORY_SEPARATOR . 'phpdbg';

        if (is_file($phpdbg)) {
            return escapeshellarg($phpdbg) . ' -qrr';
        }

        return escapeshellarg(PHP_BINARY);
    }
    /**
     * @DESC         |初始化 Pest 测试环境
     *
     * 参数区：
     * @throws Exception
     */
    public static function init(): void
    {
        // 检查是否已定义 BP 常量
        if (!defined('BP')) {
            throw new Exception(__('请先定义项目根目录常量 BP'));
        }

        // 设置测试环境常量
        if (!defined('ENV_TEST')) {
            define('ENV_TEST', true);
        }

        // 加载 bootstrap_phpunit.php 来初始化测试环境
        $bootstrapFile = BP . 'app' . DIRECTORY_SEPARATOR . 'bootstrap_phpunit.php';
        if (file_exists($bootstrapFile)) {
            require_once $bootstrapFile;
        } else {
            // 如果不存在，则加载标准的 bootstrap.php
            require_once BP . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
        }

        // 设置测试环境变量
        $_SERVER['REQUEST_URI'] = '/test';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['QUERY_STRING'] = '';
    }

    /**
     * @DESC         |检查 Pest 是否可用
     *
     * 参数区：
     * @return bool
     */
    public static function isAvailable(): bool
    {
        // 检查 Pest 二进制文件是否存在
        $pestBinary = BP . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'pest';
        if (file_exists($pestBinary)) {
            return true;
        }
        
        // 检查是否可以通过系统命令访问
        exec('which pest 2>/dev/null', $output, $returnCode);
        if ($returnCode === 0 && !empty($output)) {
            return true;
        }
        
        return false;
    }

    /**
     * @DESC         |检查 Pest 是否支持 watch 模式
     *
     * 参数区：
     * @return bool
     */
    public static function supportsWatch(): bool
    {
        if (!self::isAvailable()) {
            return false;
        }

        // 获取 Pest 二进制文件路径
        $pestBinary = BP . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'pest';
        if (!file_exists($pestBinary)) {
            $pestBinary = 'pest';
        }

        // 尝试获取 Pest 版本
        $versionOutput = [];
        $versionCommand = escapeshellarg($pestBinary) . ' --version 2>&1';
        exec($versionCommand, $versionOutput, $returnCode);
        
        if ($returnCode === 0 && !empty($versionOutput)) {
            $versionString = implode(' ', $versionOutput);
            // 检查版本号，Pest 2.x 才支持 watch 模式
            // 匹配格式：Pest Testing Framework 2.36.0 或 Pest 2.36.0
            // 先尝试匹配 "2.36.0" 这样的版本号格式
            if (preg_match('/(\d+)\.(\d+)\.(\d+)/', $versionString, $matches)) {
                $majorVersion = (int)($matches[1] ?? 0);
                if ($majorVersion >= 2) {
                    return true;
                }
            }
            // 也尝试匹配 "Pest 2.x" 这样的格式
            if (preg_match('/Pest[^\d]*(\d+)\./', $versionString, $matches)) {
                $majorVersion = (int)($matches[1] ?? 0);
                if ($majorVersion >= 2) {
                    return true;
                }
            }
        }

        // 如果无法获取版本，尝试检查帮助信息中是否包含 --watch
        $helpOutput = [];
        $helpCommand = escapeshellarg($pestBinary) . ' --help 2>&1';
        exec($helpCommand, $helpOutput, $helpReturnCode);
        
        if ($helpReturnCode === 0) {
            $helpText = implode(' ', $helpOutput);
            return str_contains($helpText, '--watch') || str_contains($helpText, 'watch');
        }

        // 默认返回 false（Pest 1.x 不支持 watch）
        return false;
    }

    /**
     * @DESC         |运行 Pest 测试
     *
     * 参数区：
     * @param array $options 测试选项
     * @return int 退出代码
     */
    public static function run(array $options = []): int
    {
        if (!self::isAvailable()) {
            throw new Exception(__('Pest 测试框架未安装，请运行: composer require --dev pestphp/pest'));
        }

        // 初始化测试环境
        self::init();

        // 构建 Pest 命令参数（支持所有 Pest 参数）
        $pestArgs = [];
        
        // SELECTION OPTIONS（选择选项）
        if (isset($options['filter']) || isset($options['f'])) {
            $pestArgs[] = '--filter=' . escapeshellarg($options['filter'] ?? $options['f']);
        }
        
        if (isset($options['group']) || isset($options['g'])) {
            $pestArgs[] = '--group=' . escapeshellarg($options['group'] ?? $options['g']);
        }
        
        if (isset($options['exclude-group'])) {
            $pestArgs[] = '--exclude-group=' . escapeshellarg($options['exclude-group']);
        }
        
        if (isset($options['testsuite']) || isset($options['s'])) {
            $pestArgs[] = '--testsuite=' . escapeshellarg($options['testsuite'] ?? $options['s']);
        }
        
        if (isset($options['exclude-testsuite'])) {
            $pestArgs[] = '--exclude-testsuite=' . escapeshellarg($options['exclude-testsuite']);
        }
        
        if (isset($options['covers'])) {
            $pestArgs[] = '--covers=' . escapeshellarg($options['covers']);
        }
        
        if (isset($options['uses'])) {
            $pestArgs[] = '--uses=' . escapeshellarg($options['uses']);
        }
        
        if (isset($options['test-suffix'])) {
            $pestArgs[] = '--test-suffix=' . escapeshellarg($options['test-suffix']);
        }
        
        // EXECUTION OPTIONS（执行选项）
        if (isset($options['parallel']) || isset($options['p'])) {
            $pestArgs[] = '--parallel';
        }
        
        if (isset($options['bail'])) {
            $pestArgs[] = '--bail';
        }
        
        if (isset($options['retry'])) {
            $pestArgs[] = '--retry';
        }
        
        if (isset($options['stop-on-error'])) {
            $pestArgs[] = '--stop-on-error';
        }
        
        if (isset($options['stop-on-failure'])) {
            $pestArgs[] = '--stop-on-failure';
        }
        
        if (isset($options['stop-on-warning'])) {
            $pestArgs[] = '--stop-on-warning';
        }
        
        if (isset($options['stop-on-defect'])) {
            $pestArgs[] = '--stop-on-defect';
        }
        
        if (isset($options['order-by'])) {
            $pestArgs[] = '--order-by=' . escapeshellarg($options['order-by']);
        }
        
        if (isset($options['random-order-seed'])) {
            $pestArgs[] = '--random-order-seed=' . escapeshellarg($options['random-order-seed']);
        }
        
        // CODE COVERAGE OPTIONS（代码覆盖率选项）
        if (isset($options['coverage']) || isset($options['c'])) {
            $pestArgs[] = '--coverage';
        }
        
        if (isset($options['min'])) {
            $pestArgs[] = '--coverage --min=' . escapeshellarg($options['min']);
        }
        
        if (isset($options['coverage-html'])) {
            $pestArgs[] = '--coverage-html=' . escapeshellarg($options['coverage-html']);
        }
        
        if (isset($options['coverage-text'])) {
            $pestArgs[] = '--coverage-text' . (isset($options['coverage-text']) && $options['coverage-text'] !== true ? '=' . escapeshellarg($options['coverage-text']) : '');
        }
        
        if (isset($options['coverage-xml'])) {
            $pestArgs[] = '--coverage-xml=' . escapeshellarg($options['coverage-xml']);
        }
        
        if (isset($options['coverage-clover'])) {
            $pestArgs[] = '--coverage-clover=' . escapeshellarg($options['coverage-clover']);
        }
        
        // REPORTING OPTIONS（报告选项）
        if (isset($options['testdox'])) {
            $pestArgs[] = '--testdox';
        }
        
        if (isset($options['compact'])) {
            $pestArgs[] = '--compact';
        }
        
        if (isset($options['debug'])) {
            $pestArgs[] = '--debug';
        }
        
        if (isset($options['profile'])) {
            $pestArgs[] = '--profile';
        }
        
        if (isset($options['colors'])) {
            $pestArgs[] = '--colors=' . escapeshellarg($options['colors']);
        }
        
        if (isset($options['no-progress'])) {
            $pestArgs[] = '--no-progress';
        }
        
        if (isset($options['no-results'])) {
            $pestArgs[] = '--no-results';
        }
        
        // CONFIGURATION OPTIONS（配置选项）
        if (isset($options['configuration']) || isset($options['c'])) {
            // 如果同时有 coverage，c 是 coverage 的简写，优先使用 coverage
            if (!isset($options['coverage']) && !isset($options['c']) || isset($options['configuration'])) {
                $pestArgs[] = '--configuration=' . escapeshellarg($options['configuration'] ?? $options['c']);
            }
        }
        
        if (isset($options['bootstrap'])) {
            $pestArgs[] = '--bootstrap=' . escapeshellarg($options['bootstrap']);
        }
        
        if (isset($options['cache-directory'])) {
            $pestArgs[] = '--cache-directory=' . escapeshellarg($options['cache-directory']);
        }
        
        // 获取测试目录或文件路径
        // 支持模块收集、文件收集、套件收集（结合之前的测试收集逻辑）
        // 注意：如果使用了 --configuration 参数，Pest 会从 XML 配置文件中读取测试，不需要再收集测试路径
        $testPath = null;
        $hasConfiguration = isset($options['configuration']) || isset($options['c']);
        
        if (!$hasConfiguration) {
            // 只有在没有使用 XML 配置文件时才收集测试路径
            if (isset($options['path'])) {
                $testPath = $options['path'];
            } elseif (isset($options['module'])) {
                // 模块模式：收集指定模块的测试
                $testPath = self::collectModuleTests($options['module']);
            } elseif (isset($options['file']) || isset($options['name'])) {
                // 文件模式：指定测试文件（支持 --name 参数）
                $fileName = $options['file'] ?? $options['name'] ?? null;
                if ($fileName) {
                    $testPath = self::findTestFile($fileName);
                }
            } else {
                // 默认：收集所有模块的测试（保持之前的功能）
                $testPath = self::collectAllModuleTests();
            }
        }
        
        // 构建完整的命令
        $pestBinary = BP . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'pest';
        
        // 如果 vendor/bin/pest 不存在，尝试使用全局安装的 pest
        if (!file_exists($pestBinary)) {
            $pestBinary = 'pest';
        }
        
        // 检查是否启用 watch 模式
        $watchMode = isset($options['watch']) && $options['watch'] === true;
        
        // 如果启用 watch 模式，检查是否支持
        if ($watchMode && !self::supportsWatch()) {
            throw new Exception(__('Pest Watch 模式需要 Pest 2.x 版本，当前使用的是 Pest 1.x。\n' .
                'Pest 1.x 不支持 --watch 参数。\n' .
                '解决方案：\n' .
                '1. 升级到 Pest 2.x（需要 PHPUnit 10+）\n' .
                '2. 使用 PHPUnit 模式运行测试\n' .
                '3. 使用第三方文件监听工具（如 nodemon、chokidar）'));
        }
        
        // 构建命令参数
        // 使用 -d 参数来抑制弃用警告（Pest 1.x 在 PHP 8.1+ 的兼容性问题）
        $errorReporting = error_reporting() & ~E_DEPRECATED;
        $commandParts = [
            self::resolvePhpCommandForTests(self::isCoverageRequested($options)),
            '-d', 'error_reporting=' . $errorReporting,
            '-d', 'display_errors=0',
            escapeshellarg($pestBinary)
        ];
        
        // 如果启用 watch 模式，实现简单的文件监听功能
        if ($watchMode) {
            return self::runWatchMode($options, $pestArgs, $testPath, $pestBinary);
        }
        
        if (!empty($pestArgs)) {
            $commandParts = array_merge($commandParts, $pestArgs);
        }
        
        // 处理测试路径（支持多个路径、单个文件、单个目录）
        if ($testPath) {
            if (is_file($testPath)) {
                // 如果是文件，直接添加文件路径
                $commandParts[] = escapeshellarg($testPath);
            } elseif (is_dir($testPath)) {
                // 如果是目录，添加目录路径
                $commandParts[] = escapeshellarg($testPath);
            } elseif (is_string($testPath) && str_contains($testPath, ' ')) {
                // 如果是多个路径（空格分隔），分别添加
                $paths = explode(' ', $testPath);
                foreach ($paths as $path) {
                    $path = trim($path);
                    if ($path && (is_dir($path) || is_file($path))) {
                        $commandParts[] = escapeshellarg($path);
                    }
                }
            } elseif (is_string($testPath)) {
                // 字符串路径，尝试作为目录或文件
                if (is_dir($testPath) || is_file($testPath)) {
                    $commandParts[] = escapeshellarg($testPath);
                }
            }
        }
        
        $command = implode(' ', $commandParts);

        // 执行命令
        $output = [];
        $exitCode = 0;
        
        // 尝试使用 passthru（如果可用）
        if (function_exists('passthru')) {
            \passthru($command, $exitCode);
        } else {
            // 如果 passthru 不可用，使用 exec
            \exec($command . ' 2>&1', $output, $exitCode);
            echo implode("\n", $output) . "\n";
        }
        
        return $exitCode;
    }

    /**
     * @DESC         |运行 Watch 模式（文件监听）
     *
     * 参数区：
     * @param array $options 测试选项
     * @param array $pestArgs Pest 参数
     * @param string $testPath 测试路径
     * @param string $pestBinary Pest 二进制文件路径
     * @return int 退出代码
     */
    private static function runWatchMode(array $options, array $pestArgs, $testPath, string $pestBinary): int
    {
        // 确定要监听的目录（支持多个路径）
        $watchDirs = [];
        
        if ($testPath) {
            if (is_file($testPath)) {
                // 如果是文件，监听文件所在目录和源代码目录
                $watchDirs[] = dirname($testPath);
                $watchDirs[] = BP . 'app' . DIRECTORY_SEPARATOR . 'code';
            } elseif (is_dir($testPath)) {
                // 如果是单个目录
                $watchDirs[] = $testPath;
                $watchDirs[] = BP . 'app' . DIRECTORY_SEPARATOR . 'code';
            } elseif (is_string($testPath) && str_contains($testPath, ' ')) {
                // 如果是多个路径（空格分隔）
                $paths = explode(' ', $testPath);
                foreach ($paths as $path) {
                    $path = trim($path);
                    if ($path && is_dir($path)) {
                        $watchDirs[] = $path;
                    }
                }
                // 同时监听源代码目录
                $watchDirs[] = BP . 'app' . DIRECTORY_SEPARATOR . 'code';
            } else {
                // 默认监听 app/code 目录
                $watchDirs[] = BP . 'app' . DIRECTORY_SEPARATOR . 'code';
            }
        } else {
            // 默认监听 app/code 目录
            $watchDirs[] = BP . 'app' . DIRECTORY_SEPARATOR . 'code';
        }
        
        // 去重
        $watchDirs = array_unique($watchDirs);
        
        // 记录文件的最后修改时间
        $fileTimes = [];
        
        echo "\n" . chr(27) . '[34m开始监听文件变化...' . chr(27) . '[0m' . "\n";
        echo chr(27) . '[33m按 Ctrl+C 停止监听' . chr(27) . '[0m' . "\n\n";
        
        // 首次运行测试
        $lastRunTime = time();
        self::runPestCommand($pestArgs, $testPath, $pestBinary);
        
        // 监听循环
        while (true) {
            usleep(500000); // 等待 0.5 秒
            
            $hasChanges = false;
            
            // 检查文件变化
            foreach ($watchDirs as $dir) {
                if (is_dir($dir)) {
                    $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::SELF_FIRST
                    );
                    
                    foreach ($iterator as $file) {
                        if ($file->isFile() && preg_match('/\.(php|phtml)$/i', $file->getExtension())) {
                            $filePath = $file->getPathname();
                            $currentTime = $file->getMTime();
                            
                            if (!isset($fileTimes[$filePath]) || $fileTimes[$filePath] < $currentTime) {
                                $fileTimes[$filePath] = $currentTime;
                                if (isset($fileTimes[$filePath]) && $fileTimes[$filePath] < $currentTime) {
                                    $hasChanges = true;
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }
            
            // 如果检测到变化，运行测试
            if ($hasChanges || (time() - $lastRunTime) > 2) {
                // 重新扫描文件时间
                foreach ($watchDirs as $dir) {
                    if (is_dir($dir)) {
                        $iterator = new \RecursiveIteratorIterator(
                            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                            \RecursiveIteratorIterator::SELF_FIRST
                        );
                        
                        foreach ($iterator as $file) {
                            if ($file->isFile() && preg_match('/\.(php|phtml)$/i', $file->getExtension())) {
                                $filePath = $file->getPathname();
                                $fileTimes[$filePath] = $file->getMTime();
                            }
                        }
                    }
                }
                
                echo "\n" . chr(27) . '[32m检测到文件变化，重新运行测试...' . chr(27) . '[0m' . "\n";
                self::runPestCommand($pestArgs, $testPath, $pestBinary);
                $lastRunTime = time();
            }
        }
        
        return 0;
    }

    /**
     * @DESC         |运行 Pest 命令
     *
     * 参数区：
     * @param array $pestArgs Pest 参数
     * @param string|null $testPath 测试路径（可选，如果使用 XML 配置则可以为 null）
     * @param string $pestBinary Pest 二进制文件路径
     * @return void
     */
    private static function runPestCommand(array $pestArgs, ?string $testPath, string $pestBinary): void
    {
        $errorReporting = error_reporting() & ~E_DEPRECATED;
        $coverageRequested = false;
        foreach ($pestArgs as $arg) {
            if (\str_starts_with($arg, '--coverage')) {
                $coverageRequested = true;
                break;
            }
        }
        $commandParts = [
            self::resolvePhpCommandForTests($coverageRequested),
            '-d', 'error_reporting=' . $errorReporting,
            '-d', 'display_errors=0',
            escapeshellarg($pestBinary)
        ];
        
        if (!empty($pestArgs)) {
            $commandParts = array_merge($commandParts, $pestArgs);
        }
        
        // 处理测试路径（支持多个路径、单个文件、单个目录）
        // 注意：如果使用 XML 配置文件（--configuration），则不需要传递测试路径
        $hasConfiguration = false;
        foreach ($pestArgs as $arg) {
            if (str_starts_with($arg, '--configuration=')) {
                $hasConfiguration = true;
                break;
            }
        }
        
        // 只有在没有使用 XML 配置文件时才添加测试路径
        if (!$hasConfiguration && $testPath) {
            if (is_file($testPath)) {
                $commandParts[] = escapeshellarg($testPath);
            } elseif (is_dir($testPath)) {
                $commandParts[] = escapeshellarg($testPath);
            } elseif (str_contains($testPath, ' ')) {
                // 多个路径
                $paths = explode(' ', $testPath);
                foreach ($paths as $path) {
                    $path = trim($path);
                    if ($path && (is_dir($path) || is_file($path))) {
                        $commandParts[] = escapeshellarg($path);
                    }
                }
            }
        }
        
        $command = implode(' ', $commandParts);
        
        if (function_exists('passthru')) {
            \passthru($command);
        } else {
            \exec($command . ' 2>&1', $output, $exitCode);
            echo implode("\n", $output) . "\n";
        }
    }

    /**
     * @DESC         |收集指定模块的测试路径
     *
     * 参数区：
     * @param string $moduleName 模块名
     * @return string|null 测试路径
     */
    private static function collectModuleTests(string $moduleName): ?string
    {
        $modules = Env::getInstance()->getActiveModules();
        
        foreach ($modules as $module) {
            if ($module['name'] === $moduleName || 
                str_contains($module['name'], $moduleName) ||
                str_contains($moduleName, $module['name'])) {
                
                // 尝试 test 和 Test 目录
                $testPath = $module['base_path'] . 'test' . DIRECTORY_SEPARATOR;
                if (is_dir($testPath)) {
                    return $testPath;
                }
                
                $testPath = $module['base_path'] . 'Test' . DIRECTORY_SEPARATOR;
                if (is_dir($testPath)) {
                    return $testPath;
                }
            }
        }
        
        return null;
    }

    /**
     * @DESC         |收集所有模块的测试路径（返回多个路径，用空格分隔）
     *
     * 参数区：
     * @return string 测试路径列表
     */
    private static function collectAllModuleTests(): string
    {
        $modules = Env::getInstance()->getActiveModules();
        $testPaths = [];
        
        foreach ($modules as $module) {
            // 尝试 test 和 Test 目录
            $testPath = $module['base_path'] . 'test' . DIRECTORY_SEPARATOR;
            if (is_dir($testPath)) {
                $testPaths[] = $testPath;
                continue;
            }
            
            $testPath = $module['base_path'] . 'Test' . DIRECTORY_SEPARATOR;
            if (is_dir($testPath)) {
                $testPaths[] = $testPath;
            }
        }
        
        // 如果没有找到任何测试目录，返回默认的 tests 目录
        if (empty($testPaths)) {
            return BP . 'tests';
        }
        
        // 返回所有路径，Pest 支持多个路径
        return implode(' ', $testPaths);
    }

    /**
     * @DESC         |查找测试文件（智能匹配，支持多种格式）
     *
     * 参数区：
     * @param string $fileName 文件名（支持多种格式：ThemeCssTaglibTest, ThemeCssTaglibTest.php, ThemeCssTaglib等）
     * @return string|null 测试文件路径
     */
    private static function findTestFile(string $fileName): ?string
    {
        $modules = Env::getInstance()->getActiveModules();
        
        // 处理文件名（支持多种格式）
        $actualFileName = $fileName;
        if (str_contains($fileName, '::')) {
            // 如果包含 ::，提取文件名部分（方法名在 filter 中处理）
            $actualFileName = explode('::', $fileName)[0];
        }
        
        // 移除可能的扩展名
        $actualFileName = preg_replace('/\.php$/', '', $actualFileName);
        
        foreach ($modules as $module) {
            // 尝试 test 和 Test 目录
            $testPath = $module['base_path'] . 'test' . DIRECTORY_SEPARATOR;
            if (!is_dir($testPath)) {
                $testPath = $module['base_path'] . 'Test' . DIRECTORY_SEPARATOR;
            }
            
            if (!is_dir($testPath)) {
                continue;
            }
            
            // 尝试多种文件名格式（与 PHPUnit 模式保持一致）
            $possibleFiles = [
                $testPath . $actualFileName,
                $testPath . $actualFileName . '.php',
                $testPath . $actualFileName . 'Test.php',
                $testPath . 'Unit' . DIRECTORY_SEPARATOR . $actualFileName,
                $testPath . 'Unit' . DIRECTORY_SEPARATOR . $actualFileName . '.php',
                $testPath . 'Unit' . DIRECTORY_SEPARATOR . $actualFileName . 'Test.php',
            ];
            
            foreach ($possibleFiles as $possibleFile) {
                if (file_exists($possibleFile)) {
                    return $possibleFile;
                }
            }
            
            // 递归搜索（更智能的匹配）
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($testPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $basename = $file->getBasename('.php');
                    // 支持多种匹配方式
                    if ($basename === $actualFileName || 
                        $basename === $actualFileName . 'Test' ||
                        str_contains($basename, $actualFileName) ||
                        str_contains($actualFileName, $basename)) {
                        return $file->getPathname();
                    }
                }
            }
        }
        
        return null;
    }
}
