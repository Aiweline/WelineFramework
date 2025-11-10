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
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        # 提示是否运行：生产环境禁止运行
        if (Env::get('deploy') !== 'dev') {
            $this->printing->setup(__('非开发环境禁止运行！如你确认是dev环境，请运行php bin/w deploy:model:set dev 转换环境后运行！'));
            exit(1);
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
        
        # 检查是否是后台运行（默认后台运行，除非明确指定前台）
        # 如果用户指定了 -f 或 --foreground，则前台运行；否则默认后台运行
        $isForeground = isset($args['f']) || isset($args['foreground']);
        $isBackground = !$isForeground; // 默认后台运行
        
        # 检查模块参数（只从用户明确指定的参数中获取）
        $moduleName = $args['--module'] ?? $args['module'] ?? null;
        
        # 检查文件名参数（只从用户明确指定的参数中获取）
        $fileName = $args['--name'] ?? $args['name'] ?? null;
        
        # 调试信息
        if (isset($args['debug']) || isset($data['debug'])) {
            $this->printing->note(__('调试 - 模块名: %{1}', [$moduleName ?? 'null']));
            $this->printing->note(__('调试 - 文件名: %{1}', [$fileName ?? 'null']));
            $this->printing->note(__('调试 - args: %{1}', [json_encode($args)]));
            $this->printing->note(__('调试 - data: %{1}', [json_encode($data)]));
        }
        
        # 检查后台运行参数（已默认后台运行）
        if ($isBackground) {
            $this->printing->note(__('运行模式: 后台运行 (默认)'));
        } else {
            $this->printing->note(__('运行模式: 前台运行'));
        }
        
        # 显示运行模式
        if ($moduleName) {
            $this->printing->note(__('运行模式: 指定模块 - %{1}', $moduleName));
        } elseif ($fileName) {
            $this->printing->note(__('运行模式: 指定文件 - %{1}', $fileName));
        } else {
            $this->printing->note(__('运行模式: 套件测试'));
        }
        $this->printing->note(__('正在 收集 测试套件...'));
        $php_unit_path = DEV_PATH . 'phpunit' . DS;
        if (!is_dir($php_unit_path)) {
            mkdir($php_unit_path, 755, true);
        }
        $php_unit_report_path = $php_unit_path . 'report';
        if (!is_dir($php_unit_report_path)) {
            mkdir($php_unit_report_path, 755, true);
        }
        $php_unit_config_path = $php_unit_path . 'config.xml';
        
        # 统计测试总数
        $totalTestCount = 0;
        
        # 根据运行模式生成不同的配置
        if ($fileName) {
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
        $phpunitCommand = PHP_BINARY . ' ' . VENDOR_PATH . "{$ds}phpunit{$ds}phpunit{$ds}phpunit --configuration $php_unit_config_path --verbose --no-interaction --debug";
        
        if ($fileName) {
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
            foreach ($args as $arg_key => $arg) {
                # 只取整数键的参数作为套件名，排除命令名和参数
                if (is_int($arg_key) && !empty($arg) && !is_bool($arg) && 
                    $arg !== 'phpunit:run' && $arg !== '-b' && $arg !== '--backend') {
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
        
        # 彩色输出测试结果
        foreach ($command['output'] as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            if (str_contains($line, 'PHPUnit') && str_contains($line, 'by Sebastian Bergmann')) {
                $this->printing->note($line);
            } elseif (str_contains($line, 'OK') || str_contains($line, 'PASSED')) {
                $this->printing->success($line);
            } elseif (str_contains($line, 'FAILURES') || str_contains($line, 'ERRORS') || str_contains($line, 'FAILED')) {
                $this->printing->error($line);
            } elseif (str_contains($line, 'WARNING') || str_contains($line, 'Warning')) {
                $this->printing->warning($line);
            } elseif (str_contains($line, 'Tests:') || str_contains($line, 'Time:') || str_contains($line, 'Memory:')) {
                $this->printing->note($line);
            } elseif (str_contains($line, 'There were') || str_contains($line, 'There was')) {
                $this->printing->error($line);
            } elseif (str_contains($line, 'ERRORS!') || str_contains($line, 'FAILURES!')) {
                $this->printing->error($line);
            } elseif (preg_match('/^\d+\)/', $line)) {
                # 测试用例编号行
                $this->printing->error($line);
            } elseif (str_contains($line, 'Error:') || str_contains($line, 'Exception:')) {
                $this->printing->error($line);
            } elseif (str_contains($line, 'Failed asserting')) {
                $this->printing->error($line);
            } elseif (str_contains($line, '--- Expected') || str_contains($line, '+++ Actual')) {
                $this->printing->error($line);
            } elseif (str_contains($line, '@@')) {
                $this->printing->error($line);
            } elseif (str_contains($line, '--') && str_contains($line, 'There was')) {
                $this->printing->error($line);
            } else {
                # 默认输出所有其他行
                echo $line . "\n";
            }
        }
        
        if ($command['return_vars']) {
            $this->printing->success((string)$command['return_vars']);
        }
        
        # 判断是否为文件或方法测试模式（快速测试）
        $isQuickTest = !empty($fileName);
        
        # 文件或方法测试时，如果指定了前台运行，直接输出结果后返回
        if ($isQuickTest && !$isBackground) {
            $this->printing->separator('─', 0, 'SUCCESS');
            $this->printing->success(__('✓ 测试完成！'));
            $this->printing->note(__('提示：测试已完成，如需详细HTML报告请移除 -f 参数（默认后台运行并生成报告）'));
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
        
        # 启动报告服务器
        if ($isBackground) {
            $this->startPhpUnitServerBackground($php_unit_report_path, $port);
            # 在服务器启动后输出测试完成标志
            $this->printing->separator('═', 0, 'SUCCESS');
            $this->printing->success(__('✓ 测试已完成，报告服务器已在后台运行'));
            $this->printing->success(__('=== PHPUNIT_TEST_COMPLETED ==='));
            $this->printing->separator('═', 0, 'SUCCESS');
            # 确保立即返回
            return;
        } else {
            $this->system->exec("php -S localhost:$port -t $php_unit_report_path");
        }
    }

    /**
     * 后台启动PHPUnit报告服务器
     * 
     * @param string $reportPath 报告路径
     * @param int $port 端口号
     */
    private function startPhpUnitServerBackground(string $reportPath, int $port = 9980): void
    {
        $pidFile = BP . 'var' . DS . 'phpunit_server.pid';
        $logFile = BP . 'var' . DS . 'log' . DS . 'phpunit_server.log';
        
        # 检查是否已经在运行
        if (file_exists($pidFile)) {
            $pid = (int)file_get_contents($pidFile);
            if (Processer::isRunningByPid($pid)) {
                $this->printing->note(__('PHPUnit报告服务器已在运行 (PID: %{1})', (string)$pid));
                $this->printing->note(__('访问地址: http://localhost:%{1}', (string)$port));
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
        $pid = Processer::startBuiltInServer($reportPath, $port, $logFile);
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
            $this->printing->note(__('访问地址: http://localhost:%{1}', (string)$port));
            $this->printing->note(__('日志文件: %{1}', $logFile));
            $this->printing->note(__('停止命令: php bin/w phpunit:stop'));
        } else {
            $this->printing->error(__('启动PHPUnit报告服务器失败'));
            $this->printing->note(__('建议：'));
            $this->printing->note(__('1. 检查 php.ini 中的 disable_functions 配置'));
            $this->printing->note(__('2. 确认端口 %{1} 未被占用', (string)$port));
            $this->printing->note(__('3. 尝试手动启动: php -S localhost:%{1} -t %{2}', [(string)$port, $reportPath]));
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
        
        if (!isset($env['phpunit_server'])) {
            $env['phpunit_server'] = [];
        }
        
        $env['phpunit_server'] = [
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
        $code_framework_modules = glob($app_code_weline_framework_dir . '*' . DS . 'test', GLOB_ONLYDIR);
        foreach ($code_framework_modules as $key => $test_dir) {
            $key_new = str_replace($app_code_weline_framework_dir, '', $test_dir);
            $key_new = explode(DS, $key_new);
            array_pop($key_new);
            $key_new = implode(':', $key_new);
            unset($code_framework_modules[$key]);
            $code_framework_modules[$key_new] = $test_dir;
        }
        $vendor_code_weline_framework_dir = APP_CODE_PATH . 'weline' . DS . 'framework' . DS;
        $vendor_framework_modules = glob($vendor_code_weline_framework_dir . '*' . DS . 'test', GLOB_ONLYDIR);
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
        
        $php_unit_xml .= '</testsuites>
<coverage processUncoveredFiles="true">
        <include>
            <directory suffix=".php">../../app</directory>
        </include>
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
        
        $test_path = $targetModule['base_path'] . 'test' . DS;
        if (!is_dir($test_path)) {
            $this->printing->error(__('模块 %{1} 没有测试目录', [$moduleName]));
            return '';
        }
        
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
            <directory suffix=".php">../../app</directory>
        </include>
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
            $this->printing->error(__('未找到测试文件: %{1}', [$fileName]));
            return '';
        }
        
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
            <directory suffix=".php">../../app</directory>
        </include>
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
        
        # 调试信息
        if ($debug) {
            $this->printing->note(__('调试 - 查找文件: %{1}', [$fileName]));
            $this->printing->note(__('调试 - 是否测试方法: %{1}', [$isTestMethod ? '是' : '否']));
            $this->printing->note(__('调试 - 实际文件名: %{1}', [$actualFileName]));
            $this->printing->note(__('调试 - 活跃模块数: %{1}', [count($modules)]));
        }
        
        foreach ($modules as $module) {
            $test_path = $module['base_path'] . 'test' . DS;
            if (is_dir($test_path)) {
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
            }
        }
        
        # 也检查Framework模块
        $app_code_weline_framework_dir = APP_CODE_PATH . 'Weline' . DS . 'Framework' . DS;
        $code_framework_modules = glob($app_code_weline_framework_dir . '*' . DS . 'test', GLOB_ONLYDIR);
        foreach ($code_framework_modules as $test_dir) {
            # 尝试直接文件名
            $possibleFile = $test_dir . DS . $actualFileName;
            if (file_exists($possibleFile)) {
                return $possibleFile;
            }
            
            # 如果文件名不包含.php，尝试添加
            if (!str_ends_with($actualFileName, '.php')) {
                $possibleFile = $test_dir . DS . $actualFileName . '.php';
                if (file_exists($possibleFile)) {
                    return $possibleFile;
                }
            }
            
            # 如果文件名不包含Test.php，尝试添加
            if (!str_ends_with($actualFileName, 'Test.php')) {
                $possibleFile = $test_dir . DS . $actualFileName . 'Test.php';
                if (file_exists($possibleFile)) {
                    return $possibleFile;
                }
            }
            
            # 如果文件名不包含Test，尝试添加Test
            if (!str_ends_with($actualFileName, 'Test')) {
                $possibleFile = $test_dir . DS . $actualFileName . 'Test.php';
                if (file_exists($possibleFile)) {
                    return $possibleFile;
                }
            }
            
            # 如果文件名以Test开头，尝试去掉Test前缀
            if (str_starts_with($actualFileName, 'Test')) {
                $withoutTest = substr($actualFileName, 4); // 去掉 "Test" 前缀
                $possibleFile = $test_dir . DS . $withoutTest . 'Test.php';
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
        
        foreach ($modules as $module) {
            if (str_contains($module['name'], $moduleName)) {
                $test_path = $module['base_path'] . 'test' . DS;
                if (is_dir($test_path)) {
                    # 尝试直接文件名
                    $possibleFile = $test_path . $actualFileName;
                    if (file_exists($possibleFile)) {
                        return $possibleFile;
                    }
                    
                    # 如果文件名不包含.php，尝试添加
                    if (!str_ends_with($actualFileName, '.php')) {
                        $possibleFile = $test_path . $actualFileName . '.php';
                        if (file_exists($possibleFile)) {
                            return $possibleFile;
                        }
                    }
                    
                    # 如果文件名不包含Test.php，尝试添加
                    if (!str_ends_with($actualFileName, 'Test.php')) {
                        $possibleFile = $test_path . $actualFileName . 'Test.php';
                        if (file_exists($possibleFile)) {
                            return $possibleFile;
                        }
                    }
                    
                    # 如果文件名不包含Test，尝试添加Test
                    if (!str_ends_with($actualFileName, 'Test')) {
                        $possibleFile = $test_path . $actualFileName . 'Test.php';
                        if (file_exists($possibleFile)) {
                            return $possibleFile;
                        }
                    }
                    
                    # 如果文件名以Test开头，尝试去掉Test前缀
                    if (str_starts_with($actualFileName, 'Test')) {
                        $withoutTest = substr($actualFileName, 4); // 去掉 "Test" 前缀
                        $possibleFile = $test_path . $withoutTest . 'Test.php';
                        if (file_exists($possibleFile)) {
                            return $possibleFile;
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
            <directory suffix=".php">../../app</directory>
        </include>
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
    PHPUnit测试套件命令使用指南
    支持多种测试方式：套件测试、模块测试、文件测试、方法测试
    提供智能文件名匹配，默认后台运行并启动报告服务器
    
    ⚡ 重要变更：
    现在默认后台运行并生成HTML报告！
    如需前台运行，请使用 -f 或 --foreground 参数

🎯 基本语法：
    php bin/w phpunit:run [选项] [套件名]

🔧 常用选项：
    -f, --foreground        前台运行（不启动报告服务器，直接输出结果）
    -p, --port=<端口>       指定报告服务器端口（默认：9980）
    --debug                 显示详细的调试信息
    --module=<模块名>       指定要测试的模块
    --name=<文件名|方法名>  指定测试文件或方法（支持智能匹配）
    -h, --help              显示此帮助信息

📝 参数：
    <套件名>                可选的测试套件名称（例如：unit）

📋 使用方式：

1️⃣ 默认测试（后台运行+HTML报告）：
    php bin/w phpunit:run --name=Eav                # 默认后台运行
    php bin/w phpunit:run --name=Eav::testMethod   # 测试单个方法
    php bin/w phpunit:run --module=Weline_Ai       # 测试整个模块
    php bin/w phpunit:run                          # 运行默认套件

2️⃣ 快速测试（前台直接输出结果）：
    php bin/w phpunit:run -f --name=Eav            # 前台运行，直接看结果
    php bin/w phpunit:run -f --name=Eav::testMethod

3️⃣ 指定套件测试：
    php bin/w phpunit:run                          # 运行默认套件（后台）
    php bin/w phpunit:run unit                     # 运行指定套件（后台）
    php bin/w phpunit:run -f unit                  # 前台运行指定套件

4️⃣ 指定模块测试：
    php bin/w phpunit:run --module=Weline_Eav        # 后台运行
    php bin/w phpunit:run --module=Weline_Database   # 后台运行
    php bin/w phpunit:run -f --module=Weline_Eav     # 前台运行

5️⃣ 自定义端口：
    php bin/w phpunit:run --port=8080 --module=Weline_Ai
    php bin/w phpunit:run -p 8080 --name=Eav

🎨 智能文件名匹配规则：
    Eav         → EavTest.php
    TestEav     → EavTest.php
    EavTest     → EavTest.php
    EavTest.php → EavTest.php

🚀 最佳实践：
    · 日常测试：直接运行（默认后台），访问浏览器查看报告
    · 快速调试：添加 -f 参数前台运行，直接查看结果
    · CI/CD集成：使用 -f 参数，将结果输出到日志
    · 调试时添加 --debug 参数查看详细信息

💡 提示：
    现在默认后台运行，测试完成后会自动启动报告服务器
    报告地址会在命令行显示（默认 http://localhost:9980）
    如需前台运行直接看结果，添加 -f 或 --foreground 参数

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
        
        $testPath = $targetModule['base_path'] . 'test' . DS;
        if (!is_dir($testPath)) {
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
            $testPath = $module['base_path'] . 'test' . DS;
            if (!is_dir($testPath)) {
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

    /**
     * 查找可用端口
     * 
     * @param int $startPort 起始端口
     * @return int 可用端口
     */
}
