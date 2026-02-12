<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/9/27 10:17:25
 */

namespace Weline\Framework\System\Process;

use Weline\Framework\App\Env;
use Weline\Framework\System\Process\Driver\ProcessDriverFactory;
use Weline\Framework\System\Process\Driver\ProcessDriverInterface;

/**
 * 进程管理 Facade
 * 
 * 遵循里氏替换原则（LSP）和开闭原则（OCP）：
 * - OS 特定操作委托给 ProcessDriverInterface 实现
 * - 添加新系统支持只需创建新驱动，无需修改此类
 * 
 * @see ProcessDriverInterface 驱动接口
 * @see ProcessDriverFactory 驱动工厂
 */
class Processer
{
    /**
     * 进程日志是否启用的缓存（null=未检查，true/false=已检查结果）
     */
    private static ?bool $logEnabledCache = null;
    
    /**
     * PowerShell 可用性缓存（仅 Windows）
     */
    private static ?bool $powerShellAvailableCache = null;
    
    
    /**
     * 框架进程名前缀（用于安全校验，防止误杀非框架进程）
     */
    public const WELINE_PROCESS_PREFIX = 'weline-';
    
    /**
     * 进程名最大长度
     * Windows 命令行最大长度 8191，Linux 通常 4096
     * 保守取 200 以确保不会过长
     */
    public const PROCESS_NAME_MAX_LENGTH = 200;
    
    /*----------------------------------------进程名规范化区域------------------------------------------*/
    
    /**
     * 规范化进程名
     * 
     * 将任意字符串转换为可用于 Windows 和 Linux 命令行搜索的进程名。
     * 
     * 规则：
     * 1. 所有标点符号替换为 -
     * 2. 空格替换为 -
     * 3. 引号直接移除
     * 4. 多个连续 - 合并为一个
     * 5. 首尾 - 移除
     * 6. 统一小写（便于搜索匹配）
     * 7. 截断到最大长度
     * 
     * @param string $name 原始名称
     * @return string 规范化后的名称
     */
    public static function normalizeName(string $name): string
    {
        if (empty($name)) {
            return '';
        }
        
        // 1. 移除引号
        $name = \str_replace(['"', "'", '`'], '', $name);
        
        // 2. 标点符号和特殊字符替换为 -
        // 包括：. , ; : ! ? @ # $ % ^ & * ( ) [ ] { } | \ / < > = + ~ 空格
        $name = \preg_replace('/[.,;:!?@#$%^&*()[\]{}|\\\\\/\s<>=+~]+/', '-', $name);
        
        // 3. 多个连续 - 合并为一个
        $name = \preg_replace('/-+/', '-', $name);
        
        // 4. 首尾 - 移除
        $name = \trim($name, '-');
        
        // 5. 转小写
        $name = \strtolower($name);
        
        // 6. 截断到最大长度
        if (\strlen($name) > self::PROCESS_NAME_MAX_LENGTH) {
            $name = \substr($name, 0, self::PROCESS_NAME_MAX_LENGTH);
            // 如果截断后最后是 -，移除它
            $name = \rtrim($name, '-');
        }
        
        return $name ?: 'process';
    }
    
    /**
     * 从命令行生成进程名
     * 
     * 如果命令行中包含 --name= 或 -name= 参数，提取并规范化；
     * 否则根据命令内容自动生成一个唯一且可搜索的名称。
     * 
     * 生成规则：
     * 1. 提取脚本文件名（不含路径和扩展名）
     * 2. 提取关键参数（如端口号）
     * 3. 添加 weline- 前缀
     * 4. 规范化
     * 
     * @param string $command 完整命令行
     * @return string 生成的进程名（已包含 weline- 前缀）
     */
    public static function generateProcessName(string $command): string
    {
        if (empty($command)) {
            return self::WELINE_PROCESS_PREFIX . 'unknown-' . \time();
        }
        
        // 尝试从命令行提取现有的 --name 参数
        if (\preg_match('/--name[=\s]+([^\s]+)/', $command, $matches)) {
            $name = $matches[1];
            // 如果已经有 weline- 前缀，直接规范化返回
            if (\str_starts_with($name, self::WELINE_PROCESS_PREFIX)) {
                return self::normalizeName($name);
            }
            // 添加前缀并规范化
            return self::WELINE_PROCESS_PREFIX . self::normalizeName($name);
        }
        
        // 尝试短格式 -name
        if (\preg_match('/-name[=\s]+([^\s]+)/', $command, $matches)) {
            $name = $matches[1];
            if (\str_starts_with($name, self::WELINE_PROCESS_PREFIX)) {
                return self::normalizeName($name);
            }
            return self::WELINE_PROCESS_PREFIX . self::normalizeName($name);
        }
        
        // 没有 name 参数，自动生成
        return self::generateNameFromCommand($command);
    }
    
    /**
     * 根据命令内容自动生成进程名
     * 
     * @param string $command 完整命令行
     * @return string 生成的进程名
     */
    private static function generateNameFromCommand(string $command): string
    {
        $parts = [];
        
        // 1. 提取脚本文件名
        // 匹配 .php 文件
        if (\preg_match('/([a-zA-Z0-9_-]+)\.php/', $command, $matches)) {
            $parts[] = $matches[1];
        }
        
        // 2. 提取端口号（如果有）
        if (\preg_match('/(?:--?port[=\s]+|localhost:)(\d+)/', $command, $matches)) {
            $parts[] = 'port-' . $matches[1];
        }
        
        // 3. 提取 server 相关关键词
        if (\preg_match('/\b(server|worker|master|dispatcher|watcher)\b/i', $command, $matches)) {
            $type = \strtolower($matches[1]);
            if (!\in_array($type, $parts)) {
                \array_unshift($parts, $type);
            }
        }
        
        // 4. 如果还是空的，使用命令的 hash
        if (empty($parts)) {
            $parts[] = 'cmd-' . \substr(\md5($command), 0, 8);
        }
        
        // 组合并规范化
        $name = self::WELINE_PROCESS_PREFIX . self::normalizeName(\implode('-', $parts));
        
        return $name;
    }
    
    /**
     * 确保命令包含 --name 参数
     * 
     * 如果命令中没有 --name 参数，自动添加一个。
     * 这样后续可以通过进程名查找。
     * 
     * @param string $command 原始命令
     * @return array{command: string, name: string} 包含修改后的命令和进程名
     */
    public static function ensureProcessName(string $command): array
    {
        // 检查是否已有 --name 或 -name 参数
        if (\preg_match('/--?name[=\s]+/', $command)) {
            $name = self::generateProcessName($command);
            return [
                'command' => $command,
                'name' => $name,
            ];
        }
        
        // 没有 name 参数，生成一个并添加到命令中
        $name = self::generateNameFromCommand($command);
        $command = $command . ' --name=' . $name;
        
        return [
            'command' => $command,
            'name' => $name,
        ];
    }
    
    /**
     * 从进程名或命令行获取统一的查找标识
     * 
     * 无论是通过 name 参数还是通过命令行，都能得到一致的查找标识。
     * 这样即使有的地方不给 name 参数，我们也能通过命令行转化后查找。
     * 
     * @param string $pname 进程名或命令行
     * @return string 统一的查找标识
     */
    public static function getSearchableIdentifier(string $pname): string
    {
        // 如果包含 --name= 参数，提取它
        if (\preg_match('/--name[=\s]+([^\s]+)/', $pname, $matches)) {
            return self::normalizeName($matches[1]);
        }
        
        // 如果是纯名字（不含空格和路径），直接规范化
        if (!\str_contains($pname, ' ') && !\str_contains($pname, '/') && !\str_contains($pname, '\\')) {
            return self::normalizeName($pname);
        }
        
        // 否则当作命令行处理
        return self::generateProcessName($pname);
    }
    
    /*----------------------------------------进程名规范化区域结束------------------------------------------*/
    
    /**
     * 获取当前系统对应的进程驱动
     * 
     * @return ProcessDriverInterface
     */
    public static function getDriver(): ProcessDriverInterface
    {
        return ProcessDriverFactory::getDriver();
    }
    
    /**
     * 检查当前是否为 Windows 系统
     * 
     * @return bool
     */
    public static function isWindows(): bool
    {
        return ProcessDriverFactory::isWindows();
    }
    
    /**
     * 检测 PowerShell 是否可用（带缓存）
     */
    private static function isPowerShellAvailable(): bool
    {
        if (!IS_WIN) {
            return false;
        }
        if (self::$powerShellAvailableCache !== null) {
            return self::$powerShellAvailableCache;
        }
        $output = [];
        $code = 0;
        @\exec('powershell -NoProfile -Command "$PSVersionTable.PSVersion.Major" 2>NUL', $output, $code);
        self::$powerShellAvailableCache = ($code === 0);
        return self::$powerShellAvailableCache;
    }
    
    public static function parseArgs(string $pname): array
    {
        $args = explode(' ', $pname);
        foreach ($args as $k => $arg) {
            // 第一个参数作为命令，但如果它是 --name=xxx 格式，也要解析
            if ($k == 0) {
                $args['command'] = $arg;
                // 如果命令本身就是 --name=xxx 格式，继续解析
                if (!str_contains($arg, '=')) {
                    continue;
                }
            }
            if (is_string($k)) {
                continue;
            }
            if (str_contains($arg, '=')) {
                $arg                      = explode('=', $arg);
                $args[trim($arg[0], '-')] = $arg[1] ?? true;
                continue;
            }
            # 参数名
            if (str_starts_with($arg, '-')) {
                $argName = trim($arg, '-');
                $next    = $args[$k + 1] ?? null;
                if (empty($next)) {
                    $args[$argName] = true;
                    $args[$arg]     = true;
                    continue;
                }
                if (str_starts_with($next, '-')) {
                    $args[$arg]     = true;
                    $args[$argName] = true;
                    $argName        = null;
                }
            } elseif (!empty($argName)) {
                if (!isset($args[$argName])) {
                    $args[$argName] = $arg;
                } else {
                    if (is_array($args[$argName])) {
                        $args[$argName][] = $arg;
                    } else {
                        $args[$argName] = [$args[$argName], $arg];
                    }
                }
            }
        }
        return $args;
    }

