<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/10/16 08:37:10
 */

namespace Weline\Framework\UnitTest\Console\PhpUnit;

use Weline\Framework\Console\CommandInterface;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\App\System;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\Process\Processer;
use Weline\Framework\UnitTest\Pest\Pest as PestTest;

class Run implements \Weline\Framework\Console\CommandInterface
{
    private System $system;
    private Printing $printing;

    public function __construct(
        System   $system,
        Printing $printing
    )
    {
        $this->system = $system;
        $this->printing = $printing;
    }

    /**
     * PHPUnit 生成配置、覆盖率与报告目录（tests/phpunit）。
     */
    private function getPhpUnitWorkspacePath(): string
    {
        return BP . 'tests' . DS . 'phpunit' . DS;
    }

    private function isCoverageRequested(array $args, array $data): bool
    {
        foreach (['coverage', 'c', 'coverage-html', 'coverage-text', 'coverage-xml', 'coverage-clover'] as $key) {
            if (isset($args[$key]) || isset($data[$key])) {
                return true;
            }
        }

        return false;
    }

    private function resolvePhpCommandForTests(bool $coverageRequested): string
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
        $phpdbg = \PHP_OS_FAMILY === 'Windows'
            ? $phpDir . DS . 'phpdbg.exe'
            : $phpDir . DS . 'phpdbg';

        if (is_file($phpdbg)) {
            $this->printing->note(__('未检测到 Xdebug/PCOV，覆盖率模式自动切换到 phpdbg 驱动'));
            return escapeshellarg($phpdbg) . ' -qrr';
        }

