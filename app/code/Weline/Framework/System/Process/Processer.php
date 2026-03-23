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
     * 已验证为受信任的 PID 缓存
     * 
     * 从 PID 文件读取的 PID 或已通过 isProcessManagerCreated 校验的 PID
     * 后续操作可跳过重复的命令行校验，大幅提升性能（Windows 上避免多次 PowerShell 调用）
     * 
     * 结构: [pid => true, ...]
     */
    private static array $trustedPidCache = [];
    private static array $orphanWelinePortHintCache = [];
    
    /**
     * 框架进程名前缀（用于安全校验，防止误杀非框架进程）
     */
    public const WELINE_PROCESS_PREFIX = 'weline-';
    /**
     * WLS 框架内置进程名前缀（如 weline-wls-worker-1）
     */
    public const WLS_PROCESS_PREFIX = 'weline-wls-';
    private const PROCESS_RECORD_VERSION = 2;
    
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

    private static function doesRecordedProcessNameMatchPort(string $value, int $port): bool
    {
        if ($port <= 0 || $value === '') {
            return false;
        }

        $quotedPort = \preg_quote((string) $port, '/');
        return \preg_match('/(?:localhost:|["\'\s=])' . $quotedPort . '(?:["\'\s]|$)/i', $value) === 1;
    }

    private static function nameIndexSuggestsWelinePort(int $port): bool
    {
        if ($port <= 0) {
            return false;
        }

        if (\array_key_exists($port, self::$orphanWelinePortHintCache)) {
            return self::$orphanWelinePortHintCache[$port];
        }

        $pidIndex = self::readPidIndex();
        foreach ($pidIndex as $pid => $record) {
            $pname = (string) ($record['pname'] ?? '');
            if ($pname === '') {
                continue;
            }

            $taskName = \str_starts_with($pname, '--name=')
                ? \substr($pname, 7)
                : $pname;

            $looksLikeWeline = (\strpos($taskName, self::WELINE_PROCESS_PREFIX) !== false)
                || (\strpos($pname, '--name=' . self::WELINE_PROCESS_PREFIX) !== false);
            if (!$looksLikeWeline) {
                continue;
            }

            if (!self::doesRecordedProcessNameMatchPort($pname, $port)
                && !self::doesRecordedProcessNameMatchPort($taskName, $port)) {
                continue;
            }

            if (self::isManagedProcessRunning((int) $pid, null, '', $pname)) {
                self::$orphanWelinePortHintCache[$port] = true;
                return true;
            }
        }

        self::$orphanWelinePortHintCache[$port] = false;
        return false;
    }

    public static function inspectPortOccupantWithHistory(int $port): array
    {
        $inspect = self::inspectPortOccupant($port);
        if (!($inspect['in_use'] ?? false)) {
            return $inspect;
        }

        if (($inspect['pid_running'] ?? false) || ($inspect['is_weline'] ?? false)) {
            return $inspect;
        }

        if (!self::nameIndexSuggestsWelinePort($port)) {
            return $inspect;
        }

        $inspect['is_weline'] = true;
        if (($inspect['state'] ?? '') === 'orphan') {
            $inspect['state'] = 'weline';
        }

        return $inspect;
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
     * 为模块自定义进程构建规范化进程名。
     *
     * 格式：weline-{moduleCode}-{name}
     * 示例：buildModuleProcessName('Weline_Payment', 'worker-1') → 'weline-weline-payment-worker-1'
     *
     * 用途：模块在 ServiceCommand 中使用此方法生成进程名，Master 通过进程名前缀区分框架/模块进程。
     *
     * @param string $moduleCode 模块代码（如 'Weline_Payment'），不含 'weline-' 前缀
     * @param string $name       自定义名称（如 'worker' / 'queue-1'）
     * @return string 完整的规范化进程名
     */
    public static function buildModuleProcessName(string $moduleCode, string $name): string
    {
        $normalizedCode = self::normalizeName($moduleCode);
        $normalizedName = self::normalizeName($name);
        return self::WELINE_PROCESS_PREFIX . $normalizedCode . '-' . $normalizedName;
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
        if (self::shouldTryManagedProcessReuse((bool) $block, $foreground)) {
            $existingPid = (int) self::getData($pname, 'pid');
            if ($existingPid > 0 && self::isManagedProcessRunning($existingPid, null, '', $pname)) {
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
            // On Windows, non-interactive PowerShell Start-Process can create headless
            // conhost instances. Use cmd/start so frontend workers get a real console.
            if (IS_WIN) {
                $pid = self::createWindowsForeground($pname, $enableLog, $availableFunctions);
                if ($pid > 0) {
                    return $pid;
                }
            }
            
            // Linux/macOS 前端模式：统一使用 proc_open（输出继承当前终端）
            // 不再使用 macOS osascript 打开新 Terminal，避免权限链路分叉与窗口残留问题。
            // exec 替换 shell 进程，确保 proc_get_status 返回的是子进程真实 PID
            if (!IS_WIN && $availableFunctions['proc_open']) {
                $execCommand = 'exec ' . $pname;
                $descriptorspec = [
                    0 => ['pipe', 'r'],
                    1 => STDOUT,
                    2 => STDERR,
                ];
                
                $lastError = null;
                \set_error_handler(function ($type, $msg) use (&$lastError) {
                    $lastError = $msg;
                    return true;
                });
                try {
                    $process = \proc_open($execCommand, $descriptorspec, $pipes, BP);
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
        
        # ===== Windows 后台模式：proc_open(PowerShell Start-Process) =====
        # 通过 proc_open 调用 PowerShell Start-Process 创建真正独立的子进程。
        # Start-Process 创建的进程不受 proc_open 资源生命周期影响，
        # proc_close 只关闭 PowerShell 本身（它 Start-Process 后立即退出）。
        if (IS_WIN && $availableFunctions['proc_open']) {
            if ($enableLog) {
                self::setOutput($pname, PHP_EOL . '--- Process started at ' . \date('Y-m-d H:i:s') . ' ---' . PHP_EOL . $pname . PHP_EOL, true);
            }

            $phpBinary = PHP_BINARY;
            $arguments = $pname;
            if (\preg_match('/^"[^"]+"(.*)$/', $pname, $m)) {
                $arguments = \trim($m[1]);
            } elseif (\str_starts_with($pname, '"' . $phpBinary . '"')) {
                $arguments = \trim(\substr($pname, \strlen('"' . $phpBinary . '"')));
            } elseif (\str_starts_with($pname, $phpBinary)) {
                $arguments = \trim(\substr($pname, \strlen($phpBinary)));
            }

            $scriptPath = self::writeWindowsStartScript($phpBinary, $arguments, BP);
            if ($scriptPath === null) {
                if ($enableLog) {
                    self::setOutput($pname, "[ERROR] failed to prepare windows start script" . PHP_EOL);
                }
            } else {
                $descriptorspec = [
                    0 => ['file', $nullDevice, 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ];

                $lastError = null;
                \set_error_handler(function ($type, $msg) use (&$lastError) {
                    $lastError = $msg;
                    return true;
                });
                try {
                    $psProcess = \proc_open(
                        self::buildWindowsPowerShellProcOpenCommand($scriptPath),
                        $descriptorspec,
                        $psPipes,
                        BP,
                        null,
                        ['bypass_shell' => true]
                    );
                } finally {
                    \restore_error_handler();
                }

                if (\is_resource($psProcess)) {
                    $output = '';
                    $stderr = '';
                    if (isset($psPipes[1])) {
                        $output = \trim(\stream_get_contents($psPipes[1]));
                        @\fclose($psPipes[1]);
                    }
                    if (isset($psPipes[2])) {
                        $stderr = \trim(\stream_get_contents($psPipes[2]));
                        @\fclose($psPipes[2]);
                    }
                    @\proc_close($psProcess);
                    @\unlink($scriptPath);

                    if (\is_numeric($output) && $output !== '' && (int)$output > 0) {
                        $pid = (int)$output;
                        $pid = self::setPid($pname, $pid);
                        return $pid;
                    }

                    if ($enableLog && $stderr !== '') {
                        self::setOutput($pname, "[ERROR] start-process failed: {$stderr}" . PHP_EOL);
                    }
                } else {
                    @\unlink($scriptPath);
                }
            }

            if ($enableLog && $lastError !== null) {
                self::setOutput($pname, "[ERROR] proc_open(powershell) failed: {$lastError}" . PHP_EOL);
            }
        }
        
        # ===== Linux/Mac 后台模式：proc_open + nohup =====
        if (!IS_WIN && $availableFunctions['proc_open']) {
            $escapedBp = \escapeshellarg(BP);
            if ($enableLog) {
                $command = 'cd ' . $escapedBp . ' && nohup ' . $pname . ' >> "' . $logFile . '" 2>&1 & echo $!';
            } else {
                $command = 'cd ' . $escapedBp . ' && nohup ' . $pname . ' > /dev/null 2>&1 & echo $!';
            }
            
            if ($enableLog) {
                self::setOutput($pname, PHP_EOL . '--- Process started at ' . \date('Y-m-d H:i:s') . ' ---' . PHP_EOL . $command . PHP_EOL, true);
            }
            
            $descriptorspec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            
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
                $pid = 0;
                // 先关闭 stdin 写端，避免子 shell 在读取 stdin 时阻塞导致不退出、父进程 stream_get_contents 永远等不到 EOF（Linux 不加 -frontend 时“退不出来”的根因）
                if (isset($procPipes[0])) {
                    @\fclose($procPipes[0]);
                    $procPipes[0] = null;
                }
                // 非阻塞模式也短暂读取一次 PID 回显，提升后台拉起稳定性（避免“已启动但无法确认”的抖动）
                if (isset($procPipes[1])) {
                    if ($block) {
                        \stream_set_blocking($procPipes[1], true);
                        $output = \stream_get_contents($procPipes[1]);
                        $output = \trim($output);
                        if (\is_numeric($output)) {
                            $pid = (int)$output;
                        }
                    } else {
                        \stream_set_blocking($procPipes[1], false);
                        $start = \microtime(true);
                        $buffer = '';
                        while ((\microtime(true) - $start) < 0.35) {
                            $chunk = \fread($procPipes[1], 64);
                            if ($chunk !== false && $chunk !== '') {
                                $buffer .= $chunk;
                                if (\str_contains($buffer, "\n")) {
                                    break;
                                }
                            } else {
                                \usleep(10_000);
                            }
                        }
                        $output = \trim($buffer);
                        if (\is_numeric($output)) {
                            $pid = (int)$output;
                        }
                    }
                }
                if ($block && $pid <= 0) {
                    $pid = (int) \proc_get_status($process)['pid'];
                }
                if (isset($procPipes[1])) @\fclose($procPipes[1]);
                if (isset($procPipes[2])) @\fclose($procPipes[2]);
                @\proc_close($process);
                
                if ($pid > 0) {
                    $pid = self::setPid($pname, $pid);
                    return $pid;
                }
                return 0;
            }
            
            if ($enableLog && $lastError !== null) {
                self::setOutput($pname, "[ERROR] proc_open failed: {$lastError}" . PHP_EOL);
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
        $pid_file  = self::getPidFile($pname, $pid);
        $task_name = self::getTaskName($pname);

        $payloadData = \array_merge([
            'pid' => $pid,
            'time' => time(),
            'date' => date('Y-m-d H:i:s'),
            'pname' => $pname,
            'task_name' => $task_name,
        ], self::buildProcessIdentityRecord($pname, $pid, $task_name));
        $payload = \json_encode($payloadData, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
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
        
        // 标记为受信任 PID（后续操作跳过命令行校验）
        self::markPidAsTrusted($pid);
        
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
        
        // 从 PID 文件读取的 PID 自动标记为受信任（跳过后续命令行校验）
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
        return self::setProcessMetadata($pname, [$key => $value]);
    }

    /**
     * 合并写入进程元数据。
     */
    public static function setProcessMetadata(string $pname, array $metadata): array
    {
        if (empty($metadata)) {
            $data = self::getData($pname);
            return \is_array($data) ? $data : [];
        }

        $pid_file = self::getPidFile($pname);
        $dir = \dirname($pid_file);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0777, true);
        }

        $data = self::getData($pname);
        if (!\is_array($data)) {
            $data = [];
        }

        foreach ($metadata as $metaKey => $metaValue) {
            $data[$metaKey] = $metaValue;
        }

        self::atomicWrite($pid_file, \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));

        return $data;
    }

    /**
     * 通过 PID 读取进程记录。
     */
    public static function getProcessRecordByPid(int $pid): array
    {
        if ($pid <= 0) {
            return [];
        }

        $pidIndex = self::readPidIndex();
        $entry = $pidIndex[$pid] ?? null;
        if (!\is_array($entry)) {
            return [];
        }

        $jsonPath = (string) ($entry['jsonPath'] ?? '');
        if ($jsonPath !== '' && \is_file($jsonPath)) {
            $raw = @\file_get_contents($jsonPath);
            $data = \json_decode($raw ?: '', true);
            if (\is_array($data)) {
                return $data;
            }
        }

        $pname = (string) ($entry['pname'] ?? '');
        if ($pname === '') {
            return [];
        }

        $data = self::getData($pname);
        return \is_array($data) ? $data : [];
    }

    /**
     * 安全检查：PID 当前是否仍然匹配受管进程身份。
     */
    public static function isManagedProcessRunning(
        int $pid,
        ?string $expectedProcessName = null,
        string $expectedLaunchId = '',
        ?string $expectedPname = null
    ): bool {
        if ($pid <= 0 || !self::isRunningByPid($pid)) {
            return false;
        }

        $record = self::getProcessRecordByPid($pid);
        if ($expectedPname !== null && $expectedPname !== '') {
            $record['pname'] = $expectedPname;
            $record['pname_key'] = self::buildPnameKey($expectedPname);
        }
        if ($expectedProcessName !== null && $expectedProcessName !== '') {
            $record['process_name'] = self::normalizeName($expectedProcessName);
        }
        if ($expectedLaunchId !== '') {
            $record['launch_id'] = $expectedLaunchId;
        }

        if (empty($record)) {
            return self::processExists($pid);
        }

        return self::doesPidMatchRecordedIdentity($pid, $record);
    }

    /**
     * 安全结束一个受管进程。
     */
    public static function killManagedProcess(
        int $pid,
        ?string $expectedProcessName = null,
        string $expectedLaunchId = '',
        ?string $expectedPname = null
    ): bool {
        if (!self::isManagedProcessRunning($pid, $expectedProcessName, $expectedLaunchId, $expectedPname)) {
            return false;
        }

        return self::killByPid($pid, true);
    }

    /**
     * 安全结束一个受管进程树。
     */
    public static function killManagedProcessTree(
        int $pid,
        ?string $expectedProcessName = null,
        string $expectedLaunchId = '',
        ?string $expectedPname = null
    ): bool {
        if (!self::isManagedProcessRunning($pid, $expectedProcessName, $expectedLaunchId, $expectedPname)) {
            return false;
        }

        return self::killProcessTreeByPid($pid, true);
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
            if (self::isManagedProcessRunning((int) $pid, null, '', $pname)) {
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
    public static function getPidFile(string $pname, int $pid = 0): string
    {
        $task_name = self::getTaskName($pname);
        $dir       = Env::VAR_DIR . 'process' . DS . 'pid' . DS;
        if ($pid > 0) {
            $path = $dir . $task_name . '-' . $pid . '-pid.json';
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
        // pid=0: 从 name_index 查找已有条目的 jsonPath
        $entries = (self::readNameIndex())[$pname] ?? [];
        foreach ($entries as $entry) {
            $entryPath = $entry['jsonPath'] ?? '';
            if ($entryPath && \is_file($entryPath)) {
                return $entryPath;
            }
        }
        // 无已有条目，返回默认路径（不创建文件）
        return $dir . $task_name . '-pid.json';
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
        // 读取 name_index 获取该 pname 的所有条目
        $nameIndex = self::readNameIndex();
        $entries = $nameIndex[$pname] ?? [];
        
        $allPids = [];
        $allPorts = [];
        
        // 遍历所有条目，收集 PID / 端口，删除各自的 JSON 文件
        foreach ($entries as $entry) {
            $entryPid = (int) ($entry['pid'] ?? 0);
            $jsonPath = $entry['jsonPath'] ?? '';
            
            if ($entryPid > 0) {
                $allPids[] = $entryPid;
            }
            
            if ($jsonPath && \is_file($jsonPath)) {
                $raw = @\file_get_contents($jsonPath);
                if ($raw !== false) {
                    $data = \json_decode($raw, true);
                    if (isset($data['ports'])) {
                        foreach ((array) $data['ports'] as $port) {
                            $allPorts[] = $port;
                        }
                    }
                }
                @\unlink($jsonPath);
            }
        }
        
        // 从 name_index 移除整个 key（原子操作）
        if (!empty($entries)) {
            self::atomicUpdateNameIndex(function (array $idx) use ($pname): array {
                unset($idx[$pname]);
                return $idx;
            });
        }
        
        // 从 pid_index 移除所有相关 PID
        if (!empty($allPids)) {
            $pidIndex = self::readPidIndex();
            $changed = false;
            foreach ($allPids as $pid) {
                if (isset($pidIndex[$pid])) {
                    unset($pidIndex[$pid]);
                    $changed = true;
                }
            }
            if ($changed) {
                self::writePidIndex($pidIndex);
            }
        }
        
        // 从 port_index 移除所有相关端口
        if (!empty($allPorts)) {
            $portIndex = self::readPortIndex();
            $changed = false;
            foreach ($allPorts as $port) {
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
        $stalePids = [];

        // 清理陈旧的 *-pid.json 文件（进程不存活的）
        $files = \glob($dir . '*-pid.json');
        foreach ($files ?? [] as $path) {
            $raw = @\file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            $data = \json_decode($raw, true);
            $pid = isset($data['pid']) ? (int) $data['pid'] : 0;
            if ($pid <= 0) {
                continue;
            }

            $matchesIdentity = \is_array($data) && self::doesPidMatchRecordedIdentity($pid, $data);
            if (!$matchesIdentity) {
                @\unlink($path);
                $stalePids[] = $pid;
                $removed++;
            }
        }
        
        // 同步清理 pid_index.json 中的陈旧记录
        if (!empty($stalePids)) {
            $pidIndex = self::readPidIndex();
            $pidIndexChanged = false;
            foreach ($stalePids as $stalePid) {
                if (isset($pidIndex[$stalePid])) {
                    unset($pidIndex[$stalePid]);
                    $pidIndexChanged = true;
                }
                self::untrustPid($stalePid);
            }
            if ($pidIndexChanged) {
                self::writePidIndex($pidIndex);
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
        $record = self::getData($pname);
        $pid = (int) ($record['pid'] ?? 0);
        if ($pid <= 0) {
            // 没有 PID 文件记录，清理残留文件
            self::removePidFile($pname);
            self::removeLogFile($pname);
            return false;
        }
        if (!self::isRunningByPid($pid)) {
            self::removePidFile($pname);
            self::removeLogFile($pname);
            return false;
        }

        if (!\is_array($record) || !self::doesPidMatchRecordedIdentity($pid, $record)) {
            return false;
        }
        // 清理 PID 文件（避免累积）
        return self::killManagedProcess(
            $pid,
            (string) ($record['process_name'] ?? ''),
            (string) ($record['launch_id'] ?? ''),
            $pname
        );
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
        $record = self::getData($pname);
        $pid = (int) ($record['pid'] ?? 0);
        if ($pid > 0 && self::isManagedProcessRunning(
            $pid,
            !empty($record['process_name']) ? (string) $record['process_name'] : null,
            (string) ($record['launch_id'] ?? ''),
            $pname
        )) {
            return true;
        }
        
        // 慢路径：文件中 PID 无效，尝试完整 getPid（可能触发系统搜索）
        // 注意：Windows 上这个调用可能很慢（5-30秒），建议只在必要时使用
        $pid = self::getPid($pname);
        if (empty($pid)) {
            return false;
        }
        return self::isManagedProcessRunning(
            $pid,
            !empty($record['process_name']) ? (string) $record['process_name'] : null,
            (string) ($record['launch_id'] ?? ''),
            $pname
        );
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
        return self::kill($pname);

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
        
        if (!self::isRunningByPid($pid)) {
            self::removePidFile($pname);
            self::removeLogFile($pname);
            return false;
        }

        if (!\is_array($record) || !self::doesPidMatchRecordedIdentity($pid, $record)) {
            return false;
        }

        return self::killManagedProcess(
            $pid,
            (string) ($record['process_name'] ?? ''),
            (string) ($record['launch_id'] ?? ''),
            $pname
        );
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
        $stillRunning = self::isRunningByPid($pid);
        
        // 从受信任缓存移除（防止 PID 复用时误信任）
        self::untrustPid($pid);

        if ($pname && $pname !== 'unknown' && !$stillRunning) {
            // 记录杀进程日志
            self::logLifecycleEvent('kill', $pname, $pid);
            # 卸载pid文件
            self::removePidFile($pname);
            # 卸载日志文件
            self::removeLogFile($pname);
        }
        return $result || !$stillRunning;
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
        $stillRunning = self::isRunningByPid($pid);
        
        // 从受信任缓存移除（防止 PID 复用时误信任）
        self::untrustPid($pid);

        if ($pname && $pname !== 'unknown' && !$stillRunning) {
            // 记录杀进程日志
            self::logLifecycleEvent('kill', $pname, $pid, 'killed process tree');
            # 卸载pid文件
            self::removePidFile($pname);
            # 卸载日志文件
            self::removeLogFile($pname);
        }
        return $result || !$stillRunning;
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
    
    /*----------------------------------------优雅停止与批量管理区域------------------------------------------*/
    
    /**
     * 优雅停止进程（带可配置超时）
     * 
     * 三阶段停止协议：
     * 1. 发送 SIGTERM（优雅终止）
     * 2. 等待指定超时时间
     * 3. 若仍存活，发送 SIGKILL（强制终止）
     * 
     * 适用场景：WLS Worker 需要更长时间处理完当前请求
     * 
     * @param int $pid 进程 ID
     * @param float $timeout 等待超时（秒），默认 5.0
     * @param bool $skipCheck 是否跳过己方进程校验
     * @return bool 是否成功停止
     */
    public static function gracefulKill(int $pid, float $timeout = 5.0, bool $skipCheck = false): bool
    {
        if ($pid <= 0) {
            return false;
        }
        
        if (!$skipCheck && !self::isProcessManagerCreated($pid)) {
            return false;
        }
        
        // 阶段1：发送 SIGTERM
        self::getDriver()->sendSignal($pid, 15); // SIGTERM = 15
        
        // 阶段2：等待进程退出
        $startTime = \microtime(true);
        while (\microtime(true) - $startTime < $timeout) {
            if (!self::isRunningByPid($pid)) {
                // 清理 PID 文件
                $pname = self::getNameByPid($pid);
                if ($pname !== 'unknown') {
                    self::logLifecycleEvent('graceful_kill', $pname, $pid, 'exited after SIGTERM');
                    self::removePidFile($pname);
                }
                return true;
            }
            \usleep(100000); // 100ms
        }
        
        // 阶段3：强制终止
        $result = self::getDriver()->killProcess($pid);
        
        $pname = self::getNameByPid($pid);
        if ($pname !== 'unknown') {
            self::logLifecycleEvent('graceful_kill', $pname, $pid, 'force killed after timeout');
            self::removePidFile($pname);
        }
        
        return $result || !self::isRunningByPid($pid);
    }
    
    /**
     * 批量停止多个进程并等待
     * 
     * 策略：
     * 1. 同时向所有进程发送 SIGTERM
     * 2. 轮询等待所有进程退出
     * 3. 超时后对剩余进程发送 SIGKILL
     * 
     * 这比逐个停止更高效，因为所有进程可以并行处理退出逻辑
     * 
     * @param int[] $pids 进程 ID 列表
     * @param float $timeout 等待超时（秒），默认 5.0
     * @param bool $skipCheck 是否跳过己方进程校验
     * @return array{killed: int, failed: int, remaining: int[]} 停止统计
     */
    public static function batchGracefulKill(array $pids, float $timeout = 5.0, bool $skipCheck = false): array
    {
        $result = [
            'killed' => 0,
            'failed' => 0,
            'remaining' => [],
        ];
        
        if (empty($pids)) {
            return $result;
        }
        
        // 过滤有效 PID
        $validPids = [];
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }
            if (!$skipCheck && !self::isProcessManagerCreated($pid)) {
                $result['failed']++;
                continue;
            }
            if (!self::isRunningByPid($pid)) {
                $result['killed']++; // 已经停止
                continue;
            }
            $validPids[] = $pid;
        }
        
        if (empty($validPids)) {
            return $result;
        }
        
        // 检测是否 Windows
        $isWin = \strtoupper(\substr(PHP_OS, 0, 3)) === 'WIN';
        
        // 阶段1：并发发送 SIGTERM（使用 Fiber 批量发送，Windows 上实际是 taskkill /F）
        self::batchSendSignal($validPids, 15);
        
        // Windows 特殊处理：taskkill /F 后进程表刷新有延迟，需要短暂等待
        if ($isWin) {
            \usleep(500000); // 500ms 等待 Windows 进程表刷新
        }
        
        // 阶段2：轮询等待
        $startTime = \microtime(true);
        $stillRunning = $validPids;
        
        while (\microtime(true) - $startTime < $timeout && !empty($stillRunning)) {
            $newStillRunning = [];
            foreach ($stillRunning as $pid) {
                if (self::isRunningByPid($pid)) {
                    $newStillRunning[] = $pid;
                } else {
                    $result['killed']++;
                    // 清理 PID 文件
                    $pname = self::getNameByPid($pid);
                    if ($pname !== 'unknown') {
                        self::logLifecycleEvent('batch_kill', $pname, $pid, 'exited after SIGTERM');
                        self::removePidFile($pname);
                    }
                }
            }
            $stillRunning = $newStillRunning;
            
            if (!empty($stillRunning)) {
                \usleep(100000); // 100ms
            }
        }
        
        // 阶段3：强制杀死剩余进程
        foreach ($stillRunning as $pid) {
            if (self::getDriver()->killProcess($pid) || !self::isRunningByPid($pid)) {
                $result['killed']++;
                $pname = self::getNameByPid($pid);
                if ($pname !== 'unknown') {
                    self::logLifecycleEvent('batch_kill', $pname, $pid, 'force killed');
                    self::removePidFile($pname);
                }
            } else {
                $result['remaining'][] = $pid;
            }
        }
        
        return $result;
    }
    
    /**
     * 批量并发创建进程（使用 Fiber）
     * 
     * 适用于需要同时启动多个进程的场景（如 WLS 启动多个 Worker）。
     * 使用 Fiber 并发执行 proc_open，减少串行等待时间。
     * 
     * @param array<string, array{command: string, block?: bool, foreground?: bool, enableLog?: bool|null}> $commands
     *        键为标识符，值为命令配置数组
     * @return array<string, int> 标识符 => PID（0 表示启动失败）
     */
    public static function batchCreate(array $commands): array
    {
        if (empty($commands)) {
            return [];
        }

        if (\count($commands) === 1) {
            $key = \array_key_first($commands);
            $config = $commands[$key];
            $pid = self::create(
                $config['command'],
                $config['block'] ?? false,
                $config['foreground'] ?? false,
                $config['enableLog'] ?? null
            );

            return [$key => $pid];
        }

        $optimizedResults = self::tryBatchCreateOptimized($commands);
        if ($optimizedResults !== null) {
            return $optimizedResults;
        }

        $results = [];
        foreach ($commands as $key => $config) {
            try {
                $results[$key] = self::create(
                    $config['command'],
                    $config['block'] ?? false,
                    $config['foreground'] ?? false,
                    $config['enableLog'] ?? null
                );
            } catch (\Throwable) {
                $results[$key] = 0;
            }
        }

        return $results;
    }

    /**
     * @param array<string, array{command: string, block?: bool, foreground?: bool, enableLog?: bool|null}> $commands
     * @return array<string, int>|null
     */
    private static function tryBatchCreateOptimized(array $commands): ?array
    {
        if (IS_WIN) {
            return self::batchCreateWindows($commands);
        }

        return null;
    }

    /**
     * @param array<string, array{command: string, block?: bool, foreground?: bool, enableLog?: bool|null}> $commands
     * @return array<string, int>|null
     */
    private static function batchCreateWindows(array $commands): ?array
    {
        $disabledFunctions = \array_map('trim', \explode(',', \ini_get('disable_functions') ?: ''));
        $procOpenAvailable = \function_exists('proc_open') && !\in_array('proc_open', $disabledFunctions, true);
        $execAvailable = \function_exists('exec') && !\in_array('exec', $disabledFunctions, true);
        if (!$procOpenAvailable && !$execAvailable) {
            return null;
        }

        $results = [];
        $launchItems = [];

        foreach ($commands as $key => $config) {
            $command = (string) ($config['command'] ?? '');
            if ($command === '') {
                $results[$key] = 0;
                continue;
            }

            $enableLog = $config['enableLog'] ?? null;
            if ($enableLog === null) {
                $enableLog = self::isLogEnabled();
            }

            $processInfo = self::ensureProcessName($command);
            $processCommand = $processInfo['command'];
            $block = (bool) ($config['block'] ?? false);
            $foreground = (bool) ($config['foreground'] ?? false);

            if (self::shouldTryManagedProcessReuse($block, $foreground)) {
                $existingPid = (int) self::getData($processCommand, 'pid');
                if ($existingPid > 0 && self::isManagedProcessRunning($existingPid, null, '', $processCommand)) {
                    $results[$key] = $existingPid;
                    continue;
                }
            }

            if ($enableLog) {
                self::setOutput(
                    $processCommand,
                    PHP_EOL . '--- Process started at ' . \date('Y-m-d H:i:s') . ' ---' . PHP_EOL . $processCommand . PHP_EOL,
                    true
                );
            }

            [$phpBinary, $arguments] = self::splitPhpCommandForStartProcess($processCommand);
            $processName = self::extractCommandLineArg($processCommand, 'name');
            $foregroundScript = null;
            if ($foreground) {
                $foregroundScript = self::writeWindowsForegroundLauncherScript($phpBinary, $arguments, BP);
                if ($foregroundScript === null) {
                    if ($enableLog) {
                        self::setOutput(
                            $processCommand,
                            "[ERROR] failed to prepare windows foreground batch launcher" . PHP_EOL,
                            true
                        );
                    }
                    $results[$key] = 0;
                    continue;
                }
            }
            $launchItems[] = [
                'key' => (string) $key,
                'command' => $processCommand,
                'php' => $phpBinary,
                'arguments' => $arguments,
                'process_name' => $processName,
                'cwd' => BP,
                'enable_log' => $enableLog,
                'foreground' => $foreground,
                'foreground_script' => $foregroundScript,
            ];
        }

        if ($launchItems === []) {
            return $results;
        }

        $resultPath = \tempnam(\sys_get_temp_dir(), 'weline-batch-result-');
        $errorPath = \tempnam(\sys_get_temp_dir(), 'weline-batch-error-');
        if ($resultPath === false || $resultPath === '' || $errorPath === false || $errorPath === '') {
            self::cleanupWindowsForegroundLaunchers($launchItems);
            return null;
        }
        $scriptPath = self::writeWindowsBatchCreateScript($launchItems, $resultPath, $errorPath);
        if ($scriptPath === null) {
            self::cleanupWindowsForegroundLaunchers($launchItems);
            @\unlink($resultPath);
            @\unlink($errorPath);
            return null;
        }

        $nullDevice = 'NUL';
        $batchCommand = 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File '
            . \escapeshellarg($scriptPath);
        $lastError = null;

        if ($procOpenAvailable) {
            $descriptorspec = [
                0 => ['file', $nullDevice, 'r'],
                1 => ['file', $nullDevice, 'w'],
                2 => ['file', $errorPath, 'a'],
            ];

            \set_error_handler(function ($type, $msg) use (&$lastError) {
                $lastError = $msg;
                return true;
            });
            try {
                $psProcess = @\proc_open(
                    self::buildWindowsPowerShellProcOpenCommand($scriptPath),
                    $descriptorspec,
                    $psPipes,
                    BP,
                    null,
                    ['bypass_shell' => true]
                );
            } finally {
                \restore_error_handler();
            }

            if (!\is_resource($psProcess)) {
                self::cleanupWindowsForegroundLaunchers($launchItems);
                @\unlink($scriptPath);
                @\unlink($resultPath);
                @\unlink($errorPath);
                if ($lastError !== null) {
                    foreach ($launchItems as $item) {
                        if (!empty($item['enable_log'])) {
                            self::setOutput($item['command'], "[ERROR] batchCreate(proc_open powershell) failed: {$lastError}" . PHP_EOL, true);
                        }
                    }
                }

                return null;
            }
            @\proc_close($psProcess);
        } elseif ($execAvailable) {
            \set_error_handler(function ($type, $msg) use (&$lastError) {
                $lastError = $msg;
                return true;
            });
            try {
                @\exec($batchCommand . ' > NUL 2>> ' . \escapeshellarg($errorPath), $outputLines, $exitCode);
            } finally {
                \restore_error_handler();
            }

            if ($lastError !== null) {
                self::cleanupWindowsForegroundLaunchers($launchItems);
                @\unlink($scriptPath);
                @\unlink($resultPath);
                @\unlink($errorPath);
                foreach ($launchItems as $item) {
                    if (!empty($item['enable_log'])) {
                        self::setOutput($item['command'], "[ERROR] batchCreate(exec powershell) failed: {$lastError}" . PHP_EOL, true);
                    }
                }

                return null;
            }
            unset($outputLines, $exitCode);
        }

        $output = self::normalizeWindowsPowerShellPipeOutput((string) (@\file_get_contents($resultPath) ?: ''));
        $stderr = self::normalizeWindowsPowerShellPipeOutput((string) (@\file_get_contents($errorPath) ?: ''));
        @\unlink($scriptPath);
        @\unlink($resultPath);
        @\unlink($errorPath);
        $diagnostic = \trim(\implode(' | ', \array_filter([$output, $stderr], static fn (string $text): bool => $text !== '')));

        $pidMap = [];
        $lines = \preg_split('/\r\n|\r|\n/', \trim($output)) ?: [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $parts = \explode("\t", $line, 2);
            if (\count($parts) !== 2) {
                continue;
            }

            $pid = \trim((string) $parts[1]);
            if (\is_numeric($pid) && (int) $pid > 0) {
                $pidMap[(string) $parts[0]] = (int) $pid;
            }
        }

        $resolvedPidMap = self::waitForManagedProcessLaunchBatch(
            \array_values(\array_filter(
                $launchItems,
                static fn (array $item): bool => (int) ($pidMap[(string) ($item['key'] ?? '')] ?? 0) <= 0
            )),
            5.0
        );

        foreach ($launchItems as $item) {
            $pid = (int) ($pidMap[$item['key']] ?? $resolvedPidMap[$item['key']] ?? 0);
            if ($pid <= 0 && !empty($item['enable_log'])) {
                if ($diagnostic !== '') {
                    self::setOutput($item['command'], "[ERROR] batchCreate(raw output) {$diagnostic}" . PHP_EOL, true);
                }
            }
            $results[$item['key']] = $pid > 0 ? self::setPid($item['command'], $pid) : 0;
        }

        foreach ($commands as $key => $config) {
            unset($config);
            if (!\array_key_exists($key, $results)) {
                $results[$key] = 0;
            }
        }

        return $results;
    }

    /**
     * Display flags such as foreground must not change whether a managed
     * process can be reused. Reuse follows block semantics only.
     */
    private static function shouldTryManagedProcessReuse(bool $block, bool $foreground): bool
    {
        unset($foreground);
        return $block;
    }

    /**
     * @param array<int, array{key: string, command: string, php: string, arguments: string, process_name: string, cwd: string, enable_log: bool, foreground: bool, foreground_script?: string|null}> $launchItems
     */
    private static function writeWindowsBatchCreateScript(array $launchItems, string $resultPath, string $errorPath): ?string
    {
        $script = self::buildWindowsBatchCreateScript($launchItems, $resultPath, $errorPath);
        if ($script === null) {
            return null;
        }

        $tmpBase = \tempnam(\sys_get_temp_dir(), 'weline-batch-create-');
        if ($tmpBase === false || $tmpBase === '') {
            return null;
        }

        $scriptPath = $tmpBase . '.ps1';
        @\unlink($tmpBase);

        if (@\file_put_contents($scriptPath, $script) === false) {
            @\unlink($scriptPath);
            return null;
        }

        return $scriptPath;
    }

    /**
     * @param array<int, array{foreground_script?: string|null}> $launchItems
     */
    private static function cleanupWindowsForegroundLaunchers(array $launchItems): void
    {
        foreach ($launchItems as $item) {
            $foregroundScript = (string) ($item['foreground_script'] ?? '');
            if ($foregroundScript !== '' && \is_file($foregroundScript)) {
                @\unlink($foregroundScript);
            }
        }
    }

    /**
     * @param array<int, array{key: string, command: string, process_name: string}> $launchItems
     * @return array<string, int>
     */
    private static function waitForManagedProcessLaunchBatch(array $launchItems, float $timeoutSeconds = 5.0): array
    {
        if ($launchItems === []) {
            return [];
        }

        $pending = [];
        foreach ($launchItems as $item) {
            $key = (string) ($item['key'] ?? '');
            $command = (string) ($item['command'] ?? '');
            $processName = (string) ($item['process_name'] ?? '');
            if ($key === '' || $command === '' || $processName === '') {
                continue;
            }

            $pending[$key] = [
                'command' => $command,
                'process_name' => $processName,
                'launch_id' => self::extractCommandLineArg($command, 'launch-id'),
            ];
        }

        if ($pending === []) {
            return [];
        }

        $resolved = [];
        $deadline = \microtime(true) + \max(0.1, $timeoutSeconds);
        do {
            foreach ($pending as $key => $item) {
                foreach (self::getProcessIdsByName($item['process_name']) as $candidatePid) {
                    $candidatePid = (int) $candidatePid;
                    if ($candidatePid <= 0) {
                        continue;
                    }

                    if (!self::isManagedProcessRunning(
                        $candidatePid,
                        $item['process_name'],
                        (string) $item['launch_id'],
                        $item['command']
                    )) {
                        continue;
                    }

                    $resolved[$key] = self::setPid($item['command'], $candidatePid);
                    unset($pending[$key]);
                    break;
                }
            }

            if ($pending === []) {
                break;
            }

            \usleep(100_000);
        } while (\microtime(true) < $deadline);

        return $resolved;
    }

    /**
     * Launch a visible Windows console for frontend workers, then poll by
     * command line identity because cmd/start does not expose the child PID.
     */
    private static function createWindowsForeground(string $pname, bool $enableLog, array $availableFunctions): int
    {
        if (empty($availableFunctions['popen']) && empty($availableFunctions['exec'])) {
            if ($enableLog) {
                self::setOutput($pname, "[ERROR] windows foreground launch requires popen or exec" . PHP_EOL, true);
            }

            return 0;
        }

        [$phpBinary, $arguments] = self::splitPhpCommandForStartProcess($pname);
        $scriptPath = self::writeWindowsForegroundLauncherScript($phpBinary, $arguments, BP);
        if ($scriptPath === null) {
            if ($enableLog) {
                self::setOutput($pname, "[ERROR] failed to prepare windows foreground launcher" . PHP_EOL, true);
            }

            return 0;
        }

        $windowTitle = self::extractCommandLineArg($pname, 'name');
        if ($windowTitle === '') {
            $windowTitle = self::generateProcessName($pname);
        }
        $launchCommand = self::buildWindowsForegroundStartCommand($scriptPath, BP, $windowTitle);
        $lastError = null;
        $launched = false;

        if (!empty($availableFunctions['popen'])) {
            \set_error_handler(function ($type, $msg) use (&$lastError) {
                $lastError = $msg;
                return true;
            });
            try {
                $handle = @\popen($launchCommand, 'r');
            } finally {
                \restore_error_handler();
            }

            if (\is_resource($handle)) {
                @\pclose($handle);
                $launched = true;
            }
        }

        if (!$launched && !empty($availableFunctions['exec'])) {
            \set_error_handler(function ($type, $msg) use (&$lastError) {
                $lastError = $msg;
                return true;
            });
            try {
                @\exec($launchCommand . ' > NUL 2>NUL', $outputLines, $exitCode);
            } finally {
                \restore_error_handler();
            }
            unset($outputLines, $exitCode);
            $launched = $lastError === null;
        }

        if (!$launched) {
            @\unlink($scriptPath);
            if ($enableLog && $lastError !== null) {
                self::setOutput($pname, "[ERROR] windows foreground launch failed: {$lastError}" . PHP_EOL, true);
            }

            return 0;
        }

        $pid = self::waitForManagedProcessLaunch($pname, 5.0);
        if ($pid > 0) {
            return $pid;
        }

        if ($enableLog) {
            self::setOutput($pname, "[WARN] windows foreground launch started without PID confirmation" . PHP_EOL, true);
        }

        return 0;
    }

    private static function writeWindowsStartScript(string $phpBinary, string $arguments, string $workingDir): ?string
    {
        $template = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$arguments = '__ARGUMENTS__'
$startArgs = @{
    FilePath = '__PHP_BINARY__'
    WorkingDirectory = '__WORKING_DIR__'
    WindowStyle = 'Hidden'
    PassThru = $true
    ErrorAction = 'Stop'
}
if ($arguments -ne '') {
    $startArgs.ArgumentList = $arguments
}
try {
    $p = Start-Process @startArgs
    [Console]::Out.WriteLine([string]$p.Id)
} catch {
    [Console]::Error.WriteLine($_.Exception.Message)
    exit 1
}
POWERSHELL;

        $script = \str_replace(
            ['__PHP_BINARY__', '__ARGUMENTS__', '__WORKING_DIR__'],
            [
                self::escapePowerShellLiteral($phpBinary),
                self::escapePowerShellLiteral($arguments),
                self::escapePowerShellLiteral($workingDir),
            ],
            $template
        );

        $tmpBase = \tempnam(\sys_get_temp_dir(), 'weline-start-');
        if ($tmpBase === false || $tmpBase === '') {
            return null;
        }

        $scriptPath = $tmpBase . '.ps1';
        @\unlink($tmpBase);

        if (@\file_put_contents($scriptPath, $script) === false) {
            @\unlink($scriptPath);
            return null;
        }

        return $scriptPath;
    }

    private static function writeWindowsForegroundLauncherScript(string $phpBinary, string $arguments, string $workingDir): ?string
    {
        $arguments = self::normalizeWindowsForegroundArguments($arguments);
        $template = <<<'CMD'
@echo off
setlocal DisableDelayedExpansion
cd /d "__WORKING_DIR__"
"__PHP_BINARY__" __ARGUMENTS__
set "exit_code=%ERRORLEVEL%"
del "%~f0" >NUL 2>&1
exit /b %exit_code%
CMD;

        $script = \str_replace(
            ['__WORKING_DIR__', '__PHP_BINARY__', '__ARGUMENTS__'],
            [
                self::escapeWindowsBatchLiteral($workingDir),
                self::escapeWindowsBatchLiteral($phpBinary),
                self::escapeWindowsBatchArgumentLiteral($arguments),
            ],
            $template
        );

        $tmpBase = \tempnam(\sys_get_temp_dir(), 'weline-foreground-');
        if ($tmpBase === false || $tmpBase === '') {
            return null;
        }

        $scriptPath = $tmpBase . '.cmd';
        @\unlink($tmpBase);

        if (@\file_put_contents($scriptPath, $script) === false) {
            @\unlink($scriptPath);
            return null;
        }

        return $scriptPath;
    }

    private static function buildWindowsForegroundStartCommand(string $scriptPath, string $workingDir, string $windowTitle): string
    {
        $windowTitle = \trim(\str_replace('"', '', $windowTitle));
        if ($windowTitle === '') {
            $windowTitle = 'weline-process';
        }

        return 'start "'
            . $windowTitle
            . '" /D '
            . \escapeshellarg($workingDir)
            . ' cmd.exe /d /c '
            . \escapeshellarg($scriptPath);
    }

    private static function waitForManagedProcessLaunch(string $pname, float $timeoutSeconds = 5.0): int
    {
        $deadline = \microtime(true) + \max(0.1, $timeoutSeconds);
        $processName = self::extractCommandLineArg($pname, 'name');
        $launchId = self::extractCommandLineArg($pname, 'launch-id');
        do {
            if ($processName !== '') {
                foreach (self::getProcessIdsByName($processName) as $candidatePid) {
                    $candidatePid = (int) $candidatePid;
                    if ($candidatePid <= 0) {
                        continue;
                    }

                    if (self::isManagedProcessRunning($candidatePid, $processName, $launchId, $pname)) {
                        return self::setPid($pname, $candidatePid);
                    }
                }
            }

            $pid = self::getPid($pname);
            if ($pid > 0) {
                return $pid;
            }

            \usleep(100_000);
        } while (\microtime(true) < $deadline);

        return 0;
    }

    private static function normalizeWindowsForegroundArguments(string $arguments): string
    {
        if ($arguments === '') {
            return '';
        }

        $normalized = \preg_replace_callback(
            '/--([A-Za-z0-9-]+)=(["\'])([^"\']+)\2/',
            static function (array $matches): string {
                $value = (string) ($matches[3] ?? '');
                if ($value === '' || \preg_match('/\s/', $value)) {
                    return (string) $matches[0];
                }

                return '--' . $matches[1] . '=' . $value;
            },
            $arguments
        );

        return \is_string($normalized) ? $normalized : $arguments;
    }

    private static function escapeWindowsBatchLiteral(string $value): string
    {
        return \str_replace('%', '%%', $value);
    }

    private static function escapeWindowsBatchArgumentLiteral(string $value): string
    {
        return \str_replace(
            ['%', "\r", "\n"],
            ['%%', ' ', ' '],
            $value
        );
    }

    private static function escapePowerShellLiteral(string $value): string
    {
        return \str_replace("'", "''", $value);
    }

    /**
     * @return list<string>
     */
    private static function buildWindowsPowerShellProcOpenCommand(string $scriptPath): array
    {
        return [
            'powershell',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-File',
            $scriptPath,
        ];
    }

    /**
     * @param array<int, array{key: string, command: string, php: string, arguments: string, process_name: string, cwd: string, enable_log: bool, foreground: bool, foreground_script?: string|null}> $launchItems
     */
    private static function buildWindowsBatchCreateScript(array $launchItems, string $resultPath, string $errorPath): ?string
    {
        $lines = [
            "\$ErrorActionPreference = 'Stop'",
            '$results = New-Object System.Collections.Generic.List[string]',
            "\$resultPath = '" . self::escapePowerShellLiteral($resultPath) . "'",
            "\$errorPath = '" . self::escapePowerShellLiteral($errorPath) . "'",
        ];

        foreach ($launchItems as $item) {
            $key = \str_replace('"', '""', (string) ($item['key'] ?? ''));
            $php = \str_replace("'", "''", (string) ($item['php'] ?? ''));
            $cwd = \str_replace("'", "''", (string) ($item['cwd'] ?? BP));
            $arguments = self::escapePowerShellLiteral((string) ($item['arguments'] ?? ''));
            $foreground = !empty($item['foreground']);
            $foregroundScript = self::escapePowerShellLiteral((string) ($item['foreground_script'] ?? ''));
            $redirectBase = (string) ($item['process_name'] ?? $item['key'] ?? 'process');
            $redirectBase = \preg_replace('/[^A-Za-z0-9._-]+/', '-', $redirectBase) ?: 'process';
            $stdoutPath = self::escapePowerShellLiteral(
                \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-batch-' . $redirectBase . '.out.log'
            );
            $stderrPath = self::escapePowerShellLiteral(
                \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-batch-' . $redirectBase . '.err.log'
            );

            if ($key === '' || (!$foreground && $php === '') || ($foreground && $foregroundScript === '')) {
                return null;
            }

            $lines[] = 'try {';
            $lines[] = '    $startArgs = @{';
            $lines[] = "        FilePath = '" . ($foreground ? 'cmd.exe' : $php) . "'";
            $lines[] = "        WorkingDirectory = '{$cwd}'";
            $lines[] = "        WindowStyle = '" . ($foreground ? 'Normal' : 'Hidden') . "'";
            $lines[] = "        ErrorAction = 'Stop'";
            if (!$foreground) {
                $lines[] = '        PassThru = $true';
            }
            $lines[] = '    }';
            if ($foreground) {
                $lines[] = "    \$startArgs.ArgumentList = @('/d','/c','\"{$foregroundScript}\"')";
                $lines[] = '    Start-Process @startArgs | Out-Null';
                $lines[] = "    \$results.Add(\"{$key}`t0\") | Out-Null";
            } else {
                $lines[] = "    \$startArgs.RedirectStandardOutput = '{$stdoutPath}'";
                $lines[] = "    \$startArgs.RedirectStandardError = '{$stderrPath}'";
                if ($arguments !== '') {
                    $lines[] = "    \$startArgs.ArgumentList = '{$arguments}'";
                }
                $lines[] = '    $p = Start-Process @startArgs';
                $lines[] = "    \$results.Add(\"{$key}`t\" + [string]\$p.Id) | Out-Null";
            }
            $lines[] = '} catch {';
            $lines[] = '    [System.IO.File]::AppendAllText($errorPath, $_.Exception.Message + [Environment]::NewLine)';
            $lines[] = "    \$results.Add(\"{$key}`t0\") | Out-Null";
            $lines[] = '}';
        }

        $lines[] = '$utf8NoBom = New-Object System.Text.UTF8Encoding($false)';
        $lines[] = '[System.IO.File]::WriteAllLines($resultPath, $results, $utf8NoBom)';

        return \implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private static function normalizeWindowsPowerShellPipeOutput(string $output): string
    {
        if ($output === '') {
            return '';
        }

        $looksUtf16Le = \str_starts_with($output, "\xFF\xFE")
            || \substr_count($output, "\x00") > (\strlen($output) / 8);
        if (!$looksUtf16Le) {
            return $output;
        }

        $decoded = \function_exists('mb_convert_encoding')
            ? \mb_convert_encoding($output, 'UTF-8', 'UTF-16LE')
            : \iconv('UTF-16LE', 'UTF-8//IGNORE', $output);

        if ($decoded === false || $decoded === '') {
            return $output;
        }

        return \preg_replace('/^\x{FEFF}/u', '', $decoded) ?? $decoded;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitPhpCommandForStartProcess(string $command): array
    {
        $phpBinary = PHP_BINARY;
        $arguments = $command;

        if (\str_starts_with($command, '"' . $phpBinary . '"')) {
            return [$phpBinary, \trim(\substr($command, \strlen('"' . $phpBinary . '"')))];
        }

        if (\str_starts_with($command, $phpBinary)) {
            return [$phpBinary, \trim(\substr($command, \strlen($phpBinary)))];
        }

        if (\preg_match('/^"([^"]+)"(.*)$/', $command, $matches)) {
            $candidateBinary = (string) ($matches[1] ?? '');
            if ($candidateBinary !== ''
                && \strcasecmp(\str_replace('/', '\\', $candidateBinary), \str_replace('/', '\\', $phpBinary)) === 0) {
                return [$candidateBinary, \trim((string) ($matches[2] ?? ''))];
            }
        }

        return [$phpBinary, $arguments];
    }
    
    /**
     * 批量并发发送信号（使用 Fiber）
     * 
     * 适用于需要同时向多个进程发送信号的场景（如批量终止）。
     * 
     * @param int[] $pids 进程 ID 列表
     * @param int $signal 信号（默认 15 = SIGTERM）
     * @return array<int, bool> PID => 是否成功发送
     */
    public static function batchSendSignal(array $pids, int $signal = 15): array
    {
        if (empty($pids)) {
            return [];
        }
        
        $results = [];
        $driver = self::getDriver();
        
        // 小批量时直接串行（Fiber 开销可能超过收益）
        if (\count($pids) <= 3) {
            foreach ($pids as $pid) {
                $pid = (int) $pid;
                if ($pid > 0) {
                    $results[$pid] = $driver->sendSignal($pid, $signal);
                }
            }
            return $results;
        }
        
        $fibers = [];
        
        // 为每个 PID 创建 Fiber
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }
            $fibers[$pid] = new \Fiber(function () use ($driver, $pid, $signal): bool {
                return $driver->sendSignal($pid, $signal);
            });
        }
        
        // 启动所有 Fiber
        foreach ($fibers as $pid => $fiber) {
            try {
                $fiber->start();
            } catch (\Throwable $e) {
                $results[$pid] = false;
                unset($fibers[$pid]);
            }
        }
        
        // 收集结果
        foreach ($fibers as $pid => $fiber) {
            try {
                if ($fiber->isTerminated()) {
                    $results[$pid] = $fiber->getReturn();
                } else {
                    $results[$pid] = false;
                }
            } catch (\Throwable $e) {
                $results[$pid] = false;
            }
        }
        
        return $results;
    }
    
    /**
     * 批量检查多个进程的运行状态
     * 
     * @param int[] $pids 进程 ID 列表
     * @return array<int, bool> PID => 是否运行
     */
    public static function batchCheckRunning(array $pids): array
    {
        $result = [];
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if ($pid > 0) {
                $result[$pid] = self::isRunningByPid($pid);
            }
        }
        return $result;
    }
    
    /**
     * 等待多个进程全部退出
     * 
     * @param int[] $pids 进程 ID 列表
     * @param float $timeout 超时时间（秒）
     * @return array{exited: int[], remaining: int[]} 退出的 PID 和仍在运行的 PID
     */
    public static function waitForExit(array $pids, float $timeout = 5.0): array
    {
        $result = [
            'exited' => [],
            'remaining' => [],
        ];
        
        if (empty($pids)) {
            return $result;
        }
        
        $startTime = \microtime(true);
        $stillRunning = \array_map('intval', $pids);
        
        while (\microtime(true) - $startTime < $timeout && !empty($stillRunning)) {
            $newStillRunning = [];
            foreach ($stillRunning as $pid) {
                if (self::isRunningByPid($pid)) {
                    $newStillRunning[] = $pid;
                } else {
                    $result['exited'][] = $pid;
                }
            }
            $stillRunning = $newStillRunning;
            
            if (!empty($stillRunning)) {
                \usleep(100000); // 100ms
            }
        }
        
        $result['remaining'] = $stillRunning;
        return $result;
    }
    
    /**
     * 按进程名前缀优雅停止（先 SIGTERM，超时后 SIGKILL）
     * 
     * 适用场景：停止某个 WLS 实例的所有 Worker
     * 
     * @param string $prefix 进程名前缀（如 weline-master-default-）
     * @param float $timeout 等待超时（秒）
     * @return array{killed: int, failed: int} 停止统计
     */
    public static function gracefulKillByPrefix(string $prefix, float $timeout = 5.0): array
    {
        // 安全校验：prefix 必须包含 weline-
        if (empty($prefix) || \strpos($prefix, self::WELINE_PROCESS_PREFIX) === false) {
            return ['killed' => 0, 'failed' => 0];
        }
        
        // 收集匹配前缀的 PID
        $pids = [];
        $nameIndex = self::readNameIndex();
        
        foreach ($nameIndex as $pname => $entries) {
            $taskName = '';
            try {
                $taskName = self::getTaskName($pname);
            } catch (\Exception $e) {
                continue;
            }
            
            if (\str_starts_with($taskName, $prefix) || \str_starts_with($pname, '--name=' . $prefix)) {
                foreach ($entries as $entry) {
                    $pid = (int) ($entry['pid'] ?? 0);
                    if ($pid > 0) {
                        $pids[] = $pid;
                    }
                }
            }
        }
        
        if (empty($pids)) {
            return ['killed' => 0, 'failed' => 0];
        }
        
        $result = self::batchGracefulKill($pids, $timeout, true);
        return [
            'killed' => $result['killed'],
            'failed' => $result['failed'] + \count($result['remaining']),
        ];
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
        if ($port !== null) {
            unset(self::$orphanWelinePortHintCache[$port]);
        } else {
            self::$orphanWelinePortHintCache = [];
        }
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
        # 显式返回 0，表示端口池已耗尽
        return 0;
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
            
            if ($pid > 0 && self::isManagedProcessRunning($pid, $processName, '', $pname)) {
                if (self::killManagedProcess($pid, $processName, '', $pname)) {
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
                    self::killManagedProcess($p, $processName);
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
        if ($pid <= 0) {
            return false;
        }
        return self::isRunningByPid($pid);
    }
    
    /**
     * 快速检查框架管理的进程是否已退出
     * 
     * 优化策略：
     * 1. 检查 pid_index.json，如果 PID 不在索引中，直接返回 true（已退出）
     * 2. 如果 PID 在索引中，调用系统命令确认进程真实状态
     * 
     * 适用场景：等待框架自己启动的进程退出（如 WLS Master/Worker）。
     * 前提条件：进程必须是通过 Processer::create() 或 setPid() 注册的，
     *           且进程退出时会调用 removePidFile() 清理索引。
     * 
     * @param int $pid 进程 ID
     * @return bool 进程是否已退出
     */
    public static function hasExitedFast(int $pid): bool
    {
        if ($pid <= 0) {
            return true;
        }
        
        $pidIndex = self::readPidIndex();
        if (!isset($pidIndex[$pid])) {
            return true;
        }
        
        return !self::isRunningByPid($pid);
    }
    
    /*----------------------------------------索引文件操作区域（SOLID: SRP - 单独职责）------------------------------------------*/
    
    /**
     * 获取 name_index.json 文件路径
     * 结构: pname → [{ pid, jsonPath }, ...]（一对多）
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
     * @return array<string, list<array{pid: int, jsonPath: string}>>
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
     * @param array<string, list<array{pid: int, jsonPath: string}>> $data
     */
    public static function writeNameIndex(array $data): bool
    {
        $path = self::getNameIndexFile();
        return self::atomicWrite($path, \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 原子更新 name_index.json（排他锁内完成读-改-写，防止并发竞态）
     * 
     * @param callable(array): array $modifier 接收当前 name_index 数据，返回修改后的数据
     * @return bool 是否成功
     */
    public static function atomicUpdateNameIndex(callable $modifier): bool
    {
        $path = self::getNameIndexFile();
        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0777, true);
        }
        
        $fp = @\fopen($path, 'c+');
        if (!$fp) {
            return false;
        }
        
        if (!\flock($fp, \LOCK_EX)) {
            \fclose($fp);
            return false;
        }
        
        $content = \stream_get_contents($fp);
        $data = \json_decode($content ?: '', true) ?: [];
        
        $data = $modifier($data);
        
        // 清理空数组 key
        foreach ($data as $key => $entries) {
            if (empty($entries)) {
                unset($data[$key]);
            }
        }
        
        \ftruncate($fp, 0);
        \rewind($fp);
        \fwrite($fp, \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
        \fflush($fp);
        \flock($fp, \LOCK_UN);
        \fclose($fp);
        
        return true;
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
    
    /*----------------------------------------name_index 一对多查询 API------------------------------------------*/
    
    /**
     * 检查进程是否存活（多 PID 时，有一个活着就算存活）
     */
    public static function isAliveByName(string $pname): bool
    {
        $entries = (self::readNameIndex())[$pname] ?? [];
        foreach ($entries as $entry) {
            $pid = (int) ($entry['pid'] ?? 0);
            if ($pid > 0 && self::isRunningByPid($pid)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 获取指定进程名下的所有 PID（不过滤存活状态）
     * @return array<int>
     */
    public static function getAllPidsByName(string $pname): array
    {
        $entries = (self::readNameIndex())[$pname] ?? [];
        $pids = [];
        foreach ($entries as $entry) {
            $pid = (int) ($entry['pid'] ?? 0);
            if ($pid > 0) {
                $pids[] = $pid;
            }
        }
        return $pids;
    }
    
    /**
     * 杀死指定名字的所有进程（stop 场景：全部关闭）
     * @return int 成功杀死的数量
     */
    public static function killAllByName(string $pname, bool $force = false): int
    {
        $killed = 0;
        foreach (self::getAllPidsByName($pname) as $pid) {
            if ($pid > 0 && self::killByPid($pid, $force)) {
                $killed++;
            }
        }
        return $killed;
    }

    private static function buildProcessIdentityRecord(string $pname, int $pid, string $taskName): array
    {
        $record = [
            'record_version' => self::PROCESS_RECORD_VERSION,
            'pname_key' => self::buildPnameKey($pname),
            'process_name' => $taskName,
        ];

        if ($pid <= 0) {
            return $record;
        }

        $cmdLine = self::getProcessCommandLine($pid);
        if ($cmdLine === '') {
            return $record;
        }

        $record['command_line_hash'] = \sha1($cmdLine);

        $processName = self::extractCommandLineArg($cmdLine, 'name');
        if ($processName !== '') {
            $record['process_name'] = self::normalizeName($processName);
        }

        $launchId = self::extractCommandLineArg($cmdLine, 'launch-id');
        if ($launchId !== '') {
            $record['launch_id'] = $launchId;
        }

        $epoch = self::extractCommandLineArg($cmdLine, 'epoch');
        if ($epoch !== '' && \is_numeric($epoch)) {
            $record['epoch'] = (int) $epoch;
        }

        return $record;
    }

    private static function buildPnameKey(string $pname): string
    {
        if ($pname === '') {
            return '';
        }

        return '--name=' . self::getTaskName($pname);
    }

    private static function getRecordedProcessName(array $record): string
    {
        $processName = (string) ($record['process_name'] ?? '');
        if ($processName !== '') {
            return self::normalizeName($processName);
        }

        $taskName = (string) ($record['task_name'] ?? '');
        if ($taskName !== '') {
            return self::normalizeName($taskName);
        }

        $pname = (string) ($record['pname'] ?? '');
        if ($pname !== '') {
            return self::getTaskName($pname);
        }

        return '';
    }

    private static function doesPidMatchRecordedIdentity(int $pid, array $record): bool
    {
        if ($pid <= 0 || !self::isRunningByPid($pid)) {
            return false;
        }

        $expectedPnameKey = (string) ($record['pname_key'] ?? '');
        if ($expectedPnameKey === '' && !empty($record['pname'])) {
            $expectedPnameKey = self::buildPnameKey((string) $record['pname']);
        }

        $expectedProcessName = self::getRecordedProcessName($record);
        $expectedLaunchId = (string) ($record['launch_id'] ?? '');

        $pidIndex = self::readPidIndex();
        $indexedPname = (string) ($pidIndex[$pid]['pname'] ?? '');
        if ($expectedPnameKey !== '' && $indexedPname !== ''
            && self::buildPnameKey($indexedPname) !== $expectedPnameKey) {
            return false;
        }

        $cmdLine = self::getProcessCommandLine($pid);
        if ($cmdLine !== '') {
            if ($expectedProcessName !== '') {
                $actualProcessName = self::extractCommandLineArg($cmdLine, 'name');
                if ($actualProcessName !== '') {
                    if (self::normalizeName($actualProcessName) !== $expectedProcessName) {
                        return false;
                    }
                } elseif (\strpos($cmdLine, '--name=' . $expectedProcessName) === false
                    && \strpos($cmdLine, '--name ' . $expectedProcessName) === false) {
                    return false;
                }
            }

            if ($expectedLaunchId !== '') {
                $actualLaunchId = self::extractCommandLineArg($cmdLine, 'launch-id');
                if ($actualLaunchId === '' || $actualLaunchId !== $expectedLaunchId) {
                    return false;
                }
            }

            return true;
        }

        if ($expectedPnameKey !== '' && $indexedPname !== '') {
            return self::buildPnameKey($indexedPname) === $expectedPnameKey;
        }

        if ($expectedProcessName !== '' && $indexedPname !== '') {
            return self::getTaskName($indexedPname) === $expectedProcessName;
        }

        return $indexedPname !== '';
    }

    private static function extractCommandLineArg(string $commandLine, string $name): string
    {
        if ($commandLine === '' || $name === '') {
            return '';
        }

        $pattern = "/--" . \preg_quote($name, '/') . "(?:=|\\s+)(?:\"([^\"]+)\"|'([^']+)'|([^\\s]+))/i";
        if (!\preg_match($pattern, $commandLine, $matches)) {
            return '';
        }

        foreach ([1, 2, 3] as $index) {
            if (!empty($matches[$index])) {
                return (string) $matches[$index];
            }
        }

        return '';
    }
    
    /*----------------------------------------索引更新区域------------------------------------------*/
    
    /**
     * 更新索引（setPid 调用后同步更新 name_index 和 pid_index）
     * 
     * 写顺序：先 *-pid.json（已写），再 name_index（原子），再 pid_index
     * 
     * @param string $pname 进程名
     * @param int $pid 进程 ID
     * @param string $jsonPath PID JSON 文件路径
     */
    private static function updateIndexes(string $pname, int $pid, string $jsonPath): void
    {
        $deadPids = [];
        
        // 1. 原子更新 name_index：追加前清理死亡 PID，防止膨胀
        self::atomicUpdateNameIndex(function (array $nameIndex) use ($pname, $pid, $jsonPath, &$deadPids): array {
            if (!isset($nameIndex[$pname])) {
                $nameIndex[$pname] = [];
            }
            
            // 清理死亡 PID
            $alive = [];
            foreach ($nameIndex[$pname] as $entry) {
                $entryPid = (int) ($entry['pid'] ?? 0);
                if ($entryPid === $pid) {
                    continue; // 将在下面追加/更新
                }
                if ($entryPid > 0 && self::isRunningByPid($entryPid)) {
                    $alive[] = $entry;
                } else {
                    $deadPids[] = $entryPid;
                }
            }
            
            // 追加当前 PID
            $alive[] = ['pid' => $pid, 'jsonPath' => $jsonPath];
            $nameIndex[$pname] = $alive;
            
            return $nameIndex;
        });
        
        // 2. 更新 pid_index
        $pidIndex = self::readPidIndex();
        // 移除已死亡的旧 PID 索引项
        foreach ($deadPids as $deadPid) {
            if ($deadPid > 0 && isset($pidIndex[$deadPid])) {
                unset($pidIndex[$deadPid]);
                self::untrustPid($deadPid);
            }
        }
        $pidIndex[$pid] = ['pname' => $pname, 'jsonPath' => $jsonPath];
        self::writePidIndex($pidIndex);
    }
    
    /**
     * 从索引中移除进程信息（仅由 GC / 定时清理调用，不调用其他查询方法避免 loop）
     * 
     * name_index 按 PID 精确移除（一对多结构），数组为空则删除整个 key
     * 
     * @param string|null $pname 进程名（可选）
     * @param int|null $pid 进程 ID（可选，用于从 name_index 精确移除）
     * @param array|null $ports 进程端口列表（可选）
     */
    private static function removeFromIndexes(?string $pname, ?int $pid, ?array $ports = null): void
    {
        // 移除 name_index 中的项（按 PID 精确移除）
        if ($pname !== null) {
            self::atomicUpdateNameIndex(function (array $nameIndex) use ($pname, $pid): array {
                if (!isset($nameIndex[$pname])) {
                    return $nameIndex;
                }
                if ($pid !== null && $pid > 0) {
                    $nameIndex[$pname] = \array_values(\array_filter(
                        $nameIndex[$pname],
                        fn(array $e): bool => (int) ($e['pid'] ?? 0) !== $pid
                    ));
                } else {
                    $nameIndex[$pname] = [];
                }
                return $nameIndex;
            });
        }
        
        // 移除 pid_index 中的项
        if ($pid !== null && $pid > 0) {
            $pidIndex = self::readPidIndex();
            if (isset($pidIndex[$pid])) {
                unset($pidIndex[$pid]);
                self::writePidIndex($pidIndex);
            }
            self::untrustPid($pid);
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
        $pidFile = self::getPidFile($pname, (int) \getmypid());
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
            
            // 从 name_index 遍历该 pname 的所有 PID，找到第一个存活的
            $nameIndex = self::readNameIndex();
            $entries = $nameIndex[$pname] ?? [];
            
            if (!empty($entries)) {
                foreach ($entries as $entry) {
                    $pid = (int) ($entry['pid'] ?? 0);
                    if ($pid > 0 && self::isRunningByPid($pid)) {
                        return $pid;
                    }
                }
                
                // 所有 PID 都不存活，清理索引项
                $deadPids = [];
                $portsToClean = [$port];
                foreach ($entries as $entry) {
                    $deadPid = (int) ($entry['pid'] ?? 0);
                    $jsonPath = $entry['jsonPath'] ?? '';
                    if ($deadPid > 0) {
                        $deadPids[] = $deadPid;
                    }
                    if ($jsonPath && \is_file($jsonPath)) {
                        $raw = @\file_get_contents($jsonPath);
                        if ($raw !== false) {
                            $data = \json_decode($raw, true);
                            if (isset($data['ports'])) {
                                foreach ((array) $data['ports'] as $p) {
                                    $portsToClean[] = $p;
                                }
                            }
                        }
                        @\unlink($jsonPath);
                    }
                }
                
                // 原子移除 name_index 整个 key
                self::atomicUpdateNameIndex(function (array $idx) use ($pname): array {
                    unset($idx[$pname]);
                    return $idx;
                });
                
                // 清理 pid_index
                $pidIndex = self::readPidIndex();
                $pidChanged = false;
                foreach ($deadPids as $deadPid) {
                    if (isset($pidIndex[$deadPid])) {
                        unset($pidIndex[$deadPid]);
                        self::untrustPid($deadPid);
                        $pidChanged = true;
                    }
                }
                if ($pidChanged) {
                    self::writePidIndex($pidIndex);
                }
                
                // 清理 port_index
                $portsToClean = \array_unique($portsToClean);
                foreach ($portsToClean as $p) {
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
        
        foreach ($nameIndex as $pname => $entries) {
            $aliveEntries = [];
            
            foreach ($entries as $entry) {
                $pid = (int) ($entry['pid'] ?? 0);
                $jsonPath = $entry['jsonPath'] ?? null;
                
                if ($pid > 0 && self::isRunningByPid($pid)) {
                    $aliveEntries[] = $entry;
                    continue;
                }
                
                // 进程不存活，清理
                $removed++;
                
                if ($pid > 0 && isset($pidIndex[$pid])) {
                    unset($pidIndex[$pid]);
                    $pidIndexChanged = true;
                }
                
                $ports = [];
                if ($jsonPath && \is_file($jsonPath)) {
                    $raw = @\file_get_contents($jsonPath);
                    if ($raw !== false) {
                        $data = \json_decode($raw, true);
                        if (isset($data['ports'])) {
                            $ports = (array) $data['ports'];
                        }
                    }
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
            
            if (empty($aliveEntries)) {
                unset($nameIndex[$pname]);
            } else {
                $nameIndex[$pname] = $aliveEntries;
            }
            $nameIndexChanged = true;
        }
        
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
     * 性能优化：使用 trustedPidCache 缓存已验证的 PID，避免重复的 PowerShell/WMI 调用
     * 
     * @param int $pid 进程 ID
     * @return bool 是否是进程管理器创建的进程
     */
    public static function isProcessManagerCreated(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        
        // 快速路径：检查缓存
        if (isset(self::$trustedPidCache[$pid])) {
            return true;
        }
        
        $cmdLine = self::getProcessCommandLine($pid);
        if ($cmdLine === '') {
            return false;
        }
        
        // 检测命令行中是否包含 weline- 标识
        $isTrusted = \strpos($cmdLine, self::WELINE_PROCESS_PREFIX) !== false;
        
        // 缓存验证结果
        if ($isTrusted) {
            self::$trustedPidCache[$pid] = true;
        }
        
        return $isTrusted;
    }
    
    /**
     * 将 PID 标记为受信任（从 PID 文件读取的）
     * 
     * 跳过后续的命令行校验，大幅提升性能
     * 
     * @param int $pid 进程 ID
     */
    public static function markPidAsTrusted(int $pid): void
    {
        if ($pid > 0) {
            self::$trustedPidCache[$pid] = true;
        }
    }
    
    /**
     * 从受信任缓存中移除 PID
     * 
     * 进程被杀死后调用，防止 PID 复用时误信任
     * 
     * @param int $pid 进程 ID
     */
    public static function untrustPid(int $pid): void
    {
        unset(self::$trustedPidCache[$pid]);
    }
    
    /**
     * 清空受信任 PID 缓存
     */
    public static function clearTrustedPidCache(): void
    {
        self::$trustedPidCache = [];
    }
    
    /**
     * 检测指定 PID 的进程是否是 Weline 框架服务器进程（Worker/HTTP重定向/Master）
     *
     * 判断策略（按优先级）：
     * 1. 命令行中包含 --name=weline- 或 weline-worker/weline-dispatcher 等标识
     * 2. pid_index.json 中存在该 PID 的记录（进程名以 weline- 开头）
     *
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
        
        // 策略1：通过命令行判断
        $cmdLine = self::getProcessCommandLine($pid);
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
        
        // 策略2：通过 pid_index.json 判断（命令行获取失败时的回退方案）
        // 进程正在终止时，命令行可能无法读取，但 pid_index 中仍有记录
        $pname = self::getNameByPid($pid);
        if ($pname !== 'unknown' && $pname !== '') {
            // 检查进程名是否以 weline- 开头（通过 --name= 参数提取）
            // 进程名格式：--name=weline-dispatcher-default 或 --name=weline-master-default-worker-1
            if (\strpos($pname, '--name=' . self::WELINE_PROCESS_PREFIX) !== false) {
                return true;
            }
            // 直接检查进程名是否包含 weline- 前缀
            if (\strpos($pname, self::WELINE_PROCESS_PREFIX) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检测指定端口是否被 Weline 框架进程占用
     *
     * 判断策略（按优先级）：
     * 1. 从 port_index.json 查找（最快，进程刚启动时可能已注册端口）
     * 2. 通过系统命令获取 PID，再判断是否是框架进程
     *
     * @param int $port 端口号
     * @return bool 是否被框架进程占用
     */
    public static function isPortUsedByWeline(int $port): bool
    {
        return (bool) (self::inspectPortOccupantWithHistory($port)['is_weline'] ?? false);
    }
    
    /**
     * 统一检查端口占用归属，避免“端口仍可连但 PID 已失效”被误判。
     *
     * state:
     * - free:    端口空闲
     * - weline:  明确为框架进程占用（或 port_index 命中框架进程名）
     * - foreign: 明确为非框架进程占用
     * - orphan:  端口被占用但 PID 不可用/失效
     *
     * @return array{
     *   in_use:bool,
     *   pid:int,
     *   pid_running:bool,
     *   is_weline:bool,
     *   state:string
     * }
     */
    public static function inspectPortOccupant(int $port): array
    {
        if (!self::isPortInUse($port)) {
            return [
                'in_use' => false,
                'pid' => 0,
                'pid_running' => false,
                'is_weline' => false,
                'state' => 'free',
            ];
        }

        $portIndexSuggestsWeline = false;
        $portKey = (string) $port;
        $portIndex = self::readPortIndex();
        if (isset($portIndex[$portKey])) {
            $pname = (string) $portIndex[$portKey];
            $portIndexSuggestsWeline = (\strpos($pname, self::WELINE_PROCESS_PREFIX) !== false)
                || (\strpos($pname, '--name=' . self::WELINE_PROCESS_PREFIX) !== false);
        }

        $pid = self::getProcessIdByPort($port);
        if ($pid <= 0) {
            $ghostSuggestsWeline = $portIndexSuggestsWeline || self::nameIndexSuggestsWelinePort($port);
            return [
                'in_use' => true,
                'pid' => 0,
                'pid_running' => false,
                'is_weline' => $ghostSuggestsWeline,
                'state' => $ghostSuggestsWeline ? 'weline' : 'orphan',
            ];
        }

        $pidRunning = self::isRunningByPid($pid);
        if (!$pidRunning) {
            $ghostSuggestsWeline = $portIndexSuggestsWeline || self::nameIndexSuggestsWelinePort($port);
            return [
                'in_use' => true,
                'pid' => $pid,
                'pid_running' => false,
                'is_weline' => $ghostSuggestsWeline,
                'state' => $ghostSuggestsWeline ? 'weline' : 'orphan',
            ];
        }

        $isWeline = self::isWelineServerProcess($pid) || $portIndexSuggestsWeline;
        return [
            'in_use' => true,
            'pid' => $pid,
            'pid_running' => true,
            'is_weline' => $isWeline,
            'state' => $isWeline ? 'weline' : 'foreign',
        ];
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

        return !empty(self::getProcessIdsByName($processName));
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

        $pname = '--name=' . $processName;
        $pids = [];

        // 快速路径：优先使用 Processer 已登记的 PID。
        $pid = (int) self::getData($pname, 'pid');
        if ($pid > 0 && self::isManagedProcessRunning($pid, $processName, '', $pname)) {
            $pids[$pid] = true;
        }

        // 慢路径：系统级按命令行搜索，但必须再次校验受管身份，避免误命中仅“提到”该 name 的其它进程。
        foreach (self::getDriver()->findProcessesByName($processName) as $candidatePid) {
            $candidatePid = (int) $candidatePid;
            if ($candidatePid <= 0) {
                continue;
            }
            if (self::isManagedProcessRunning($candidatePid, $processName, '', $pname)) {
                $pids[$candidatePid] = true;
            }
        }

        return \array_map('intval', \array_keys($pids));
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
     * 从 var/process（name_index）中按前缀枚举进程名
     *
     * 用于在杀逃逸进程前从进程目录明确找到匹配的进程，再配合 killByProcessNamePrefix 使用。
     *
     * @param string $prefix 进程名前缀（如 weline-wls-master- 或 weline-wls-master-default）
     * @return array<string> 匹配的 pname 列表（name_index 的 key）
     */
    public static function getProcessNamesByPrefix(string $prefix): array
    {
        if (empty($prefix) || \strpos($prefix, self::WELINE_PROCESS_PREFIX) === false) {
            return [];
        }
        $nameIndex = self::readNameIndex();
        $matched = [];
        foreach ($nameIndex as $pname => $entries) {
            $taskName = '';
            try {
                $taskName = self::getTaskName($pname);
            } catch (\Exception $e) {
                continue;
            }
            if (\str_starts_with($taskName, $prefix) || \str_starts_with($pname, '--name=' . $prefix)) {
                $matched[] = $pname;
            }
        }
        return $matched;
    }

    /**
     * 按进程名前缀批量杀进程（使用 name_index 查找，仅杀己方进程）
     * 
     * 用途：例如前缀 weline-master-default- 可杀该实例下所有 worker；
     * 逃逸 Master 清理时可由 getProcessNamesByPrefix 从 var/process 找到旧 master 再按此前缀杀。
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
        $killedPids = [];
        $currentPid = \getmypid();
        
        // 1. 从 name_index 读取所有进程
        $nameIndex = self::readNameIndex();
        $pidsToKill = [];
        
        // 2. 按前缀匹配收集所有 PID（一对多）
        foreach ($nameIndex as $pname => $entries) {
            $taskName = '';
            try {
                $taskName = self::getTaskName($pname);
            } catch (\Exception $e) {
                continue;
            }
            
            if (\str_starts_with($taskName, $prefix) || \str_starts_with($pname, '--name=' . $prefix)) {
                foreach ($entries as $entry) {
                    $pid = (int) ($entry['pid'] ?? 0);
                    if ($pid > 0) {
                        $pidsToKill[$pid] = $pname;
                    }
                }
            }
        }
        
        // 3. 批量杀死 name_index 中的进程
        foreach ($pidsToKill as $pid => $pname) {
            if ($pid === $currentPid || !self::isProcessManagerCreated($pid)) {
                continue;
            }
            $taskName = '';
            try {
                $taskName = self::getTaskName($pname);
            } catch (\Throwable) {
            }
            $result = self::killManagedProcess(
                $pid,
                $taskName !== '' ? $taskName : null,
                '',
                $pname
            );
            if ($result) {
                $killed++;
                $killedPids[$pid] = true;
                self::logLifecycleEvent('kill', $pname, $pid, 'killed by prefix: ' . $prefix);
            }
        }
        
        // 4. 系统级兜底：通过 pgrep/ps 搜索命令行中含 --name={prefix} 的进程
        //    解决 osascript/nohup 等启动方式导致 name_index 缺失 PID 的问题
        $sysPids = self::getDriver()->findProcessesByName($prefix);
        foreach ($sysPids as $pid) {
            if ($pid <= 0 || $pid === $currentPid || isset($killedPids[$pid])) {
                continue;
            }
            if (!self::isWelineServerProcess($pid)) {
                continue;
            }
            $result = self::killByPid($pid);
            if ($result) {
                $killed++;
                $killedPids[$pid] = true;
                self::logLifecycleEvent('kill', '--name=' . $prefix . '(sys)', $pid, 'killed by system search, prefix: ' . $prefix);
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
     * 批量获取多个进程的详细信息
     * 
     * 单次系统调用获取多个进程信息，避免逐个查询的性能开销
     * Windows 上单次 PowerShell 调用 vs N 次调用可提升 10-50 倍性能
     * 
     * @param int[] $pids 进程 ID 数组
     * @return array<int, array{pid: int, exists: bool, name: string, command: string, memory: string, cpu: string, start_time: string}>
     */
    public static function batchGetProcessInfo(array $pids): array
    {
        return self::getDriver()->batchGetProcessInfo($pids);
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