    /**
     * 创建进程
     * 
     * 参考 Symfony Process 的 start() 方法的最佳实践：
     * - 使用 set_error_handler 捕获 proc_open 内部错误
     * - Windows 上使用 bypass_shell 选项避免 cmd.exe 中间层
     * - 多层回退策略确保在各种环境下都能启动进程
     * 
     * @param string $pname 进程命令（含参数）
     * @param bool $block 是否阻塞等待 PID（默认 true）
     * @param bool $foreground 是否前端模式（默认 false）
     *                         - false: 后台模式，输出到日志文件，窗口隐藏
     *                         - true: 前端模式，继承父进程控制台，输出直接显示在当前终端
     * @param bool|null $enableLog 是否启用日志记录（默认 null，跟随 env 配置 system.processer.log）
     *                        - true: 强制启用，后台模式下将 stdout/stderr 重定向到进程日志文件（追加模式）
     *                        - false: 强制禁用，后台模式下不记录日志，输出丢弃
     *                        - null: 跟随 env 配置 system.processer.log（默认 false）
     * @return int 进程 PID，失败返回 0
     */
    public static function create(string $pname, $block = true, bool $foreground = false, ?bool $enableLog = null): int
    {
        // enableLog 为 null 时，跟随 env 配置
        if ($enableLog === null) {
            $enableLog = self::isLogEnabled();
        }
        
        // 确保进程有 --name 参数（如果没有则自动生成）
        $processInfo = self::ensureProcessName($pname);
        $pname = $processInfo['command'];
        
        // 阻塞模式 + 后台：先快速检测进程是否已在运行
        // 使用 getData + isRunningByPid 快速路径，避免慢的系统搜索
        if ($block && !$foreground) {
            $existingPid = (int) self::getData($pname, 'pid');
            if ($existingPid > 0 && self::isRunningByPid($existingPid)) {
                return $existingPid;
            }
        }
        
        # 获取驱动提供的函数检测（使用缓存）
        $driver = self::getDriver();
        
        # 检查可用的进程控制函数
        $disabledFunctions = \array_map('trim', \explode(',', \ini_get('disable_functions') ?: ''));
        $availableFunctions = [
            'proc_open' => \function_exists('proc_open') && !\in_array('proc_open', $disabledFunctions, true),
            'exec' => \function_exists('exec') && !\in_array('exec', $disabledFunctions, true),
            'shell_exec' => \function_exists('shell_exec') && !\in_array('shell_exec', $disabledFunctions, true),
            'popen' => \function_exists('popen') && !\in_array('popen', $disabledFunctions, true),
            'pclose' => \function_exists('pclose') && !\in_array('pclose', $disabledFunctions, true)
        ];
        
        
        $pid = 0;
        
        # ========== 前端模式：显示进程输出 ==========
        if ($foreground) {
            // Windows 前端模式：弹出独立窗口，同时获取 PID
            if (IS_WIN) {
                $phpBinary = PHP_BINARY;
                $args = $pname;
                if (\preg_match('/^"[^"]+"(.*)$/', $pname, $m)) {
                    $args = \trim($m[1]);
                }
                $escapedArgs = \str_replace("'", "''", $args);
                $escapedBP = \str_replace("'", "''", BP);
                
                // 使用 PowerShell Start-Process -PassThru 获取 PID
                $psCommand = 'powershell -NoProfile -Command "($p = Start-Process -FilePath \'' . $phpBinary . '\' -ArgumentList \'' . $escapedArgs . '\' -WorkingDirectory \'' . $escapedBP . '\' -PassThru).Id"';
                $output = [];
                @\exec($psCommand . ' 2>NUL', $output);
                
                if (!empty($output[0]) && \is_numeric(\trim($output[0]))) {
                    $pid = (int)\trim($output[0]);
                    $pid = self::setPid($pname, $pid);
                    return $pid;
                }
                
                // 回退到不获取 PID 的方式
                $psCommandFallback = 'powershell -NoProfile -Command "Start-Process -FilePath \'' . $phpBinary . '\' -ArgumentList \'' . $escapedArgs . '\' -WorkingDirectory \'' . $escapedBP . '\'"';
                @\exec($psCommandFallback . ' 2>NUL');
                return 0;
            }
            
            // Linux/Mac 前端模式：使用 proc_open
            if (!IS_WIN && $availableFunctions['proc_open']) {
                $nohupCommand = $pname . ' &';
                $descriptorspec = [
                    0 => ['pipe', 'r'],
                    1 => STDOUT,
                    2 => STDERR,
                ];
                
                // 参考 Symfony：使用 set_error_handler 捕获启动错误
                $lastError = null;
                \set_error_handler(function ($type, $msg) use (&$lastError) {
                    $lastError = $msg;
                    return true;
                });
                try {
                    $process = \proc_open($nohupCommand, $descriptorspec, $pipes, BP);
                } finally {
                    \restore_error_handler();
                }
                
                if (\is_resource($process)) {
                    $status = @\proc_get_status($process);
                    $pid = $status['pid'] ?? 0;
                    if ($pid > 0) {
                        $pid = self::setPid($pname, $pid);
                    }
                    if (isset($pipes[0])) {
                        @\fclose($pipes[0]);
                    }
                    return $pid;
                }
                
                // 记录启动错误到日志
                if ($enableLog && $lastError !== null) {
                    self::setOutput($pname, "[ERROR] proc_open failed (foreground): {$lastError}" . PHP_EOL, true);
                }
            }
            // 前端模式失败，回退到后台模式
        }
        
        # ========== 后台模式：根据 $enableLog 决定输出目标 ==========
        // $enableLog=true: stdout/stderr 追加到进程日志文件
        // $enableLog=false: stdout/stderr 丢弃（输出到 NUL / /dev/null）
        $logFile = $enableLog ? self::getLogFile($pname) : '';
        $nullDevice = IS_WIN ? 'NUL' : '/dev/null';
        
        # 方案1: proc_open (参考 Symfony Process - 最可靠的跨平台方式)
        if ($availableFunctions['proc_open']) {
            if (IS_WIN) {
                // Windows: 使用 cmd /c 包装以支持重定向
                // 参考 Symfony Process：Windows 上使用临时文件替代管道（PHP bug #51800）
                $escapedBP = \str_replace('"', '\"', BP);
                if ($enableLog) {
                    $escapedLogFile = \str_replace('"', '\"', $logFile);
                    $command = 'cmd /c start /min /d "' . $escapedBP . '" "" cmd /c "' . $pname . ' >> \"' . $escapedLogFile . '\" 2>&1"';
                } else {
                    $command = 'cmd /c start /min /d "' . $escapedBP . '" "" cmd /c "' . $pname . ' > NUL 2>&1"';
                }
            } else {
                if ($enableLog) {
                    $command = 'cd ' . BP . ' && nohup ' . $pname . ' >> "' . $logFile . '" 2>&1 & echo $!';
                } else {
                    $command = 'cd ' . BP . ' && nohup ' . $pname . ' > /dev/null 2>&1 & echo $!';
                }
            }
            
            if ($enableLog) {
                self::setOutput($pname, PHP_EOL . '--- Process started at ' . \date('Y-m-d H:i:s') . ' ---' . PHP_EOL . $command . PHP_EOL, true);
            }
            
            $descriptorspec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            
            // 参考 Symfony Process：使用 set_error_handler 捕获 proc_open 错误
            $lastError = null;
            \set_error_handler(function ($type, $msg) use (&$lastError) {
                $lastError = $msg;
                return true;
            });
            try {
                $process = \proc_open($command, $descriptorspec, $procPipes, BP);
            } finally {
                \restore_error_handler();
            }
            
            if (\is_resource($process)) {
                // 设置输出管道非阻塞（避免 PHP bug #51800 在 Windows 上的阻塞问题）
                if (isset($procPipes[1])) {
                    \stream_set_blocking($procPipes[1], false);
                }
                
                $pid = (int) \proc_get_status($process)['pid'];
                
                // Windows cmd /c start 场景：proc_get_status 可能返回 cmd.exe PID，尝试获取真实进程 PID
                if (IS_WIN && $pid > 0 && \str_starts_with($command, 'cmd /c start')) {
                    $realPid = self::findPhpProcessPid($pname);
                    if ($realPid > 0) {
                        $pid = $realPid;
                    }
                }
                
                // 关闭管道
                if (isset($procPipes[0])) @\fclose($procPipes[0]);
                if (isset($procPipes[1])) @\fclose($procPipes[1]);
                if (isset($procPipes[2])) @\fclose($procPipes[2]);
                
                if ($pid > 0) {
                    $pid = self::setPid($pname, $pid);
                    return $pid;
                }
            }
            
            // 记录错误
            if ($enableLog && $lastError !== null) {
                self::setOutput($pname, "[ERROR] proc_open failed: {$lastError}" . PHP_EOL);
            }
        }
        
        # 方案2: Windows PowerShell 启动（proc_open 失败后回退）
        $psAvailable = IS_WIN && $availableFunctions['exec'] && self::isPowerShellAvailable();
        if (IS_WIN && $availableFunctions['exec'] && $psAvailable) {
            $phpBinary = PHP_BINARY;
            $arguments = $pname;
            
            if (\str_starts_with($pname, '"' . $phpBinary . '"')) {
                $arguments = \trim(\substr($pname, \strlen('"' . $phpBinary . '"')));
            } elseif (\str_starts_with($pname, $phpBinary)) {
                $arguments = \trim(\substr($pname, \strlen($phpBinary)));
            }
            
            // PowerShell 单引号转义
            $escapedArgs = \str_replace("'", "''", $arguments);
            $escapedBP = \str_replace("'", "''", BP);
            // Start-Process -PassThru 获取 PID
            $psCommand = 'powershell -NoProfile -Command "($p = Start-Process -FilePath \'' . $phpBinary . '\' -ArgumentList \'' . $escapedArgs . '\' -WorkingDirectory \'' . $escapedBP . '\' -WindowStyle Hidden -PassThru).Id"';
            $output = [];
            @\exec($psCommand . ' 2>NUL', $output, $returnCode);
            if ($returnCode === 0 && !empty($output[0]) && \is_numeric(\trim($output[0]))) {
                $pid = (int)\trim($output[0]);
                $pid = self::setPid($pname, $pid);
                return $pid;
            }
            
            // 非阻塞模式：快速启动不等 PID
            if (!$block) {
                $psCommandFast = 'powershell -NoProfile -Command "Start-Process -FilePath \'' . $phpBinary . '\' -ArgumentList \'' . $escapedArgs . '\' -WorkingDirectory \'' . $escapedBP . '\' -WindowStyle Hidden" 2>NUL';
                @\exec($psCommandFast);
                return 0;
            }
            
            // 阻塞模式：等待后查找 PID
            \sleep(1);
            $pid = self::findPhpProcessPid($pname);
            if ($pid > 0) {
                $pid = self::setPid($pname, $pid);
                return $pid;
            }
        }
        
        # 方案3: Windows cmd /c start 回退（仅阻塞模式）
        if (IS_WIN && $availableFunctions['exec'] && $block) {
            $cmdCommand = 'cmd /c start /min /d "' . BP . '" ' . $pname;
            $redirect = $enableLog ? ' >> "' . $logFile . '" 2>&1' : ' > NUL 2>&1';
            \exec($cmdCommand . $redirect);
            \sleep(1);
            $pid = self::findPhpProcessPid($pname);
            if ($pid > 0) {
                $pid = self::setPid($pname, $pid);
                return $pid;
            }
        }
        
        # 方案4: Linux/Mac nohup 后台启动
        if (!IS_WIN && $availableFunctions['exec']) {
            $redirect = $enableLog ? '>> "' . $logFile . '" 2>&1' : '> /dev/null 2>&1';
            $nohupCommand = 'nohup ' . $pname . ' ' . $redirect . ' & echo $!';
            $output = [];
            \exec($nohupCommand, $output);
            if (!empty($output) && \is_numeric($output[0])) {
                $pid = (int)$output[0];
                $pid = self::setPid($pname, $pid);
                return $pid;
            }
        }
        
        return 0;
    }