        $this->printing->warning(__('未检测到可用覆盖率驱动（Xdebug/PCOV/phpdbg），PHPUnit 可能无法生成覆盖率报告'));
        return escapeshellarg(PHP_BINARY);
    }

    private function appendCoverageArguments(string $phpunitCommand, array $args, array $data, string $reportPath): string
    {
        if (!$this->isCoverageRequested($args, $data)) {
            return $phpunitCommand;
        }

        $coverageHtml = $args['coverage-html'] ?? $data['coverage-html'] ?? ($reportPath . DS . 'coverage-html');
        $coverageText = $args['coverage-text'] ?? $data['coverage-text'] ?? ($reportPath . DS . 'coverage.txt');
        $coverageClover = $args['coverage-clover'] ?? $data['coverage-clover'] ?? ($reportPath . DS . 'coverage.xml');

        $phpunitCommand .= ' --coverage-html ' . escapeshellarg((string) $coverageHtml);
        $phpunitCommand .= ' --coverage-text=' . escapeshellarg((string) $coverageText);
        $phpunitCommand .= ' --coverage-clover ' . escapeshellarg((string) $coverageClover);

        if (isset($args['coverage-xml']) || isset($data['coverage-xml'])) {
            $coverageXml = $args['coverage-xml'] ?? $data['coverage-xml'];
            $phpunitCommand .= ' --coverage-xml ' . escapeshellarg((string) $coverageXml);
        }

        return $phpunitCommand;
    }

    private function normalizeCoverageSourcePath(string $path): string
    {
        return str_replace('\\', '/', rtrim($path, "\\/"));
    }

    private function resolveCoverageSourceForTestFile(string $testFile): string
    {
        $normalized = str_replace(['/', '\\'], DS, $testFile);
        foreach ([DS . 'Test' . DS, DS . 'test' . DS] as $marker) {
            $pos = strpos($normalized, $marker);
            if ($pos !== false) {
                return $this->normalizeCoverageSourcePath(substr($normalized, 0, $pos));
            }
        }

        return '../../app/code';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        # 提示是否运行：生产环境禁止运行
        if (Env::system('deploy') !== 'dev') {
            $this->printing->setup(__('非开发环境禁止运行！如你确认是dev环境，请运行php bin/w deploy:model:set dev 转换环境后运行！'));
            exit(1);
        }
        
        # 检查是否使用 Pest（--pest 参数）
        $usePest = isset($args['pest']) || isset($args['--pest']);
        
        # 默认使用 PHPUnit，只有指定 --pest 时才使用 Pest
        if ($usePest && PestTest::isAvailable()) {
            $this->printing->note(__('使用 Pest 测试框架运行测试...'));
            $this->printing->note(__('提示：默认使用 PHPUnit，如需使用 Pest，请添加 --pest 参数'));
            echo "\n";
            
            // 构建 Pest 选项
            $pestOptions = [];
            
            // 检查模块参数（结合之前的测试收集逻辑）
            $moduleName = $args['--module'] ?? $args['module'] ?? null;
            if ($moduleName) {
                $pestOptions['module'] = $moduleName;
            }
            
            // 检查文件名参数（支持智能匹配）
            $fileName = $args['--name'] ?? $args['name'] ?? null;
            if ($fileName) {
                // 如果包含 ::，说明是方法名，需要分别处理
                if (str_contains($fileName, '::')) {
                    $parts = explode('::', $fileName);
                    $pestOptions['file'] = $parts[0]; // 文件名部分
                    $pestOptions['filter'] = $parts[1] ?? $fileName; // 方法名部分
                } else {
                    $pestOptions['file'] = $fileName; // 文件名
                }
            }
            
            // SELECTION OPTIONS（选择选项）
            if (isset($args['filter']) || isset($args['f'])) {
                $pestOptions['filter'] = $args['filter'] ?? $args['f'];
            }
            
            if (isset($args['group']) || isset($args['g'])) {
                $pestOptions['group'] = $args['group'] ?? $args['g'];
            }
            
            if (isset($args['exclude-group'])) {
                $pestOptions['exclude-group'] = $args['exclude-group'];
            }
            
            if (isset($args['testsuite']) || isset($args['s'])) {
                $pestOptions['testsuite'] = $args['testsuite'] ?? $args['s'];
            }
            
            if (isset($args['exclude-testsuite'])) {
                $pestOptions['exclude-testsuite'] = $args['exclude-testsuite'];
            }
            
            if (isset($args['covers'])) {
                $pestOptions['covers'] = $args['covers'];
            }
            
            if (isset($args['uses'])) {
                $pestOptions['uses'] = $args['uses'];
            }
            
            // EXECUTION OPTIONS（执行选项）
            if (isset($args['parallel']) || isset($args['p'])) {
                $pestOptions['parallel'] = true;
            }
            
            if (isset($args['bail'])) {
                $pestOptions['bail'] = true;
            }
            
            if (isset($args['retry'])) {
                $pestOptions['retry'] = true;
            }
            
            if (isset($args['stop-on-error'])) {
                $pestOptions['stop-on-error'] = true;
            }
            
            if (isset($args['stop-on-failure'])) {
                $pestOptions['stop-on-failure'] = true;
            }
            
            if (isset($args['stop-on-warning'])) {
                $pestOptions['stop-on-warning'] = true;
            }
            
            if (isset($args['stop-on-defect'])) {
                $pestOptions['stop-on-defect'] = true;
            }
            
            if (isset($args['order-by'])) {
                $pestOptions['order-by'] = $args['order-by'];
            }
            
            if (isset($args['random-order-seed'])) {
                $pestOptions['random-order-seed'] = $args['random-order-seed'];
            }
            
            // CODE COVERAGE OPTIONS（代码覆盖率选项）
            if (isset($args['coverage']) || isset($args['c'])) {
                $pestOptions['coverage'] = true;
            }
            
            if (isset($args['min'])) {
                $pestOptions['min'] = $args['min'];
            }
            
            if (isset($args['coverage-html'])) {
                $pestOptions['coverage-html'] = $args['coverage-html'];
            }
            
            if (isset($args['coverage-text'])) {
                $pestOptions['coverage-text'] = $args['coverage-text'];
            }
            
            if (isset($args['coverage-xml'])) {
                $pestOptions['coverage-xml'] = $args['coverage-xml'];
            }
            
            if (isset($args['coverage-clover'])) {
                $pestOptions['coverage-clover'] = $args['coverage-clover'];
            }
            
            // REPORTING OPTIONS（报告选项）
            if (isset($args['testdox'])) {
                $pestOptions['testdox'] = true;
            }
            
            if (isset($args['compact'])) {
                $pestOptions['compact'] = true;
            }
            
            if (isset($args['debug'])) {
                $pestOptions['debug'] = true;
            }
            
            if (isset($args['profile'])) {
                $pestOptions['profile'] = true;
            }
            
            if (isset($args['colors'])) {
                $pestOptions['colors'] = $args['colors'];
            }
            
            if (isset($args['no-progress'])) {
                $pestOptions['no-progress'] = true;
            }
            
            if (isset($args['no-results'])) {
                $pestOptions['no-results'] = true;
            }
            
            // CONFIGURATION OPTIONS（配置选项）
            if (isset($args['configuration'])) {
                $pestOptions['configuration'] = $args['configuration'];
            }
            
            if (isset($args['bootstrap'])) {
                $pestOptions['bootstrap'] = $args['bootstrap'];
            }
            
            if (isset($args['cache-directory'])) {
                $pestOptions['cache-directory'] = $args['cache-directory'];
            }
            
            // 检查是否启用监听模式
            $watchMode = isset($args['watch']) || isset($args['w']);
            if ($watchMode) {
                $pestOptions['watch'] = true;
            }
            
            // 生成 XML 配置文件（与 PHPUnit 模式保持一致）
            // 这样 Pest 可以通过 --configuration 参数读取所有收集到的测试
            // 注意：$moduleName 和 $fileName 已经在上面获取过了
            // 如果 fileName 包含 ::，需要提取文件名部分
            $actualFileName = $fileName;
            if ($fileName && str_contains($fileName, '::')) {
                $parts = explode('::', $fileName);
                $actualFileName = $parts[0]; // 文件名部分
            }
            
            $php_unit_path = $this->getPhpUnitWorkspacePath();
            if (!is_dir($php_unit_path)) {
                mkdir($php_unit_path, 0755, true);
            }
            $php_unit_report_path = $php_unit_path . 'report';
            if (!is_dir($php_unit_report_path)) {
                mkdir($php_unit_report_path, 0755, true);
            }
            $php_unit_config_path = $php_unit_path . 'config.xml';
            
            // 根据运行模式生成不同的配置
            $php_unit_xml = '';
            if ($actualFileName) {
                // 优先处理文件名参数
                if ($moduleName) {
                    // 指定模块 + 文件名：在指定模块中查找文件
                    $php_unit_xml = $this->generateModuleFileConfig($moduleName, $actualFileName, $php_unit_report_path);
                } else {
                    // 只指定文件名：在所有模块中查找文件
                    $php_unit_xml = $this->generateFileConfig($actualFileName, $php_unit_report_path);
                }
            } elseif ($moduleName) {
                // 只指定模块：运行整个模块的测试
                $php_unit_xml = $this->generateModuleConfig($moduleName, $php_unit_report_path);
            } else {
                // 套件模式：运行套件测试
                $php_unit_xml = $this->generateSuiteConfig($php_unit_report_path);
            }
            
            // 如果生成了 XML 配置，写入文件并传递给 Pest
            // 注意：当使用 XML 配置文件时，Pest 会从配置文件中读取测试，不需要再传递测试路径
            if (!empty($php_unit_xml)) {
                file_put_contents($php_unit_config_path, $php_unit_xml);
                // 使用生成的 XML 配置文件
                $pestOptions['configuration'] = $php_unit_config_path;
                // 清除之前设置的测试路径相关选项，让 Pest 从 XML 配置文件中读取
                unset($pestOptions['module']);
                unset($pestOptions['file']);
                unset($pestOptions['name']);
                unset($pestOptions['path']);
            }
            
            // 运行 Pest 测试
            try {
                $exitCode = PestTest::run($pestOptions);
                
                // 如果使用 watch 模式，不返回，继续监听
                if ($watchMode) {
                    // watch 模式会持续运行，不返回
                    return 0;
                }
                
                echo "\n";
                if ($exitCode === 0) {
                    $this->printing->success(__('所有测试通过！'));
                } else {
                    $this->printing->error(__('测试失败，退出代码: %{1}', [$exitCode]));
                }
                
                // 如果启用了 Web 模式，启动 Web 服务器并打开浏览器
                $isBackground = isset($args['web']) || isset($args['--web']); // Pest 模式也需要 --web 参数
                if ($isBackground && !$watchMode) {
                    if (!$this->isInteractiveConsole()) {
                        $this->printing->warning(__('检测到非交互环境，已跳过启动 Web 报告服务器（避免后台进程堆积）'));
                        return $exitCode;
                    }
                    $php_unit_workspace = $this->getPhpUnitWorkspacePath();
                    $php_unit_report_path = $php_unit_workspace . 'report';
                    if (!is_dir($php_unit_report_path)) {
                        mkdir($php_unit_report_path, 0755, true);
                    }
                    $port = $this->getPortFromArgs($args, $data);
                    $this->startPhpUnitServerBackground($php_unit_workspace, $port, false);
                    $this->openBrowser($port, $this->isCoverageRequested($args, $data));
                }
                
                return $exitCode;
            } catch (Exception $e) {
                $this->printing->error(__('Pest 测试运行失败: %{1}', [$e->getMessage()]));
                $this->printing->note(__('回退到 PHPUnit 模式...'));
                echo "\n";
                // 继续执行 PHPUnit 逻辑
            }
        }
        
        # 检查帮助参数（只检查明确的帮助参数，避免误识别）
        if (isset($args['h']) || isset($args['help']) || isset($args['--help'])) {
            $this->printing->success($this->tip());
            return;
        }
        
        # 注意：不检查位置参数中的help，避免与套件名冲突
        # 用户应使用 -h 或 --help 参数查看帮助
        
        # 调试：检查参数解析
        if (isset($args['debug']) || isset($data['debug'])) {
            $this->printing->note(__('调试 - 所有参数: %{1}', [json_encode($args)]));
        }
        
        # 运行模式：默认不启用 Web 界面，可通过 --web 参数启用
        $isForeground = true;
        $isBackground = isset($args['web']) || isset($args['--web']);
        
        # 检查模块参数（只从用户明确指定的参数中获取）
        $moduleName = $args['--module'] ?? $args['module'] ?? null;
        
        # 检查文件名参数（只从用户明确指定的参数中获取）
        $fileName = $args['--name'] ?? $args['name'] ?? null;
        $fileList = $this->extractFilesListFromArgs($args);
        
        # 调试信息
        if (isset($args['debug']) || isset($data['debug'])) {
            $this->printing->note(__('调试 - 模块名: %{1}', [$moduleName ?? 'null']));
            $this->printing->note(__('调试 - 文件名: %{1}', [$fileName ?? 'null']));
            $this->printing->note(__('调试 - args: %{1}', [json_encode($args)]));
            $this->printing->note(__('调试 - data: %{1}', [json_encode($data)]));
        }
        
        # 检查 Web 界面参数
        if ($isBackground) {
            $this->printing->note(__('运行模式: 启用 Web 报告界面 (--web)'));
        } else {
            $this->printing->note(__('运行模式: 命令行输出 (默认)'));
        }
        
        # 显示运行模式
        if ($moduleName) {
            $this->printing->note(__('运行模式: 指定模块 - %{1}', $moduleName));
        } elseif ($fileList !== []) {
            $this->printing->note(__('运行模式: 指定文件列表 - %{1}', [implode(', ', $fileList)]));
        } elseif ($fileName) {
            $this->printing->note(__('运行模式: 指定文件 - %{1}', $fileName));
        } else {
            $this->printing->note(__('运行模式: 套件测试'));
        }
        $this->printing->note(__('正在 收集 测试套件...'));
        $php_unit_path = $this->getPhpUnitWorkspacePath();
        if (!is_dir($php_unit_path)) {
            mkdir($php_unit_path, 0755, true);
        }
        $php_unit_report_path = $php_unit_path . 'report';
        if (!is_dir($php_unit_report_path)) {
            mkdir($php_unit_report_path, 0755, true);
        }
        $php_unit_config_path = $php_unit_path . 'config.xml';
        
        # 统计测试总数
        $totalTestCount = 0;
        
        # 根据运行模式生成不同的配置
        if ($fileList !== []) {
            # 指定文件列表：按路径直接执行
            $this->printing->note(__('运行模式: 指定文件列表'));
            $php_unit_xml = $this->generateSuiteConfig($php_unit_report_path);
            $totalTestCount = 0;
            foreach ($fileList as $oneFile) {
                $totalTestCount += $this->countTestMethodsInFile($oneFile);
            }
        } elseif ($fileName) {
            # 优先处理文件名参数
            if ($moduleName) {
                # 指定模块 + 文件名：在指定模块中查找文件
                $this->printing->note(__('运行模式: 指定模块文件 - %{1}::%{2}', [$moduleName, $fileName]));
                $php_unit_xml = $this->generateModuleFileConfig($moduleName, $fileName, $php_unit_report_path);
                if (empty($php_unit_xml)) {
                    return;
                }
                # 统计单个文件的测试方法数量
                $totalTestCount = $this->countTestMethodsInFile($fileName, $moduleName);
            } else {
                # 只指定文件名：在所有模块中查找文件
                $this->printing->note(__('运行模式: 指定文件 - %{1}', [$fileName]));
                $php_unit_xml = $this->generateFileConfig($fileName, $php_unit_report_path);
                if (empty($php_unit_xml)) {
                    return;
                }
                # 统计单个文件的测试方法数量
                $totalTestCount = $this->countTestMethodsInFile($fileName);
            }
        } elseif ($moduleName) {
            # 只指定模块：运行整个模块的测试
            $this->printing->note(__('运行模式: 指定模块 - %{1}', [$moduleName]));
            $php_unit_xml = $this->generateModuleConfig($moduleName, $php_unit_report_path);
            if (empty($php_unit_xml)) {
                return;
            }
            # 统计整个模块的测试方法数量
            $totalTestCount = $this->countTestMethodsInModule($moduleName);
        } else {
            # 套件模式：运行套件测试
            $this->printing->note(__('运行模式: 套件测试'));
            $php_unit_xml = $this->generateSuiteConfig($php_unit_report_path);
            # 统计套件的测试方法数量
            $totalTestCount = $this->countTestMethodsInSuite('unit');
        }
        
        file_put_contents($php_unit_config_path, $php_unit_xml);
        # 根据运行模式执行不同的命令
        $this->printing->note(__('收集完成，准备运行...'));
        
        $ds = DS;
        // PHPUnit 10.x 不支持 --verbose 参数，使用 --testdox 代替以获得更好的输出
        $coverageRequested = str_contains($php_unit_xml, '<coverage') || $this->isCoverageRequested($args, $data);
        $phpBinaryCommand = $this->resolvePhpCommandForTests($coverageRequested);
        $phpunitCommand = $phpBinaryCommand . ' ' . VENDOR_PATH . "{$ds}phpunit{$ds}phpunit{$ds}phpunit --configuration $php_unit_config_path --testdox";
        // 如果指定了 debug 参数，添加 --debug
        if (isset($args['debug']) || isset($data['debug'])) {
            $phpunitCommand .= ' --debug';
        }
        $phpunitCommand = $this->appendCoverageArguments($phpunitCommand, $args, $data, $php_unit_path);
        
        if ($fileList !== []) {
            $resolvedFiles = [];
            foreach ($fileList as $oneFile) {
                $resolved = $this->resolveTestFilePathFromInput($oneFile);
                if ($resolved === null) {
                    $this->printing->error(__('未找到测试文件: %{1}', [$oneFile]));
                    return;
                }
                $resolvedFiles[] = escapeshellarg($resolved);
            }
            $this->printing->note(__('正在运行文件列表测试: %{1}', [implode(', ', $fileList)]));
            $command = $this->system->exec($phpunitCommand . ' ' . implode(' ', $resolvedFiles), true);
        } elseif ($fileName) {
            # 文件模式：运行指定文件的测试
            if ($moduleName) {
                # 在指定模块中查找文件
                $testFile = $this->findTestFileInModule($fileName, $moduleName);
                if (!$testFile) {
                    $this->printing->error(__('在模块 %{1} 中未找到测试文件: %{2}', [$moduleName, $fileName]));
                    return;
                }
                
                # 检查是否是测试方法名
                if (str_contains($fileName, '::')) {
                    $methodName = explode('::', $fileName)[1];
                    # 检查测试方法是否存在
                    if (!$this->checkTestMethodExists($testFile, $methodName)) {
                        $this->printing->error(__('在文件 %{1} 中未找到测试方法: %{2}', [basename($testFile), $methodName]));
                        return;
                    }
                    $this->printing->note(__('正在运行模块测试方法: %{1}::%{2}', [$moduleName, $fileName]));
                    # 对于测试方法，直接使用测试方法名
                    $command = $this->system->exec($phpunitCommand . " --filter '$methodName' $testFile", true);
                } else {
                    $this->printing->note(__('正在运行模块文件测试: %{1}::%{2}', [$moduleName, $fileName]));
                    # 对于整个文件，直接指定文件路径
                    $command = $this->system->exec($phpunitCommand . " $testFile", true);
                }
            } else {
                # 在所有模块中查找文件
                $testFile = $this->findTestFile($fileName, isset($args['debug']) || isset($data['debug']));
                if (!$testFile) {
                    $this->printing->error(__('未找到测试文件: %{1}', [$fileName]));
                    return;
                }
                
                # 检查是否是测试方法名
                if (str_contains($fileName, '::')) {
                    $methodName = explode('::', $fileName)[1];
                    # 检查测试方法是否存在
                    if (!$this->checkTestMethodExists($testFile, $methodName)) {
                        $this->printing->error(__('在文件 %{1} 中未找到测试方法: %{2}', [basename($testFile), $methodName]));
                        return;
                    }
                    $this->printing->note(__('正在运行测试方法: %{1}', [$fileName]));
                    # 对于测试方法，直接使用测试方法名
                    $command = $this->system->exec($phpunitCommand . " --filter '$methodName' $testFile", true);
                } else {
                    $this->printing->note(__('正在运行文件测试: %{1}', [$fileName]));
                    # 对于整个文件，直接指定文件路径
                    $command = $this->system->exec($phpunitCommand . " $testFile", true);
                }
            }
        } elseif ($moduleName) {
            # 模块模式：运行指定模块的测试
            $this->printing->note(__('正在运行模块测试: %{1}', [$moduleName]));
            $command = $this->system->exec($phpunitCommand . " --testsuite $moduleName", true);
        } else {
            # 套件模式：运行套件测试
            $filteredArgs = [];
            # 获取命令名，用于排除
            $commandName = $args['command'] ?? '';
            foreach ($args as $arg_key => $arg) {
                # 只取整数键的参数作为套件名，排除命令名和参数
                if (is_int($arg_key) && !empty($arg) && !is_bool($arg) && 
                    $arg !== 'phpunit:run' && $arg !== 'p:r' && 
                    $arg !== '-b' && $arg !== '--backend' &&
                    $arg !== $commandName) {
                    $filteredArgs[] = $arg;
                }
            }
            $text_suite_name = implode(',', $filteredArgs) ?: 'unit';
            $this->printing->note(__('正在测试套件: %{1}', [$text_suite_name]));
            $command = $this->system->exec($phpunitCommand . " --testsuite $text_suite_name", true);
        }
        
        if(!DEV){
            $this->printing->setup(__('重要提示：测试套件运行过程中会操作数据库，从而产生不可预知的风险。请确认当前环境非生产环境，你确认当前环境非生产环境么？(y/n)'));
            if (strtolower(trim($this->system->input())) !== 'y') {
                $this->printing->setup(__('已停止运行！'));
                exit(1);
            }
            $this->printing->setup(__('重要提示：再次确认需要运行么？(y/n)'));
            if (strtolower(trim($this->system->input())) !== 'y') {
                $this->printing->setup(__('已停止运行！'));
                exit(1);
            }
        }
        $this->printing->success($command['command']);
        
        # 调试信息（可选）
        if (isset($args['debug']) || isset($data['debug'])) {
            $this->printing->note(__('调试 - 命令输出行数: %{1}', (string)count($command['output'])));
            $this->printing->note(__('调试 - 退出代码: %{1}', (string)$command['return_vars']));
        }
        
        # 彩色输出测试结果，并捕获失败的测试用例
        $failedTests = [];
        $currentTest = null;
        $inFailureBlock = false;
        
        foreach ($command['output'] as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            // 检测未知选项错误
            if (str_contains($line, 'Unknown option')) {
                $this->printing->error($line);
                $this->printing->warning(__('提示：PHPUnit 版本可能不支持某些参数，请检查 PHPUnit 版本'));
                continue;
            }
            
            // 检测 PHPUnit 版本信息
            if (str_contains($line, 'PHPUnit') && str_contains($line, 'by Sebastian Bergmann')) {
                $this->printing->note($line);
            }
            // 检测测试用例名称（TestDox 格式：类名和方法名）
            elseif (preg_match('/^([A-Z][a-zA-Z0-9_\\\\]+)::([a-zA-Z0-9_]+)/', $line, $matches)) {
                $currentTest = $matches[1] . '::' . $matches[2];
                $this->printing->note($line);
            }
            // 检测 TestDox 格式的测试名称（例如：✓ Test name 或 ✗ Test name）
            elseif (preg_match('/^[✓✗]\s+(.+)/', $line, $matches)) {
                $testName = $matches[1];
                if (str_starts_with($line, '✗')) {
                    $failedTests[] = $testName;
                    $currentTest = $testName;
                    $inFailureBlock = true;
                    $this->printing->error($line);
                } else {
                    $this->printing->success($line);
                    $inFailureBlock = false;
                }
            }
            // 检测成功信息
            elseif (str_contains($line, 'OK') || str_contains($line, 'PASSED')) {
                $this->printing->success($line);
            }
            // 检测失败信息
            elseif (str_contains($line, 'FAILURES') || str_contains($line, 'ERRORS') || str_contains($line, 'FAILED')) {
                $this->printing->error($line);
                $inFailureBlock = true;
            }
            // 检测警告
            elseif (str_contains($line, 'WARNING') || str_contains($line, 'Warning')) {
                $this->printing->warning($line);
            }
            // 检测统计信息
            elseif (str_contains($line, 'Tests:') || str_contains($line, 'Time:') || str_contains($line, 'Memory:')) {
                $this->printing->note($line);
            }
            // 检测错误摘要
            elseif (str_contains($line, 'There were') || str_contains($line, 'There was')) {
                $this->printing->error($line);
            }
            // 检测错误标记
            elseif (str_contains($line, 'ERRORS!') || str_contains($line, 'FAILURES!')) {
                $this->printing->error($line);
            }
            // 检测测试用例编号（传统格式）
            elseif (preg_match('/^\d+\)\s+(.+)/', $line, $matches)) {
                $testCase = $matches[1];
                $failedTests[] = $testCase;
                $currentTest = $testCase;
                $inFailureBlock = true;
                $this->printing->error($line);
            }
            // 检测错误和异常
            elseif (str_contains($line, 'Error:') || str_contains($line, 'Exception:')) {
                $this->printing->error($line);
                if ($currentTest && !in_array($currentTest, $failedTests)) {
                    $failedTests[] = $currentTest;
                }
            }
            // 检测断言失败
            elseif (str_contains($line, 'Failed asserting')) {
                $this->printing->error($line);
                if ($currentTest && !in_array($currentTest, $failedTests)) {
                    $failedTests[] = $currentTest;
                }
            }
            // 检测差异信息
            elseif (str_contains($line, '--- Expected') || str_contains($line, '+++ Actual')) {
                $this->printing->error($line);
            }
            // 检测差异标记
            elseif (str_contains($line, '@@')) {
                $this->printing->error($line);
            }
            // 检测其他错误格式
            elseif (str_contains($line, '--') && str_contains($line, 'There was')) {
                $this->printing->error($line);
            }
            // 在失败块中的其他行也标记为错误
            elseif ($inFailureBlock) {
                $this->printing->error($line);
            }
            // 默认输出所有其他行
            else {
                echo $line . "\n";
            }
        }
        
        // 如果有失败的测试用例，显示摘要
        if (!empty($failedTests)) {
            echo "\n";
            $this->printing->error(__('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━'));
            $this->printing->error(__('失败的测试用例：'));
            foreach ($failedTests as $index => $test) {
                $this->printing->error(__('  %{1}. %{2}', [($index + 1), $test]));
            }
            $this->printing->error(__('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━'));
            echo "\n";
        }
        
        if ($command['return_vars']) {
            $this->printing->success((string)$command['return_vars']);
        }
        
        # 判断是否为文件或方法测试模式（快速测试）
        $isQuickTest = !empty($fileName) || !empty($fileList);
        
        # 文件或方法测试时，如果指定了前台运行，直接输出结果后返回
        if ($isQuickTest && !$isBackground) {
            $this->printing->separator('─', 0, 'SUCCESS');
            $this->printing->success(__('✓ 测试完成！'));
            $this->printing->note(__('提示：测试已完成。'));
            return;
        }
        
        # 生成自定义HTML报告（包含测试文件数据）
        $testFiles = $this->generateCustomHtmlReport($command['output'], $command['return_vars'], $totalTestCount, $php_unit_report_path);
        
        # 显示树形测试结构
        $this->displayTestTree($testFiles);
        
        # 生成测试报告（包含详细统计）
        $this->generateTestReport($command['output'], $command['return_vars'], $totalTestCount, $testFiles);
        
        # 获取端口参数
        $port = $this->getPortFromArgs($args, $data);
        
        # 检查是否启用监听模式
        $watchMode = isset($args['watch']) || isset($args['w']);
        
        # 启动报告服务器（仅 --web）
        if ($isBackground) {
            if (!$this->isInteractiveConsole()) {
                $this->printing->warning(__('检测到非交互环境，已跳过启动 Web 报告服务器（避免后台进程堆积）'));
                $this->printing->note(__('如需查看报告，请在交互终端手动执行：php bin/w phpunit:run --web'));
                return;
            }
            $this->startPhpUnitServerBackground($php_unit_path, $port, $watchMode);
            
            # 自动打开浏览器（文档根为 tests/phpunit：/report/ 为摘要，/coverage-html/ 为 PHPUnit 覆盖率）
            $this->printing->note(__('报告摘要: http://localhost:%{1}/report/index.html', [(string)$port]));
            if ($coverageRequested) {
                $this->printing->note(__('覆盖率 HTML: http://localhost:%{1}/coverage-html/index.html', [(string)$port]));
            }
            $this->openBrowser($port, $coverageRequested);
            
            # 如果启用监听模式，启动文件监听
            if ($watchMode) {
                $this->startWatchMode($php_unit_path, $port, $args, $data);
            }
            
            # 在服务器启动后输出测试完成标志
            $this->printing->separator('═', 0, 'SUCCESS');
            $this->printing->success(__('✓ 测试已完成，报告服务器已在后台运行'));
            if ($watchMode) {
                $this->printing->note(__('📡 监听模式已启用，文件变化时将自动重新运行测试'));
            }
            $this->printing->success(__('=== PHPUNIT_TEST_COMPLETED ==='));
            $this->printing->separator('═', 0, 'SUCCESS');
            # 确保立即返回
            return;
        }
        
        $this->printing->note(__('测试运行完成；如需 Web 报告请添加 --web 参数。'));
    }

    /**
     * @param array<int|string, mixed> $args
     * @return array<int, string>
     */
    private function extractFilesListFromArgs(array $args): array
    {
        $rawValues = [];
        foreach (['--files', 'files'] as $key) {
            if (isset($args[$key]) && !is_bool($args[$key])) {
                $rawValues[] = (string)$args[$key];
            }
        }

        foreach ($args as $argKey => $argValue) {
            if (!is_int($argKey) || !is_string($argValue)) {
                continue;
            }
            $trimmed = trim($argValue);
            if (str_starts_with($trimmed, '--files=')) {
                $rawValues[] = substr($trimmed, strlen('--files='));
                continue;
            }
            if (str_starts_with($trimmed, '--files,')) {
                $rawValues[] = substr($trimmed, strlen('--files,'));
                continue;
            }
        }

        $files = [];
        foreach ($rawValues as $raw) {
            $parts = preg_split('/[,\r\n]+/', (string)$raw) ?: [];
            foreach ($parts as $part) {
                $file = trim((string)$part, " \t\n\r\0\x0B\"'");
                if ($file === '') {
                    continue;
                }
                $files[] = $file;
            }
        }

        return array_values(array_unique($files));
    }

    private function resolveTestFilePathFromInput(string $filePath): ?string
    {
        $trimmed = trim($filePath);
        if ($trimmed === '') {
            return null;
        }

        if (is_file($trimmed)) {
            return $trimmed;
        }

        $normalized = str_replace(['/', '\\'], DS, $trimmed);
        $relativeFromRoot = BP . ltrim($normalized, DS);
        if (is_file($relativeFromRoot)) {
            return $relativeFromRoot;
        }

        return $this->findTestFile($trimmed, false);
    }

    /**
     * 自动打开浏览器
     *
     * 内置服务器文档根为 tests/phpunit：摘要报告在 /report/index.html，覆盖率在 /coverage-html/index.html。
     *
     * @param int $port 端口号
     * @param bool $openCoverageFirst 是否优先打开覆盖率 HTML（已生成 --coverage 时）
     */
    private function openBrowser(int $port, bool $openCoverageFirst = false): void
    {
        // 检查是否禁用自动打开浏览器
        if (getenv('WELINE_NO_BROWSER') === '1') {
            return;
        }

        $path = $openCoverageFirst ? '/coverage-html/index.html' : '/report/index.html';
        $url = 'http://localhost:' . $port . $path;
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
        if ($isWindows) {
            // Windows 系统
            \exec("start \"\" \"$url\"", $output, $exitCode);
        } elseif (strtoupper(PHP_OS) === 'DARWIN') {
            // macOS 系统
            \exec("open \"$url\"", $output, $exitCode);
        } else {
            // Linux 系统
            \exec("xdg-open \"$url\"", $output, $exitCode);
        }
        
        if ($exitCode === 0) {
            $this->printing->success(__('✓ 已自动打开浏览器: %{1}', [$url]));
        } else {
            $this->printing->note(__('提示：请手动访问 %{1}', [$url]));
        }
    }

    /**
     * 判断是否为交互终端，避免在批处理/CI中拉起后台 web 服务造成进程堆积。
     */
    private function isInteractiveConsole(): bool
    {
        if (\function_exists('stream_isatty') && \defined('STDOUT')) {
            try {
                return \stream_isatty(STDOUT);
            } catch (\Throwable) {
            }
        }
        if (\function_exists('posix_isatty') && \defined('STDOUT')) {
            try {
                return \posix_isatty(STDOUT);
            } catch (\Throwable) {
            }
        }
        return false;
    }

    /**
     * 启动监听模式
     * 
     * @param string $reportPath 报告路径
     * @param int $port 端口号
     * @param array $args 参数
     * @param array $data 数据
     */
    private function startWatchMode(string $reportPath, int $port, array $args, array $data): void
    {
        // 检查是否安装了 Pest（Pest 有内置的 watch 功能）
        $usePest = isset($args['pest']) || isset($args['--pest']);
        if ($usePest && PestTest::isAvailable()) {
            $this->printing->note(__('使用 Pest 的 watch 模式...'));
            // Pest 的 watch 模式会在后台运行，自动监听文件变化
            // 这里可以添加额外的监听逻辑
        } else {
            // 对于 PHPUnit，可以使用简单的文件监听
            $this->printing->note(__('启动文件监听模式...'));
            // 可以在这里添加文件监听逻辑
        }
    }

    /**
     * 后台启动 PHPUnit 报告服务器（文档根为 tests/phpunit，可同时访问 report/ 与 coverage-html/）
     *
     * @param string $documentRoot PHPUnit 工作区路径（tests/phpunit）
     * @param int $port 端口号
     * @param bool $watchMode 是否启用监听模式
     */
    private function startPhpUnitServerBackground(string $documentRoot, int $port = 9980, bool $watchMode = false): void
    {
        $pidFile = BP . 'var' . DS . 'phpunit_server.pid';
        $logFile = BP . 'var' . DS . 'log' . DS . 'phpunit_server.log';
        
        # 检查是否已经在运行
        if (file_exists($pidFile)) {
            $pid = (int)file_get_contents($pidFile);
            if (Processer::isRunningByPid($pid)) {
                $this->printing->note(__('PHPUnit报告服务器已在运行 (PID: %{1})', (string)$pid));
                $this->printing->note(__('访问地址: 摘要 http://localhost:%{1}/report/index.html | 覆盖率 http://localhost:%{2}/coverage-html/index.html', [(string)$port, (string)$port]));
                return;
            } else {
                # 清理无效的PID文件
                unlink($pidFile);
            }
        }
        
        # 确保日志目录存在
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        # 检测操作系统
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
        # 查找可用端口，如果被占用则先停止占用端口的进程
        $originalPort = $port;
        if (Processer::isPortInUse($port)) {
            $this->printing->note(__('端口 %{1} 被占用，尝试停止占用该端口的进程...', (string)$port));
            Processer::killProcessByPort($port);
            sleep(1); // 等待进程完全停止
            # 再次检查端口是否可用
            if (Processer::isPortInUse($port)) {
                # 如果仍然被占用，查找新端口
                $port = Processer::findAvailablePort($port);
                $this->printing->note(__('端口 %{1} 仍被占用，自动选择端口 %{2}', [(string)$originalPort, (string)$port]));
            } else {
                $this->printing->success(__('已停止占用端口 %{1} 的进程', (string)$port));
            }
        }
        
        # 简化启动：使用 Processer::startBuiltInServer 统一处理启动与 PID 获取
        $pid = 0;
        $method = '';
        $this->printing->note(__('使用统一启动方案启动内置 PHP 服务器...'));
        $pid = Processer::startBuiltInServer($documentRoot, $port, $logFile);
        if ($pid > 0) {
            $method = 'built_in';
            $this->printing->success(__('服务器已启动 (PID: %{1})', (string)$pid));
        } elseif (Processer::isPortInUse($port)) {
            # 端口被占，但没有拿到 PID：尝试短时间重试查 PID
            for ($i = 0; $i < 10 && $pid === 0; $i++) {
                $pid = Processer::findPhpProcessPid("localhost:$port");
                if ($pid > 0) {
                    $method = 'built_in';
                    $this->printing->success(__('延迟确认服务器已启动 (PID: %{1})', (string)$pid));
                    break;
                }
                usleep(150000);
            }
            if ($pid === 0) {
                $this->printing->success(__('服务器已在端口 %{1} 监听（无法立即获取 PID），继续运行', (string)$port));
            }
        } else {
            $this->printing->warning(__('统一启动方案失败，无法启动服务器'));
        }
        
        # 如果所有后台启动方案都失败
        if ($pid === 0) {
            $this->printing->warning(__('所有后台启动方案均失败'));
            $this->printing->note(__('可能的原因：'));
            $this->printing->note(__('1. 所有进程控制函数都被禁用 (disable_functions)'));
            $this->printing->note(__('2. 系统权限限制'));
            $this->printing->note(__('3. 端口 %{1} 已被占用', (string)$port));
        }
        
        # 保存PID并更新服务器信息
        if (!empty($pid) && $pid > 0) {
            # 确保PID文件正确保存
            $pidSaved = file_put_contents($pidFile, $pid);
            if ($pidSaved === false) {
                $this->printing->warning(__('保存PID文件失败，但服务器已启动 (PID: %{1})', [(string)$pid]));
            }
            
            # 更新env.php中的服务器信息
            $this->updateServerInfo($pid, 'running', $port);
            
            $this->printing->success(__('PHPUnit报告服务器已启动 (PID: %{1}, 方法: %{2})', [(string)$pid, $method]));
            $this->printing->note(__('访问地址: 摘要 http://localhost:%{1}/report/index.html | 覆盖率 http://localhost:%{2}/coverage-html/index.html', [(string)$port, (string)$port]));
            $this->printing->note(__('日志文件: %{1}', $logFile));
            $this->printing->note(__('停止命令: php bin/w phpunit:stop'));
        } else {
            $this->printing->error(__('启动PHPUnit报告服务器失败'));
            $this->printing->note(__('建议：'));
            $this->printing->note(__('1. 检查 php.ini 中的 disable_functions 配置'));
            $this->printing->note(__('2. 确认端口 %{1} 未被占用', (string)$port));
            $this->printing->note(__('3. 尝试手动启动: php -S localhost:%{1} -t %{2}', [(string)$port, $documentRoot]));
        }
    }
    
    /**
     * 显示服务器启动信息
     * 
     * @param string $logFile 日志文件路径
     */
    private function displayTestLogs(string $logFile): void
    {
        $this->printing->note(__('PHPUnit报告服务器已在后台启动'));
        $this->printing->note(__('服务器将继续在后台运行'));
    }
    
    /**
     * 彩色输出日志行
     * 
     * @param string $line 日志行
     */
    private function colorizeLogLine(string $line): void
    {
        if (empty($line)) {
            return;
        }
        
        if (str_contains($line, 'PHPUnit') && str_contains($line, 'by Sebastian Bergmann')) {
            $this->printing->note($line);
        } elseif (str_contains($line, 'OK') || str_contains($line, 'PASSED')) {
            $this->printing->success($line);
        } elseif (str_contains($line, 'FAILURES') || str_contains($line, 'ERRORS') || str_contains($line, 'FAILED')) {
            $this->printing->error($line);
        } elseif (str_contains($line, 'WARNING') || str_contains($line, 'Warning')) {
            $this->printing->warning($line);
        } elseif (str_contains($line, 'Tests:') || str_contains($line, 'Time:')) {
            $this->printing->note($line);
        } elseif (str_contains($line, 'Development Server') && str_contains($line, 'started')) {
            $this->printing->success($line);
            $this->printing->success(__('=== PHPUNIT_SERVER_STARTED ==='));
        } else {
            echo $line . "\n";
        }
    }
    
    /**
     * 检查必需的系统函数是否可用
     * 
     * @param array $functions 需要检查的函数列表
     * @return bool 如果所有函数都可用返回true，否则返回false
     */
    private function checkRequiredFunctions(array $functions, bool $silent = false): bool
    {
        $disabledFunctions = [];
        
        foreach ($functions as $function) {
            if (!\function_exists($function)) {
                $disabledFunctions[] = $function;
            }
        }
        
        if (!empty($disabledFunctions)) {
            if (!$silent) {
                $this->printing->error(__('以下系统函数不可用或被禁用：'));
                foreach ($disabledFunctions as $func) {
                    $this->printing->note("  - $func()");
                }
                $this->printing->warning(__('这些函数可能在 php.ini 的 disable_functions 中被禁用'));
                $this->printing->note(__('解决方案：'));
                $this->printing->note(__('1. 检查 php.ini 文件中的 disable_functions 配置'));
                $this->printing->note(__('2. 从 disable_functions 中移除上述函数'));
                $this->printing->note(__('3. 重启 PHP 服务'));
                $this->printing->note(__('4. 如果是生产环境限制，建议在开发环境中运行测试'));
            }
            
            return false;
        }
        
        return true;
    }
    
    /**
     * 方案1：使用 popen/pclose (Windows) 或 exec (Linux)
     * 
     * @param string $reportPath 报告路径
     * @param int $port 端口
     * @param string $logFile 日志文件
     * @param bool $isWindows 是否为Windows系统
     * @return int 进程ID，失败返回0
     */
    private function startServerMethod1(string $reportPath, int $port, string $logFile, bool $isWindows): int
    {
        $requiredFunctions = ['exec'];
        if ($isWindows) {
            $requiredFunctions[] = 'popen';
            $requiredFunctions[] = 'pclose';
        }
        
        if (!$this->checkRequiredFunctions($requiredFunctions, true)) {
            return 0;
        }
        
        $pid = 0;
        
        if ($isWindows) {
            # Windows系统使用cmd启动后台服务器
            $command = "cmd /c start /min \"PHPUnit Server\" php -S localhost:$port -t \"$reportPath\" > \"$logFile\" 2>&1";
            \exec($command, $output, $exitCode);
            
            if ($exitCode === 0) {
                # 等待服务器启动
                sleep(2);
                
                # 查找PHP进程ID
                $output = [];
                \exec("wmic process where \"commandline like '%php%$port%'\" get processid", $output);
                foreach ($output as $line) {
                    $line = trim($line);
                    if (is_numeric($line) && $line > 0) {
                        $pid = (int)$line;
                        break;
                    }
                }
            }
        } else {
            # Linux/Mac系统使用&符号后台启动
            $command = "nohup php -S localhost:$port -t $reportPath > $logFile 2>&1 & echo $!";
            $output = [];
            \exec($command, $output);
            $pid = !empty($output) ? (int)$output[0] : 0;
        }
        
        return $pid;
    }
    
    /**
     * 方案2：使用 proc_open/proc_close
     * 
     * @param string $reportPath 报告路径
     * @param int $port 端口
     * @param string $logFile 日志文件
     * @param bool $isWindows 是否为Windows系统
     * @return int 进程ID，失败返回0
     */
    private function startServerMethod2(string $reportPath, int $port, string $logFile, bool $isWindows): int
    {
        if (!$this->checkRequiredFunctions(['proc_open', 'proc_close'], true)) {
            return 0;
        }
        
        $descriptorspec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['file', $logFile, 'w'],  // stdout
            2 => ['file', $logFile, 'a']   // stderr
        ];
        
        $command = "php -S localhost:$port -t \"$reportPath\"";
        
        if ($isWindows) {
            # Windows下使用 start /B 后台启动
            $command = "start /B $command";
        } else {
            # Linux/Mac下使用 nohup 和 & 后台启动
            $command = "nohup $command > /dev/null 2>&1 &";
        }
        
        $process = \proc_open($command, $descriptorspec, $pipes, BP);
        
        if (is_resource($process)) {
            # 关闭stdin管道
            if (isset($pipes[0])) {
                fclose($pipes[0]);
            }
            
            # 获取进程信息
            $status = \proc_get_status($process);
            $pid = $status['pid'] ?? 0;
            
            # 不要等待进程结束，让它在后台运行
            # 注意：在某些系统上，这会立即返回，进程继续在后台运行
            \proc_close($process);
            
            # 等待服务器启动
            sleep(1);
            
            # Windows需要额外查找真实的PHP服务器进程ID
            if ($isWindows && $this->checkRequiredFunctions(['exec'], true)) {
                $output = [];
                \exec("wmic process where \"commandline like '%php%$port%'\" get processid", $output);
                foreach ($output as $line) {
                    $line = trim($line);
                    if (is_numeric($line) && $line > 0) {
                        $pid = (int)$line;
                        break;
                    }
                }
            }
            
            return $pid;
        }
        
        return 0;
    }
    
    /**
     * 方案3：使用 shell_exec（最后的降级方案）
     * 
     * @param string $reportPath 报告路径
     * @param int $port 端口
     * @param string $logFile 日志文件
     * @param bool $isWindows 是否为Windows系统
     * @return int 进程ID，失败返回0
     */
    private function startServerMethod3(string $reportPath, int $port, string $logFile, bool $isWindows): int
    {
        if (!$this->checkRequiredFunctions(['shell_exec'], true)) {
            return 0;
        }
        
        $pid = 0;
        
        if ($isWindows) {
            # 创建批处理文件来启动服务器
            $batFile = BP . 'var' . DS . 'start_phpunit_server_' . $port . '.bat';
            $vbsFile = BP . 'var' . DS . 'start_phpunit_server_' . $port . '.vbs';
            
            # 创建批处理文件内容
            $batContent = "@echo off\n";
            $batContent .= "php -S localhost:$port -t \"$reportPath\" > \"$logFile\" 2>&1\n";
            $batContent .= "exit\n";
            file_put_contents($batFile, $batContent);
            
            # 创建VBScript文件来隐藏窗口启动
            $vbsContent = "Set WshShell = CreateObject(\"WScript.Shell\")\n";
            $vbsContent .= "WshShell.Run \"\"\"$batFile\"\"\", 0, False\n";
            file_put_contents($vbsFile, $vbsContent);
            
            # 使用VBScript启动（真正的后台启动）
            exec("cscript //nologo \"$vbsFile\"");
            
            # 等待服务器启动
            sleep(2);
            
            # 尝试查找进程ID
            if ($this->checkRequiredFunctions(['exec'], true)) {
                $output = [];
                \exec("wmic process where \"commandline like '%php%$port%'\" get processid", $output);
                foreach ($output as $line) {
                    $line = trim($line);
                    if (is_numeric($line) && $line > 0) {
                        $pid = (int)$line;
                        break;
                    }
                }
            }
        } else {
            # Linux/Mac系统
            $command = "nohup php -S localhost:$port -t $reportPath > $logFile 2>&1 & echo $!";
            $output = \shell_exec($command);
            $pid = $output ? (int)trim($output) : 0;
        }
        
        return $pid;
    }
    
    /**
     * 停止PHPUnit报告服务器
     */
    private function stopPhpUnitServer(): void
    {
        $pidFile = BP . 'var' . DS . 'phpunit_server.pid';
        
        if (!file_exists($pidFile)) {
            $this->printing->note(__('PHPUnit报告服务器未运行'));
            return;
        }
        
        $pid = (int)file_get_contents($pidFile);
        
        if (!Processer::isRunningByPid($pid)) {
            $this->printing->note(__('PHPUnit报告服务器未运行 (PID: %{1})', (string)$pid));
            unlink($pidFile);
            return;
        }
        
        # 使用多层降级策略停止服务器
        $success = false;
        $method = '';
        
        # 方案1：使用 exec
        if ($this->checkRequiredFunctions(['exec'], true)) {
            $this->printing->note(__('尝试使用 exec 停止服务器...'));
            if (PHP_OS_FAMILY === 'Windows') {
                \exec("taskkill /PID $pid /F", $output, $return_var);
            } else {
                \exec("kill $pid", $output, $return_var);
            }
            $success = ($return_var === 0);
            if ($success) {
                $method = 'exec';
                $this->printing->success(__('使用 exec 成功停止！'));
            }
        }
        
        # 方案2：使用 shell_exec
        if (!$success && $this->checkRequiredFunctions(['shell_exec'], true)) {
            $this->printing->warning(__('exec 失败，尝试使用 shell_exec...'));
            if (PHP_OS_FAMILY === 'Windows') {
                $result = \shell_exec("taskkill /PID $pid /F 2>&1");
            } else {
                $result = \shell_exec("kill $pid 2>&1");
            }
            # 等待一下看进程是否被终止
            sleep(1);
            $success = !Processer::isRunningByPid($pid);
            if ($success) {
                $method = 'shell_exec';
                $this->printing->success(__('使用 shell_exec 成功停止！'));
            }
        }
        
        # 方案3：使用 proc_open (最后的尝试)
        if (!$success && $this->checkRequiredFunctions(['proc_open', 'proc_close'], true)) {
            $this->printing->warning(__('shell_exec 失败，尝试使用 proc_open...'));
            $descriptorspec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w']
            ];
            
            if (PHP_OS_FAMILY === 'Windows') {
                $command = "taskkill /PID $pid /F";
            } else {
                $command = "kill $pid";
            }
            
            $process = \proc_open($command, $descriptorspec, $pipes);
            if (is_resource($process)) {
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                \proc_close($process);
                
                # 等待一下看进程是否被终止
                sleep(1);
                $success = !Processer::isRunningByPid($pid);
                if ($success) {
                    $method = 'proc_open';
                    $this->printing->success(__('使用 proc_open 成功停止！'));
                }
            }
        }
        
        # 如果所有方法都失败
        if (!$success) {
            $this->printing->error(__('无法自动停止PHPUnit报告服务器'));
            $this->printing->warning(__('所有停止方法均失败，可能是因为：'));
            $this->printing->note(__('1. 所有进程控制函数都被禁用 (disable_functions)'));
            $this->printing->note(__('2. 没有足够的权限终止进程'));
            $this->printing->note(__(''));
            $this->printing->note(__('请手动停止进程 (PID: %{1}):', (string)$pid));
            if (PHP_OS_FAMILY === 'Windows') {
                $this->printing->note(__('  Windows命令: taskkill /PID %{1} /F', (string)$pid));
            } else {
                $this->printing->note(__('  Linux/Mac命令: kill %{1}', (string)$pid));
            }
            return;
        }
        
        # 清理PID文件
        unlink($pidFile);
        
        # 更新env.php中的服务器信息
        $this->updateServerInfo(0, 'stopped');
        
        $this->printing->success(__('PHPUnit报告服务器已停止 (PID: %{1}, 方法: %{2})', [(string)$pid, $method]));
    }
    
    /**
     * 检查进程是否在运行
     * 
     * @param int $pid 进程ID
     * @return bool
     */
    
    /**
     * 更新env.php中的服务器信息
     * 
     * @param int $pid 进程ID
     * @param string $status 状态
     * @param int $port 端口号
     */
    private function updateServerInfo(int $pid, string $status = 'running', int $port = 9980): void
    {
        $envFile = BP . 'app' . DS . 'etc' . DS . 'env.php';
        
        if (!file_exists($envFile)) {
            return;
        }
        
        $env = include $envFile;
        
        if (!isset($env['dev'])) {
            $env['dev'] = [];
        }
        
        $env['dev']['phpunit_server'] = [
            'host' => '127.0.0.1',
            'port' => $port,
            'pid' => $pid,
            'start_time' => time(),
            'status' => $status,
        ];
        
        # 写入env.php文件
        $content = "<?php return " . var_export($env, true) . ";";
        file_put_contents($envFile, $content);
    }
    
    /**
     * 生成套件测试配置
     * 
     * @param string $reportPath 报告路径
     * @return string
     */
    private function generateSuiteConfig(string $reportPath): string
    {
        $modules = Env::getInstance()->getActiveModules();
        $php_unit_xml = '<?xml version=\'1.0\' encoding=\'UTF-8\'?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="http://schema.phpunit.de/6.2/phpunit.xsd"
         backupGlobals="false"
         backupStaticAttributes="false"
         bootstrap="../../app/bootstrap.php"
         colors="true"
         convertErrorsToExceptions="true"
         convertNoticesToExceptions="true"
         convertWarningsToExceptions="true"
         defaultTestSuite="unit"
         processIsolation="false"
         stopOnFailure="false">
    <testsuites>';
        
        $exist_suites = [];
        foreach ($modules as $module) {
            $test_path = $module['base_path'] . 'test' . DS;
            $testsuite_path = $test_path . 'testsuite.xml';
            if (is_dir($test_path)) {
                $testsuites = '';
                if (is_file($testsuite_path)) {
                    $xml = simplexml_load_file($testsuite_path);
                    foreach ($xml->children() as $testsuite) {
                        $testsuite = get_object_vars($testsuite);
                        if (!isset($testsuite['@attributes']['name'])) {
                            $this->printing->error(__('testsuite套件配置错误,未配置套件名：%{1} ，示例：<testsuite name="unit"><file>CacheTest.php</file></testsuite>', [$testsuite_path]));
                            return '';
                        }
                        $suite_name = $testsuite['@attributes']['name'] ?? $module['name'];
                        unset($testsuite['@attributes']);
                        foreach ($testsuite as $key => $testsuite_data) {
                            if (($key === 'file' or $key === 'directory') and !str_starts_with(BP, $testsuite_data)) {
                                $testsuite_data = $test_path . $testsuite_data;
                            }
                            $exist_suites[$suite_name] = $suite_name;
                            $testsuites .= "
        <testsuite name='unit'>
            <{$key}>{$testsuite_data}</{$key}>
        </testsuite>
        <testsuite name='$suite_name'>
            <{$key}>{$testsuite_data}</{$key}>
        </testsuite>
                        ";
                        }
                    }
                } else {
                    $exist_suites[$module['name']] = $module['name'];
                    $testsuites .= "
        <testsuite name='unit'>
            <directory suffix=\"Test.php\">$test_path</directory>
        </testsuite>
        <testsuite name='{$module['name']}'>
            <directory suffix=\"Test.php\">$test_path</directory>
        </testsuite>
                        ";
                }
                $php_unit_xml .= "
            $testsuites
            ";
            }
        }
        
        # 添加Framework模块测试
        $app_code_weline_framework_dir = APP_CODE_PATH . 'Weline' . DS . 'Framework' . DS;
        # 兼容 Weline\Framework\*\test 与 Weline\Framework\*\Test
        $code_framework_modules = glob($app_code_weline_framework_dir . '*' . DS . 'test', GLOB_ONLYDIR);
        $code_framework_modules_uc = glob($app_code_weline_framework_dir . '*' . DS . 'Test', GLOB_ONLYDIR);
        if (is_array($code_framework_modules_uc) and !empty($code_framework_modules_uc)) {
            $code_framework_modules = array_merge($code_framework_modules ?: [], $code_framework_modules_uc);
        }
        foreach ($code_framework_modules as $key => $test_dir) {
            $key_new = str_replace($app_code_weline_framework_dir, '', $test_dir);
            $key_new = explode(DS, $key_new);
            array_pop($key_new);
            $key_new = implode(':', $key_new);
            unset($code_framework_modules[$key]);
            $code_framework_modules[$key_new] = $test_dir;
        }
        $vendor_code_weline_framework_dir = APP_CODE_PATH . 'weline' . DS . 'framework' . DS;
        # 兼容 vendor/weline/framework/*/test 与 vendor/weline/framework/*/Test
        $vendor_framework_modules = glob($vendor_code_weline_framework_dir . '*' . DS . 'test', GLOB_ONLYDIR);
        $vendor_framework_modules_uc = glob($vendor_code_weline_framework_dir . '*' . DS . 'Test', GLOB_ONLYDIR);
        if (is_array($vendor_framework_modules_uc) and !empty($vendor_framework_modules_uc)) {
            $vendor_framework_modules = array_merge($vendor_framework_modules ?: [], $vendor_framework_modules_uc);
        }
        foreach ($vendor_framework_modules as $key => $test_dir) {
            $key_new = str_replace($vendor_code_weline_framework_dir, '', $test_dir);
            $key_new = explode(DS, $key_new);
            array_pop($key_new);
            $key_new = implode(':', $key_new);
            unset($vendor_framework_modules[$key]);
            $vendor_framework_modules[$key_new] = $test_dir;
        }
        $framework_modules = array_merge($vendor_framework_modules, $code_framework_modules);
        foreach ($framework_modules as $path_name => $test_path) {
            $path_name = str_replace(DS, ':', $path_name);
            $test_path = $test_path . DS;
            $testsuite_path = $test_path . 'testsuite.xml';
            if (is_dir($test_path)) {
                $testsuites = '';
                if (is_file($testsuite_path)) {
                    $xml = simplexml_load_file($testsuite_path);
                    foreach ($xml->children() as $testsuite) {
                        $testsuite = get_object_vars($testsuite);
                        if (!isset($testsuite['@attributes']['name'])) {
                            $this->printing->error(__('testsuite套件配置错误,未配置套件名：%{1} ，示例：<testsuite name="unit"><file>CacheTest.php</file></testsuite>', [$testsuite_path]));
                            return '';
                        }
                        $suite_name = $testsuite['@attributes']['name'] ?? '';
                        if (empty($suite_name)) {
                            $suite_name = "framework::" . $path_name;
                        }
                        unset($testsuite['@attributes']);
                        foreach ($testsuite as $key => $testsuite_data) {
                            if (($key === 'file' or $key === 'directory') and !str_starts_with(BP, $testsuite_data)) {
                                $testsuite_data = $test_path . $testsuite_data;
                            }
                            $testsuites .= "
                                            <testsuite name='framework'>
                                                <{$key}>{$testsuite_data}</{$key}>
                                            </testsuite>
                                            <testsuite name='unit'>
                                                <{$key}>{$testsuite_data}</{$key}>
                                            </testsuite>
                                            <testsuite name='$suite_name'>
                                                <{$key}>{$testsuite_data}</{$key}>
                                            </testsuite>
                                            ";
                        }
                    }
                } else {
                    $suite_name = "framework::" . $path_name;
                    $testsuites .= "
                                    <testsuite name='framework'>
                                        <directory suffix=\"Test.php\">$test_path</directory>
                                    </testsuite>
                                    <testsuite name='unit'>
                                        <directory suffix=\"Test.php\">$test_path</directory>
                                    </testsuite>
                                    <testsuite name='$suite_name'>
                                        <directory suffix=\"Test.php\">$test_path</directory>
                                    </testsuite>
                        ";
                }
                $php_unit_xml .= $testsuites;
            }
        }

        $coverageRoot = $this->normalizeCoverageSourcePath('../../app/code');

        $php_unit_xml .= '</testsuites>
<coverage processUncoveredFiles="true">
        <include>
            <directory suffix=".php">' . $coverageRoot . '</directory>
        </include>
        <exclude>
            <directory suffix=".php">' . $coverageRoot . '/*/*/Test</directory>
            <directory suffix=".php">' . $coverageRoot . '/*/*/test</directory>
            <directory suffix=".php">' . $coverageRoot . '/*/*/view</directory>
            <directory suffix=".php">' . $coverageRoot . '/*/*/Console</directory>
            <directory suffix=".php">' . $coverageRoot . '/*/*/env</directory>
        </exclude>
    </coverage>
     <logging>
        <junit outputFile="' . $reportPath . '/junit.xml"/>
        <teamcity outputFile="' . $reportPath . '/teamcity.txt"/>
        <testdoxHtml outputFile="' . $reportPath . '/index.html"/>
        <testdoxText outputFile="' . $reportPath . '/testdox.txt"/>
        <testdoxXml outputFile="' . $reportPath . '/testdox.xml"/>
        <text outputFile="' . $reportPath . '/logfile.txt"/>
     </logging>
</phpunit>';
        
        return $php_unit_xml;
    }
    
    /**
     * 生成模块测试配置
     * 
     * @param string $moduleName 模块名
     * @param string $reportPath 报告路径
     * @return string
     */
    private function generateModuleConfig(string $moduleName, string $reportPath): string
    {
        $modules = Env::getInstance()->getActiveModules();
        $targetModule = null;
        
        # 查找指定模块
        foreach ($modules as $module) {
            if ($module['name'] === $moduleName || 
                str_contains($module['name'], $moduleName) ||
                str_contains($moduleName, $module['name'])) {
                $targetModule = $module;
                break;
            }
        }
        
        if (!$targetModule) {
            $this->printing->error(__('未找到模块: %{1}', [$moduleName]));
            return '';
        }
        
        $test_path = $this->resolveTestPath($targetModule['base_path']);
        if ($test_path === null) {
            $this->printing->error(__('模块 %{1} 没有测试目录', [$moduleName]));
            return '';
        }
        
        $coverageSource = $this->normalizeCoverageSourcePath($targetModule['base_path']);
        $php_unit_xml = '<?xml version=\'1.0\' encoding=\'UTF-8\'?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="http://schema.phpunit.de/6.2/phpunit.xsd"
         backupGlobals="false"
         backupStaticAttributes="false"
         bootstrap="../../app/bootstrap.php"
         colors="true"
         convertErrorsToExceptions="true"
         convertNoticesToExceptions="true"
         convertWarningsToExceptions="true"
         defaultTestSuite="' . $moduleName . '"
         processIsolation="false"
         stopOnFailure="false">
    <testsuites>
        <testsuite name="' . $moduleName . '">
            <directory suffix="Test.php">' . $test_path . '</directory>
        </testsuite>
    </testsuites>
<coverage processUncoveredFiles="true">
        <include>
            <directory suffix=".php">' . $coverageSource . '</directory>
        </include>
        <exclude>
            <directory suffix=".php">' . $coverageSource . '/Test</directory>
            <directory suffix=".php">' . $coverageSource . '/test</directory>
            <directory suffix=".php">' . $coverageSource . '/view</directory>
            <directory suffix=".php">' . $coverageSource . '/Console</directory>
            <directory suffix=".php">' . $coverageSource . '/env</directory>
        </exclude>
    </coverage>
     <logging>
        <junit outputFile="' . $reportPath . '/junit.xml"/>
        <teamcity outputFile="' . $reportPath . '/teamcity.txt"/>
        <testdoxHtml outputFile="' . $reportPath . '/index.html"/>
        <testdoxText outputFile="' . $reportPath . '/testdox.txt"/>
        <testdoxXml outputFile="' . $reportPath . '/testdox.xml"/>
        <text outputFile="' . $reportPath . '/logfile.txt"/>
     </logging>
</phpunit>';
        
        return $php_unit_xml;
    }
    
    /**
     * 生成文件测试配置
     * 
     * @param string $fileName 文件名
     * @param string $reportPath 报告路径
     * @return string
     */
    private function generateFileConfig(string $fileName, string $reportPath): string
    {
        # 查找测试文件
        $testFile = $this->findTestFile($fileName, false);
        if (!$testFile) {
            # 如果找不到文件，尝试查找包含该文件的测试目录
            # 这样可以让 Pest 从目录中查找测试文件
            $modules = Env::getInstance()->getActiveModules();
            $testDirs = [];
            foreach ($modules as $module) {
                $testPath = $module['base_path'] . 'test' . DS;
                if (is_dir($testPath)) {
                    $testDirs[] = $testPath;
                } else {
                    $testPath = $module['base_path'] . 'Test' . DS;
                    if (is_dir($testPath)) {
                        $testDirs[] = $testPath;
                    }
                }
            }
            
            # 如果找到了测试目录，生成包含这些目录的配置
            if (!empty($testDirs)) {
                $this->printing->note(__('未找到测试文件: %{1}，将搜索所有测试目录', [$fileName]));
                # 生成包含所有测试目录的配置，并使用 filter 参数过滤
                $php_unit_xml = '<?xml version=\'1.0\' encoding=\'UTF-8\'?>';
                $php_unit_xml .= '<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"';
                $php_unit_xml .= ' xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"';
                $php_unit_xml .= ' bootstrap="../../app/bootstrap_phpunit.php"';
                $php_unit_xml .= ' colors="true"';
                $php_unit_xml .= ' beStrictAboutTestsThatDoNotTestAnything="false"';
                $php_unit_xml .= ' beStrictAboutOutputDuringTests="false"';
                $php_unit_xml .= ' beStrictAboutTodoAnnotatedTests="false"';
                $php_unit_xml .= ' convertErrorsToExceptions="true"';
                $php_unit_xml .= ' convertNoticesToExceptions="true"';
                $php_unit_xml .= ' convertWarningsToExceptions="true"';
                $php_unit_xml .= ' processIsolation="false"';
                $php_unit_xml .= ' stopOnFailure="false">';
                $php_unit_xml .= '<testsuites>';
                $php_unit_xml .= '<testsuite name="file">';
                foreach ($testDirs as $testDir) {
                    $php_unit_xml .= '<directory suffix="Test.php">' . $testDir . '</directory>';
                }
                $php_unit_xml .= '</testsuite>';
                $php_unit_xml .= '</testsuites>';
                $php_unit_xml .= '<coverage processUncoveredFiles="true">';
                $php_unit_xml .= '<include>';
                $php_unit_xml .= '<directory suffix=".php">../../app/code</directory>';
                $php_unit_xml .= '</include>';
                $php_unit_xml .= '<exclude>';
                $php_unit_xml .= '<directory suffix=".php">../../app/code/*/*/Test</directory>';
                $php_unit_xml .= '<directory suffix=".php">../../app/code/*/*/test</directory>';
                $php_unit_xml .= '<directory suffix=".php">../../app/code/*/*/view</directory>';
                $php_unit_xml .= '<directory suffix=".php">../../app/code/*/*/Console</directory>';
                $php_unit_xml .= '<directory suffix=".php">../../app/code/*/*/env</directory>';
                $php_unit_xml .= '</exclude>';
                $php_unit_xml .= '</coverage>';
                $php_unit_xml .= '<logging>';
                $php_unit_xml .= '<junit outputFile="' . $reportPath . '/junit.xml"/>';
                $php_unit_xml .= '<teamcity outputFile="' . $reportPath . '/teamcity.txt"/>';
                $php_unit_xml .= '<testdoxHtml outputFile="' . $reportPath . '/index.html"/>';
                $php_unit_xml .= '<testdoxText outputFile="' . $reportPath . '/testdox.txt"/>';
                $php_unit_xml .= '<testdoxXml outputFile="' . $reportPath . '/testdox.xml"/>';
                $php_unit_xml .= '<text outputFile="' . $reportPath . '/logfile.txt"/>';
                $php_unit_xml .= '</logging>';
                $php_unit_xml .= '</phpunit>';
                return $php_unit_xml;
            }
            
            $this->printing->error(__('未找到测试文件: %{1}', [$fileName]));
            return '';
        }

        $coverageSource = $this->resolveCoverageSourceForTestFile($testFile);

        $php_unit_xml = '<?xml version=\'1.0\' encoding=\'UTF-8\'?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="http://schema.phpunit.de/6.2/phpunit.xsd"
         backupGlobals="false"
         backupStaticAttributes="false"
         bootstrap="../../app/bootstrap.php"
         colors="true"
         convertErrorsToExceptions="true"
         convertNoticesToExceptions="true"
         convertWarningsToExceptions="true"
         defaultTestSuite="file"
         processIsolation="false"
         stopOnFailure="false">
    <testsuites>
        <testsuite name="file">
            <file>' . $testFile . '</file>
        </testsuite>
    </testsuites>
<coverage processUncoveredFiles="true">
        <include>
            <directory suffix=".php">' . $coverageSource . '</directory>
        </include>
        <exclude>
            <directory suffix=".php">' . $coverageSource . '/Test</directory>
            <directory suffix=".php">' . $coverageSource . '/test</directory>
            <directory suffix=".php">' . $coverageSource . '/view</directory>
            <directory suffix=".php">' . $coverageSource . '/Console</directory>
            <directory suffix=".php">' . $coverageSource . '/env</directory>
        </exclude>
    </coverage>
     <logging>
        <junit outputFile="' . $reportPath . '/junit.xml"/>
        <teamcity outputFile="' . $reportPath . '/teamcity.txt"/>
        <testdoxHtml outputFile="' . $reportPath . '/index.html"/>
        <testdoxText outputFile="' . $reportPath . '/testdox.txt"/>
        <testdoxXml outputFile="' . $reportPath . '/testdox.xml"/>
        <text outputFile="' . $reportPath . '/logfile.txt"/>
     </logging>
</phpunit>';
        
        return $php_unit_xml;
    }
    
    /**
     * 测试文件名智能匹配（修复：避免 str_contains 反向匹配把 t.php 这类短 basename 错配到长 fileName）。
     * 规则（任意一条命中即视为匹配）：
     *   1) basename 与输入完全相等；
     *   2) basename === input.'Test'（自动补 Test 后缀）；
     *   3) basename 以 input 开头并以 'Test' 结尾，且 input 长度 >= 3（用于支持前缀搜索）。
     */
    private function isTestFileNameMatch(string $basename, string $actualFileName): bool
    {
        if ($basename === $actualFileName) {
            return true;
        }
        if ($basename === $actualFileName . 'Test') {
            return true;
        }
        if (\strlen($actualFileName) >= 3
            && \str_starts_with($basename, $actualFileName)
            && \str_ends_with($basename, 'Test')
        ) {
            return true;
        }
        return false;
    }

    /**
     * 查找测试文件
     * 
     * @param string $fileName 文件名
     * @return string|null
     */
    private function findTestFile(string $fileName, bool $debug = false): ?string
    {
        $modules = Env::getInstance()->getActiveModules();
        
        # 检查是否是测试方法名（包含::）
        $isTestMethod = str_contains($fileName, '::');
        $actualFileName = $isTestMethod ? explode('::', $fileName)[0] : $fileName;
        # 支持使用 \ 或 / 指定子目录，例如 Extends\Unit\ExtendsScannerTest
        $normalizedName = str_replace(['\\', '/'], DS, $actualFileName);
        
        # 调试信息
        if ($debug) {
            $this->printing->note(__('调试 - 查找文件: %{1}', [$fileName]));
            $this->printing->note(__('调试 - 是否测试方法: %{1}', [$isTestMethod ? '是' : '否']));
            $this->printing->note(__('调试 - 实际文件名: %{1}', [$actualFileName]));
            $this->printing->note(__('调试 - 规范化名称: %{1}', [$normalizedName]));
            $this->printing->note(__('调试 - 活跃模块数: %{1}', [count($modules)]));
        }
        
        foreach ($modules as $module) {
            $test_path = $this->resolveTestPath($module['base_path']);
            if ($test_path !== null) {
                # 调试信息
                if ($debug) {
                    $this->printing->note(__('调试 - 检查模块: %{1}, 测试路径: %{2}', [$module['name'], $test_path]));
                }
                
                # 尝试直接文件名
                $possibleFile = $test_path . $actualFileName;
                if (file_exists($possibleFile)) {
                    if ($debug) {
                        $this->printing->note(__('调试 - 找到文件: %{1}', [$possibleFile]));
                    }
                    return $possibleFile;
                }
                
                # 如果文件名不包含.php，尝试添加
                if (!str_ends_with($actualFileName, '.php')) {
                    $possibleFile = $test_path . $actualFileName . '.php';
                    if (file_exists($possibleFile)) {
                        if ($debug) {
                            $this->printing->note(__('调试 - 找到文件: %{1}', [$possibleFile]));
                        }
                        return $possibleFile;
                    }
                }
                
                # 如果文件名不包含Test.php，尝试添加
                if (!str_ends_with($actualFileName, 'Test.php')) {
                    $possibleFile = $test_path . $actualFileName . 'Test.php';
                    if (file_exists($possibleFile)) {
                        if ($debug) {
                            $this->printing->note(__('调试 - 找到文件: %{1}', [$possibleFile]));
                        }
                        return $possibleFile;
                    }
                }
                
                # 如果文件名不包含Test，尝试添加Test
                if (!str_ends_with($actualFileName, 'Test')) {
                    $possibleFile = $test_path . $actualFileName . 'Test.php';
                    if (file_exists($possibleFile)) {
                        if ($debug) {
                            $this->printing->note(__('调试 - 找到文件: %{1}', [$possibleFile]));
                        }
                        return $possibleFile;
                    }
                }
                
                # 如果文件名以Test开头，尝试去掉Test前缀
                if (str_starts_with($actualFileName, 'Test')) {
                    $withoutTest = substr($actualFileName, 4); // 去掉 "Test" 前缀
                    $possibleFile = $test_path . $withoutTest . 'Test.php';
                    if (file_exists($possibleFile)) {
                        if ($debug) {
                            $this->printing->note(__('调试 - 找到文件: %{1}', [$possibleFile]));
                        }
                        return $possibleFile;
                    }
                }
                
                # 递归查找子目录（支持 Unit、Integration 等子目录）
                if (is_dir($test_path)) {
                    $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($test_path, \RecursiveDirectoryIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::SELF_FIRST
                    );
                    
                    foreach ($iterator as $file) {
                        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                            $basename = $file->getBasename('.php');
                            if ($this->isTestFileNameMatch($basename, $actualFileName)) {
                                if ($debug) {
                                    $this->printing->note(__('调试 - 递归找到文件: %{1}', [$file->getPathname()]));
                                }
                                return $file->getPathname();
                            }
                        }
                    }
                }
            }
        }
        
        # 也检查Framework模块
        $app_code_weline_framework_dir = APP_CODE_PATH . 'Weline' . DS . 'Framework' . DS;
        # 兼容小写 test 与 大写 Test
        $code_framework_modules = glob($app_code_weline_framework_dir . '*' . DS . 'test', GLOB_ONLYDIR);
        $code_framework_modules_uc = glob($app_code_weline_framework_dir . '*' . DS . 'Test', GLOB_ONLYDIR);
        if (is_array($code_framework_modules_uc) and !empty($code_framework_modules_uc)) {
            $code_framework_modules = array_merge($code_framework_modules ?: [], $code_framework_modules_uc);
        }
        foreach ($code_framework_modules as $test_dir) {
            $submodule = basename(dirname($test_dir)); # 如 Extends
            $relName = $normalizedName;
            if ($submodule && str_starts_with($relName, $submodule . DS)) {
                $relName = substr($relName, strlen($submodule . DS));
            }
            # 尝试直接相对路径
            $possibleFile = $test_dir . DS . $relName;
            if (file_exists($possibleFile)) {
                return $possibleFile;
            }
            
            # 如果文件名不包含.php，尝试添加
            if (!str_ends_with($relName, '.php')) {
                $possibleFile = $test_dir . DS . $relName . '.php';
                if (file_exists($possibleFile)) {
                    return $possibleFile;
                }
            }
            
            # 如果文件名不包含Test.php，尝试添加
            $lastSeg = basename($relName);
            $dirPrefix = rtrim(substr($relName, 0, -strlen($lastSeg)), DS);
            if (!str_ends_with($lastSeg, 'Test.php')) {
                $possibleFile = $test_dir . DS . ($dirPrefix ? $dirPrefix . DS : '') . $lastSeg . 'Test.php';
                if (file_exists($possibleFile)) {
                    return $possibleFile;
                }
            }
            
            # 如果文件名不包含Test，尝试添加Test
            if (!str_ends_with($lastSeg, 'Test')) {
                $possibleFile = $test_dir . DS . ($dirPrefix ? $dirPrefix . DS : '') . $lastSeg . 'Test.php';
                if (file_exists($possibleFile)) {
                    return $possibleFile;
                }
            }
            
            # 如果文件名以Test开头，尝试去掉Test前缀
            if (str_starts_with($lastSeg, 'Test')) {
                $withoutTest = substr($lastSeg, 4); // 去掉 "Test" 前缀
                $possibleFile = $test_dir . DS . ($dirPrefix ? $dirPrefix . DS : '') . $withoutTest . 'Test.php';
                if (file_exists($possibleFile)) {
                    return $possibleFile;
                }
            }
        }
        
        return null;
    }
    
    /**
     * 在指定模块中查找测试文件
     * 
     * @param string $fileName 文件名
     * @param string $moduleName 模块名
     * @return string|null
     */
    private function findTestFileInModule(string $fileName, string $moduleName): ?string
    {
        $modules = Env::getInstance()->getActiveModules();
        
        # 检查是否是测试方法名（包含::）
        $actualFileName = str_contains($fileName, '::') ? explode('::', $fileName)[0] : $fileName;
        $normalizedName = str_replace(['\\', '/'], DS, $actualFileName);
        
        foreach ($modules as $module) {
            if (str_contains($module['name'], $moduleName)) {
                # 候选测试根目录：test、Test、以及子模块下的 test/Test
                $candidateRoots = [];
                $base = $module['base_path'];
                if (is_dir($base . 'test' . DS)) $candidateRoots[] = $base . 'test' . DS;
                if (is_dir($base . 'Test' . DS)) $candidateRoots[] = $base . 'Test' . DS;
                $subTests = glob($base . '*' . DS . 'test', GLOB_ONLYDIR) ?: [];
                $subTestsUc = glob($base . '*' . DS . 'Test', GLOB_ONLYDIR) ?: [];
                foreach (array_merge($subTests, $subTestsUc) as $root) {
                    $candidateRoots[] = rtrim($root, DS) . DS;
                }
                
                foreach ($candidateRoots as $root) {
                    $submodule = basename(dirname(rtrim($root, DS)));
                    $relName = $normalizedName;
                    if ($submodule && str_starts_with($relName, $submodule . DS)) {
                        $relName = substr($relName, strlen($submodule . DS));
                    }
                    
                    # 直接相对路径
                    $possibleFile = $root . $relName;
                    if (file_exists($possibleFile)) return $possibleFile;
                    
                    # 尝试添加 .php
                    if (!str_ends_with($relName, '.php')) {
                        $possibleFile = $root . $relName . '.php';
                        if (file_exists($possibleFile)) return $possibleFile;
                    }
                    
                    # 针对最后段名处理 Test.php
                    $lastSeg = basename($relName);
                    $dirPrefix = rtrim(substr($relName, 0, -strlen($lastSeg)), DS);
                    if (!str_ends_with($lastSeg, 'Test.php')) {
                        $possibleFile = $root . ($dirPrefix ? $dirPrefix . DS : '') . $lastSeg . 'Test.php';
                        if (file_exists($possibleFile)) return $possibleFile;
                    }
                    if (!str_ends_with($lastSeg, 'Test')) {
                        $possibleFile = $root . ($dirPrefix ? $dirPrefix . DS : '') . $lastSeg . 'Test.php';
                        if (file_exists($possibleFile)) return $possibleFile;
                    }
                    if (str_starts_with($lastSeg, 'Test')) {
                        $withoutTest = substr($lastSeg, 4);
                        $possibleFile = $root . ($dirPrefix ? $dirPrefix . DS : '') . $withoutTest . 'Test.php';
                        if (file_exists($possibleFile)) return $possibleFile;
                    }

                    if (is_dir($root)) {
                        $iterator = new \RecursiveIteratorIterator(
                            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
                            \RecursiveIteratorIterator::SELF_FIRST
                        );

                        foreach ($iterator as $file) {
                            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                                continue;
                            }

                            $basename = $file->getBasename('.php');
                            if ($this->isTestFileNameMatch($basename, $actualFileName)) {
                                return $file->getPathname();
                            }
                        }
                    }
                }
                break;
            }
        }
        
        return null;
    }
    
    /**
     * 生成模块文件配置
     * 
     * @param string $moduleName 模块名
     * @param string $fileName 文件名
     * @param string $reportPath 报告路径
     * @return string
     */
    private function generateModuleFileConfig(string $moduleName, string $fileName, string $reportPath): string
    {
        $testFile = $this->findTestFileInModule($fileName, $moduleName);
        if (!$testFile) {
            $this->printing->error(__('在模块 %{1} 中未找到测试文件: %{2}', [$moduleName, $fileName]));
            return '';
        }
        
        $coverageSource = $this->resolveCoverageSourceForTestFile($testFile);
        $php_unit_xml = '<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.6/phpunit.xsd"
         bootstrap="../../app/bootstrap_phpunit.php"
         colors="true"
         beStrictAboutTestsThatDoNotTestAnything="false"
         beStrictAboutOutputDuringTests="false"
         beStrictAboutTodoAnnotatedTests="false"
         convertErrorsToExceptions="true"
         convertNoticesToExceptions="true"
         convertWarningsToExceptions="true"
         processIsolation="false"
         stopOnFailure="false">
    <testsuites>
        <testsuite name="module_file">
            <file>' . $testFile . '</file>
        </testsuite>
    </testsuites>
<coverage processUncoveredFiles="true">
        <include>
            <directory suffix=".php">' . $coverageSource . '</directory>
        </include>
        <exclude>
            <directory suffix=".php">' . $coverageSource . '/Test</directory>
            <directory suffix=".php">' . $coverageSource . '/test</directory>
            <directory suffix=".php">' . $coverageSource . '/view</directory>
            <directory suffix=".php">' . $coverageSource . '/Console</directory>
            <directory suffix=".php">' . $coverageSource . '/env</directory>
        </exclude>
    </coverage>
     <logging>
        <junit outputFile="' . $reportPath . '/junit.xml"/>
        <teamcity outputFile="' . $reportPath . '/teamcity.txt"/>
        <testdoxHtml outputFile="' . $reportPath . '/index.html"/>
        <testdoxText outputFile="' . $reportPath . '/testdox.txt"/>
        <testdoxXml outputFile="' . $reportPath . '/testdox.xml"/>
        <text outputFile="' . $reportPath . '/logfile.txt"/>
     </logging>
</phpunit>';
        
        return $php_unit_xml;
    }
    
    /**
     * 构建完整的测试类名
     * 
     * @param string $fileName 文件名（可能包含测试方法）
     * @param string $testFile 测试文件的完整路径
     * @return string
     */
    private function buildFullClassName(string $fileName, string $testFile): string
    {
        # 从文件路径中提取命名空间和类名
        $relativePath = str_replace(BP, '', $testFile);
        $pathParts = explode(DS, trim($relativePath, DS));
        
        # 构建命名空间（从 app/code 开始）
        $namespace = '';
        $inCode = false;
        $inTest = false;
        foreach ($pathParts as $part) {
            if ($part === 'app') {
                $inCode = true;
                continue;
            }
            if ($inCode && $part === 'code') {
                continue;
            }
            if ($inCode && $part === 'test') {
                $inTest = true;
                continue;
            }
            if ($inCode && !$inTest) {
                $namespace .= '\\' . $part;
            }
        }
        
        # 获取文件名（不含扩展名）
        $className = basename($testFile, '.php');
        
        # 构建完整的类名
        $fullClassName = $namespace . '\\test\\' . $className;
        
        # 如果输入包含测试方法，添加方法名
        if (str_contains($fileName, '::')) {
            $methodName = explode('::', $fileName)[1];
            $fullClassName .= '::' . $methodName;
        }
        
        return $fullClassName;
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return 'PHPUnit测试套件运行工具，支持套件、模块、文件和方法级别的测试';
    }

    public function help(): array|string
    {
        return '
════════════════════════════════════════════════════════════════════════════════
命令名称: phpunit:run
════════════════════════════════════════════════════════════════════════════════

📖 描述：
    单元测试运行命令，支持 PHPUnit 和 Pest 两种测试框架
    默认使用 PHPUnit，可通过 --pest 参数使用 Pest（如果已安装）
    支持多种测试方式：套件测试、模块测试、文件测试、方法测试
    提供智能文件名匹配，自动收集各个模块的测试脚本
    默认命令行输出，可通过 --web 参数启用 Web 报告界面
    
    ⚡ 重要特性：
    - 自动收集所有模块的测试脚本（保持之前的功能）
    - 智能文件名匹配（支持多种格式）
    - 支持 Pest 2.x 的所有参数
    - 自定义 Watch 模式（文件监听）

🎯 基本语法：
    php bin/w phpunit:run [选项] [套件名]

🔧 核心选项：
    --pest                  使用 Pest 测试框架（默认使用 PHPUnit）
    --web                   启用 Web 报告界面（默认关闭，仅命令行输出）
    -p, --port=<端口>       指定报告服务器端口（默认：9980，需配合 --web 使用）
    --debug                 显示详细的调试信息
    --module=<模块名>       指定要测试的模块（自动收集该模块的测试）
    --name=<文件名|方法名>  指定测试文件或方法（支持智能匹配，自动查找）
    
🔧 Pest 选择选项（SELECTION OPTIONS）：
    --filter=<pattern>      过滤测试名称（支持正则表达式）
    -f, --filter            同上
    --group=<name>          运行指定组的测试
    -g, --group             同上
    --exclude-group=<name>  排除指定组的测试
    --testsuite=<name>      运行指定测试套件
    -s, --testsuite         同上
    --exclude-testsuite     排除指定测试套件
    --covers=<name>         只运行覆盖指定代码的测试
    --uses=<name>           只运行使用指定代码的测试
    --test-suffix=<suffixes> 指定测试文件后缀（默认：Test.php,.phpt）
    
🔧 Pest 执行选项（EXECUTION OPTIONS）：
    --parallel              并行运行测试（提高速度）
    -p, --parallel          同上（注意：与 --port 冲突时使用完整形式）
    --bail                  遇到第一个失败就停止
    --retry                  优先运行失败的测试
    --stop-on-error         遇到错误就停止
    --stop-on-failure       遇到失败就停止
    --stop-on-warning       遇到警告就停止
    --stop-on-defect        遇到缺陷就停止
    --order-by=<order>      测试执行顺序（default|defects|depends|duration|random|reverse|size）
    --random-order-seed=<N> 随机顺序的种子值
    
🔧 Pest 代码覆盖率选项（CODE COVERAGE OPTIONS）：
    --coverage              生成代码覆盖率报告
    -c, --coverage          同上
    --coverage --min=<n>    设置最小覆盖率要求
    --coverage-html=<dir>   生成 HTML 格式覆盖率报告
    --coverage-text[=<file>] 生成文本格式覆盖率报告
    --coverage-xml=<dir>    生成 XML 格式覆盖率报告
    --coverage-clover=<file> 生成 Clover 格式覆盖率报告
    
🔧 Pest 报告选项（REPORTING OPTIONS）：
    --testdox               使用 TestDox 格式输出
    --compact               紧凑格式输出
    --debug                 调试模式输出
    --profile               显示最慢的 10 个测试
    --colors=<flag>         颜色输出（never|auto|always）
    --no-progress           禁用进度输出
    --no-results            禁用结果输出
    
🔧 Pest 配置选项（CONFIGURATION OPTIONS）：
    --configuration=<file>  指定配置文件
    --bootstrap=<file>      指定引导文件
    --cache-directory=<dir> 指定缓存目录

📝 参数：
    <套件名>                可选的测试套件名称（例如：unit）

📋 使用方式：

1️⃣ 默认测试（自动收集所有模块的测试）：
    php bin/w phpunit:run                          # 使用 PHPUnit 运行所有模块的测试
    php bin/w phpunit:run --name=ThemeCssTaglibTest  # 智能查找并运行指定测试文件
    php bin/w phpunit:run --module=Weline_Theme     # 自动收集并运行指定模块的测试
    php bin/w phpunit:run --pest                   # 使用 Pest 运行测试

2️⃣ 智能文件匹配（自动查找测试文件）：
    php bin/w phpunit:run --name=ThemeCssTaglibTest     # 自动查找 ThemeCssTaglibTest.php
    php bin/w phpunit:run --name=ThemeCssTaglib         # 自动匹配 ThemeCssTaglibTest.php
    php bin/w phpunit:run --name=ThemeCssTaglibTest::testMethod  # 运行指定方法

3️⃣ 模块测试（自动收集模块下的所有测试）：
    php bin/w phpunit:run --module=Weline_Theme         # 运行 Theme 模块的所有测试
    php bin/w phpunit:run --module=Weline_Framework     # 运行 Framework 模块的所有测试

4️⃣ 强大的 Pest 参数组合：
    # 并行测试 + 覆盖率
    php bin/w phpunit:run --parallel --coverage --min=80
    
    # 测试特定组 + TestDox 格式
    php bin/w phpunit:run --group=unit --testdox
    
    # 调试模式 + 显示最慢测试
    php bin/w phpunit:run --debug --profile
    
    # 只运行覆盖特定代码的测试
    php bin/w phpunit:run --covers=ThemeCss
    
    # 遇到失败就停止
    php bin/w phpunit:run --stop-on-failure --bail

5️⃣ Watch 模式（文件监听 + 自动收集）：
    php bin/w phpunit:run --watch                    # 监听所有模块的测试
    php bin/w phpunit:run --watch --name=Test         # 监听指定测试文件
    php bin/w phpunit:run --watch --module=Weline_Theme  # 监听指定模块

6️⃣ Pest 模式（需要指定 --pest 参数）：
    php bin/w phpunit:run --pest --name=Eav        # Pest 模式运行
    php bin/w phpunit:run --pest --module=Weline_Ai  # Pest 模式测试模块

🎨 智能文件名匹配规则（自动查找）：
    ThemeCssTaglibTest     → app/code/Weline/Theme/test/Unit/ThemeCssTaglibTest.php
    ThemeCssTaglib         → ThemeCssTaglibTest.php（自动添加 Test 后缀）
    TestThemeCssTaglib     → ThemeCssTaglibTest.php（智能转换）
    ThemeCssTaglibTest.php → 直接匹配文件
    
💡 测试收集功能：
    - 自动扫描所有模块的 test/ 或 Test/ 目录
    - 支持递归查找子目录中的测试文件
    - 支持 Unit、Integration 等子目录结构
    - 自动识别测试文件（*Test.php 格式）

🚀 最佳实践：
    · 日常测试：直接运行命令行输出，快速查看结果
    · 详细报告：添加 --web 参数启动 Web 报告界面
    · CI/CD集成：结合 --debug 参数查看详细信息

💡 提示：
    默认命令行输出，测试完成后直接显示结果
    如需 Web 报告界面，添加 --web 参数

🌐 Web 报告界面（--web）：
    php bin/w phpunit:run --web               # 启用 Web 报告界面
    php bin/w phpunit:run --web --port=9980   # 指定端口号
    
    💡 Web 模式功能：
    - 测试完成后自动启动报告服务器
    - 自动打开浏览器显示测试报告
    - 报告地址默认 http://localhost:9980/report/index.html（摘要）；覆盖率：http://localhost:9980/coverage-html/index.html
    - 如不想自动打开浏览器，设置环境变量：WELINE_NO_BROWSER=1

📡 监听模式（Watch Mode）：
    ✅ 已实现：自定义文件监听功能（Pest 2.x 本身不支持 --watch，已实现替代方案）
    php bin/w phpunit:run --watch              # 启用文件监听模式
    php bin/w phpunit:run -w                    # 短参数形式
    php bin/w phpunit:run --watch --name=Test   # 监听指定测试文件
    php bin/w phpunit:run --watch --module=Weline_Theme  # 监听指定模块测试
    
    💡 Watch 模式功能：
    - 自动监听 app/code 目录下的 PHP 文件变化
    - 文件变化时自动重新运行相关测试
    - 持续运行直到手动停止（Ctrl+C）
    - 每 0.5 秒检查一次文件变化

════════════════════════════════════════════════════════════════════════════════
';
    }
    
    /**
     * 检查测试方法是否存在
     * @param string $testFile 测试文件路径
     * @param string $methodName 测试方法名
     * @return bool
     */
    private function checkTestMethodExists(string $testFile, string $methodName): bool
    {
        if (!file_exists($testFile)) {
            return false;
        }
        
        $content = file_get_contents($testFile);
        if ($content === false) {
            return false;
        }
        
        # 检查方法是否存在（支持多种格式）
        $patterns = [
            "function {$methodName}(",      # function testMethod(
            "public function {$methodName}(", # public function testMethod(
            "protected function {$methodName}(", # protected function testMethod(
            "private function {$methodName}(", # private function testMethod(
        ];
        
        foreach ($patterns as $pattern) {
            if (str_contains($content, $pattern)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 生成测试报告
     * @param array $output PHPUnit输出
     * @param int $returnCode 返回代码
     * @param int $expectedTotalTests 预期的测试总数
     * @param array $testFiles 测试文件数据
     */
    private function generateTestReport(array $output, int $returnCode, int $expectedTotalTests = 0, array $testFiles = []): void
    {
        $this->printing->note(__('📊 测试报告'));
        $this->printing->note(__('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━'));
        
        # 分析测试结果
        $totalTests = 0;
        $passedTests = 0;
        $failedTests = 0;
        $skippedTests = 0;
        $executionTime = '';
        $memoryUsage = '';
        
        # 通过计算测试开始和结束的行数来统计测试数量
        $testStartedCount = 0;
        $testEndedCount = 0;
        $hasFailures = false;
        $hasErrors = false;
        
        # 断言消息收集
        $assertionMessages = [];
        $failureMessages = [];
        $errorMessages = [];
        
        foreach ($output as $line) {
            $line = trim($line);
            
            # 计算测试开始和结束
            if (str_contains($line, 'Test ') && str_contains($line, ' started')) {
                $testStartedCount++;
            }
            if (str_contains($line, 'Test ') && str_contains($line, ' ended')) {
                $testEndedCount++;
            }
            
            # 检查是否有失败或错误
            if (str_contains($line, 'FAILURES!') || str_contains($line, 'ERRORS!') || 
                str_contains($line, 'There were') || str_contains($line, 'There was')) {
                $hasFailures = true;
            }
            if (str_contains($line, 'Error:') || str_contains($line, 'Exception:') || 
                str_contains($line, 'Failed asserting')) {
                $hasErrors = true;
                $currentTestHasError = true; // 标记当前测试有错误
            }
            
            # 检查HTTP错误代码（如404、500等）
            if (preg_match('/^\d{3}$/', $line) && (int)$line >= 400) {
                $hasErrors = true;
                $currentTestHasError = true; // 标记当前测试有错误
            }
            
            # 收集断言消息
            if (str_contains($line, 'assertions') || 
                str_contains($line, 'OK (') ||
                str_contains($line, 'FAILURES!') ||
                str_contains($line, 'ERRORS!')) {
                $assertionMessages[] = $line;
            }
            
            # 收集失败消息
            if (str_contains($line, 'Failed asserting') || 
                str_contains($line, 'FAIL') || 
                str_contains($line, 'Error:') ||
                str_contains($line, 'Exception:')) {
                $failureMessages[] = $line;
            }
            
            # 收集错误消息
            if (str_contains($line, 'Error:') || 
                str_contains($line, 'Exception:') ||
                str_contains($line, 'Fatal error') ||
                str_contains($line, 'Warning:')) {
                $errorMessages[] = $line;
            }
            
            # 收集HTTP错误代码
            if (preg_match('/^\d{3}$/', $line) && (int)$line >= 400) {
                $errorMessages[] = "HTTP错误: $line";
            }
            
            # 解析测试统计
            if (preg_match('/Tests: (\d+)/', $line, $matches)) {
                $totalTests = (int)$matches[1];
            }
            if (preg_match('/Time: ([0-9:.]+)/', $line, $matches)) {
                $executionTime = $matches[1];
            }
            if (preg_match('/Memory: ([0-9.]+)/', $line, $matches)) {
                $memoryUsage = $matches[1];
            }
            
            # 解析测试结果 - 改进正则表达式
            if (str_contains($line, 'OK') && preg_match('/OK \((\d+) test/', $line, $matches)) {
                $passedTests = (int)$matches[1];
                $totalTests = $passedTests; // 如果只有OK，总测试数就是通过的测试数
            }
            if (str_contains($line, 'FAILURES!') && preg_match('/FAILURES! \((\d+) test/', $line, $matches)) {
                $failedTests = (int)$matches[1];
            }
            if (str_contains($line, 'ERRORS!') && preg_match('/ERRORS! \((\d+) test/', $line, $matches)) {
                $failedTests = (int)$matches[1];
            }
            
            # 解析更详细的测试结果
            if (preg_match('/(\d+) test.*(\d+) assertion/', $line, $matches)) {
                $totalTests = (int)$matches[1];
            }
        }
        
        # PHPUnit 10 输出 "OK, but there were issues!" 时没有 "OK (N test)"，需根据总测试数补全通过数
        if ($totalTests > 0 && $passedTests === 0 && $returnCode === 0 && !$hasFailures && !$hasErrors) {
            $passedTests = $totalTests;
            $failedTests = 0;
        }
        
        # 优先使用预期的测试总数，如果没有则使用PHPUnit输出或计算的结果
        if ($expectedTotalTests > 0) {
            $totalTests = $expectedTotalTests;
        } elseif ($totalTests === 0 && $testEndedCount > 0) {
            $totalTests = $testEndedCount;
            
            # 根据退出代码和错误情况判断测试结果
            if ($returnCode === 0 && !$hasFailures && !$hasErrors) {
                # 退出代码为0且没有失败/错误，所有测试都通过
                $passedTests = $testEndedCount;
                $failedTests = 0;
        } else {
                # 退出代码不为0或有失败/错误，需要进一步分析
                if ($hasFailures || $hasErrors) {
                    # 有明确的失败或错误，假设有失败
                    $failedTests = 1; // 至少有一个失败
                    $passedTests = $testEndedCount - $failedTests;
                } else {
                    # 退出代码不为0但没有明确的失败信息，可能是其他错误
                    $passedTests = $testEndedCount;
                    $failedTests = 0;
                }
            }
        }
        
        # 显示测试统计
        if ($totalTests > 0) {
            $this->printing->note(__('总测试数: %{1}', [$totalTests]));
            $this->printing->success(__('通过测试: %{1}', [$passedTests]));
            
            if ($failedTests > 0) {
                $this->printing->error(__('失败测试: %{1}', [$failedTests]));
            } else {
                $this->printing->success(__('失败测试: %{1}', [$failedTests]));
            }
            
            if ($skippedTests > 0) {
                $this->printing->warning(__('跳过测试: %{1}', [$skippedTests]));
            }
            
            # 计算并显示通过率
            $passRate = round(($passedTests / $totalTests) * 100, 1);
            if ($passRate >= 90) {
                $this->printing->success(__('通过率: %{1}%', [$passRate]));
            } elseif ($passRate >= 70) {
                $this->printing->warning(__('通过率: %{1}%', [$passRate]));
            } else {
                $this->printing->error(__('通过率: %{1}%', [$passRate]));
            }
        } else {
            $this->printing->warning(__('未执行任何测试'));
        }
        
        # 显示执行信息
        if ($executionTime) {
            $this->printing->note(__('执行时间: %{1} 秒', [$executionTime]));
        }
        if ($memoryUsage) {
            $this->printing->note(__('内存使用: %{1} MB', [$memoryUsage]));
        }
        
        # 显示断言消息
        if (!empty($assertionMessages)) {
            $this->printing->note(__('📋 断言统计:'));
            foreach ($assertionMessages as $message) {
                $this->printing->note('  ' . $message);
            }
        }
        
        # 显示失败消息
        if (!empty($failureMessages)) {
            $this->printing->error(__('❌ 失败详情:'));
            foreach ($failureMessages as $message) {
                $this->printing->error('  ' . $message);
            }
        }
        
        # 显示错误消息
        if (!empty($errorMessages)) {
            $this->printing->error(__('⚠️ 错误详情:'));
            foreach ($errorMessages as $message) {
                $this->printing->error('  ' . $message);
            }
        }
        
        # 显示整体结果
        if ($returnCode === 0) {
            $this->printing->success(__('✅ 所有测试通过'));
        } else {
            $this->printing->error(__('❌ 测试失败 (退出代码: %{1})', [$returnCode]));
        }
        
        $this->printing->note(__('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━'));
    }
    
    /**
     * 统计文件中的测试方法数量
     * @param string $fileName 文件名
     * @param string|null $moduleName 模块名（可选）
     * @return int
     */
    private function countTestMethodsInFile(string $fileName, ?string $moduleName = null): int
    {
        $testFile = $moduleName ? 
            $this->findTestFileInModule($fileName, $moduleName) : 
            $this->findTestFile($fileName, false);
            
        if (!$testFile || !file_exists($testFile)) {
            return 0;
        }
        
        $content = file_get_contents($testFile);
        if ($content === false) {
            return 0;
        }
        
        # 统计测试方法数量
        $count = 0;
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $line = trim($line);
            # 匹配测试方法：public function test*() 或 function test*()
            if (preg_match('/^\s*(public\s+)?function\s+test\w+\s*\(/', $line)) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * 统计模块中的测试方法数量
     * @param string $moduleName 模块名
     * @return int
     */
    private function countTestMethodsInModule(string $moduleName): int
    {
        $modules = Env::get('modules');
        if (!$modules || !is_array($modules)) {
            return 0;
        }
        
        $targetModule = null;
        
        foreach ($modules as $module) {
            if ($module['name'] === $moduleName || str_contains($moduleName, $module['name'])) {
                $targetModule = $module;
                break;
            }
        }
        
        if (!$targetModule) {
            return 0;
        }
        
        $testPath = $this->resolveTestPath($targetModule['base_path']);
        if ($testPath === null) {
            return 0;
        }
        
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($testPath));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());
                if ($content !== false) {
                    $lines = explode("\n", $content);
                    foreach ($lines as $line) {
                        $line = trim($line);
                        # 匹配测试方法：public function test*() 或 function test*()
                        if (preg_match('/^\s*(public\s+)?function\s+test\w+\s*\(/', $line)) {
                            $count++;
                        }
                    }
                }
            }
        }
        
        return $count;
    }
    
    /**
     * 统计套件中的测试方法数量
     * @param string $suiteName 套件名
     * @return int
     */
    private function countTestMethodsInSuite(string $suiteName): int
    {
        $count = 0;
        $modules = Env::get('modules');
        if (!$modules || !is_array($modules)) {
            return 0;
        }
        
        foreach ($modules as $module) {
            $testPath = $this->resolveTestPath($module['base_path']);
            if ($testPath === null) {
                continue;
            }
            
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($testPath));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $content = file_get_contents($file->getPathname());
                    if ($content !== false) {
                        $lines = explode("\n", $content);
                        foreach ($lines as $line) {
                            $line = trim($line);
                            # 匹配测试方法：public function test*() 或 function test*()
                            if (preg_match('/^\s*(public\s+)?function\s+test\w+\s*\(/', $line)) {
                                $count++;
                            }
                        }
                    }
                }
            }
        }
        
        return $count;
    }
    
    /**
     * 生成自定义HTML报告
     * @param array $output PHPUnit输出
     * @param int $returnCode 返回代码
     * @param int $expectedTotalTests 预期的测试总数
     * @param string $reportPath 报告路径
     * @return array 测试文件数据
     */
    private function generateCustomHtmlReport(array $output, int $returnCode, int $expectedTotalTests, string $reportPath): array
    {
        # 分析测试结果（复用generateTestReport的逻辑）
        $totalTests = 0;
        $passedTests = 0;
        $failedTests = 0;
        $skippedTests = 0;
        $executionTime = '';
        $memoryUsage = '';
        
        $testStartedCount = 0;
        $testEndedCount = 0;
        $hasFailures = false;
        $hasErrors = false;
        
        # 断言消息收集
        $assertionMessages = [];
        $failureMessages = [];
        $errorMessages = [];
        
        # 测试文件和方法信息收集
        $testFiles = [];
        $currentTestFile = null;
        $currentTestMethod = null;
        $currentTestHasError = false;
        
        foreach ($output as $line) {
            $line = trim($line);
            
            # 解析测试文件和方法信息
            if (str_contains($line, 'Test ') && str_contains($line, ' started')) {
                $testStartedCount++;
                $currentTestHasError = false; // 重置当前测试的错误状态
                # 解析测试类和方法名
                if (preg_match('/Test \'([^\']+)\' started/', $line, $matches)) {
                    $fullTestName = $matches[1];
                    $parts = explode('::', $fullTestName);
                    if (count($parts) >= 2) {
                        $className = $parts[0];
                        $methodName = $parts[1];
                        
                        # 提取文件名
                        $fileName = basename(str_replace('\\', '/', $className)) . '.php';
                        
                        if (!isset($testFiles[$fileName])) {
                            $testFiles[$fileName] = [
                                'name' => $fileName,
                                'class' => $className,
                                'methods' => [],
                                'status' => 'unknown'
                            ];
                        }
                        
                        $testFiles[$fileName]['methods'][$methodName] = [
                            'name' => $methodName,
                            'status' => 'running',
                            'start_time' => microtime(true)
                        ];
                        
                        $currentTestFile = $fileName;
                        $currentTestMethod = $methodName;
                    }
                }
            }
            
            if (str_contains($line, 'Test ') && str_contains($line, ' ended')) {
                $testEndedCount++;
                # 解析结束的测试方法并标记完成
                if (preg_match('/Test \'([^\']+)\' ended/', $line, $matches)) {
                    $fullTestName = $matches[1];
                    $parts = explode('::', $fullTestName);
                    if (count($parts) >= 2) {
                        $className = $parts[0];
                        $methodName = $parts[1];
                        $fileName = basename(str_replace('\\', '/', $className)) . '.php';
                        
                        if (isset($testFiles[$fileName]['methods'][$methodName])) {
                            # 检查是否有错误，如果有错误则标记为失败
                            $testFiles[$fileName]['methods'][$methodName]['status'] = $currentTestHasError ? 'failed' : 'completed';
                            $testFiles[$fileName]['methods'][$methodName]['end_time'] = microtime(true);
                        }
                    }
                }
            }
            
            # 检查是否有失败或错误
            if (str_contains($line, 'FAILURES!') || str_contains($line, 'ERRORS!') || 
                str_contains($line, 'There were') || str_contains($line, 'There was')) {
                $hasFailures = true;
            }
            if (str_contains($line, 'Error:') || str_contains($line, 'Exception:') || 
                str_contains($line, 'Failed asserting')) {
                $hasErrors = true;
                $currentTestHasError = true; // 标记当前测试有错误
            }
            
            # 检查HTTP错误代码（如404、500等）
            if (preg_match('/^\d{3}$/', $line) && (int)$line >= 400) {
                $hasErrors = true;
                $currentTestHasError = true; // 标记当前测试有错误
            }
            
            # 收集断言消息
            if (str_contains($line, 'assertions') || 
                str_contains($line, 'OK (') ||
                str_contains($line, 'FAILURES!') ||
                str_contains($line, 'ERRORS!')) {
                $assertionMessages[] = $line;
            }
            
            # 收集失败消息
            if (str_contains($line, 'Failed asserting') || 
                str_contains($line, 'FAIL') || 
                str_contains($line, 'Error:') ||
                str_contains($line, 'Exception:')) {
                $failureMessages[] = $line;
            }
            
            # 收集错误消息
            if (str_contains($line, 'Error:') || 
                str_contains($line, 'Exception:') ||
                str_contains($line, 'Fatal error') ||
                str_contains($line, 'Warning:')) {
                $errorMessages[] = $line;
            }
            
            # 收集HTTP错误代码
            if (preg_match('/^\d{3}$/', $line) && (int)$line >= 400) {
                $errorMessages[] = "HTTP错误: $line";
            }
            
            # 解析测试统计
            if (preg_match('/Tests: (\d+)/', $line, $matches)) {
                $totalTests = (int)$matches[1];
            }
            if (preg_match('/Time: ([0-9:.]+)/', $line, $matches)) {
                $executionTime = $matches[1];
            }
            if (preg_match('/Memory: ([0-9.]+)/', $line, $matches)) {
                $memoryUsage = $matches[1];
            }
            
            if (str_contains($line, 'OK') && preg_match('/OK \((\d+) test/', $line, $matches)) {
                $passedTests = (int)$matches[1];
                $totalTests = $passedTests;
            }
            if (str_contains($line, 'FAILURES!') && preg_match('/FAILURES! \((\d+) test/', $line, $matches)) {
                $failedTests = (int)$matches[1];
            }
            if (str_contains($line, 'ERRORS!') && preg_match('/ERRORS! \((\d+) test/', $line, $matches)) {
                $failedTests = (int)$matches[1];
            }
            
            if (preg_match('/(\d+) test.*(\d+) assertion/', $line, $matches)) {
                $totalTests = (int)$matches[1];
            }
        }
        
        # 优先使用预期的测试总数
        if ($expectedTotalTests > 0) {
            $totalTests = $expectedTotalTests;
        } elseif ($totalTests === 0 && $testEndedCount > 0) {
            $totalTests = $testEndedCount;
            
            # 根据退出代码和错误情况判断测试结果
            if ($returnCode === 0 && !$hasFailures && !$hasErrors) {
                $passedTests = $testEndedCount;
                $failedTests = 0;
            } else {
                if ($hasFailures || $hasErrors) {
                    $failedTests = 1;
                    $passedTests = $testEndedCount - $failedTests;
                } else {
                    $passedTests = $testEndedCount;
                    $failedTests = 0;
                }
            }
        }
        
        # 计算通过率
        $passRate = 0;
        if ($totalTests > 0) {
            $passRate = round(($passedTests / $totalTests) * 100, 1);
        }
        
        # 生成HTML内容
        $html = $this->generateHtmlContent($totalTests, $passedTests, $failedTests, $skippedTests, 
                                         $passRate, $executionTime, $memoryUsage, $returnCode,
                                         $assertionMessages, $failureMessages, $errorMessages, $testFiles);
        
        # 生成默认的index.html，直接显示自定义报告内容
        $indexFile = $reportPath . DS . 'index.html';
        file_put_contents($indexFile, $html);
        
        return $testFiles;
    }
    
    /**
     * 生成HTML内容
     */
    private function generateHtmlContent(int $totalTests, int $passedTests, int $failedTests, int $skippedTests,
                                       float $passRate, string $executionTime, string $memoryUsage, int $returnCode,
                                       array $assertionMessages, array $failureMessages, array $errorMessages, array $testFiles = []): string
    {
        $statusClass = $returnCode === 0 ? 'success' : 'error';
        $statusText = $returnCode === 0 ? '✅ 所有测试通过' : '❌ 测试失败';
        $passRateClass = $passRate >= 90 ? 'success' : ($passRate >= 70 ? 'warning' : 'error');
        
        # 判断是否有错误需要默认展开
        $hasErrors = !empty($failureMessages) || !empty($errorMessages);
        
        $html = '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHPUnit 测试报告</title>
    <style>
        * { box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; 
            margin: 0; padding: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            min-height: 100vh;
        }
        .container { 
            max-width: 1200px; margin: 0 auto; background: white; border-radius: 12px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.2); overflow: hidden;
        }
        .header { 
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%); 
            color: white; padding: 30px; text-align: center; position: relative;
        }
        .header::before {
            content: ""; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            background: url("data:image/svg+xml,%3Csvg width=\'60\' height=\'60\' viewBox=\'0 0 60 60\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cg fill=\'none\' fill-rule=\'evenodd\'%3E%3Cg fill=\'%23ffffff\' fill-opacity=\'0.05\'%3E%3Ccircle cx=\'30\' cy=\'30\' r=\'2\'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
        }
        .header h1 { margin: 0; font-size: 28px; font-weight: 600; position: relative; z-index: 1; }
        .header .subtitle { margin: 10px 0 0 0; opacity: 0.9; font-size: 16px; position: relative; z-index: 1; }
        .header .summary { 
            display: flex; justify-content: center; gap: 30px; margin-top: 20px; 
            flex-wrap: wrap; position: relative; z-index: 1;
        }
        .summary-item { 
            text-align: center; padding: 15px 20px; background: rgba(255,255,255,0.1); 
            border-radius: 8px; backdrop-filter: blur(10px);
        }
        .summary-number { font-size: 24px; font-weight: bold; margin-bottom: 5px; }
        .summary-label { font-size: 14px; opacity: 0.8; }
        .content { padding: 30px; }
        .accordion { margin-bottom: 20px; }
        .accordion-item { 
            border: 1px solid #e1e5e9; border-radius: 8px; margin-bottom: 10px; 
            overflow: hidden; background: white;
        }
        .accordion-header { 
            background: #f8f9fa; padding: 15px 20px; cursor: pointer; 
            display: flex; justify-content: space-between; align-items: center;
            transition: all 0.3s ease; border-bottom: 1px solid #e1e5e9;
        }
        .accordion-header:hover { background: #e9ecef; }
        .accordion-header.active { background: #007bff; color: white; }
        .accordion-title { font-weight: 600; font-size: 16px; }
        .accordion-icon { 
            transition: transform 0.3s ease; font-size: 18px;
        }
        .accordion-header.active .accordion-icon { transform: rotate(180deg); }
        .accordion-content { 
            padding: 0; max-height: 0; overflow: hidden; 
            transition: max-height 0.3s ease, padding 0.3s ease;
        }
        .accordion-content.active { 
            padding: 20px; max-height: none; 
        }
        .message-list { 
            background: #f8f9fa; padding: 15px; border-radius: 6px; 
            border-left: 4px solid #007bff;
        }
        .message-item { 
            padding: 10px 0; border-bottom: 1px solid #e9ecef; 
            font-family: "SF Mono", Monaco, "Cascadia Code", "Roboto Mono", Consolas, "Courier New", monospace;
            font-size: 14px; line-height: 1.5;
        }
        .message-item:last-child { border-bottom: none; }
        .assertion { color: #28a745; }
        .failure { color: #dc3545; }
        .error { color: #dc3545; }
        .info-grid { 
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 15px; margin-bottom: 20px;
        }
        .info-item { 
            background: #f8f9fa; padding: 15px; border-radius: 6px; 
            border-left: 4px solid #007bff;
        }
        .info-label { font-size: 12px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
        .info-value { font-size: 16px; font-weight: 600; margin-top: 5px; }
        .status-success { color: #28a745; }
        .status-error { color: #dc3545; }
        .status-warning { color: #ffc107; }
        
        /* 测试文件列表样式 */
        .test-files-list { padding: 10px 0; }
        .test-file { 
            background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 8px; 
            margin-bottom: 15px; overflow: visible;
        }
        .file-header { 
            background: #e9ecef; padding: 12px 15px; display: flex; 
            justify-content: space-between; align-items: center; font-weight: 600;
            cursor: pointer; transition: background-color 0.2s ease;
        }
        .file-header:hover { background: #dee2e6; }
        .file-info { display: flex; flex-direction: column; gap: 4px; }
        .file-name { color: #495057; font-size: 16px; font-weight: 600; }
        .file-class { 
            color: #6c757d; font-size: 13px; 
            font-family: "SF Mono", Monaco, "Cascadia Code", "Roboto Mono", Consolas, "Courier New", monospace;
        }
        .file-stats { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
        .file-methods { 
            background: #007bff; color: white; padding: 4px 8px; 
            border-radius: 12px; font-size: 12px; font-weight: 500;
        }
        .file-status { 
            padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: 500;
        }
        .file-status.success { 
            background: #d4edda; color: #155724; 
        }
        .file-status.failed { 
            background: #f8d7da; color: #721c24; 
        }
        .methods-list { padding: 10px 15px; }
        .method-item { 
            display: flex; align-items: center; padding: 8px 0; 
            border-bottom: 1px solid #e9ecef; gap: 10px;
        }
        .method-item:last-child { border-bottom: none; }
        .method-icon { font-size: 14px; }
        .method-name { 
            flex: 1; font-family: "SF Mono", Monaco, "Cascadia Code", "Roboto Mono", Consolas, "Courier New", monospace;
            font-size: 14px; color: #495057;
        }
        .method-status { 
            font-size: 12px; padding: 2px 6px; border-radius: 4px; 
            text-transform: uppercase; font-weight: 500;
        }
        .method-item.success .method-status { 
            background: #d4edda; color: #155724; 
        }
        .method-item.running .method-status { 
            background: #fff3cd; color: #856404; 
        }
        .method-item.failed .method-status { 
            background: #f8d7da; color: #721c24; 
        }
        
        /* 搜索和过滤控件样式 */
        .test-files-controls { 
            background: #f8f9fa; padding: 15px; border-radius: 8px; 
            margin-bottom: 20px; border: 1px solid #e9ecef;
        }
        .search-box { 
            position: relative; margin-bottom: 15px; 
        }
        .search-box input { 
            width: 100%; padding: 10px 40px 10px 15px; border: 1px solid #ced4da; 
            border-radius: 6px; font-size: 14px; background: white;
        }
        .search-icon { 
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%); 
            color: #6c757d; pointer-events: none;
        }
        .filter-buttons { 
            display: flex; gap: 8px; flex-wrap: wrap; 
        }
        .filter-btn { 
            padding: 6px 12px; border: 1px solid #ced4da; background: white; 
            border-radius: 4px; cursor: pointer; font-size: 12px; 
            transition: all 0.2s ease;
        }
        .filter-btn:hover { 
            background: #e9ecef; border-color: #adb5bd; 
        }
        .filter-btn.active { 
            background: #007bff; color: white; border-color: #007bff; 
        }
        
        /* 模块分组样式 */
        .module-group { 
            margin-bottom: 20px; border: 1px solid #e9ecef; border-radius: 8px; 
            overflow: visible; background: white;
        }
        .module-header { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            color: white; padding: 12px 15px; display: flex; 
            justify-content: space-between; align-items: center; font-weight: 600;
            cursor: pointer; transition: background-color 0.2s ease;
        }
        .module-header:hover { 
            background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%); 
        }
        .module-info { display: flex; flex-direction: column; gap: 4px; }
        .module-name { font-size: 16px; font-weight: 600; }
        .module-stats { font-size: 12px; opacity: 0.9; }
        .module-status { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
        .module-status-text { 
            padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: 500;
        }
        .module-status-text.success { 
            background: rgba(255, 255, 255, 0.2); color: white; 
        }
        .module-status-text.failed { 
            background: rgba(220, 53, 69, 0.8); color: white; 
        }
        .module-files { padding: 15px; }
        .test-file.failed { 
            border-left: 4px solid #dc3545; 
        }
        .test-file.success { 
            border-left: 4px solid #28a745; 
        }
        
        @media (max-width: 768px) {
            .header .summary { gap: 15px; }
            .summary-item { padding: 10px 15px; }
            .summary-number { font-size: 20px; }
            .content { padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 PHPUnit 测试报告</h1>
            <p class="subtitle">生成时间: ' . date('Y-m-d H:i:s') . '</p>
            <div class="summary">
                <div class="summary-item">
                    <div class="summary-number">' . $totalTests . '</div>
                    <div class="summary-label">总测试数</div>
                </div>
                <div class="summary-item">
                    <div class="summary-number">' . $passedTests . '</div>
                    <div class="summary-label">通过测试</div>
                </div>
                <div class="summary-item">
                    <div class="summary-number">' . $failedTests . '</div>
                    <div class="summary-label">失败测试</div>
                </div>
                <div class="summary-item">
                    <div class="summary-number ' . $passRateClass . '">' . $passRate . '%</div>
                    <div class="summary-label">通过率</div>
                </div>
            </div>
        </div>
        
        <div class="content">
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">执行时间</div>
                    <div class="info-value">' . $executionTime . ' 秒</div>
                </div>
                <div class="info-item">
                    <div class="info-label">内存使用</div>
                    <div class="info-value">' . $memoryUsage . ' MB</div>
                </div>
                <div class="info-item">
                    <div class="info-label">整体状态</div>
                    <div class="info-value status-' . $statusClass . '">' . $statusText . '</div>
                </div>
            </div>';
        
        $html .= '
            <div class="accordion">';
        
        # 断言统计
        if (!empty($assertionMessages)) {
            $html .= '
                <div class="accordion-item">
                    <div class="accordion-header" onclick="toggleAccordion(this)">
                        <span class="accordion-title">📋 断言统计</span>
                        <span class="accordion-icon">▼</span>
                    </div>
                    <div class="accordion-content">
                        <div class="message-list">';
            foreach ($assertionMessages as $message) {
                $html .= '<div class="message-item assertion">' . htmlspecialchars($message) . '</div>';
            }
            $html .= '
                        </div>
                    </div>
                </div>';
        }
        
        # 失败详情
        if (!empty($failureMessages)) {
            $defaultExpanded = $hasErrors ? 'active' : '';
            $html .= '
                <div class="accordion-item">
                    <div class="accordion-header ' . $defaultExpanded . '" onclick="toggleAccordion(this)">
                        <span class="accordion-title">❌ 失败详情 (' . count($failureMessages) . ')</span>
                        <span class="accordion-icon">▼</span>
                    </div>
                    <div class="accordion-content ' . $defaultExpanded . '">
                        <div class="message-list">';
            foreach ($failureMessages as $message) {
                $html .= '<div class="message-item failure">' . htmlspecialchars($message) . '</div>';
            }
            $html .= '
                        </div>
                    </div>
                </div>';
        }
        
        # 错误详情
        if (!empty($errorMessages)) {
            $defaultExpanded = $hasErrors ? 'active' : '';
            $html .= '
                <div class="accordion-item">
                    <div class="accordion-header ' . $defaultExpanded . '" onclick="toggleAccordion(this)">
                        <span class="accordion-title">⚠️ 错误详情 (' . count($errorMessages) . ')</span>
                        <span class="accordion-icon">▼</span>
                    </div>
                    <div class="accordion-content ' . $defaultExpanded . '">
                        <div class="message-list">';
            foreach ($errorMessages as $message) {
                $html .= '<div class="message-item error">' . htmlspecialchars($message) . '</div>';
            }
            $html .= '
                        </div>
                    </div>
                </div>';
        }
        
        # 测试文件详情
        if (!empty($testFiles)) {
            # 按模块分组测试文件
            $moduleGroups = [];
            foreach ($testFiles as $file) {
                $moduleName = explode('\\', $file['class'])[1] ?? 'Unknown';
                if (!isset($moduleGroups[$moduleName])) {
                    $moduleGroups[$moduleName] = [];
                }
                $moduleGroups[$moduleName][] = $file;
            }
            
            $html .= '
                <div class="accordion-item">
                    <div class="accordion-header active" onclick="toggleAccordion(this)">
                        <span class="accordion-title">📁 ' . __('测试文件详情') . ' (' . count($testFiles) . ' ' . __('个文件') . ', ' . count($moduleGroups) . ' ' . __('个模块') . ')</span>
                        <span class="accordion-icon">▼</span>
                    </div>
                    <div class="accordion-content active">
                        <div class="test-files-controls">
                            <div class="search-box">
                                <input type="text" id="testSearch" placeholder="' . __('搜索测试文件或方法...') . '" onkeyup="filterTests()">
                                <span class="search-icon">🔍</span>
                            </div>
                            <div class="filter-buttons">
                                <button class="filter-btn active" onclick="filterByStatus(\'all\')">' . __('全部') . '</button>
                                <button class="filter-btn" onclick="filterByStatus(\'success\')">✅ ' . __('通过') . '</button>
                                <button class="filter-btn" onclick="filterByStatus(\'failed\')">❌ ' . __('失败') . '</button>
                            </div>
                        </div>
                        <div class="test-files-list">';
            
            # 按模块分组显示
            foreach ($moduleGroups as $moduleName => $moduleFiles) {
                $totalMethods = 0;
                $moduleHasErrors = false;
                
                foreach ($moduleFiles as $file) {
                    $totalMethods += count($file['methods']);
                    foreach ($file['methods'] as $method) {
                        if ($method['status'] === 'failed') {
                            $moduleHasErrors = true;
                            break 2; // 跳出两层循环
                        }
                    }
                }
                
                $moduleDefaultExpanded = $moduleHasErrors ? 'active' : '';
                $moduleStatusText = $moduleHasErrors ? '❌ ' . __('有错误') : '✅ ' . __('全部通过');
                
                $html .= '
                            <div class="module-group" data-module="' . htmlspecialchars($moduleName) . '">
                                <div class="module-header" onclick="toggleAccordion(this)">
                                    <div class="module-info">
                                        <span class="module-name">📦 ' . htmlspecialchars($moduleName) . '</span>
                                        <span class="module-stats">' . count($moduleFiles) . ' ' . __('个文件') . ', ' . $totalMethods . ' ' . __('个方法') . '</span>
                                    </div>
                                    <div class="module-status">
                                        <span class="module-status-text ' . ($moduleHasErrors ? 'failed' : 'success') . '">' . $moduleStatusText . '</span>
                                        <span class="accordion-icon">▼</span>
                                    </div>
                                </div>
                                <div class="module-files accordion-content ' . $moduleDefaultExpanded . '">';
                
                foreach ($moduleFiles as $file) {
                    $methodCount = count($file['methods']);
                    $hasFailedMethods = false;
                    $failedCount = 0;
                    $successCount = 0;
                    
                    foreach ($file['methods'] as $method) {
                        if ($method['status'] === 'failed') {
                            $hasFailedMethods = true;
                            $failedCount++;
                        } else {
                            $successCount++;
                        }
                    }
                    
                    $fileStatusClass = $hasFailedMethods ? 'failed' : 'success';
                    $fileStatusText = $hasFailedMethods ? '❌ ' . $failedCount . ' ' . __('个失败') : '✅ ' . __('全部通过');
                    $defaultExpanded = $hasFailedMethods ? 'active' : '';
                    
                    $html .= '
                                    <div class="test-file ' . $fileStatusClass . '" data-file="' . htmlspecialchars($file['name']) . '">
                                        <div class="file-header" onclick="toggleAccordion(this)">
                                            <div class="file-info">
                                                <span class="file-name">' . htmlspecialchars($file['name']) . '</span>
                                                <span class="file-class">' . htmlspecialchars($file['class']) . '</span>
                                            </div>
                                            <div class="file-stats">
                                                <span class="file-methods">' . $methodCount . ' ' . __('个方法') . '</span>
                                                <span class="file-status ' . $fileStatusClass . '">' . $fileStatusText . '</span>
                                            </div>
                                            <span class="accordion-icon">▼</span>
                                        </div>
                                        <div class="methods-list accordion-content ' . $defaultExpanded . '">';
                    
                    foreach ($file['methods'] as $method) {
                        $statusClass = $method['status'] === 'completed' ? 'success' : ($method['status'] === 'failed' ? 'failed' : 'running');
                        $statusIcon = $method['status'] === 'completed' ? '✅' : ($method['status'] === 'failed' ? '❌' : '⏳');
                        $html .= '
                                            <div class="method-item ' . $statusClass . '" data-method="' . htmlspecialchars($method['name']) . '">
                                                <span class="method-icon">' . $statusIcon . '</span>
                                                <span class="method-name">' . htmlspecialchars($method['name']) . '</span>
                                                <span class="method-status">' . $method['status'] . '</span>
                                            </div>';
                    }
                    
                    $html .= '
                                        </div>
                                    </div>';
                }
                
                $html .= '
                                </div>
                            </div>';
            }
            
            $html .= '
                        </div>
                    </div>
                </div>';
        }
        
        $html .= '
            </div>
        </div>
    </div>
    
    <script>
        function toggleAccordion(header) {
            const content = header.nextElementSibling;
            const icon = header.querySelector(".accordion-icon");
            
            if (content.classList.contains("active")) {
                content.classList.remove("active");
                header.classList.remove("active");
                icon.style.transform = "rotate(0deg)";
            } else {
                content.classList.add("active");
                header.classList.add("active");
                icon.style.transform = "rotate(180deg)";
            }
        }
        
        // 搜索功能
        function filterTests() {
            const searchTerm = document.getElementById("testSearch").value.toLowerCase();
            const moduleGroups = document.querySelectorAll(".module-group");
            
            moduleGroups.forEach(group => {
                const moduleName = group.getAttribute("data-module").toLowerCase();
                const testFiles = group.querySelectorAll(".test-file");
                let hasVisibleFiles = false;
                
                testFiles.forEach(file => {
                    const fileName = file.getAttribute("data-file").toLowerCase();
                    const methods = file.querySelectorAll(".method-item");
                    let hasVisibleMethods = false;
                    
                    methods.forEach(method => {
                        const methodName = method.getAttribute("data-method").toLowerCase();
                        const isMatch = fileName.includes(searchTerm) || 
                                       methodName.includes(searchTerm) || 
                                       moduleName.includes(searchTerm);
                        
                        if (isMatch) {
                            method.style.display = "flex";
                            hasVisibleMethods = true;
                        } else {
                            method.style.display = "none";
                        }
                    });
                    
                    if (hasVisibleMethods) {
                        file.style.display = "block";
                        hasVisibleFiles = true;
                    } else {
                        file.style.display = "none";
                    }
                });
                
                group.style.display = hasVisibleFiles ? "block" : "none";
            });
        }
        
        // 状态过滤功能
        function filterByStatus(status) {
            // 更新按钮状态
            document.querySelectorAll(".filter-btn").forEach(btn => {
                btn.classList.remove("active");
            });
            event.target.classList.add("active");
            
            const moduleGroups = document.querySelectorAll(".module-group");
            
            moduleGroups.forEach(group => {
                const testFiles = group.querySelectorAll(".test-file");
                let hasVisibleFiles = false;
                
                testFiles.forEach(file => {
                    const methods = file.querySelectorAll(".method-item");
                    let hasVisibleMethods = false;
                    
                    methods.forEach(method => {
                        const methodStatus = method.classList.contains("success") ? "success" : 
                                           method.classList.contains("failed") ? "failed" : "success";
                        
                        if (status === "all" || methodStatus === status) {
                            method.style.display = "flex";
                            hasVisibleMethods = true;
                        } else {
                            method.style.display = "none";
                        }
                    });
                    
                    if (hasVisibleMethods) {
                        file.style.display = "block";
                        hasVisibleFiles = true;
                    } else {
                        file.style.display = "none";
                    }
                });
                
                group.style.display = hasVisibleFiles ? "block" : "none";
            });
        }
        
        // 页面加载完成后的初始化
        document.addEventListener("DOMContentLoaded", function() {
            // 如果有错误，确保错误面板默认展开
            const errorHeaders = document.querySelectorAll(".accordion-header.active");
            errorHeaders.forEach(header => {
                const icon = header.querySelector(".accordion-icon");
                if (icon) {
                    icon.style.transform = "rotate(180deg)";
                }
            });
        });
    </script>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * 从参数中获取端口号
     * 
     * @param array $args 参数数组
     * @param array $data 数据数组
     * @return int 端口号
     */
    private function getPortFromArgs(array $args, array $data): int
    {
        # 检查 -p 或 --port 参数
        if (isset($args['p']) && is_numeric($args['p'])) {
            return (int)$args['p'];
        }
        if (isset($args['port']) && is_numeric($args['port'])) {
            return (int)$args['port'];
        }
        if (isset($args['--port']) && is_numeric($args['--port'])) {
            return (int)$args['--port'];
        }
        if (isset($data['p']) && is_numeric($data['p'])) {
            return (int)$data['p'];
        }
        if (isset($data['port']) && is_numeric($data['port'])) {
            return (int)$data['port'];
        }
        
        # 默认端口
        return 9980;
    }
    
    /**
     * 显示树形测试结构
     * @param array $testFiles 测试文件数据
     */
    private function displayTestTree(array $testFiles): void
    {
        if (empty($testFiles)) {
            return;
        }
        
        $this->printing->note(__('📁 测试文件结构:'));
        
        # 按模块分组
        $moduleGroups = [];
        foreach ($testFiles as $file) {
            $moduleName = $this->extractModuleName($file['class']);
            if (!isset($moduleGroups[$moduleName])) {
                $moduleGroups[$moduleName] = [];
            }
            $moduleGroups[$moduleName][] = $file;
        }
        
        foreach ($moduleGroups as $moduleName => $moduleFiles) {
            $moduleHasErrors = false;
            $totalMethods = 0;
            
            foreach ($moduleFiles as $file) {
                $totalMethods += count($file['methods']);
                foreach ($file['methods'] as $method) {
                    if ($method['status'] === 'failed') {
                        $moduleHasErrors = true;
                        break 2;
                    }
                }
            }
            
            # 模块头部
            $moduleStatus = $moduleHasErrors ? '❌' : '✅';
            $this->printing->note("$moduleStatus 📦 $moduleName (" . count($moduleFiles) . " 个文件, $totalMethods 个方法)");
            
            foreach ($moduleFiles as $file) {
                $methodCount = count($file['methods']);
                $hasFailedMethods = false;
                $failedCount = 0;
                
                foreach ($file['methods'] as $method) {
                    if ($method['status'] === 'failed') {
                        $hasFailedMethods = true;
                        $failedCount++;
                    }
                }
                
                $fileStatus = $hasFailedMethods ? '❌' : '✅';
                $fileStatusText = $hasFailedMethods ? " ($failedCount 个失败)" : ' (全部通过)';
                
                # 文件头部
                $this->printing->note("  $fileStatus 📄 {$file['name']} ($methodCount 个方法)$fileStatusText");
                
                # 显示测试方法
                foreach ($file['methods'] as $method) {
                    $methodStatus = $method['status'] === 'completed' ? '✅' : 
                                   ($method['status'] === 'failed' ? '❌' : '⏳');
                    $methodName = $method['name'];
                    
                    if ($method['status'] === 'failed') {
                        $this->printing->error("    $methodStatus $methodName");
                    } else {
                        $this->printing->success("    $methodStatus $methodName");
                    }
                }
            }
        }
    }
    
    /**
     * 从类名中提取模块名
     * @param string $className 完整类名
     * @return string 模块名
     */
    private function extractModuleName(string $className): string
    {
        $parts = explode('\\', $className);
        if (count($parts) >= 2) {
            return $parts[1]; // 例如: Weline\Eav\test\EavTest -> Eav
        }
        return 'Unknown';
    }
    
    /**
     * 备用服务器启动方案
     * 
     * @param string $reportPath 报告路径
     * @param int $port 端口
     * @param string $logFile 日志文件
     * @param bool $isWindows 是否为Windows系统
     * @return int 进程ID，失败返回0
     */
    private function startServerMethodBackup(string $reportPath, int $port, string $logFile, bool $isWindows): int
    {
        if (!\function_exists('exec')) {
            return 0;
        }
        
        $pid = 0;
        
        if ($isWindows) {
            # Windows下使用start /B后台启动
            # 使用唯一的日志文件名避免文件被占用（基于端口）
            $uniqueLogFile = str_replace('.log', '_' . $port . '.log', $logFile);
            
            # 确保日志目录存在
            $logDir = dirname($uniqueLogFile);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            
            // 优先尝试使用 PowerShell Start-Process 返回 PID（避免先用 start /B 导致后面再用 Start-Process 重复启动）
            $pid = 0;
            if (\function_exists('exec')) {
                $psCmd = "powershell -NoProfile -Command \"(Start-Process -FilePath 'php' -ArgumentList '-S','localhost:{$port}','-t','" . addslashes($reportPath) . "' -PassThru).Id\"";
                $psOut = [];
                $psCode = null;
                exec($psCmd, $psOut, $psCode);
                if ($psCode === 0 && !empty($psOut[0]) && is_numeric(trim($psOut[0]))) {
                    $pid = (int)trim($psOut[0]);
                }
            }

            // 如果 PowerShell 启动失败（例如没有权限或不可用），回退为 start /B 启动并轮询端口
            if ($pid === 0) {
                $command = sprintf('start /B "" php -S localhost:%d -t "%s" > "%s" 2>&1', $port, addslashes($reportPath), addslashes($uniqueLogFile));
                exec($command, $output, $exitCode);
            }

            // 如果 PowerShell 方法失败，再使用短轮询 netstat+tasklist 确认 PID
            if ($pid === 0) {
                $attempts = 8;
                for ($i = 0; $i < $attempts; $i++) {
                
                    $checkOutput = [];
                    exec("netstat -ano | findstr :$port 2>NUL", $checkOutput);
                    
                    foreach ($checkOutput as $checkLine) {
                        $checkLineTrim = trim($checkLine);
                        
                        if (preg_match('/\s+(\d+)$/', $checkLineTrim, $m)) {
                            $candidatePid = (int)$m[1];
                            
                            if ($candidatePid > 0) {
                                $tl = [];
                                exec("tasklist /FI \"PID eq $candidatePid\" /FO CSV 2>NUL", $tl);
                                
                                foreach ($tl as $tlLine) {
                                    
                                    if (stripos($tlLine, 'php.exe') !== false) {
                                        $pid = $candidatePid;
                                        break 3;
                                    }
                                }
                            }
                        }
                    }
                    usleep(300000); // 300ms
                }
            }

            // 最后回退到 Processer 的查找方法
            if ($pid === 0) {
                
                $pid = Processer::findPhpProcessPid("localhost:$port");
                
            }

            if ($pid > 0) {
                
            } else {
                
            }
        } else {
            # Linux/Mac下使用nohup后台启动
            $command = sprintf('nohup php -S localhost:%d -t %s > %s 2>&1 &', $port, addslashes($reportPath), addslashes($logFile));
            exec($command);
            sleep(2);
            
            # 查找进程
            $output = [];
            exec("ps aux | grep -v grep | grep \"php.*localhost:$port\" | awk '{print $2}' 2>/dev/null", $output);
            if (!empty($output) && is_numeric($output[0])) {
                $pid = (int)$output[0];
            }
        }
        
        return $pid;
    }

    private function resolveTestPath(string $basePath): ?string
    {
        $lowerPath = $basePath . 'test' . DS;
        if (is_dir($lowerPath)) {
            return $lowerPath;
        }

        $upperPath = $basePath . 'Test' . DS;
        if (is_dir($upperPath)) {
            return $upperPath;
        }

        return null;
    }

    /**
     * 查找可用端口
     * 
     * @param int $startPort 起始端口
     * @return int 可用端口
     */
}