    /**
     * 通过进程名查找 PHP 进程 PID
     * 
     * 委托给系统驱动实现
     *
     * @param string $pname 进程名
     * @return int PID，未找到返回 0
     */
    public static function findPhpProcessPid(string $pname): int
    {
        return self::getDriver()->findPhpProcessPid($pname);
    }

    /**
     * 启动 PHP 内置服务器并返回 PID
     * 
     * 委托给系统驱动实现
     *
     * @param string $docRoot 文档根目录
     * @param int $port 端口
     * @param string $logFile 日志文件
     * @return int PID，失败返回 0
     */
    public static function startBuiltInServer(string $docRoot, int $port, string $logFile): int
    {
        return self::getDriver()->startBuiltInServer($docRoot, $port, $logFile);
    }

    /**
     * 注册进程 PID。同时更新 name_index.json 和 pid_index.json 索引。
     * 
     * 注：不再创建 {pid}.pid 文件，反向查找统一使用 pid_index.json
     */
    public static function setPid(string $pname, int $pid): int
    {
        $pid_file  = self::getPidFile($pname);
        $task_name = self::getTaskName($pname);
        
        // 同 pname 下若已有不同 PID，从 pid_index 移除旧 pid
        $existingPid = 0;
        if (\is_file($pid_file)) {
            $raw = @\file_get_contents($pid_file);
            if ($raw !== false) {
                $data = \json_decode($raw, true);
                $existingPid = isset($data['pid']) ? (int) $data['pid'] : 0;
            }
        }
        if ($existingPid > 0 && $existingPid !== $pid) {
            // 从 pid_index 移除旧 pid（不调用其他查询方法，直接操作索引）
            $pidIndex = self::readPidIndex();
            if (isset($pidIndex[$existingPid])) {
                unset($pidIndex[$existingPid]);
                self::writePidIndex($pidIndex);
            }
        }
        
        $payload = \json_encode([
            'pid' => $pid,
            'time' => time(),
            'date' => date('Y-m-d H:i:s'),
            'pname' => $pname,
            'task_name' => $task_name,
        ]);
        $dir = \dirname($pid_file);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0777, true);
        }
        // 写入时加锁，避免并发写导致文件损坏
        $fp = @\fopen($pid_file, 'cb');
        if ($fp && \flock($fp, \LOCK_EX)) {
            \ftruncate($fp, 0);
            \fwrite($fp, $payload);
            \flock($fp, \LOCK_UN);
            \fclose($fp);
        } else {
            if ($fp) {
                \fclose($fp);
            }
            @\file_put_contents($pid_file, $payload);
        }
        
        // 更新索引（写顺序：先 *-pid.json 已完成，再 name_index，再 pid_index）
        self::updateIndexes($pname, $pid, $pid_file);
        
        // 记录进程创建日志
        self::logLifecycleEvent('create', $pname, $pid);
        
        return $pid;
    }

    /**
     * @DESC          # 获取进程数据
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 下午5:13
     * 参数区：
     * @param string $pname
     * @param string $key
     * @return array|string
     */
    public static function getData(string $pname, string $key = ''): mixed
    {
        $pid_file = self::getPidFile($pname);
        if (!\is_file($pid_file)) {
            return $key ? null : [];
        }
        $raw = '';
        $fp  = @\fopen($pid_file, 'rb');
        if ($fp && \flock($fp, \LOCK_SH)) {
            $raw = \stream_get_contents($fp);
            \flock($fp, \LOCK_UN);
            \fclose($fp);
        } else {
            if ($fp) {
                \fclose($fp);
            }
            $raw = @\file_get_contents($pid_file) ?: '';
        }
        $data = \json_decode($raw, true) ?: [];
        if ($key && isset($data[$key])) {
            return $data[$key];
        }
        return $data;
    }

    /**
     * @DESC          # 设置进程数据
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 下午5:12
     * 参数区：
     * @param string $pname
     * @param string $key
     * @param string $value
     * @return array
     */
    public static function setData(string $pname, string $key, string $value): array
    {
        $pid_file   = self::getPidFile($pname);
        $data       = json_decode(file_get_contents($pid_file) ?: '', true) ?: [];
        $data[$key] = $value;
        file_put_contents($pid_file, json_encode($data));
        return $data;
    }

    /**
     * @DESC          # 获取进程pid
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 下午5:12
     * 参数区：
     * @param string $pname
     * @return int
     */
    public static function getPid(string $pname): int
    {
        $pid = self::getData($pname, 'pid') ?: 0;
        if ($pid) {
            // 验证 PID 是否仍在运行
            if (self::isRunningByPid($pid)) {
                return $pid;
            }
        }
        // 使用驱动查找进程
        $result = self::findPhpProcessPid($pname);
        if ($result > 0) {
            self::setPid($pname, $result);
        }
        return $result;
    }

    /**
     * @DESC          # 获取父进程pid
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:11
     * 参数区：
     * @param string $pname
     * @return int
     */
    public static function getParentPid(string $pname): int
    {
        $pid  = self::getPidByName($pname);
        $ppid = self::getParentPidByPid($pid);
        return $ppid;
    }

    /**
     * @DESC          # 获取进程日志
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:11
     * 参数区：
     * @param string $pname
     * @return string
     */
    public static function getLogFile(string $pname): string
    {
        $task_name = self::getTaskName($pname);
        $path      = Env::VAR_DIR . 'process' . DS . $task_name . '.log';
        if (!is_file($path)) {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            touch($path);
        }
        return $path;
    }

    /**
     * @DESC          # 获取进程名
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 下午4:41
     * 参数区：
     * @param string $pname
     * @return string
     * @throws \Exception
     */
    /**
     * 获取进程任务名
     * 
     * 从进程名或命令行提取/生成一个规范化的任务名，用于 PID 文件等。
     * 使用统一的规范化规则，确保 Windows 和 Linux 都能通过该名称查找进程。
     * 
     * @param string $pname 进程名或命令行
     * @return string 规范化的任务名
     * @throws \Exception 如果进程名为空
     */
    public static function getTaskName(string $pname): string
    {
        if (empty($pname)) {
            throw new \Exception(__('进程名不能为空'));
        }
        
        // 优先从 --name 参数提取
        $args = self::parseArgs($pname);
        $task_name = $args['name'] ?? $args['process'] ?? '';
        
        if (!empty($task_name)) {
            // 规范化已有的名称
            return self::normalizeName($task_name);
        }
        
        // 没有 name 参数，尝试从命令提取
        // 移除 PHP 二进制路径
        $p_name_array = \explode(PHP_BINARY, $pname);
        $command_part = \array_pop($p_name_array);
        
        if (empty($command_part)) {
            $command_part = $pname;
        }
        
        // 若包含路径，只保留最后一段（文件名）
        if (\str_contains($command_part, \DIRECTORY_SEPARATOR) || \str_contains($command_part, '/')) {
            // 提取最后一个路径段
            $command_part = \preg_replace('#^.*[/\\\\]#', '', $command_part);
        }
        
        // 使用统一的规范化方法
        $task_name = self::normalizeName($command_part);
        
        return $task_name ?: 'process';
    }

    /**
     * @DESC          # 获取进程日志
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:11
     * 参数区：
     * @param string $pname
     * @return string
     */
    public static function getPidFile(string $pname): string
    {
        $task_name = self::getTaskName($pname);
        $dir       = Env::VAR_DIR . 'process' . DS . 'pid' . DS;
        $path      = $dir . $task_name . '-pid.json';
        if (!\is_file($path)) {
            if (!\is_dir($dir)) {
                @\mkdir($dir, 0777, true);
            }
            if (\is_dir($dir)) {
                @\touch($path);
            }
        }
        return $path;
    }

    /**
     * @DESC          # 获取进程日志
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:11
     * 参数区：
     * @param string $pname
     * @return string
     */
    /**
     * @deprecated 已废弃，反向查找使用 pid_index.json，不再创建 {pid}.pid 文件
     * 保留仅为向后兼容，不创建文件
     */
    public static function getPidNameFile(int $pid): string
    {
        if (0 === $pid) {
            return '';
        }
        // 不再创建文件，仅返回路径（用于清理旧文件）
        return Env::VAR_DIR . 'process' . DS . 'pid' . DS . $pid . '.pid';
    }

    /**
     * @DESC          # 移除进程日志文件
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午10:44
     * 参数区：
     * @param string $pname
     * @return true
     */
    public static function removeLogFile(string $pname)
    {
        $path = self::getLogFile($pname);
        if (is_file($path)) {
            unlink($path);
        }
        return true;
    }

    /**
     * 移除进程 PID 文件及相关索引
     * 
     * 删除 *-pid.json 文件并从 name_index/pid_index/port_index 移除对应项
     *
     * @param string $pname 进程名
     * @return true
     */
    public static function removePidFile(string $pname)
    {
        // 从 PID 文件读取 pid 和 ports（不调用 getPid，直接读文件）
        $pid = 0;
        $ports = [];
        $pidFile = self::getPidFile($pname);
        if (\is_file($pidFile)) {
            $raw = @\file_get_contents($pidFile);
            if ($raw !== false) {
                $data = \json_decode($raw, true);
                $pid = isset($data['pid']) ? (int) $data['pid'] : 0;
                $ports = isset($data['ports']) ? (array) $data['ports'] : [];
            }
            // 删除 JSON 文件
            @\unlink($pidFile);
        }
        
        // 从索引中移除（直接操作，不调用其他查询方法避免 loop）
        $nameIndex = self::readNameIndex();
        if (isset($nameIndex[$pname])) {
            unset($nameIndex[$pname]);
            self::writeNameIndex($nameIndex);
        }
        
        if ($pid > 0) {
            $pidIndex = self::readPidIndex();
            if (isset($pidIndex[$pid])) {
                unset($pidIndex[$pid]);
                self::writePidIndex($pidIndex);
            }
        }
        
        if (!empty($ports)) {
            $portIndex = self::readPortIndex();
            $changed = false;
            foreach ($ports as $port) {
                $key = (string) $port;
                if (isset($portIndex[$key])) {
                    unset($portIndex[$key]);
                    $changed = true;
                }
            }
            if ($changed) {
                self::writePortIndex($portIndex);
            }
        }
        
        return true;
    }

    /**
     * 清理已不存在进程的陈旧 *-pid.json 文件
     * 
     * 注：不再处理 {pid}.pid 文件（已废弃），改为清理陈旧的 JSON 文件
     *
     * @return int 删除的文件数量
     */
    public static function cleanupStalePidFiles(): int
    {
        $dir = Env::VAR_DIR . 'process' . DS . 'pid' . DS;
        if (!\is_dir($dir)) {
            return 0;
        }
        $removed = 0;
        
        // 清理陈旧的 *-pid.json 文件（进程不存活的）
        $files = \glob($dir . '*-pid.json');
        foreach ($files ?? [] as $path) {
            $raw = @\file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            $data = \json_decode($raw, true);
            $pid = isset($data['pid']) ? (int) $data['pid'] : 0;
            if ($pid > 0 && !self::isRunningByPid($pid)) {
                @\unlink($path);
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * @DESC          # 杀死进程
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午10:45
     * 参数区：
     * @param string $pname
     * @return bool
     */
    public static function kill(string $pname)
    {
        // 快速路径：先从文件获取 PID（< 1ms）
        // 不调用 getPidByName()，它会触发慢的系统进程搜索
        $pid = (int) self::getData($pname, 'pid');
        if ($pid <= 0) {
            // 没有 PID 文件记录，清理残留文件
            self::removePidFile($pname);
            return false;
        }
        $result = self::killByPid($pid);
        // 清理 PID 文件（避免累积）
        self::removePidFile($pname);
        return $result;
    }


    /**
     * @DESC          # 判断进程是否在运行
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午10:45
     * 参数区：
     * @param string $pname
     * @return bool
     */
    public static function running(string $pname): bool
    {
        // 快速路径：先从文件获取 PID（< 1ms）
        $pid = (int) self::getData($pname, 'pid');
        if ($pid > 0 && self::isRunningByPid($pid)) {
            return true;
        }
        
        // 慢路径：文件中 PID 无效，尝试完整 getPid（可能触发系统搜索）
        // 注意：Windows 上这个调用可能很慢（5-30秒），建议只在必要时使用
        $pid = self::getPid($pname);
        if (empty($pid)) {
            return false;
        }
        return self::isRunningByPid($pid);
    }

    /**
     * @DESC          # 判断进程是否在运行
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午10:45
     * 参数区：
     * @param string $pname
     * @return bool
     */
    public static function destroy(string $pname): bool
    {
        // 快速路径：先从文件获取 PID（< 1ms）
        $pid = (int) self::getData($pname, 'pid');
        
        // 如果文件中没有 PID，直接清理文件即可
        // 注意：不调用 getPid()，因为在 Windows 上它可能很慢（5-30秒）
        // 进程管理器创建的进程必须有 PID 文件，没有 PID 说明进程不存在或已被清理
        if ($pid <= 0) {
            self::removePidFile($pname);
            self::removeLogFile($pname);
            return false;
        }
        
        $result = self::killByPid($pid);
        // 无论杀死是否成功，都要清理 PID 文件（避免累积）
        self::removePidFile($pname);
        self::removeLogFile($pname);
        return $result;
    }


    /**
     * @DESC          # 获取进程输出
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午10:45
     * 参数区：
     * @param string $pname
     * @return string|false
     */
    public static function output(string $pname): string|false
    {
        $path = self::getLogFile($pname);
        return file_get_contents($path);
    }


    /**
     * @DESC          # 写入进程日志
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:11
     * 参数区：
     * @param string $pname
     * @param string $content
     * @return false|int
     */
    public static function setOutput(string $pname, string $content, bool $append = true): false|int
    {
        $path = self::getLogFile($pname);
        return file_put_contents($path, $content, $append ? FILE_APPEND : 0);
    }

    /*----------------------------------------进程日志配置区域------------------------------------------*/
    
    /**
     * 检查进程管理日志是否启用
     * 
     * 读取 env.php 中的 system.processer.log 配置项。
     * 默认 false（不启用），需要在 env.php 中显式设置为 true 才启用。
     * 
     * 结果会缓存到静态变量中，WLS 常驻进程下仅首次读取配置文件。
     * 
     * @return bool 是否启用日志
     */
    public static function isLogEnabled(): bool
    {
        if (self::$logEnabledCache !== null) {
            return self::$logEnabledCache;
        }
        
        try {
            self::$logEnabledCache = (bool) Env::get('system.processer.log', false);
        } catch (\Throwable $e) {
            // 框架未初始化时（如极早期启动阶段），默认不启用
            self::$logEnabledCache = false;
        }
        
        return self::$logEnabledCache;
    }
    
    /**
     * 强制设置进程日志开关（覆盖 env 配置）
     * 
     * 用于 WLS -log 参数等场景：即使 env 配置为 false，
     * 通过此方法设置为 true 后，所有后续进程创建都会启用日志。
     * 
     * @param bool $enabled 是否启用
     */
    public static function setLogEnabled(bool $enabled): void
    {
        self::$logEnabledCache = $enabled;
    }
    
    /**
     * 清除日志启用缓存（用于 WLS 热重载后重新读取配置）
     */
    public static function clearLogEnabledCache(): void
    {
        self::$logEnabledCache = null;
    }
    
    /*----------------------------------------通过Pid操作函数区域------------------------------------------*/
    /**
     * @DESC          # 通过pid获取父进程pid
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:12
     * 参数区：
     * @param int $pid
     * @return int
     */
    public static function getParentPidByPid(int $pid): int
    {
        if ($pid <= 0) {
            return 0;
        }
        
        if (IS_WIN) {
            // 优先使用 PowerShell CIM（wmic 已废弃）
            $out = [];
            $code = 0;
            @\exec("powershell -NoProfile -Command \"(Get-CimInstance Win32_Process -Filter \\\"ProcessId={$pid}\\\" -ErrorAction SilentlyContinue).ParentProcessId\" 2>NUL", $out, $code);
            if ($code === 0 && !empty($out[0]) && \is_numeric(\trim($out[0]))) {
                return (int)\trim($out[0]);
            }
            return 0;
        }
        
        // Linux/Mac：优先使用 /proc（零开销），回退到 ps
        $ppidFile = "/proc/{$pid}/status";
        if (\is_file($ppidFile)) {
            $status = @\file_get_contents($ppidFile);
            if ($status !== false && \preg_match('/^PPid:\s+(\d+)/m', $status, $m)) {
                return (int) $m[1];
            }
        }
        
        $out = [];
        @\exec("ps -p {$pid} -o ppid= 2>/dev/null", $out);
        if (!empty($out[0])) {
            return (int) \trim($out[0]);
        }
        
        return 0;
    }

    /**
     * @DESC          # 通过pid移除进程日志文件
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午10:44
     * 参数区：
     * @param string $pname
     * @return true
     */
    public static function removeLogFileByPid(int $pid)
    {
        $pname = self::getNameByPid($pid);
        return self::removeLogFile($pname);
    }

    /**
     * @DESC          # 通过pid获取进程日志文件
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:12
     * 参数区：
     * @param int $pid
     * @return string
     */
    public static function getLogFileByPid(int $pid): string
    {
        $pname = self::getNameByPid($pid);
        return self::getLogFile($pname);
    }

    /**
     * @DESC          # 通过pid杀死进程（仅杀己方进程）
     *
     * 安全策略：杀前校验 isProcessManagerCreated($pid)，非己方进程拒绝杀并返回 false。
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午10:45
     * 参数区：
     * @param int $pid
     * @param bool $skipCheck 是否跳过己方进程校验（内部调用时可跳过，默认 false）
     * @return bool
     */
    public static function killByPid(int $pid, bool $skipCheck = false)
    {
        if ($pid <= 0) {
            return false;
        }
        
        // 己方进程校验：非己方进程拒绝杀
        if (!$skipCheck && !self::isProcessManagerCreated($pid)) {
            return false;
        }
        
        $pname   = self::getNameByPid($pid);
        $logfile = '';
        if ($pname && $pname !== 'unknown') {
            $logfile = self::getLogFile($pname);
        }
        
        // 使用驱动执行 kill 操作
        $result = self::getDriver()->killProcess($pid);

        if ($pname && $pname !== 'unknown') {
            // 记录杀进程日志
            self::logLifecycleEvent('kill', $pname, $pid);
            # 卸载pid文件
            self::removePidFile($pname);
            # 卸载日志文件
            self::removeLogFile($pname);
        }
        return $result;
    }
    
    /**
     * 通过 PID 杀死进程树（包括所有子进程）
     * 
     * 安全策略：杀前校验 isProcessManagerCreated($pid)，非己方进程拒绝杀并返回 false。
     * 
     * @param int $pid 进程 ID
     * @param bool $skipCheck 是否跳过己方进程校验（内部调用时可跳过，默认 false）
     * @return bool 是否成功
     */
    public static function killProcessTreeByPid(int $pid, bool $skipCheck = false): bool
    {
        if ($pid <= 0) {
            return false;
        }
        
        // 己方进程校验：非己方进程拒绝杀
        if (!$skipCheck && !self::isProcessManagerCreated($pid)) {
            return false;
        }
        
        $pname = self::getNameByPid($pid);
        $logfile = '';
        if ($pname && $pname !== 'unknown') {
            $logfile = self::getLogFile($pname);
        }
        
        // 使用驱动执行 killProcessTree 操作
        $result = self::getDriver()->killProcessTree($pid);
        
        // 如果 killProcessTree 失败，尝试使用 killProcess（单进程杀死）作为回退
        if (!$result && self::isRunningByPid($pid)) {
            // 回退：使用 killProcess（单进程杀死）
            $result = self::getDriver()->killProcess($pid);
        }

        if ($pname && $pname !== 'unknown') {
            // 记录杀进程日志
            self::logLifecycleEvent('kill', $pname, $pid, 'killed process tree');
            # 卸载pid文件
            self::removePidFile($pname);
            # 卸载日志文件
            self::removeLogFile($pname);
        }
        return $result;
    }
    
    /**
     * 向指定进程发送信号（跨平台委托到驱动）
     *
     * @param int $pid 进程 ID
     * @param int $signal 信号值（如 SIGTERM/SIGUSR1/SIGHUP）
     * @param bool $skipCheck 是否跳过己方进程校验
     * @return bool 是否发送成功
     */
    public static function sendSignal(int $pid, int $signal, bool $skipCheck = false): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (!$skipCheck && !self::isProcessManagerCreated($pid)) {
            return false;
        }
        return self::getDriver()->sendSignal($pid, $signal);
    }

    /**
     * 检查端口是否被占用（是否有进程在监听该端口，可用于判断能否 bind）
     *
     * 仅以 LISTENING 为准：kill 掉监听进程后，可能仍有 ESTABLISHED/TIME_WAIT 连接，
     * 不应算作“占用”，否则会出现“已 kill-port 释放，但 start 仍报非框架进程占用”的逻辑错误。
     *
     * @param int $port 端口号
     * @return bool true=被占用（有进程在监听），false=可用
     */
    public static function isPortInUse(int $port): bool
    {
        return self::getDriver()->isPortInUse($port);
    }
    
    /**
     * 清除端口缓存
     * 
     * @param int|null $port 指定端口，null 清除全部
     */
    public static function clearPortCache(?int $port = null): void
    {
        self::getDriver()->clearPortCache($port);
    }
    
    /**
     * 查找可用端口
     * 
     * @param int $startPort 起始端口
     * @param int $maxAttempts 最大尝试次数
     * @return int 可用端口
     */
    public static function findAvailablePort(int $startPort = 9980, int $maxAttempts = 50): int
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $port = $startPort + $i;
            if (!self::isPortInUse($port)) {
                return $port;
            }
        }
        # 如果所有端口都被占用，返回原始端口
        return $startPort;
    }
    
    /**
     * 通过进程名杀死进程（仅杀己方进程）
     * 
     * 安全策略：
     * 1. 若 processName 不以 weline- 开头，视为非己方进程，直接返回 false
     * 2. 对每个 pid 杀前再校验命令行含 weline-，非己方不杀
     * 
     * @param string $processName 进程名（--name= 参数值）
     * @param int $maxRetries 最大重试次数
     * @return bool 是否成功杀死
     */
    public static function killByProcessName(string $processName, int $maxRetries = 3): bool
    {
        if (empty($processName)) {
            return false;
        }
        
        // 己方进程校验：进程名必须以 weline- 开头
        if (\strpos($processName, self::WELINE_PROCESS_PREFIX) === false) {
            return false;
        }
        
        for ($retry = 0; $retry < $maxRetries; $retry++) {
            // 方式 1：从 PID 文件获取（最快）
            $pname = '--name=' . $processName;
            $pid = (int) self::getData($pname, 'pid');
            
            if ($pid > 0 && self::isRunningByPid($pid)) {
                // 再次校验命令行（防止同名非框架进程）
                if (self::isProcessManagerCreated($pid)) {
                    self::killByPid($pid, true); // skipCheck=true 因为已校验
                    self::removePidFile($pname);
                    \usleep(200000);
                    
                    if (!self::isRunningByPid($pid)) {
                        return true;
                    }
                }
            }
            
            // 方式 2：通过进程名搜索系统进程
            $pids = self::getProcessIdsByName($processName);
            if (empty($pids)) {
                // 进程已不存在
                self::removePidFile($pname);
                return true;
            }
            
            foreach ($pids as $p) {
                // 校验每个 pid 是否为己方进程
                if (self::isProcessManagerCreated($p)) {
                    self::killByPid($p, true);
                }
            }
            
            \usleep(300000);
            
            // 验证是否全部杀死
            $pids = self::getProcessIdsByName($processName);
            if (empty($pids)) {
                self::removePidFile($pname);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 查找并终止占用指定端口的进程（仅杀己方进程）
     * 
     * 安全策略：取得占用端口的 pid 后，杀前校验 isProcessManagerCreated($pid)，
     * 非己方进程拒绝杀并返回 false。
     * 
     * @param int $port 端口号
     * @param int $maxRetries 最大重试次数
     * @return bool 是否成功终止（端口已释放）
     */
    public static function killProcessByPort(int $port, int $maxRetries = 3): bool
    {
        for ($retry = 0; $retry < $maxRetries; $retry++) {
            // 检查端口是否已经释放
            if (!self::isPortInUse($port)) {
                return true;
            }
            
            // 获取占用端口的 PID
            $pid = self::getProcessIdByPort($port);
            if ($pid <= 0) {
                // 端口被占用但无法获取 PID，等待后重试
                \usleep(500000);
                continue;
            }
            
            // 己方进程校验：非己方进程拒绝杀
            if (!self::isProcessManagerCreated($pid)) {
                return false;
            }
            
            // 杀死进程
            self::killByPid($pid, true); // skipCheck=true 因为已经校验过
            
            // 等待进程退出
            \usleep(300000); // 300ms
            
            // 验证端口是否已释放
            if (!self::isPortInUse($port)) {
                return true;
            }
            
            // 未释放，等待更长时间后重试
            \usleep(200000); // 200ms
        }
        
        // 最后一次检查
        return !self::isPortInUse($port);
    }
    
    /**
     * 强制释放端口（更激进的方式，仅杀己方进程）
     * 
     * 安全策略：取得 pid 后先校验 isProcessManagerCreated($pid)，
     * 非己方进程拒绝杀并返回 false。
     * 
     * @param int $port 端口号
     * @return bool 是否成功释放
     */
    public static function forceReleasePort(int $port): bool
    {
        // 检查端口是否已释放
        if (!self::isPortInUse($port)) {
            return true;
        }
        
        // 先尝试普通方式（killProcessByPort 内部已有己方校验）
        if (self::killProcessByPort($port, 3)) {
            return true;
        }
        
        // 获取占用端口的 PID
        $pid = self::getProcessIdByPort($port);
        if ($pid <= 0) {
            return !self::isPortInUse($port);
        }
        
        // 己方进程校验：非己方进程拒绝杀
        if (!self::isProcessManagerCreated($pid)) {
            return false;
        }
        
        // 使用驱动强制释放端口
        return self::getDriver()->forceReleasePort($port);
    }
    
    /**
     * 检查进程是否存在（通过 PID）
     * 
     * @param int $pid 进程 ID
     * @return bool 进程是否存在
     */
    public static function processExists(int $pid): bool
    {
        return self::isRunningByPid($pid);
    }
    
    /*----------------------------------------索引文件操作区域（SOLID: SRP - 单独职责）------------------------------------------*/
    
    /**
     * 获取 name_index.json 文件路径
     * 结构: pname → { pid, jsonPath }
     */
    public static function getNameIndexFile(): string
    {
        return Env::VAR_DIR . 'process' . DS . 'pid' . DS . 'name_index.json';
    }
    
    /**
     * 获取 pid_index.json 文件路径
     * 结构: pid → { pname, jsonPath }
     */
    public static function getPidIndexFile(): string
    {
        return Env::VAR_DIR . 'process' . DS . 'pid' . DS . 'pid_index.json';
    }
    
    /**
     * 获取 port_index.json 文件路径
     * 结构: port(string) → pname
     */
    public static function getPortIndexFile(): string
    {
        return Env::VAR_DIR . 'process' . DS . 'pid' . DS . 'port_index.json';
    }
    
    /**
     * 获取进程生命周期日志文件路径
     */
    public static function getLifecycleLogFile(): string
    {
        return Env::VAR_DIR . 'process' . DS . 'lifecycle.log';
    }
    
    /**
     * 原子写入文件（先写 .tmp 再 rename，避免半写）
     * 
     * @param string $path 目标文件路径
     * @param string $content 文件内容
     * @return bool 是否成功
     */
    private static function atomicWrite(string $path, string $content): bool
    {
        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0777, true);
        }
        $tmpPath = $path . '.tmp';
        $fp = @\fopen($tmpPath, 'wb');
        if (!$fp) {
            return false;
        }
        if (\flock($fp, \LOCK_EX)) {
            \fwrite($fp, $content);
            \fflush($fp);
            \flock($fp, \LOCK_UN);
            \fclose($fp);
            // rename 在同目录下是原子操作
            return @\rename($tmpPath, $path);
        }
        \fclose($fp);
        @\unlink($tmpPath);
        return false;
    }
    
    /**
     * 读取 name_index.json
     * @return array<string, array{pid: int, jsonPath: string}>
     */
    public static function readNameIndex(): array
    {
        $path = self::getNameIndexFile();
        if (!\is_file($path)) {
            return [];
        }
        $fp = @\fopen($path, 'rb');
        if (!$fp) {
            return [];
        }
        $data = [];
        if (\flock($fp, \LOCK_SH)) {
            $content = \stream_get_contents($fp);
            \flock($fp, \LOCK_UN);
            $data = \json_decode($content ?: '', true) ?: [];
        }
        \fclose($fp);
        return $data;
    }
    
    /**
     * 写入 name_index.json（原子写）
     * @param array<string, array{pid: int, jsonPath: string}> $data
     */
    public static function writeNameIndex(array $data): bool
    {
        $path = self::getNameIndexFile();
        return self::atomicWrite($path, \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 读取 pid_index.json
     * @return array<int, array{pname: string, jsonPath: string}>
     */
    public static function readPidIndex(): array
    {
        $path = self::getPidIndexFile();
        if (!\is_file($path)) {
            return [];
        }
        $fp = @\fopen($path, 'rb');
        if (!$fp) {
            return [];
        }
        $data = [];
        if (\flock($fp, \LOCK_SH)) {
            $content = \stream_get_contents($fp);
            \flock($fp, \LOCK_UN);
            $data = \json_decode($content ?: '', true) ?: [];
        }
        \fclose($fp);
        return $data;
    }
    
    /**
     * 写入 pid_index.json（原子写）
     * @param array<int, array{pname: string, jsonPath: string}> $data
     */
    public static function writePidIndex(array $data): bool
    {
        $path = self::getPidIndexFile();
        return self::atomicWrite($path, \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 读取 port_index.json
     * @return array<string, string> port(string) => pname
     */
    public static function readPortIndex(): array
    {
        $path = self::getPortIndexFile();
        if (!\is_file($path)) {
            return [];
        }
        $fp = @\fopen($path, 'rb');
        if (!$fp) {
            return [];
        }
        $data = [];
        if (\flock($fp, \LOCK_SH)) {
            $content = \stream_get_contents($fp);
            \flock($fp, \LOCK_UN);
            $data = \json_decode($content ?: '', true) ?: [];
        }
        \fclose($fp);
        return $data;
    }
    
    /**
     * 写入 port_index.json（原子写）
     * @param array<string, string> $data port(string) => pname
     */
    public static function writePortIndex(array $data): bool
    {
        $path = self::getPortIndexFile();
        return self::atomicWrite($path, \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 更新索引（setPid 调用后同步更新 name_index 和 pid_index）
     * 
     * 写顺序：先 *-pid.json（已写），再 name_index，再 pid_index
     * 
     * @param string $pname 进程名
     * @param int $pid 进程 ID
     * @param string $jsonPath PID JSON 文件路径
     */
    private static function updateIndexes(string $pname, int $pid, string $jsonPath): void
    {
        // 1. 更新 name_index
        $nameIndex = self::readNameIndex();
        // 若 pname 已存在且 pid 不同，需从 pid_index 移除旧 pid
        $oldPid = 0;
        if (isset($nameIndex[$pname]) && $nameIndex[$pname]['pid'] !== $pid) {
            $oldPid = (int) $nameIndex[$pname]['pid'];
        }
        $nameIndex[$pname] = ['pid' => $pid, 'jsonPath' => $jsonPath];
        self::writeNameIndex($nameIndex);
        
        // 2. 更新 pid_index
        $pidIndex = self::readPidIndex();
        // 移除旧 pid 的索引项
        if ($oldPid > 0 && isset($pidIndex[$oldPid])) {
            unset($pidIndex[$oldPid]);
        }
        $pidIndex[$pid] = ['pname' => $pname, 'jsonPath' => $jsonPath];
        self::writePidIndex($pidIndex);
    }
    
    /**
     * 从索引中移除进程信息（仅由 GC / 定时清理调用，不调用其他查询方法避免 loop）
     * 
     * @param string|null $pname 进程名（可选）
     * @param int|null $pid 进程 ID（可选）
     * @param array|null $ports 进程端口列表（可选）
     */
    private static function removeFromIndexes(?string $pname, ?int $pid, ?array $ports = null): void
    {
        // 移除 name_index 中的项
        if ($pname !== null) {
            $nameIndex = self::readNameIndex();
            if (isset($nameIndex[$pname])) {
                unset($nameIndex[$pname]);
                self::writeNameIndex($nameIndex);
            }
        }
        
        // 移除 pid_index 中的项
        if ($pid !== null && $pid > 0) {
            $pidIndex = self::readPidIndex();
            if (isset($pidIndex[$pid])) {
                unset($pidIndex[$pid]);
                self::writePidIndex($pidIndex);
            }
        }
        
        // 移除 port_index 中的相关项
        if ($ports !== null && !empty($ports)) {
            $portIndex = self::readPortIndex();
            $changed = false;
            foreach ($ports as $port) {
                $portKey = (string) $port;
                if (isset($portIndex[$portKey])) {
                    unset($portIndex[$portKey]);
                    $changed = true;
                }
            }
            if ($changed) {
                self::writePortIndex($portIndex);
            }
        }
    }
    
    /**
     * 设置进程监听端口（更新 PID 文件的 ports 字段，并同步 port_index）
     * 
     * 调用时机：进程启动并确认监听端口后调用
     * 
     * @param string $pname 进程名
     * @param array $ports 端口数组，如 [19443, 80]
     */
    public static function setProcessPorts(string $pname, array $ports): void
    {
        $pidFile = self::getPidFile($pname);
        if (!\is_file($pidFile)) {
            return;
        }
        
        // 读取现有 PID 文件
        $data = [];
        $fp = @\fopen($pidFile, 'rb');
        if ($fp && \flock($fp, \LOCK_SH)) {
            $content = \stream_get_contents($fp);
            \flock($fp, \LOCK_UN);
            $data = \json_decode($content ?: '', true) ?: [];
        }
        if ($fp) {
            \fclose($fp);
        }
        
        if (empty($data)) {
            return;
        }
        
        // 获取旧端口列表
        $oldPorts = isset($data['ports']) ? (array) $data['ports'] : [];
        
        // 更新 port_index：先移除旧端口，再添加新端口
        $portIndex = self::readPortIndex();
        
        // 移除旧端口
        foreach ($oldPorts as $oldPort) {
            $key = (string) $oldPort;
            if (isset($portIndex[$key]) && $portIndex[$key] === $pname) {
                unset($portIndex[$key]);
            }
        }
        
        // 添加新端口
        foreach ($ports as $port) {
            $key = (string) $port;
            $portIndex[$key] = $pname;
        }
        
        self::writePortIndex($portIndex);
        
        // 更新 PID 文件
        $data['ports'] = $ports;
        $fp = @\fopen($pidFile, 'cb');
        if ($fp && \flock($fp, \LOCK_EX)) {
            \ftruncate($fp, 0);
            \fwrite($fp, \json_encode($data));
            \flock($fp, \LOCK_UN);
            \fclose($fp);
        } else {
            if ($fp) {
                \fclose($fp);
            }
            @\file_put_contents($pidFile, \json_encode($data));
        }
    }
    
    /**
     * 通过端口获取进程 PID（优先索引，回退系统命令）
     * 
     * 分层策略：
     * 1. 优选：port_index → pname → name_index → pid，验证 isRunningByPid
     * 2. 索引无效时：清理该端口索引项，回退到 getProcessIdByPort（系统命令）
     * 
     * @param int $port 端口号
     * @return int 进程 ID，未找到返回 0
     */
    public static function getPidByPort(int $port): int
    {
        $portKey = (string) $port;
        
        // 1. 优选路径：从 port_index 获取 pname
        $portIndex = self::readPortIndex();
        if (isset($portIndex[$portKey])) {
            $pname = $portIndex[$portKey];
            
            // 从 name_index 获取 pid
            $nameIndex = self::readNameIndex();
            if (isset($nameIndex[$pname])) {
                $pid = (int) $nameIndex[$pname]['pid'];
                
                // 验证进程是否存活
                if ($pid > 0 && self::isRunningByPid($pid)) {
                    return $pid;
                }
                
                // 进程不存活，清理索引项（Loop 防护：只根据已知的 pid/pname/port 直接操作索引，不调用其他查询方法）
                // 获取 ports 用于清理（从 PID 文件直接读取，不调用 getData 避免 loop）
                $ports = [$port];
                $jsonPath = $nameIndex[$pname]['jsonPath'] ?? null;
                if ($jsonPath && \is_file($jsonPath)) {
                    $raw = @\file_get_contents($jsonPath);
                    if ($raw !== false) {
                        $data = \json_decode($raw, true);
                        if (isset($data['ports'])) {
                            $ports = (array) $data['ports'];
                        }
                    }
                    // 删除 PID JSON 文件
                    @\unlink($jsonPath);
                }
                
                // 从索引中移除（直接操作，不调用 removeFromIndexes 以避免潜在的额外逻辑）
                unset($nameIndex[$pname]);
                self::writeNameIndex($nameIndex);
                
                $pidIndex = self::readPidIndex();
                if ($pid > 0 && isset($pidIndex[$pid])) {
                    unset($pidIndex[$pid]);
                    self::writePidIndex($pidIndex);
                }
                
                foreach ($ports as $p) {
                    $pk = (string) $p;
                    if (isset($portIndex[$pk])) {
                        unset($portIndex[$pk]);
                    }
                }
                self::writePortIndex($portIndex);
            } else {
                // name_index 中没有该 pname，清理 port_index 中的脏项
                unset($portIndex[$portKey]);
                self::writePortIndex($portIndex);
            }
        }
        
        // 2. 回退路径：使用系统命令
        return self::getProcessIdByPort($port);
    }
    
    /*----------------------------------------进程生命周期日志区域------------------------------------------*/
    
    /**
     * 记录进程生命周期事件（ISO 8601 时间戳）
     * 
     * 日志格式: 2025-02-04T12:00:00+08:00 [EVENT] pname=xxx pid=123 message
     * 
     * @param string $event 事件类型 (create/destroy/kill)
     * @param string $pname 进程名
     * @param int $pid 进程 ID
     * @param string $message 附加消息（可选）
     */
    public static function logLifecycleEvent(string $event, string $pname, int $pid, string $message = ''): void
    {
        // 日志未启用时跳过
        if (!self::isLogEnabled()) {
            return;
        }
        
        $logFile = self::getLifecycleLogFile();
        $dir = \dirname($logFile);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0777, true);
        }
        
        // ISO 8601 时间戳
        $timestamp = \date('c'); // e.g. 2025-02-04T12:00:00+08:00
        $eventUpper = \strtoupper($event);
        $taskName = '';
        try {
            $taskName = self::getTaskName($pname);
        } catch (\Exception $e) {
            $taskName = 'unknown';
        }
        
        $logLine = "{$timestamp} [{$eventUpper}] pname={$taskName} pid={$pid}";
        if ($message !== '') {
            $logLine .= " {$message}";
        }
        $logLine .= \PHP_EOL;
        
        // 追加写入（不加锁，日志允许少量乱序）
        @\file_put_contents($logFile, $logLine, \FILE_APPEND);
    }
    
    /*----------------------------------------GC（垃圾回收）区域------------------------------------------*/
    
    /**
     * 进程管理 GC（统一垃圾回收入口）
     * 
     * 日志清理职责：
     * - 所有日志清理仅由 GC 执行
     * - 进程管理内除 GC 外不得清理日志
     * 
     * 执行内容：
     * 1. 清理 7 天前的生命周期日志条目
     * 2. 清理已不存活进程的索引项（name_index/pid_index/port_index）
     * 3. 清理已不存活进程的 PID 文件（*-pid.json 和 {pid}.pid）
     * 
     * 由 Framework Cron 定时调用
     * 
     * @param int $logRetentionDays 日志保留天数（默认 7 天）
     * @return array 清理统计
     */
    public static function runProcessGc(int $logRetentionDays = 7): array
    {
        $stats = [
            'log_lines_removed' => 0,
            'stale_pids_removed' => 0,
            'stale_json_files_removed' => 0,
            'legacy_pid_files_removed' => 0,
        ];
        
        // 1. 清理 N 天前的生命周期日志
        $stats['log_lines_removed'] = self::cleanupLifecycleLog($logRetentionDays);
        
        // 2. 清理陈旧索引项（根据 name_index 遍历，检查 pid 是否存活）
        $cleanupResult = self::cleanupStaleIndexEntries();
        $stats['stale_pids_removed'] = $cleanupResult['removed'];
        $stats['stale_json_files_removed'] = $cleanupResult['json_removed'];
        
        // 3. 清理陈旧的 *-pid.json 文件（进程不存活的）
        $stats['stale_json_files_removed'] += self::cleanupStalePidFiles();
        
        // 4. 清理历史遗留的 {pid}.pid 文件（已废弃机制）
        $stats['legacy_pid_files_removed'] = self::cleanupLegacyPidFiles();
        
        return $stats;
    }
    
    /**
     * 清理历史遗留的 {pid}.pid 文件
     * 
     * 这些文件来自旧版机制，现已被 pid_index.json 替代
     * 
     * @return int 删除的文件数量
     */
    private static function cleanupLegacyPidFiles(): int
    {
        $dir = Env::VAR_DIR . 'process' . DS . 'pid' . DS;
        if (!\is_dir($dir)) {
            return 0;
        }
        $removed = 0;
        // 匹配 {数字}.pid 格式的文件
        $files = \glob($dir . '*.pid');
        foreach ($files ?? [] as $path) {
            $base = \basename($path, '.pid');
            // 只处理纯数字文件名（如 12345.pid）
            if ($base === '' || !\is_numeric($base)) {
                continue;
            }
            // 直接删除这些遗留文件
            @\unlink($path);
            $removed++;
        }
        return $removed;
    }
    
    /**
     * 清理生命周期日志中 N 天前的条目
     * 
     * @param int $days 保留天数
     * @return int 删除的行数
     */
    private static function cleanupLifecycleLog(int $days): int
    {
        $logFile = self::getLifecycleLogFile();
        if (!\is_file($logFile)) {
            return 0;
        }
        
        $lines = @\file($logFile, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if ($lines === false || empty($lines)) {
            return 0;
        }
        
        $cutoffTime = \time() - ($days * 86400);
        $newLines = [];
        $removed = 0;
        
        foreach ($lines as $line) {
            // 解析行首 ISO 8601 时间戳
            if (\preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2})/', $line, $matches)) {
                $timestamp = \strtotime($matches[1]);
                if ($timestamp !== false && $timestamp < $cutoffTime) {
                    $removed++;
                    continue;
                }
            }
            $newLines[] = $line;
        }
        
        if ($removed > 0) {
            @\file_put_contents($logFile, \implode(\PHP_EOL, $newLines) . \PHP_EOL);
        }
        
        return $removed;
    }
    
    /**
     * 清理陈旧索引项（遍历 name_index，检查 pid 是否存活，不存活则移除）
     * 
     * Loop 防护：此方法仅直接读写索引和文件，不调用 getPid/getData/getPidByPort 等查询方法
     * 
     * @return array 清理统计
     */
    private static function cleanupStaleIndexEntries(): array
    {
        $removed = 0;
        $jsonRemoved = 0;
        
        // 读取所有索引
        $nameIndex = self::readNameIndex();
        $pidIndex = self::readPidIndex();
        $portIndex = self::readPortIndex();
        
        $nameIndexChanged = false;
        $pidIndexChanged = false;
        $portIndexChanged = false;
        
        foreach ($nameIndex as $pname => $info) {
            $pid = (int) ($info['pid'] ?? 0);
            $jsonPath = $info['jsonPath'] ?? null;
            
            // 检查进程是否存活
            if ($pid > 0 && self::isRunningByPid($pid)) {
                continue; // 进程存活，跳过
            }
            
            // 进程不存活，清理相关数据
            
            // 1. 从 name_index 移除
            unset($nameIndex[$pname]);
            $nameIndexChanged = true;
            $removed++;
            
            // 2. 从 pid_index 移除
            if ($pid > 0 && isset($pidIndex[$pid])) {
                unset($pidIndex[$pid]);
                $pidIndexChanged = true;
            }
            
            // 3. 读取 ports 并从 port_index 移除
            $ports = [];
            if ($jsonPath && \is_file($jsonPath)) {
                $raw = @\file_get_contents($jsonPath);
                if ($raw !== false) {
                    $data = \json_decode($raw, true);
                    if (isset($data['ports'])) {
                        $ports = (array) $data['ports'];
                    }
                }
                // 删除 JSON 文件
                @\unlink($jsonPath);
                $jsonRemoved++;
            }
            
            foreach ($ports as $port) {
                $portKey = (string) $port;
                if (isset($portIndex[$portKey]) && $portIndex[$portKey] === $pname) {
                    unset($portIndex[$portKey]);
                    $portIndexChanged = true;
                }
            }
        }
        
        // 写入更新后的索引
        if ($nameIndexChanged) {
            self::writeNameIndex($nameIndex);
        }
        if ($pidIndexChanged) {
            self::writePidIndex($pidIndex);
        }
        if ($portIndexChanged) {
            self::writePortIndex($portIndex);
        }
        
        return ['removed' => $removed, 'json_removed' => $jsonRemoved];
    }
    
    /**
     * 获取进程命令行（用于识别进程类型）
     * 
     * 委托给系统驱动实现，确保跨平台兼容
     *
     * @param int $pid 进程 ID
     * @return string 命令行，获取失败返回空字符串
     */
    public static function getProcessCommandLine(int $pid): string
    {
        if ($pid <= 0) {
            return '';
        }
        return self::getDriver()->getProcessCommandLine($pid);
    }
    
    /**
     * 检测指定 PID 的进程是否由进程管理器创建（命令行含 weline- 标识）
     * 
     * 进程管理器只允许操作自己创建的进程，非己方进程一律不杀。
     * 判定依据：进程的命令行中包含 weline- 前缀。
     * 
     * @param int $pid 进程 ID
     * @return bool 是否是进程管理器创建的进程
     */
    public static function isProcessManagerCreated(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        $cmdLine = self::getProcessCommandLine($pid);
        if ($cmdLine === '') {
            return false;
        }
        // 检测命令行中是否包含 weline- 标识
        return \strpos($cmdLine, self::WELINE_PROCESS_PREFIX) !== false;
    }
    
    /**
     * 检测指定 PID 的进程是否是 Weline 框架服务器进程（Worker/HTTP重定向/Master）
     *
     * 通过命令行中是否包含 --name=weline- 或 weline-worker/weline-http-redirect 等标识判断。
     * 用于：端口被占用时，判断是否是框架进程，如果不是则不乱杀。
     *
     * @param int $pid 进程 ID
     * @return bool 是否是 Weline 框架进程
     */
    public static function isWelineServerProcess(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        $cmdLine = self::getProcessCommandLine($pid);
        // 检测标识：--name=weline-xxx 或各类进程脚本名
        // 新进程名格式：weline-master-{instance}-{worker_id}, weline-dispatcher-{instance}
        if ($cmdLine !== '') {
            // Master 进程：通过 bin/w (或 bin\w) CLI 入口启动的 server 命令
            // 命令行形如 php bin/w server:start ... 或 php bin/w s:start ...
            if ((\strpos($cmdLine, 'bin/w ') !== false || \strpos($cmdLine, 'bin\\w ') !== false)
                && (\strpos($cmdLine, 'server:start') !== false || \strpos($cmdLine, 's:start') !== false)) {
                return true;
            }
            if (\strpos($cmdLine, '--name=' . self::WELINE_PROCESS_PREFIX) !== false) {
                return true;
            }
            if (\strpos($cmdLine, 'weline-master-') !== false || \strpos($cmdLine, 'weline-dispatcher-') !== false) {
                return true;
            }
            if (\strpos($cmdLine, 'weline-worker') !== false || \strpos($cmdLine, 'weline-http-redirect') !== false) {
                return true;
            }
            if (\strpos($cmdLine, 'worker.php') !== false || \strpos($cmdLine, 'worker_ssl.php') !== false) {
                return true;
            }
            if (\strpos($cmdLine, 'http_redirect_worker.php') !== false) {
                return true;
            }
            if (\strpos($cmdLine, 'dispatcher.php') !== false || \strpos($cmdLine, 'dispatcher_ssl.php') !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 检测指定端口是否被 Weline 框架进程占用
     *
     * @param int $port 端口号
     * @return bool 是否被框架进程占用
     */
    public static function isPortUsedByWeline(int $port): bool
    {
        $pid = self::getProcessIdByPort($port);
        if ($pid <= 0) {
            return false;
        }
        return self::isWelineServerProcess($pid);
    }
    
    /**
     * 通过进程名检测进程是否在运行
     * 
     * 用于多 Master 架构下，通过进程名（如 weline-master-{instance}-{worker_id}）检测特定进程
     * 
     * @param string $processName 进程名（--name= 参数值）
     * @return bool 进程是否在运行
     */
    /**
     * 通过进程名检测进程是否在运行
     * 
     * 委托给系统驱动实现，确保跨平台兼容
     * 
     * 注意：此方法在 Windows 上可能较慢（需要搜索进程列表），
     * 推荐使用快速路径：getData($pname, 'pid') + isRunningByPid($pid)
     * 
     * @param string $processName 进程名（--name= 参数值）
     * @return bool 进程是否在运行
     */
    public static function isProcessRunningByName(string $processName): bool
    {
        if (empty($processName)) {
            return false;
        }
        
        // 快速路径：先从文件检查（避免昂贵的系统搜索）
        $pname = '--name=' . $processName;
        $pid = (int) self::getData($pname, 'pid');
        if ($pid > 0 && self::isRunningByPid($pid)) {
            return true;
        }
        
        // 慢路径：委托给驱动进行系统级搜索
        $pids = self::getDriver()->findProcessesByName($processName);
        return !empty($pids);
    }
    
    /**
     * 通过进程名获取进程 ID 列表
     * 
     * @param string $processName 进程名（--name= 参数值）
     * @return int[] 进程 ID 列表
     */
    public static function getProcessIdsByName(string $processName): array
    {
        if (empty($processName)) {
            return [];
        }
        return self::getDriver()->findProcessesByName($processName);
    }
    
    /**
     * 杀死指定进程名的所有进程（旧方法，使用系统命令）
     * 
     * 用于多 Master 架构下，杀死特定实例的所有 Worker
     * 
     * @deprecated 使用 killByProcessNamePrefix() 替代，后者使用 name_index 更高效
     * @param string $processNamePrefix 进程名前缀（如 weline-master-{instance}）
     * @return int 成功杀死的进程数
     */
    public static function killProcessesByNamePrefix(string $processNamePrefix): int
    {
        // 委托给新方法
        return self::killByProcessNamePrefix($processNamePrefix);
    }
    
    /**
     * 按进程名前缀批量杀进程（使用 name_index 查找，仅杀己方进程）
     * 
     * 用途：例如前缀 weline-master-default- 可杀该实例下所有 worker
     * 
     * 安全策略：
     * 1. prefix 必须包含 weline-，否则返回 0
     * 2. 每个 PID 杀前校验命令行含 weline-，非己方进程跳过
     * 3. 不在此方法内改索引，由 GC 或读路径懒清理统一移除
     * 
     * @param string $prefix 进程名前缀
     * @return int 成功杀死的进程数
     */
    public static function killByProcessNamePrefix(string $prefix): int
    {
        // 安全校验：prefix 必须包含 weline-
        if (empty($prefix) || \strpos($prefix, self::WELINE_PROCESS_PREFIX) === false) {
            return 0;
        }
        
        $killed = 0;
        
        // 1. 从 name_index 读取所有进程
        $nameIndex = self::readNameIndex();
        $pidsToKill = [];
        
        // 2. 按前缀匹配收集 PID
        foreach ($nameIndex as $pname => $info) {
            // 检查 pname 是否以 prefix 开头（pname 可能是 --name=weline-xxx 格式）
            $taskName = '';
            try {
                $taskName = self::getTaskName($pname);
            } catch (\Exception $e) {
                continue;
            }
            
            if (\str_starts_with($taskName, $prefix) || \str_starts_with($pname, '--name=' . $prefix)) {
                $pid = (int) ($info['pid'] ?? 0);
                if ($pid > 0) {
                    $pidsToKill[$pid] = $pname;
                }
            }
        }
        
        // 3. 批量杀死（校验己方进程 + 记录日志 + 委托驱动）
        foreach ($pidsToKill as $pid => $pname) {
            // 校验是否为己方进程
            if (!self::isProcessManagerCreated($pid)) {
                continue;
            }
            
            // 使用驱动杀死进程（跨平台兼容）
            $result = self::getDriver()->killProcess($pid);
            
            if ($result) {
                $killed++;
                // 记录杀进程日志
                self::logLifecycleEvent('kill', $pname, $pid, 'killed by prefix: ' . $prefix);
            }
        }
        
        return $killed;
    }
    
    /**
     * 通过端口获取进程 ID
     * 
     * @param int $port 端口号
     * @return int 进程 ID，未找到返回 0
     */
    public static function getProcessIdByPort(int $port): int
    {
        return self::getDriver()->getProcessIdByPort($port);
    }
    
    /**
     * 检查进程是否运行中（通过 PID）
     * 
     * 委托给系统驱动实现
     * 
     * @param int $pid 进程ID
     * @return bool 进程是否运行
     */
    public static function isRunningByPid(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        return self::getDriver()->isRunningByPid($pid);
    }
    
    /**
     * 获取进程详细信息
     * 
     * @param int $pid 进程ID
     * @return array 进程信息
     */
    public static function getProcessInfo(int $pid): array
    {
        return self::getDriver()->getProcessInfo($pid);
    }

    /**
     * @DESC          # 通过pid获取进程输出
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:12
     * 参数区：
     * @param int $pid
     * @return string|false
     */
    public static function outputByPid(int $pid): string|false
    {
        $pname = self::getNameByPid($pid);
        $path  = self::getLogFile($pname);
        return file_get_contents($path);
    }

    /**
     * @DESC          # 通过pid设置进程输出到日志
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:12
     * 参数区：
     * @param int $pid
     * @param string $content
     * @return false|int
     */
    public static function setOutputByPid(int $pid, string $content): false|int
    {
        $pname = self::getNameByPid($pid);
        $path  = self::getLogFile($pname);
        return file_put_contents($path, $content, FILE_APPEND);
    }

    /**
     * @DESC          # 通过进程名获取pid
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:13
     * 参数区：
     * @param string $pname
     * @return int
     */
    public static function getPidByName(string $pname): int
    {
        return self::getPid($pname);
    }

    /**
     * @DESC          # 通过pid获取进程名
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/9/27 上午11:13
     * 参数区：
     * @param int $pid
     * @return string
     */
    /**
     * 通过 PID 获取进程名
     * 
     * 使用 pid_index.json 进行反向查找（不再使用 {pid}.pid 文件）
     */
    public static function getNameByPid(int $pid): string
    {
        if ($pid <= 0) {
            return 'unknown';
        }
        $pidIndex = self::readPidIndex();
        if (isset($pidIndex[$pid]) && !empty($pidIndex[$pid]['pname'])) {
            return $pidIndex[$pid]['pname'];
        }
        return 'unknown';
    }

    /**
     * @DESC          # 执行命令并获取输出（遵循SOLID原则，统一使用进程类执行命令）
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/12/19
     * 参数区：
     * @param string $command 要执行的命令
     * @param array &$output 输出数组（引用传递）
     * @param int &$returnCode 返回码（引用传递）
     * @return bool 是否执行成功
     */
    public static function execute(string $command, array &$output = [], int &$returnCode = 0): bool
    {
        // 检查可用的命令执行函数
        $availableFunctions = [
            'exec' => function_exists('exec'),
            'shell_exec' => function_exists('shell_exec'),
        ];

        // 优先使用 exec（更可靠，不依赖 passthru）
        if ($availableFunctions['exec']) {
            // 执行命令并捕获输出（包含错误输出）
            exec($command . ' 2>&1', $output, $returnCode);
            return $returnCode === 0;
        }

        // 备选：使用 shell_exec
        if ($availableFunctions['shell_exec']) {
            $result = shell_exec($command . ' 2>&1');
            $output = $result ? explode("\n", trim($result)) : [];
            $returnCode = $result !== null ? 0 : 1;
            return $returnCode === 0;
        }

        // 所有方法都不可用
        $output = ['错误：没有可用的命令执行函数（exec 或 shell_exec）'];
        $returnCode = -1;
        return false;
    }
}