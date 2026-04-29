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
     * Windows batchCreate 非等待模式下的后台 helper 资源。
     *
     * 这些 PowerShell helper 负责异步批量拉起子进程；必须保留资源句柄，
     * 否则局部变量析构时可能触发阻塞性的 proc_close()。统一在后续调用或
     * 进程退出前做最佳努力回收，避免重新退回 cmd.exe 中间层。
     *
     * @var array<int, array{process: resource, started_at: float, script_path: string, result_path: string, error_path: string}>
     */
    private static array $windowsDetachedBatchHelpers = [];

    private static bool $windowsDetachedBatchHelperShutdownRegistered = false;
    
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

    /**
     * PowerShell 5.x 对无 BOM 的 .ps1 常按系统 ANSI 解码；BP/参数含中文时会导致 Start-Process 路径错误、PID 回传为空。
     */
    private const WINDOWS_PS1_UTF8_BOM = "\xEF\xBB\xBF";
    
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
     * 从进程名 / cmdline 中抽取 WLS 项目作用域 token。
     *
     * 进程名格式由 {@see \Weline\Server\Service\MasterProcess::buildScopedProcessName()} 生成：
     *   weline-{role}-{instance}-p{8 位小写十六进制}[-{slot}]
     * 例如：
     *   - weline-wls-dispatcher-default-p16330cac
     *   - weline-wls-worker-default-p16330cac-3
     *   - --name=weline-wls-dispatcher-default-p16330cac
     *   - php /srv/site/bin/dispatcher.php --name=weline-wls-... --port=9981
     *
     * 仅当严格匹配 `-p[0-9a-f]{8}` 段时返回作用域，否则返回空字符串。
     * 老版本（无作用域段）的进程名会返回空字符串，调用方应将其按
     * "兼容性疑似自家进程" 处理。
     *
     * 该方法是纯字符串操作，跨平台、无副作用，可被启停判定路径直接使用。
     */
    public static function extractProjectScopeFromProcessName(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $candidate = \trim($value);
        if ($candidate === '') {
            return '';
        }

        if (\preg_match('/--name(?:=|\s+)(?:"([^"]+)"|\'([^\']+)\'|([^\s]+))/', $candidate, $matches) === 1) {
            foreach ([1, 2, 3] as $idx) {
                if (!empty($matches[$idx])) {
                    $candidate = (string) $matches[$idx];
                    break;
                }
            }
        }

        if (\preg_match('/-(p[0-9a-f]{8})(?:-\d+)?(?:\s|$)/', $candidate, $scopeMatches) === 1) {
            return (string) $scopeMatches[1];
        }

        return '';
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

        return self::resolveWelinePnameByPortHint($port) !== '';
    }

    /**
     * 在 pid_index 中查找与该端口可能关联的 weline 进程名（用于 history 推断）。
     * 命中返回原始 pname（含 --name= 前缀时保留），否则返回空串。
     */
    private static function resolveWelinePnameByPortHint(int $port): string
    {
        if ($port <= 0) {
            return '';
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

            self::$orphanWelinePortHintCache[$port] = true;
            return $pname;
        }

        self::$orphanWelinePortHintCache[$port] = false;
        return '';
    }

    public static function inspectPortOccupantWithHistory(int $port): array
    {
        $inspect = self::inspectPortOccupant($port);
        if (!($inspect['in_use'] ?? false)) {
            return $inspect;
        }

        if ($inspect['is_weline'] ?? false) {
            return $inspect;
        }

        // Historical name/port indexes are advisory only. A Windows LISTEN row
        // can temporarily point at a PID that no longer exists; treating that
        // stale row as a live WLS owner blocks restart cleanup on ghost control
        // ports that cannot be killed.
        if (!($inspect['pid_running'] ?? false)) {
            return $inspect;
        }

        $hintedPname = self::resolveWelinePnameByPortHint($port);
        if ($hintedPname === '') {
            return $inspect;
        }

        $inspect['is_weline'] = true;
        $inspect['state'] = 'weline';
        if (!isset($inspect['pname']) || $inspect['pname'] === '') {
            $inspect['pname'] = $hintedPname;
        }
        if (!isset($inspect['scope']) || $inspect['scope'] === '') {
            $inspect['scope'] = self::extractProjectScopeFromProcessName($hintedPname);
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

        $disabledFunctions = \array_map('trim', \explode(',', \ini_get('disable_functions') ?: ''));
        if (!\function_exists('proc_open') || \in_array('proc_open', $disabledFunctions, true)) {
            self::$powerShellAvailableCache = false;
            return false;
        }

        $descriptors = [
            0 => ['file', 'NUL', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $lastError = null;
        \set_error_handler(static function ($type, $msg) use (&$lastError): bool {
            $lastError = $msg;
            return true;
        });
        try {
            $process = @\proc_open(
                ['powershell', '-NoProfile', '-Command', '$PSVersionTable.PSVersion.Major'],
                $descriptors,
                $pipes,
                BP,
                null,
                ['bypass_shell' => true]
            );
        } finally {
            \restore_error_handler();
        }

        if (!\is_resource($process)) {
            self::$powerShellAvailableCache = false;
            return false;
        }

        $stdout = isset($pipes[1]) ? (string) (\stream_get_contents($pipes[1]) ?: '') : '';
        $stderr = isset($pipes[2]) ? (string) (\stream_get_contents($pipes[2]) ?: '') : '';
        if (isset($pipes[1])) {
            @\fclose($pipes[1]);
        }
        if (isset($pipes[2])) {
            @\fclose($pipes[2]);
        }
        if (isset($pipes[0])) {
            @\fclose($pipes[0]);
        }
        $code = @\proc_close($process);
        $stdout = \trim(\preg_replace('/^\xEF\xBB\xBF/', '', $stdout) ?? $stdout);
        self::$powerShellAvailableCache = ($code === 0 && $stdout !== '' && $stderr === '' && $lastError === null);
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
            if ($existingPid > 0) {
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
            // Windows foreground launches must bypass cmd.exe; cmd can fail with
            // 0xc0000142 on some desktop sessions and block WLS startup.
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

        // Windows 后台默认优先走 argv 快速路径（Start-Process ArgumentList 数组），
        // 失败时再回退到旧脚本路径，兼容历史行为。
        if (IS_WIN && !$foreground) {
            $argv = self::buildWindowsDetachedPhpArgvFromCommand($pname);
            if ($argv !== []) {
                $fastPathStart = \microtime(true);
                $pid = self::createWindowsDetachedPhpArgv($argv, BP, $pname, $enableLog);
                $fastPathCostMs = (int) \round((\microtime(true) - $fastPathStart) * 1000);
                if ($pid > 0) {
                    if ($enableLog) {
                        self::setOutput(
                            $pname,
                            "[INFO] windows argv fast path success: pid={$pid}, cost_ms={$fastPathCostMs}" . PHP_EOL,
                            true
                        );
                    }
                    return $pid;
                }
                if ($enableLog) {
                    self::setOutput(
                        $pname,
                        "[WARN] windows argv fast path failed (cost_ms={$fastPathCostMs}), fallback to legacy start script" . PHP_EOL,
                        true
                    );
                }
            }
        }
        
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
                        @\stream_set_blocking($psPipes[1], false);
                        $startedAt = \microtime(true);
                        $buffer = '';
                        while ((\microtime(true) - $startedAt) < 0.35) {
                            $chunk = @\fread($psPipes[1], 256);
                            if (\is_string($chunk) && $chunk !== '') {
                                $buffer .= $chunk;
                                if (\str_contains($buffer, "\n")) {
                                    break;
                                }
                            } else {
                                \Weline\Framework\Runtime\SchedulerSystem::usleep(10_000);
                            }
                        }
                        $output = \trim($buffer);
                        @\fclose($psPipes[1]);
                    }
                    if (isset($psPipes[2])) {
                        @\stream_set_blocking($psPipes[2], false);
                        $bufferErr = '';
                        $startedAt = \microtime(true);
                        while ((\microtime(true) - $startedAt) < 0.1) {
                            $chunkErr = @\fread($psPipes[2], 256);
                            if (\is_string($chunkErr) && $chunkErr !== '') {
                                $bufferErr .= $chunkErr;
                            } else {
                                \Weline\Framework\Runtime\SchedulerSystem::usleep(10_000);
                            }
                        }
                        $stderr = \trim($bufferErr);
                        @\fclose($psPipes[2]);
                    }
                    $status = @\proc_get_status($psProcess);
                    self::finishWindowsDetachedHelperProcess($psProcess, $status);
                    @\unlink($scriptPath);

                    if (\is_numeric($output) && $output !== '' && (int)$output > 0) {
                        $pid = (int)$output;
                        $pid = self::setPid($pname, $pid);
                        return $pid;
                    }

                    if ($enableLog && $stderr !== '') {
                        self::setOutput($pname, "[ERROR] start-process failed: {$stderr}" . PHP_EOL);
                    }
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
                                \Weline\Framework\Runtime\SchedulerSystem::usleep(10_000);
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
     * Windows：用 PowerShell Start-Process，且 ArgumentList 为字符串数组，避免「整段命令行」经 ANSI/控制台编码后损坏（中文 BP、中文参数等），导致 PID 回传为空。
     *
     * @param list<string> $argv [php.exe 路径, 脚本绝对路径, ...脚本 argv]
     * @param string       $cwd  WorkingDirectory（一般为 BP）
     * @param string       $pnameForRegistry 与 {@see create()} 相同语义的命令行（须含 --name=），用于 setPid/日志
     */
    public static function createWindowsDetachedPhpArgv(
        array $argv,
        string $cwd,
        string $pnameForRegistry,
        ?bool $enableLog = null
    ): int {
        if (!IS_WIN || \count($argv) < 2) {
            return 0;
        }

        $disabledFunctions = \array_map('trim', \explode(',', \ini_get('disable_functions') ?: ''));
        if (!\function_exists('proc_open') || \in_array('proc_open', $disabledFunctions, true)) {
            return 0;
        }

        if ($enableLog === null) {
            $enableLog = self::isLogEnabled();
        }

        $processInfo = self::ensureProcessName($pnameForRegistry);
        $pname = $processInfo['command'];

        $scriptPath = self::writeWindowsStartScriptArgv($argv, $cwd);
        if ($scriptPath === null) {
            return 0;
        }

        $nullDevice = 'NUL';
        if ($enableLog) {
            self::setOutput(
                $pname,
                PHP_EOL . '--- Process started at ' . \date('Y-m-d H:i:s') . " (Windows argv Start-Process)\n" . $pname . PHP_EOL,
                true
            );
        }

        $descriptorspec = [
            0 => ['file', $nullDevice, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $lastError = null;
        \set_error_handler(static function ($type, $msg) use (&$lastError): bool {
            $lastError = $msg;

            return true;
        });
        try {
            $psProcess = \proc_open(
                self::buildWindowsPowerShellProcOpenCommand($scriptPath),
                $descriptorspec,
                $psPipes,
                $cwd,
                null,
                ['bypass_shell' => true]
            );
        } finally {
            \restore_error_handler();
        }

        $pid = 0;
        if (\is_resource($psProcess)) {
            $output = '';
            $stderr = '';
            // 非阻塞读取 PID 回传，避免 PowerShell 脚本异常挂起拖慢 Master 启动编排。
            if (isset($psPipes[1])) {
                @\stream_set_blocking($psPipes[1], false);
            }
            if (isset($psPipes[2])) {
                @\stream_set_blocking($psPipes[2], false);
            }
            $deadline = \microtime(true) + 0.35;
            while (\microtime(true) < $deadline) {
                if (isset($psPipes[1])) {
                    $chunk = @\fread($psPipes[1], 256);
                    if (\is_string($chunk) && $chunk !== '') {
                        $output .= $chunk;
                    }
                }
                if (isset($psPipes[2])) {
                    $chunkErr = @\fread($psPipes[2], 256);
                    if (\is_string($chunkErr) && $chunkErr !== '') {
                        $stderr .= $chunkErr;
                    }
                }
                if (\str_contains($output, "\n")) {
                    break;
                }
                \Weline\Framework\Runtime\SchedulerSystem::usleep(10_000);
            }
            $output = \trim($output);
            $output = \preg_replace('/^\xEF\xBB\xBF/', '', $output) ?? $output;
            $stderr = \trim($stderr);
            if (isset($psPipes[1])) {
                @\fclose($psPipes[1]);
            }
            if (isset($psPipes[2])) {
                @\fclose($psPipes[2]);
            }
            $status = @\proc_get_status($psProcess);
            self::finishWindowsDetachedHelperProcess($psProcess, $status);
            @\unlink($scriptPath);

            if ($output !== '' && \ctype_digit($output) && (int) $output > 0) {
                $pid = (int) $output;
                $pid = self::setPid($pname, $pid);
            }

            if ($enableLog && $stderr !== '') {
                self::setOutput($pname, "[ERROR] start-process(argv) failed: {$stderr}" . PHP_EOL);
            }
        }

        if ($enableLog && $lastError !== null) {
            self::setOutput($pname, "[ERROR] proc_open(powershell argv) failed: {$lastError}" . PHP_EOL);
        }

        return $pid;
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
        // 带 PID 的记录文件天然是一进程一文件，使用原子写可避免 Windows
        // 上阻塞 flock 把 Master 启动链卡死在 registerMasterPid()。
        self::atomicWrite($pid_file, $payload);
        
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

    private static function canOperateOnRegisteredPid(
        int $pid,
        ?string $expectedProcessName = null,
        string $expectedLaunchId = '',
        ?string $expectedPname = null
    ): bool {
        if ($pid <= 0) {
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
            if (!empty($record['launch_id']) && (string) $record['launch_id'] !== $expectedLaunchId) {
                return false;
            }
            $record['launch_id'] = $expectedLaunchId;
        }

        if ($record === []) {
            $pname = self::getNameByPid($pid);
            if ($pname !== 'unknown' && \strpos($pname, self::WELINE_PROCESS_PREFIX) !== false) {
                return true;
            }
            return self::isProcessManagerCreated($pid);
        }

        return self::doesRecordedPidIdentityAllowOperation($pid, $record);
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
        if (!self::canOperateOnRegisteredPid($pid, $expectedProcessName, $expectedLaunchId, $expectedPname)) {
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
        if (!self::canOperateOnRegisteredPid($pid, $expectedProcessName, $expectedLaunchId, $expectedPname)) {
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
            return (int) $pid;
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
            if (!\is_dir($dir)) {
                @\mkdir($dir, 0777, true);
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

        $pidIndex = self::filterPidIndexExistingJsonPaths(self::readPidIndex());
        self::writePidIndex($pidIndex);
        self::atomicUpdateNameIndex(
            static fn(array $nameIndex): array => self::filterNameIndexByPidIndex($nameIndex, $pidIndex)
        );
        
        return $removed;
    }

    /**
     * Cleanup only PID records touched by the current operation.
     *
     * The full cleanupStalePidFiles() path scans every *-pid.json and may need
     * command-line identity checks for many live Windows processes. Stop/restart
     * hot paths already know their candidate PID set, so they should avoid that
     * global sweep and remove only records whose candidate process is gone.
     *
     * @param list<int>|array<int> $pids
     */
    public static function cleanupStalePidFilesForPids(array $pids): int
    {
        $uniquePids = \array_values(\array_unique(\array_filter(
            \array_map('intval', $pids),
            static fn (int $pid): bool => $pid > 0
        )));
        if ($uniquePids === []) {
            return 0;
        }

        $pidIndex = self::readPidIndex();
        if ($pidIndex === []) {
            return 0;
        }

        $processInfo = self::batchGetProcessInfo($uniquePids);
        $removed = 0;
        $removedPids = [];
        $removedPorts = [];

        foreach ($uniquePids as $pid) {
            $record = \is_array($pidIndex[$pid] ?? null) ? $pidIndex[$pid] : [];
            $jsonPath = (string) ($record['jsonPath'] ?? '');
            if ($jsonPath === '' || !\is_file($jsonPath)) {
                continue;
            }

            $info = \is_array($processInfo[$pid] ?? null) ? $processInfo[$pid] : [];
            if ((bool) ($info['exists'] ?? false)) {
                continue;
            }

            $raw = @\file_get_contents($jsonPath);
            if ($raw !== false) {
                $data = \json_decode($raw, true);
                if (\is_array($data)) {
                    foreach ((array) ($data['ports'] ?? []) as $port) {
                        $port = (int) $port;
                        if ($port > 0) {
                            $removedPorts[$port] = true;
                        }
                    }
                }
            }

            @\unlink($jsonPath);
            unset($pidIndex[$pid]);
            $removedPids[$pid] = true;
            self::untrustPid($pid);
            $removed++;
        }

        if ($removed === 0) {
            return 0;
        }

        self::writePidIndex($pidIndex);

        $removedPidMap = $removedPids;
        self::atomicUpdateNameIndex(
            static function (array $nameIndex) use ($removedPidMap): array {
                foreach ($nameIndex as $pname => $entries) {
                    $kept = [];
                    foreach ((array) $entries as $entry) {
                        $pid = (int) ($entry['pid'] ?? 0);
                        if ($pid > 0 && !isset($removedPidMap[$pid])) {
                            $kept[] = $entry;
                        }
                    }
                    if ($kept === []) {
                        unset($nameIndex[$pname]);
                    } else {
                        $nameIndex[$pname] = \array_values($kept);
                    }
                }

                return $nameIndex;
            }
        );

        if ($removedPorts !== []) {
            $portIndex = self::readPortIndex();
            $changed = false;
            foreach (\array_keys($removedPorts) as $port) {
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
        if (!\is_array($record) || !self::doesRecordedPidIdentityAllowOperation($pid, $record)) {
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
        $pid = self::findPhpProcessPid($pname);
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
    /**
     * 销毁进程并清理所有相关资源
     *
     * 完整流程：
     * 1. 从 PID 文件获取进程 PID
     * 2. 验证进程是否为受信任的框架进程
     * 3. 杀死进程
     * 4. 清理 PID 文件和日志文件
     * 5. 从受信任缓存移除 PID
     *
     * @param string $pname 进程名或 --name=xxx 格式
     * @return bool 是否成功销毁
     */
    public static function destroy(string $pname): bool
    {
        // kill() 已经包含完整的清理逻辑（PID文件、日志文件、受信任缓存）
        return self::kill($pname);
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
            @\exec(
                'powershell -NoProfile -Command "(Get-CimInstance Win32_Process -Filter '
                . "'ProcessId={$pid}'"
                . ' -ErrorAction SilentlyContinue).ParentProcessId" 2>NUL',
                $out,
                $code
            );
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
        
        // 从受信任缓存移除（防止 PID 复用时误信任）
        self::untrustPid($pid);

        if ($pname && $pname !== 'unknown' && $result) {
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

        // 记录杀进程日志（先记录，失败时补充失败原因）
        $killAttemptLog = 'kill_tree_attempt';

        // 使用驱动执行 killProcessTree 操作
        $result = self::getDriver()->killProcessTree($pid);

        // 如果 killProcessTree 失败，添加重试机制（特别是 Windows 上 taskkill 可能因权限问题首次失败）
        if (!$result) {
            for ($retry = 0; $retry < 3; $retry++) {
                // 等待 500ms 后重试
                usleep(500000);
                $result = self::getDriver()->killProcessTree($pid);
                if ($result) {
                    $killAttemptLog = 'kill_tree_retry_success';
                    break;
                }
            }
        }

        // 如果 killProcessTree 仍然失败，尝试使用 killProcess（单进程杀死）作为回退
        if (!$result) {
            for ($retry = 0; $retry < 3; $retry++) {
                $result = self::getDriver()->killProcess($pid);
                if ($result) {
                    $killAttemptLog = 'kill_single_retry_success';
                    break;
                }
                usleep(500000);
            }
        }

        // 从受信任缓存移除（防止 PID 复用时误信任）
        self::untrustPid($pid);

        if ($pname && $pname !== 'unknown') {
            if ($result) {
                // 记录杀进程日志
                self::logLifecycleEvent('kill', $pname, $pid, $killAttemptLog);
                # 卸载pid文件
                self::removePidFile($pname);
                # 卸载日志文件
                self::removeLogFile($pname);
            } else {
                // 记录杀进程失败日志
                self::logLifecycleEvent('kill_failed', $pname, $pid, 'kill_tree_failed_after_retries');
            }
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
        
        self::getDriver()->sendSignal($pid, 15);
        if ($timeout > 0.0) {
            \Weline\Framework\Runtime\SchedulerSystem::usleep((int) \round($timeout * 1000000));
        }

        $result = self::getDriver()->killProcess($pid);
        
        $pname = self::getNameByPid($pid);
        if ($pname !== 'unknown') {
            self::logLifecycleEvent('graceful_kill', $pname, $pid, 'force killed after timeout');
            self::removePidFile($pname);
        }
        
        return $result;
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
            $validPids[] = $pid;
        }
        
        if (empty($validPids)) {
            return $result;
        }
        
        self::batchSendSignal($validPids, 15);
        if ($timeout > 0.0) {
            \Weline\Framework\Runtime\SchedulerSystem::usleep((int) \round($timeout * 1000000));
        }

        foreach ($validPids as $pid) {
            if (self::getDriver()->killProcess($pid)) {
                $result['killed']++;
                self::finalizeBatchGracefulKillPid($pid, 'force killed');
            } else {
                $result['failed']++;
                $result['remaining'][] = $pid;
            }
        }
        
        return $result;
    }

    /**
     * 批量强制终止进程树。
     *
     * Windows 下优先合并成单条 taskkill /T /F 命令，避免逐个树杀带来的长尾延迟。
     *
     * @param int[] $pids
     * @return array{killed: int, failed: int, remaining: int[]}
     */
    public static function batchKillProcessTrees(array $pids, bool $skipCheck = false): array
    {
        $result = [
            'killed' => 0,
            'failed' => 0,
            'remaining' => [],
        ];

        if (empty($pids)) {
            return $result;
        }

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
            $validPids[$pid] = $pid;
        }

        if ($validPids === []) {
            return $result;
        }

        if (IS_WIN) {
            $batched = self::batchKillProcessTreesWindows(\array_values($validPids));
            if ($batched !== null) {
                $result['killed'] += $batched['killed'];
                $result['failed'] += $batched['failed'];
                $result['remaining'] = $batched['remaining'];
                return $result;
            }
        }

        return self::batchKillProcessTreesPosix(\array_values($validPids));
    }

    /**
     * Dispatch process-tree termination without waiting for Windows taskkill to
     * finish walking every child. Callers must verify remaining PIDs separately.
     *
     * @param int[] $pids
     * @return array{killed: int, failed: int, remaining: int[]}
     */
    public static function dispatchBatchKillProcessTrees(array $pids, bool $skipCheck = false): array
    {
        $result = [
            'killed' => 0,
            'failed' => 0,
            'remaining' => [],
        ];

        if (empty($pids)) {
            return $result;
        }

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
            $validPids[$pid] = $pid;
        }

        if ($validPids === []) {
            return $result;
        }

        if (IS_WIN) {
            return self::dispatchBatchKillProcessTreesWindows(\array_values($validPids));
        }

        return self::batchKillProcessTrees(\array_values($validPids), true);
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

        if (IS_WIN) {
            throw new \RuntimeException(
                'Windows batch process creation is unavailable; refusing to fall back to serial create() startup.'
            );
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
        self::reapWindowsDetachedBatchHelpers();

        $disabledFunctions = \array_map('trim', \explode(',', \ini_get('disable_functions') ?: ''));
        $procOpenAvailable = \function_exists('proc_open') && !\in_array('proc_open', $disabledFunctions, true);
        if (!$procOpenAvailable) {
            return null;
        }

        $results = [];
        $batchLaunchItems = [];

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
                if ($existingPid > 0) {
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

            $arguments = '';
            $argumentList = [];
            $detachedPhpArgv = self::buildWindowsDetachedPhpArgvFromCommand($processCommand);
            if ($detachedPhpArgv !== []) {
                $phpBinary = (string) ($detachedPhpArgv[0] ?? PHP_BINARY);
                $argumentList = \array_values(\array_slice($detachedPhpArgv, 1));
                $arguments = \implode(' ', $argumentList);
            } else {
                [$phpBinary, $arguments] = self::splitPhpCommandForStartProcess($processCommand);
                $argumentList = self::tokenizeCommandLineArguments($arguments);
            }
            $processName = self::extractCommandLineArg($processCommand, 'name');
            $item = [
                'key' => (string) $key,
                'command' => $processCommand,
                'php' => $phpBinary,
                'arguments' => $arguments,
                'argument_list' => $argumentList,
                'process_name' => $processName,
                'cwd' => BP,
                'enable_log' => $enableLog,
                'block' => $block,
                'foreground' => $foreground,
            ];

            $batchLaunchItems[] = $item;
        }

        if ($batchLaunchItems === []) {
            return $results;
        }

        $resultPath = null;
        $errorPath = null;
        $scriptPath = null;
        $psProcess = null;
        $diagnostic = '';
        $output = '';
        $stderr = '';
        $waitForResults = false;

        if ($batchLaunchItems !== []) {
            $resultPath = \tempnam(\sys_get_temp_dir(), 'weline-batch-result-');
            $errorPath = \tempnam(\sys_get_temp_dir(), 'weline-batch-error-');
            if ($resultPath === false || $resultPath === '' || $errorPath === false || $errorPath === '') {
                return null;
            }
            $scriptPath = self::writeWindowsBatchCreateScript($batchLaunchItems, $resultPath, $errorPath);
            if ($scriptPath === null) {
                @\unlink($resultPath);
                @\unlink($errorPath);
                return null;
            }

            $nullDevice = 'NUL';
            $batchCommand = 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File '
                . \escapeshellarg($scriptPath);
            $waitForResults = \defined('WELINE_BATCH_CREATE_WAIT_RESULTS') && WELINE_BATCH_CREATE_WAIT_RESULTS;
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
                    @\unlink($scriptPath);
                    @\unlink($resultPath);
                    @\unlink($errorPath);
                    if ($lastError !== null) {
                        foreach ($batchLaunchItems as $item) {
                            if (!empty($item['enable_log'])) {
                                self::setOutput($item['command'], "[ERROR] batchCreate(proc_open powershell) failed: {$lastError}" . PHP_EOL, true);
                            }
                        }
                    }

                    return null;
                }
            }
        }

        if ($batchLaunchItems !== []) {
            if (\is_resource($psProcess) && $resultPath !== null) {
                // 对于 Master 的并发启动场景，不要等待所有进程完成报告，PowerShell 脚本已经启动了所有进程。
                // 快速返回让 Master 立即进入主循环和 IPC 监听，否则子进程启动后无法连接 Master。
                if (!$waitForResults) {
                    // 非等待模式：不等结果文件、不扫进程表、不做 PID 猜测。
                    // Master 后续只以 IPC REGISTER/READY 回填的真实 PID 为准。
                    // 这里保留 helper 资源，避免局部变量析构时退回阻塞性 proc_close()
                    // 或再次引入 cmd.exe 中间层。
                    self::rememberWindowsDetachedBatchHelper($psProcess, (string) $scriptPath, (string) $resultPath, (string) $errorPath);
                    $psProcess = null;
                } else {
                    // 等待模式：同步等待所有进程启动完成（仅在需要时启用）
                    self::waitForWindowsBatchCreateHelper($psProcess, $resultPath, \count($batchLaunchItems));
                }
            }

            // 在非等待模式下，不要尝试读取结果，因为 PowerShell 脚本还在运行
            if ($waitForResults) {
                $output = self::normalizeWindowsPowerShellPipeOutput((string) (@\file_get_contents((string) $resultPath) ?: ''));
                $stderr = self::normalizeWindowsPowerShellPipeOutput((string) (@\file_get_contents((string) $errorPath) ?: ''));
            } else {
                // 非等待模式：返回占位符 PID，进程已被启动但 PID 可能稍后才能获得
                $output = '';
                $stderr = '';
            }
            if ($waitForResults) {
                @\unlink((string) $scriptPath);
                @\unlink((string) $resultPath);
                @\unlink((string) $errorPath);
            }
            $diagnostic = \trim(\implode(' | ', \array_filter([$output, $stderr], static fn (string $text): bool => $text !== '')));
        }

        if (!$waitForResults) {
            foreach ($batchLaunchItems as $item) {
                $results[$item['key']] = 0;
            }
            foreach ($commands as $key => $config) {
                unset($config);
                if (!\array_key_exists($key, $results)) {
                    $results[$key] = 0;
                }
            }

            return $results;
        }

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

        $pidResolutionItems = self::collectLaunchItemsNeedingPidResolution(
            $batchLaunchItems,
            $pidMap,
            true
        );
        $resolvedPidMap = self::waitForManagedProcessLaunchBatch(
            $pidResolutionItems,
            5.0
        );

        foreach ($batchLaunchItems as $item) {
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
     * Blocking callers may wait longer for managed PID resolution. Non-blocking
     * WLS startup still needs a short best-effort PID pass, otherwise every
     * child returns 0 and upper layers fall into slow recovery/adoption paths.
     *
     * @param array<int, array{key: string, block?: bool}> $launchItems
     * @param array<string, int> $pidMap
     * @return array<int, array<string, mixed>>
     */
    private static function collectBlockingLaunchItemsNeedingPidResolution(array $launchItems, array $pidMap): array
    {
        return self::collectLaunchItemsNeedingPidResolution($launchItems, $pidMap, true);
    }

    /**
     * @param array<int, array{key: string, block?: bool}> $launchItems
     * @param array<string, int> $pidMap
     * @return array<int, array<string, mixed>>
     */
    private static function collectLaunchItemsNeedingPidResolution(
        array $launchItems,
        array $pidMap,
        bool $blockingOnly
    ): array
    {
        return \array_values(\array_filter(
            $launchItems,
            static fn (array $item): bool => (!$blockingOnly || (bool) ($item['block'] ?? false))
                && (int) ($pidMap[(string) ($item['key'] ?? '')] ?? 0) <= 0
        ));
    }

    private static function resolveWindowsBatchCreateNonBlockingPidResolutionTimeout(int $pendingCount): float
    {
        if ($pendingCount <= 0) {
            return 0.1;
        }

        $configured = (float) (Env::get('system.processer.windows_batch_create_nonblocking_pid_resolution_timeout_sec', 0) ?? 0);
        if ($configured > 0.0) {
            return \max(0.05, \min(3.0, $configured));
        }

        return \min(1.0, \max(0.2, 0.15 + ($pendingCount * 0.12)));
    }

    /**
     * Wait for the one-shot Windows batch helper as a batch, not per child.
     * The helper writes one result row per requested child after issuing all
     * Start-Process calls. If it hangs, we cap the wait and still let IPC
     * register/ready fill in child state later.
     *
     * @param resource $process
     */
    private static function waitForWindowsBatchCreateHelper($process, string $resultPath, int $expectedRows): void
    {
        if (!\is_resource($process)) {
            return;
        }

        $expectedRows = \max(1, $expectedRows);
        $timeoutSeconds = self::resolveWindowsBatchCreateHelperTimeout($expectedRows);
        $deadline = \microtime(true) + $timeoutSeconds;
        $status = @\proc_get_status($process);

        do {
            if (self::countWindowsBatchResultRows($resultPath) >= $expectedRows) {
                break;
            }

            $status = @\proc_get_status($process);
            if (($status['running'] ?? false) !== true) {
                break;
            }

            \Weline\Framework\Runtime\SchedulerSystem::usleep(20_000);
        } while (\microtime(true) < $deadline);

        $status = @\proc_get_status($process);
        if (($status['running'] ?? false) === true) {
            @\proc_terminate($process);
            $status = @\proc_get_status($process);
        }

        // Intentionally skip proc_close(): on Windows the helper can be reported
        // as exited while proc_close() still blocks behind the detached child
        // process/console plumbing. The caller already closed pipes and only
        // needs best-effort helper cleanup before the CLI exits.
    }

    private static function resolveWindowsBatchCreateHelperTimeout(int $expectedRows): float
    {
        $configured = (float) (Env::get('system.processer.windows_batch_create_helper_timeout_sec', 0) ?? 0);
        if ($configured > 0.0) {
            return \max(0.2, \min(10.0, $configured));
        }

        return \min(5.0, \max(0.75, 0.25 + ($expectedRows * 0.15)));
    }

    private static function countWindowsBatchResultRows(string $resultPath): int
    {
        if (!\is_file($resultPath)) {
            return 0;
        }

        $content = (string) (@\file_get_contents($resultPath) ?: '');
        $content = \trim(self::normalizeWindowsPowerShellPipeOutput($content));
        if ($content === '') {
            return 0;
        }

        $rows = \preg_split('/\r\n|\r|\n/', $content) ?: [];

        return \count(\array_filter($rows, static fn (string $row): bool => \trim($row) !== ''));
    }

    /**
     * Windows detached PowerShell helpers have occasionally blocked for minutes
     * inside proc_close() even after the child process was already started and
     * a PID had been returned. We prefer a small bounded wait here and skip the
     * blocking close path if the helper refuses to exit promptly.
     *
     * @param resource $process
     * @param array<string,mixed>|null $status
     */
    private static function finishWindowsDetachedHelperProcess($process, ?array $status = null): void
    {
        if (!\is_resource($process)) {
            return;
        }

        $status ??= @\proc_get_status($process);
        if (($status['running'] ?? false) === true) {
            @\proc_terminate($process);
            $deadline = \microtime(true) + 0.1;
            do {
                \Weline\Framework\Runtime\SchedulerSystem::usleep(10_000);
                $status = @\proc_get_status($process);
                if (($status['running'] ?? false) !== true) {
                    break;
                }
            } while (\microtime(true) < $deadline);
        }

        if (($status['running'] ?? false) === false) {
            @\proc_close($process);
        }
    }

    /**
     * @param array<int, array{key: string, command: string, php: string, arguments: string, process_name: string, cwd: string, enable_log: bool, foreground: bool}> $launchItems
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

        if (@\file_put_contents($scriptPath, self::WINDOWS_PS1_UTF8_BOM . $script) === false) {
            @\unlink($scriptPath);
            return null;
        }

        return $scriptPath;
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

                    if (!self::canOperateOnRegisteredPid(
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

            \Weline\Framework\Runtime\SchedulerSystem::usleep(100_000);
        } while (\microtime(true) < $deadline);

        return $resolved;
    }

    /**
     * Launch a visible Windows console for frontend workers without routing
     * through cmd.exe, then poll by command-line identity if PID echo is delayed.
     */
    private static function createWindowsForeground(string $pname, bool $enableLog, array $availableFunctions): int
    {
        if (empty($availableFunctions['proc_open'])) {
            if ($enableLog) {
                self::setOutput($pname, "[ERROR] windows foreground launch requires proc_open to bypass cmd.exe" . PHP_EOL, true);
            }

            return 0;
        }

        [$phpBinary, $arguments] = self::splitPhpCommandForStartProcess($pname);
        $windowTitle = self::resolveWindowsForegroundWindowTitle($pname);
        $result = self::launchWindowsForegroundPhpProcess($phpBinary, $arguments, BP, $windowTitle);
        $directPid = (int) ($result['pid'] ?? 0);
        if ($directPid > 0) {
            return self::setPid($pname, $directPid);
        }

        $phpPid = self::waitForManagedProcessLaunch($pname, 5.0);
        if ($phpPid > 0) {
            return self::setPid($pname, $phpPid);
        }
        if ($enableLog && (string) ($result['error'] ?? '') !== '') {
            self::setOutput($pname, "[WARN] windows foreground direct launch failed: " . (string) $result['error'] . PHP_EOL, true);
        }

        return 0;
    }

    /**
     * PowerShell 单引号字符串字面量（值内单引号加倍）。
     */
    private static function toPowerShellSingleQuoted(string $value): string
    {
        return "'" . \str_replace("'", "''", $value) . "'";
    }

    /**
     * @param list<string> $values
     */
    private static function buildPowerShellArrayLiteral(array $values): string
    {
        if ($values === []) {
            return '@()';
        }

        return "@(\n"
            . \implode(",\n", \array_map(
                static fn (string $value): string => '    ' . self::toPowerShellSingleQuoted($value),
                $values
            ))
            . "\n)";
    }

    /**
     * 生成使用 Start-Process -ArgumentList @(...) 的启动脚本（UTF-8 BOM），供 {@see createWindowsDetachedPhpArgv} 使用。
     *
     * @param list<string> $argv
     */
    private static function writeWindowsStartScriptArgv(array $argv, string $workingDir): ?string
    {
        if (\count($argv) < 2) {
            return null;
        }

        $argLines = [];
        for ($i = 1, $n = \count($argv); $i < $n; $i++) {
            $argLines[] = '    ' . self::toPowerShellSingleQuoted((string) $argv[$i]);
        }
        $argListBody = \implode(",\n", $argLines);

        $template = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$phpExe = __PHP__
$wd = __WD__
$argList = @(
__ARGS__
)
try {
    Set-Location -LiteralPath $wd -ErrorAction Stop
} catch {
    [Console]::Error.WriteLine("Failed to set working directory: $($_.Exception.Message)")
    exit 1
}
$startArgs = @{
    FilePath = $phpExe
    WindowStyle = 'Hidden'
    PassThru = $true
    ErrorAction = 'Stop'
    ArgumentList = $argList
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
            ['__PHP__', '__WD__', '__ARGS__'],
            [self::toPowerShellSingleQuoted((string) $argv[0]), self::toPowerShellSingleQuoted($workingDir), $argListBody],
            $template
        );

        $tmpBase = \tempnam(\sys_get_temp_dir(), 'weline-start-argv-');
        if ($tmpBase === false || $tmpBase === '') {
            return null;
        }

        $scriptPath = $tmpBase . '.ps1';
        @\unlink($tmpBase);

        if (@\file_put_contents($scriptPath, self::WINDOWS_PS1_UTF8_BOM . $script) === false) {
            @\unlink($scriptPath);

            return null;
        }

        return $scriptPath;
    }

    private static function writeWindowsStartScript(string $phpBinary, string $arguments, string $workingDir): ?string
    {
        $template = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$arguments = '__ARGUMENTS__'
$wd = '__WORKING_DIR__'
try {
    Set-Location -LiteralPath $wd -ErrorAction Stop
} catch {
    [Console]::Error.WriteLine("Failed to set working directory: $($_.Exception.Message)")
    exit 1
}
$startArgs = @{
    FilePath = '__PHP_BINARY__'
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

        if (@\file_put_contents($scriptPath, self::WINDOWS_PS1_UTF8_BOM . $script) === false) {
            @\unlink($scriptPath);
            return null;
        }

        return $scriptPath;
    }

    /**
     * @param list<string> $arguments
     */
    private static function writeWindowsForegroundPhpStartScript(
        string $phpBinary,
        array $arguments,
        string $workingDir,
        string $windowTitle = ''
    ): ?string {
        $windowTitle = self::normalizeWindowsForegroundWindowTitle($windowTitle);
        $argList = self::buildPowerShellArrayLiteral($arguments);
        $template = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$phpExe = __PHP__
$wd = __WD__
$windowTitle = __WINDOW_TITLE__
$argList = __ARGS__
try {
    if ($windowTitle -ne '') {
        $Host.UI.RawUI.WindowTitle = $windowTitle
    }
} catch {
}
try {
    Set-Location -LiteralPath $wd -ErrorAction Stop
} catch {
    [Console]::Error.WriteLine("Failed to set working directory: $($_.Exception.Message)")
    exit 1
}
$startArgs = @{
    FilePath = $phpExe
    WorkingDirectory = $wd
    WindowStyle = 'Normal'
    PassThru = $true
    ErrorAction = 'Stop'
}
if ($argList.Count -gt 0) {
    $startArgs.ArgumentList = $argList
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
            ['__PHP__', '__WD__', '__WINDOW_TITLE__', '__ARGS__'],
            [
                self::toPowerShellSingleQuoted($phpBinary),
                self::toPowerShellSingleQuoted($workingDir),
                self::toPowerShellSingleQuoted($windowTitle),
                $argList,
            ],
            $template
        );

        $tmpBase = \tempnam(\sys_get_temp_dir(), 'weline-foreground-php-');
        if ($tmpBase === false || $tmpBase === '') {
            return null;
        }

        $startScriptPath = $tmpBase . '.ps1';
        @\unlink($tmpBase);

        if (@\file_put_contents($startScriptPath, self::WINDOWS_PS1_UTF8_BOM . $script) === false) {
            @\unlink($startScriptPath);
            return null;
        }

        return $startScriptPath;
    }

    /**
     * @return array{pid:int,error:string}
     */
    private static function launchWindowsForegroundPhpProcess(
        string $phpBinary,
        string $arguments,
        string $workingDir,
        string $windowTitle = ''
    ): array {
        $argv = self::tokenizeCommandLineArguments($arguments);
        $startScriptPath = self::writeWindowsForegroundPhpStartScript($phpBinary, $argv, $workingDir, $windowTitle);
        if ($startScriptPath === null) {
            return ['pid' => 0, 'error' => 'failed to prepare foreground php start script'];
        }

        $descriptorspec = [
            0 => ['file', 'NUL', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $lastError = null;
        \set_error_handler(static function ($type, $msg) use (&$lastError): bool {
            $lastError = $msg;

            return true;
        });
        try {
            $psProcess = \proc_open(
                self::buildWindowsPowerShellProcOpenCommand($startScriptPath),
                $descriptorspec,
                $psPipes,
                $workingDir,
                null,
                ['bypass_shell' => true]
            );
        } finally {
            \restore_error_handler();
        }

        $pid = 0;
        $output = '';
        $stderr = '';
        if (\is_resource($psProcess)) {
            if (isset($psPipes[1])) {
                @\stream_set_blocking($psPipes[1], false);
            }
            if (isset($psPipes[2])) {
                @\stream_set_blocking($psPipes[2], false);
            }

            $deadline = \microtime(true) + 0.5;
            while (\microtime(true) < $deadline) {
                if (isset($psPipes[1])) {
                    $chunk = @\fread($psPipes[1], 512);
                    if (\is_string($chunk) && $chunk !== '') {
                        $output .= $chunk;
                    }
                }
                if (isset($psPipes[2])) {
                    $chunkErr = @\fread($psPipes[2], 512);
                    if (\is_string($chunkErr) && $chunkErr !== '') {
                        $stderr .= $chunkErr;
                    }
                }
                if (\str_contains($output, "\n")) {
                    break;
                }
                $status = @\proc_get_status($psProcess);
                if (($status['running'] ?? false) !== true) {
                    break;
                }
                \Weline\Framework\Runtime\SchedulerSystem::usleep(10_000);
            }

            $output = \trim(self::normalizeWindowsPowerShellPipeOutput($output));
            $output = \preg_replace('/^\xEF\xBB\xBF/', '', $output) ?? $output;
            $stderr = \trim(self::normalizeWindowsPowerShellPipeOutput($stderr));
            foreach (\preg_split('/\r\n|\r|\n/', $output) ?: [] as $line) {
                $line = \trim((string) $line);
                if ($line !== '' && \ctype_digit($line) && (int) $line > 0) {
                    $pid = (int) $line;
                    break;
                }
            }

            if (isset($psPipes[1])) {
                @\fclose($psPipes[1]);
            }
            if (isset($psPipes[2])) {
                @\fclose($psPipes[2]);
            }
            $status = @\proc_get_status($psProcess);
            self::finishWindowsDetachedHelperProcess($psProcess, $status);
        }

        @\unlink($startScriptPath);

        $error = \trim(\implode(' | ', \array_filter([
            (string) $lastError,
            $stderr,
        ], static fn (string $value): bool => $value !== '')));
        if ($error === '' && $pid <= 0) {
            $error = 'no pid returned';
        }

        return ['pid' => $pid, 'error' => $error];
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

                    if (self::canOperateOnRegisteredPid($candidatePid, $processName, $launchId, $pname)) {
                        return self::setPid($pname, $candidatePid);
                    }
                }
            }

            $pid = self::getPid($pname);
            if ($pid > 0) {
                return $pid;
            }

            \Weline\Framework\Runtime\SchedulerSystem::usleep(100_000);
        } while (\microtime(true) < $deadline);

        return 0;
    }

    private static function normalizeWindowsForegroundWindowTitle(string $windowTitle): string
    {
        $windowTitle = \trim(\str_replace(["\r", "\n"], ' ', $windowTitle));
        if ($windowTitle === '') {
            return 'weline-process';
        }

        $windowTitle = (string) \preg_replace('/[^A-Za-z0-9._-]+/', '-', $windowTitle);
        $windowTitle = \trim($windowTitle, '-');

        return $windowTitle !== '' ? $windowTitle : 'weline-process';
    }

    private static function resolveWindowsForegroundWindowTitle(string $pname): string
    {
        $windowTitle = self::extractCommandLineArg($pname, 'window-title');
        if ($windowTitle === '') {
            $windowTitle = self::extractCommandLineArg($pname, 'name');
        }
        if ($windowTitle === '') {
            $windowTitle = self::generateProcessName($pname);
        }

        return self::normalizeWindowsForegroundWindowTitle($windowTitle);
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
     * @param resource $process
     */
    private static function rememberWindowsDetachedBatchHelper($process, string $scriptPath, string $resultPath, string $errorPath): void
    {
        if (!\is_resource($process)) {
            return;
        }

        self::registerWindowsDetachedBatchHelperShutdown();
        self::$windowsDetachedBatchHelpers[] = [
            'process' => $process,
            'started_at' => \microtime(true),
            'script_path' => $scriptPath,
            'result_path' => $resultPath,
            'error_path' => $errorPath,
        ];
    }

    private static function registerWindowsDetachedBatchHelperShutdown(): void
    {
        if (self::$windowsDetachedBatchHelperShutdownRegistered) {
            return;
        }

        self::$windowsDetachedBatchHelperShutdownRegistered = true;
        \register_shutdown_function(static function (): void {
            self::reapWindowsDetachedBatchHelpers(true);
        });
    }

    private static function reapWindowsDetachedBatchHelpers(bool $force = false): void
    {
        if (self::$windowsDetachedBatchHelpers === []) {
            return;
        }

        $now = \microtime(true);
        foreach (self::$windowsDetachedBatchHelpers as $key => $helper) {
            $process = $helper['process'] ?? null;
            if (!\is_resource($process)) {
                self::cleanupWindowsDetachedBatchHelperFiles($helper);
                unset(self::$windowsDetachedBatchHelpers[$key]);
                continue;
            }

            $status = @\proc_get_status($process);
            $age = $now - (float) ($helper['started_at'] ?? $now);
            if (!$force && ($status['running'] ?? false) === true && $age < 10.0) {
                continue;
            }

            self::finishWindowsDetachedHelperProcess($process, $status);
            self::cleanupWindowsDetachedBatchHelperFiles($helper);
            unset(self::$windowsDetachedBatchHelpers[$key]);
        }

        if (self::$windowsDetachedBatchHelpers !== []) {
            self::$windowsDetachedBatchHelpers = \array_values(self::$windowsDetachedBatchHelpers);
        }
    }

    /**
     * @param array{script_path?: string, result_path?: string, error_path?: string} $helper
     */
    private static function cleanupWindowsDetachedBatchHelperFiles(array $helper): void
    {
        foreach (['script_path', 'result_path', 'error_path'] as $field) {
            $path = (string) ($helper[$field] ?? '');
            if ($path !== '') {
                @\unlink($path);
            }
        }
    }

    /**
     * @param array<int, array{key: string, command: string, php: string, arguments: string, argument_list?: list<string>, process_name: string, cwd: string, enable_log: bool, foreground: bool}> $launchItems
     */
    private static function buildWindowsBatchCreateScript(array $launchItems, string $resultPath, string $errorPath): ?string
    {
        $lines = [
            '$ErrorActionPreference = ' . self::toPowerShellSingleQuoted('Continue'),
            '$resultFile = ' . self::toPowerShellSingleQuoted($resultPath),
            '$errorFile = ' . self::toPowerShellSingleQuoted($errorPath),
            'Set-Content -LiteralPath $resultFile -Value ' . self::toPowerShellSingleQuoted('') . ' -NoNewline -Encoding UTF8',
            'Set-Content -LiteralPath $errorFile -Value ' . self::toPowerShellSingleQuoted('') . ' -NoNewline -Encoding UTF8',
            'function Add-WelineResult([string]$key, [int]$pid) { Add-Content -LiteralPath $resultFile -Value ($key + "`t" + [string]$pid) -Encoding UTF8 }',
            'function Add-WelineError([string]$key, [string]$message) { if ($message -ne ' . self::toPowerShellSingleQuoted('') . ') { Add-Content -LiteralPath $errorFile -Value ($key + ": " + $message) -Encoding UTF8 } }',
        ];

        foreach ($launchItems as $item) {
            $key = (string) ($item['key'] ?? '');
            $php = (string) ($item['php'] ?? '');
            $cwd = (string) ($item['cwd'] ?? BP);
            $argumentList = $item['argument_list'] ?? self::tokenizeCommandLineArguments((string) ($item['arguments'] ?? ''));
            $arguments = \array_map(static fn (mixed $argument): string => (string) $argument, $argumentList);
            $foreground = !empty($item['foreground']);

            if ($key === '' || $php === '') {
                return null;
            }

            $lines[] = 'try {';
            $lines[] = '    $startArgs = @{';
            $lines[] = '        FilePath = ' . self::toPowerShellSingleQuoted($php);
            $lines[] = '        WorkingDirectory = ' . self::toPowerShellSingleQuoted($cwd);
            $lines[] = '        WindowStyle = ' . self::toPowerShellSingleQuoted($foreground ? 'Normal' : 'Hidden');
            $lines[] = '        PassThru = $true';
            $lines[] = '        ErrorAction = ' . self::toPowerShellSingleQuoted('Stop');
            $lines[] = '    }';
            $lines[] = '    $argList = ' . self::buildPowerShellArrayLiteral($arguments);
            $lines[] = '    if ($argList.Count -gt 0) { $startArgs.ArgumentList = $argList }';
            $lines[] = '    $p = Start-Process @startArgs';
            $lines[] = '    Add-WelineResult ' . self::toPowerShellSingleQuoted($key) . ' ([int]$p.Id)';
            $lines[] = '} catch {';
            $lines[] = '    Add-WelineError ' . self::toPowerShellSingleQuoted($key) . ' ([string]$_.Exception.Message)';
            $lines[] = '    Add-WelineResult ' . self::toPowerShellSingleQuoted($key) . ' 0';
            $lines[] = '}';
        }
        $lines[] = 'Remove-Item -LiteralPath $PSCommandPath -Force -ErrorAction SilentlyContinue';

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
     * 将完整命令行拆为 argv 列表，供 Windows Start-Process ArgumentList 使用。
     *
     * @return list<string>
     */
    private static function buildWindowsDetachedPhpArgvFromCommand(string $command): array
    {
        [$phpBinary, $arguments] = self::splitPhpCommandForStartProcess($command);
        $tokens = self::tokenizeCommandLineArguments($arguments);
        if ($tokens === []) {
            return [];
        }

        return [$phpBinary, ...$tokens];
    }

    /**
     * 命令行参数轻量分词（支持双引号包裹参数）。
     *
     * @return list<string>
     */
    private static function tokenizeCommandLineArguments(string $arguments): array
    {
        $arguments = \trim($arguments);
        if ($arguments === '') {
            return [];
        }

        \preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|(\\S+)/', $arguments, $matches, \PREG_SET_ORDER);
        $tokens = [];
        foreach ($matches as $m) {
            if (isset($m[1]) && $m[1] !== '') {
                $tokens[] = self::decodeQuotedCommandLineToken($m[1]);
                continue;
            }
            if (isset($m[2]) && $m[2] !== '') {
                $tokens[] = $m[2];
            }
        }

        return $tokens;
    }

    private static function decodeQuotedCommandLineToken(string $token): string
    {
        // Windows command lines commonly carry absolute paths like
        // "E:\...\var\tmp\foo.php" or "...\\bin\\dispatcher.php".
        // stripcslashes() would turn \t/\b/\n into control chars and corrupt
        // the script path before Start-Process receives it.
        return \str_replace(['\\"', '\\\\'], ['"', '\\'], $token);
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

        if (IS_WIN) {
            $batched = self::batchSendSignalWindows($pids, $signal);
            if ($batched !== null) {
                return $batched;
            }
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
     * 批量派发信号但不等待子进程真正退出。
     *
     * stopAll 阶段 3 只负责把终止意图尽快派发出去，实际退出校验统一交给后续阶段，
     * 避免 Master 在 Windows 上同步等待 taskkill 完成而阻塞控制面。
     *
     * @param int[] $pids
     * @return array<int, bool>
     */
    public static function dispatchBatchSignal(array $pids, int $signal = 15): array
    {
        if (empty($pids)) {
            return [];
        }

        if (IS_WIN) {
            $dispatched = self::dispatchBatchSignalWindows($pids, $signal);
            if ($dispatched !== null) {
                return $dispatched;
            }
        }

        return self::batchSendSignal($pids, $signal);
    }

    /**
     * @param int[] $pids
     * @return array<int, bool>|null
     */
    private static function batchSendSignalWindows(array $pids, int $signal): ?array
    {
        if (!\in_array($signal, [9, 15], true)) {
            return null;
        }

        $validPids = [];
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if ($pid > 0) {
                $validPids[$pid] = $pid;
            }
        }

        if ($validPids === []) {
            return [];
        }

        $results = [];
        foreach (\array_chunk(\array_values($validPids), 32) as $chunk) {
            $command = self::buildWindowsBatchSignalCommand($chunk);
            $output = [];
            $returnCode = 0;
            self::execute($command, $output, $returnCode);
            \Weline\Framework\Runtime\SchedulerSystem::usleep(200000);

            $running = self::batchCheckRunning($chunk);
            foreach ($chunk as $pid) {
                $results[$pid] = !($running[$pid] ?? false);
            }
        }

        return $results;
    }

    /**
     * @param int[] $pids
     * @return array<int, bool>|null
     */
    private static function dispatchBatchSignalWindows(array $pids, int $signal): ?array
    {
        if (!\in_array($signal, [9, 15], true)) {
            return null;
        }

        $validPids = [];
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if ($pid > 0) {
                $validPids[$pid] = $pid;
            }
        }

        if ($validPids === []) {
            return [];
        }

        $results = [];
        foreach (\array_chunk(\array_values($validPids), 32) as $chunk) {
            $command = self::buildWindowsAsyncBatchSignalCommand($chunk);
            $output = [];
            $returnCode = 0;
            self::execute($command, $output, $returnCode);

            $chunkSucceeded = ($returnCode === 0);
            foreach ($chunk as $pid) {
                $results[$pid] = $chunkSucceeded;
            }
        }

        return $results;
    }

    /**
     * @param int[] $pids
     * @return array{killed: int, failed: int, remaining: int[]}|null
     */
    private static function batchKillProcessTreesWindows(array $pids): ?array
    {
        if ($pids === []) {
            return [
                'killed' => 0,
                'failed' => 0,
                'remaining' => [],
            ];
        }

        $result = [
            'killed' => 0,
            'failed' => 0,
            'remaining' => [],
        ];

        $pids = \array_values($pids);
        $command = self::buildWindowsBatchTreeKillCommand($pids);
        $output = [];
        $returnCode = 0;
        self::execute($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $result['failed'] += \count($pids);
            $result['remaining'] = $pids;
            return $result;
        }

        foreach ($pids as $pid) {
            $result['killed']++;
            self::finalizeBatchGracefulKillPid($pid, 'force tree kill dispatched');
        }

        return $result;
    }

    /**
     * @param int[] $pids
     * @return array{killed: int, failed: int, remaining: int[]}
     */
    private static function dispatchBatchKillProcessTreesWindows(array $pids): array
    {
        $result = [
            'killed' => 0,
            'failed' => 0,
            'remaining' => [],
        ];
        if ($pids === []) {
            return $result;
        }

        $command = self::buildWindowsAsyncBatchTreeKillCommand($pids);
        $output = [];
        $returnCode = 0;
        self::execute($command, $output, $returnCode);
        if ($returnCode !== 0) {
            $result['failed'] += \count($pids);
            $result['remaining'] = $pids;
            return $result;
        }

        foreach ($pids as $pid) {
            $result['killed']++;
            self::finalizeBatchGracefulKillPid((int) $pid, 'force tree kill dispatched async');
        }

        return $result;
    }

    /**
     * @param int[] $pids
     * @return array{killed: int, failed: int, remaining: int[]}
     */
    private static function batchKillProcessTreesPosix(array $pids): array
    {
        $rootPids = \array_values(\array_unique(\array_filter(
            \array_map('intval', $pids),
            static fn (int $pid): bool => $pid > 0
        )));

        $result = [
            'killed' => 0,
            'failed' => 0,
            'remaining' => [],
        ];
        if ($rootPids === []) {
            return $result;
        }

        $targetPids = self::collectBatchProcessTreePids($rootPids);
        if ($targetPids === []) {
            return $result;
        }

        self::sendPosixSignalToPids($targetPids, 15);
        \Weline\Framework\Runtime\SchedulerSystem::usleep(150000);

        $runningAfterTerm = self::partitionRunningPids($targetPids)['running'];
        if ($runningAfterTerm !== []) {
            self::sendPosixSignalToPids($runningAfterTerm, 9);
            \Weline\Framework\Runtime\SchedulerSystem::usleep(150000);
        }

        $remainingTargetPids = self::partitionRunningPids($targetPids)['running'];
        if ($remainingTargetPids !== []) {
            self::sendPosixSignalToPidsWithSudo($remainingTargetPids, 9);
            \Weline\Framework\Runtime\SchedulerSystem::usleep(150000);
            $remainingTargetPids = self::partitionRunningPids($targetPids)['running'];
        }

        $remainingMap = \array_fill_keys($remainingTargetPids, true);
        foreach ($rootPids as $pid) {
            if (isset($remainingMap[$pid])) {
                $result['failed']++;
                $result['remaining'][] = $pid;
                continue;
            }
            $result['killed']++;
            self::finalizeBatchGracefulKillPid($pid, 'force tree kill confirmed');
        }
        foreach ($remainingTargetPids as $pid) {
            if (!\in_array($pid, $rootPids, true)) {
                $result['remaining'][] = $pid;
            }
        }
        $result['remaining'] = \array_values(\array_unique(\array_map('intval', $result['remaining'])));

        return $result;
    }

    /**
     * @param int[] $pids
     */
    private static function sendPosixSignalToPids(array $pids, int $signal): void
    {
        $pids = \array_values(\array_unique(\array_filter(
            \array_map('intval', $pids),
            static fn (int $pid): bool => $pid > 0
        )));
        if ($pids === []) {
            return;
        }

        if (\function_exists('posix_kill')) {
            foreach ($pids as $pid) {
                @\posix_kill($pid, $signal);
            }
            return;
        }

        @\exec(self::buildPosixKillCommand($pids, $signal));
    }

    /**
     * @param int[] $pids
     */
    private static function sendPosixSignalToPidsWithSudo(array $pids, int $signal): void
    {
        $pids = \array_values(\array_unique(\array_filter(
            \array_map('intval', $pids),
            static fn (int $pid): bool => $pid > 0
        )));
        if ($pids === []) {
            return;
        }

        @\exec(self::buildPosixKillCommand($pids, $signal, true));
    }

    /**
     * @param int[] $pids
     */
    private static function buildPosixKillCommand(array $pids, int $signal, bool $sudo = false): string
    {
        $pidArgs = \implode(' ', \array_values(\array_unique(\array_filter(
            \array_map('intval', $pids),
            static fn (int $pid): bool => $pid > 0
        ))));

        return ($sudo ? 'sudo -n ' : '') . 'kill -' . $signal . ' ' . $pidArgs . ' 2>/dev/null';
    }

    /**
     * @param int[] $rootPids
     * @return int[]
     */
    private static function collectBatchProcessTreePids(array $rootPids): array
    {
        $rootPids = \array_values(\array_unique(\array_filter(
            \array_map('intval', $rootPids),
            static fn (int $pid): bool => $pid > 0
        )));
        if ($rootPids === []) {
            return [];
        }

        $childrenByParent = [];
        $lines = [];
        @\exec('ps -eo pid=,ppid= 2>/dev/null', $lines);
        foreach ($lines as $line) {
            $parts = \preg_split('/\s+/', \trim((string) $line));
            if (\count($parts) < 2) {
                continue;
            }
            $pid = (int) $parts[0];
            $ppid = (int) $parts[1];
            if ($pid <= 0 || $ppid <= 0) {
                continue;
            }
            $childrenByParent[$ppid][] = $pid;
        }

        $targets = [];
        $stack = $rootPids;
        while ($stack !== []) {
            $pid = (int) \array_pop($stack);
            if ($pid <= 0 || isset($targets[$pid])) {
                continue;
            }
            $targets[$pid] = true;
            foreach (($childrenByParent[$pid] ?? []) as $childPid) {
                $stack[] = (int) $childPid;
            }
        }

        return \array_map('intval', \array_keys($targets));
    }

    /**
     * @param int[] $pids
     */
    private static function buildWindowsBatchSignalCommand(array $pids): string
    {
        $pidArgs = [];
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if ($pid > 0) {
                $pidArgs[] = '/PID ' . $pid;
            }
        }

        return 'taskkill /F ' . \implode(' ', $pidArgs) . ' 2>NUL';
    }

    /**
     * @param int[] $pids
     */
    private static function buildWindowsAsyncBatchSignalCommand(array $pids): string
    {
        $pidArgs = [];
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if ($pid > 0) {
                $pidArgs[] = '/PID ' . $pid;
            }
        }

        return self::buildWindowsBatchSignalCommand($pids);
    }

    /**
     * @param int[] $pids
     */
    private static function buildWindowsAsyncBatchTreeKillCommand(array $pids): string
    {
        $pidArgs = [];
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if ($pid > 0) {
                $pidArgs[] = '/PID ' . $pid;
            }
        }

        return self::buildWindowsBatchTreeKillCommand($pids);
    }

    /**
     * @param int[] $pids
     */
    private static function buildWindowsBatchTreeKillCommand(array $pids): string
    {
        $pidArgs = [];
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if ($pid > 0) {
                $pidArgs[] = '/PID ' . $pid;
            }
        }

        return 'taskkill /F /T ' . \implode(' ', $pidArgs) . ' 2>NUL';
    }

    /**
     * @param int[] $pids
     * @return array{running: int[], exited: int[]}
     */
    private static function partitionRunningPids(array $pids): array
    {
        $state = [
            'running' => [],
            'exited' => [],
        ];

        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }

            if (self::isRunningByPid($pid)) {
                $state['running'][] = $pid;
            } else {
                $state['exited'][] = $pid;
            }
        }

        return $state;
    }

    private static function finalizeBatchGracefulKillPid(int $pid, string $detail): void
    {
        $pname = self::getNameByPid($pid);
        if ($pname === 'unknown') {
            return;
        }

        self::logLifecycleEvent('batch_kill', $pname, $pid, $detail);
        self::removePidFile($pname);
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
        $validPids = [];
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if ($pid > 0) {
                $validPids[$pid] = $pid;
            }
        }

        if ($validPids === []) {
            return $result;
        }

        if (IS_WIN) {
            $fastResult = self::batchCheckRunningWindows(\array_values($validPids));
            if ($fastResult !== null) {
                return $fastResult;
            }
        }

        $processInfo = self::batchGetProcessInfo(\array_values($validPids));
        foreach ($validPids as $pid) {
            $result[$pid] = (bool) ($processInfo[$pid]['exists'] ?? false);
        }

        return $result;
    }

    /**
     * Windows 下小批量 PID 查询优先走 tasklist /FI，避免每次 stop 都扫描全量进程表。
     *
     * @param int[] $pids
     * @return array<int, bool>|null
     */
    private static function batchCheckRunningWindows(array $pids): ?array
    {
        if (\count($pids) === 0) {
            return [];
        }

        if (\count($pids) > 16) {
            return null;
        }

        $results = [];
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }

            $output = [];
            $returnCode = 0;
            self::execute("tasklist /FI \"PID eq {$pid}\" /FO CSV /NH 2>NUL", $output, $returnCode);

            $results[$pid] = false;
            foreach ($output as $line) {
                $parts = \str_getcsv(\trim($line), ',', '"', '');
                if (\count($parts) < 2) {
                    continue;
                }

                if ((int) \preg_replace('/[^\d]/', '', (string) $parts[1]) === $pid) {
                    $results[$pid] = true;
                    break;
                }
            }
        }

        return $results;
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
                \Weline\Framework\Runtime\SchedulerSystem::usleep(100000); // 100ms
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
        unset($maxRetries);
        if ($processName === '' || \strpos($processName, self::WELINE_PROCESS_PREFIX) === false) {
            return false;
        }

        $pname = '--name=' . $processName;
        $pid = (int) self::getData($pname, 'pid');
        if ($pid > 0) {
            return self::killManagedProcess($pid, $processName, '', $pname);
        }

        $pids = self::getProcessIdsByName($processName);
        if ($pids === []) {
            self::removePidFile($pname);
            return true;
        }

        $result = self::batchKillProcessTrees($pids, true);
        if (($result['failed'] ?? 0) === 0 && ($result['remaining'] ?? []) === []) {
            self::removePidFile($pname);
            return true;
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
                \Weline\Framework\Runtime\SchedulerSystem::usleep(500000);
                continue;
            }
            
            // 己方进程校验：非己方进程拒绝杀
            if (!self::isProcessManagerCreated($pid)) {
                return false;
            }
            
            // 杀死进程
            self::killByPid($pid, true); // skipCheck=true 因为已经校验过
            
            // 等待进程退出
            \Weline\Framework\Runtime\SchedulerSystem::usleep(300000); // 300ms
            
            // 验证端口是否已释放
            if (!self::isPortInUse($port)) {
                return true;
            }
            
            // 未释放，等待更长时间后重试
            \Weline\Framework\Runtime\SchedulerSystem::usleep(200000); // 200ms
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
            // rename 在同目录下通常是原子操作；Windows 目标已存在时 rename
            // 可能失败，因此这里补一层可恢复覆盖逻辑。
            if (@\rename($tmpPath, $path)) {
                return true;
            }
            if (\is_file($path) && @\unlink($path) && @\rename($tmpPath, $path)) {
                return true;
            }
            @\unlink($tmpPath);
            return false;
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

        $identitySource = $pname;
        if ($pid !== \getmypid()) {
            $cmdLine = self::getProcessCommandLine($pid);
            if ($cmdLine !== '') {
                $identitySource = $cmdLine;
                $record['command_line_hash'] = \sha1($cmdLine);
            }
        }

        $processName = self::extractCommandLineArg($identitySource, 'name');
        if ($processName !== '') {
            $record['process_name'] = self::normalizeName($processName);
        }

        $launchId = self::extractCommandLineArg($identitySource, 'launch-id');
        if ($launchId !== '') {
            $record['launch_id'] = $launchId;
        }

        $epoch = self::extractCommandLineArg($identitySource, 'epoch');
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

    /**
     * @param array<int, array{pname: string, jsonPath: string}> $pidIndex
     * @return array<int, array{pname: string, jsonPath: string}>
     */
    private static function filterPidIndexExistingJsonPaths(array $pidIndex): array
    {
        $filtered = [];
        foreach ($pidIndex as $pid => $record) {
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }

            $pname = (string) ($record['pname'] ?? '');
            $jsonPath = (string) ($record['jsonPath'] ?? '');
            if ($pname === '' || $jsonPath === '' || !\is_file($jsonPath)) {
                continue;
            }

            $filtered[$pid] = [
                'pname' => $pname,
                'jsonPath' => $jsonPath,
            ];
        }

        return $filtered;
    }

    /**
     * @param array<string, list<array{pid: int, jsonPath: string}>> $nameIndex
     * @param array<int, array{pname: string, jsonPath: string}> $pidIndex
     * @return array<string, list<array{pid: int, jsonPath: string}>>
     */
    private static function filterNameIndexByPidIndex(array $nameIndex, array $pidIndex): array
    {
        if ($nameIndex === [] || $pidIndex === []) {
            return [];
        }

        $filtered = [];
        foreach ($nameIndex as $pname => $entries) {
            $validEntries = [];
            foreach ($entries as $entry) {
                $pid = (int) ($entry['pid'] ?? 0);
                if ($pid <= 0 || !isset($pidIndex[$pid])) {
                    continue;
                }

                $pidRecord = $pidIndex[$pid];
                $jsonPath = (string) ($entry['jsonPath'] ?? '');
                if ($jsonPath === ''
                    || $jsonPath !== (string) ($pidRecord['jsonPath'] ?? '')
                    || $pname !== (string) ($pidRecord['pname'] ?? '')) {
                    continue;
                }

                $validEntries[] = [
                    'pid' => $pid,
                    'jsonPath' => $jsonPath,
                ];
            }

            if ($validEntries !== []) {
                $filtered[$pname] = \array_values($validEntries);
            }
        }

        return $filtered;
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
            $expectedCommandLineHash = (string) ($record['command_line_hash'] ?? '');
            if ($expectedCommandLineHash !== '' && \sha1($cmdLine) === $expectedCommandLineHash) {
                return true;
            }

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

    private static function doesRecordedPidIdentityAllowOperation(int $pid, array $record): bool
    {
        if ($pid <= 0) {
            return false;
        }

        $expectedPnameKey = (string) ($record['pname_key'] ?? '');
        if ($expectedPnameKey === '' && !empty($record['pname'])) {
            $expectedPnameKey = self::buildPnameKey((string) $record['pname']);
        }

        $pidIndex = self::readPidIndex();
        $indexedPname = (string) ($pidIndex[$pid]['pname'] ?? '');
        if ($expectedPnameKey !== '' && $indexedPname !== ''
            && self::buildPnameKey($indexedPname) !== $expectedPnameKey) {
            return false;
        }

        foreach ([
            (string) ($record['pname'] ?? ''),
            (string) ($record['task_name'] ?? ''),
            self::getRecordedProcessName($record),
            $indexedPname,
        ] as $identity) {
            if ($identity !== '' && \strpos($identity, self::WELINE_PROCESS_PREFIX) !== false) {
                return true;
            }
        }

        return false;
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
                if ($entryPid > 0) {
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
                    if ($pid > 0) {
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
            $processName = self::extractCommandLineArg($cmdLine, 'name');
            if ($processName !== '' && \str_starts_with(self::normalizeName($processName), self::WELINE_PROCESS_PREFIX)) {
                return true;
            }
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
            $sharedServiceFlag = self::extractCommandLineArg($cmdLine, 'shared-service');
            if ($sharedServiceFlag === '1' && \strpos($cmdLine, 'session_server.php') !== false) {
                return true;
            }
            if (\strpos($cmdLine, 'weline-wls-session-') !== false || \strpos($cmdLine, 'weline-wls-memory-') !== false) {
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
     * 返回字段：
     * - in_use / pid / pid_running / is_weline / state（既有契约，向后兼容）
     * - pname  从 port_index / cmdline / pid_index 还原出的最完整进程标识，便于上层识别归属
     * - scope  WLS 进程的项目作用域 token（如 p16330cac），命中 weline 时尽量填；非 weline 或无法识别时为空字符串
     *
     * @return array{
     *   in_use:bool,
     *   pid:int,
     *   pid_running:bool,
     *   is_weline:bool,
     *   state:string,
     *   pname:string,
     *   scope:string
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
                'pname' => '',
                'scope' => '',
            ];
        }

        $portIndexSuggestsWeline = false;
        $portIndexPname = '';
        $portKey = (string) $port;
        $portIndex = self::readPortIndex();
        if (isset($portIndex[$portKey])) {
            $portIndexPname = (string) $portIndex[$portKey];
            $portIndexSuggestsWeline = (\strpos($portIndexPname, self::WELINE_PROCESS_PREFIX) !== false)
                || (\strpos($portIndexPname, '--name=' . self::WELINE_PROCESS_PREFIX) !== false);
        }

        $pid = self::getProcessIdByPort($port);
        if ($pid <= 0) {
            return [
                'in_use' => true,
                'pid' => 0,
                'pid_running' => false,
                'is_weline' => false,
                'state' => 'orphan',
                'pname' => $portIndexPname,
                'scope' => self::extractProjectScopeFromProcessName($portIndexPname),
            ];
        }

        $pidRunning = self::isRunningByPid($pid);
        if (!$pidRunning) {
            return [
                'in_use' => true,
                'pid' => $pid,
                'pid_running' => false,
                'is_weline' => false,
                'state' => 'orphan',
                'pname' => $portIndexPname,
                'scope' => self::extractProjectScopeFromProcessName($portIndexPname),
            ];
        }

        $isWeline = $portIndexSuggestsWeline || self::isWelineServerProcess($pid);

        // 命中 weline 时尽量补齐 pname，便于上层按项目作用域辨别归属。
        $pname = $portIndexPname;
        if ($pname === '' && $isWeline) {
            $cmdLine = self::getProcessCommandLine($pid);
            if ($cmdLine !== '') {
                $pname = $cmdLine;
            } else {
                $indexed = self::getNameByPid($pid);
                if ($indexed !== 'unknown' && $indexed !== '') {
                    $pname = $indexed;
                }
            }
        }

        return [
            'in_use' => true,
            'pid' => $pid,
            'pid_running' => true,
            'is_weline' => $isWeline,
            'state' => $isWeline ? 'weline' : 'foreign',
            'pname' => $pname,
            'scope' => self::extractProjectScopeFromProcessName($pname),
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
        if ($pid > 0 && self::canOperateOnRegisteredPid($pid, $processName, '', $pname)) {
            $pids[$pid] = true;
        }

        // 慢路径：系统级按命令行搜索，但必须再次校验受管身份，避免误命中仅“提到”该 name 的其它进程。
        foreach (self::getDriver()->findProcessesByName($processName) as $candidatePid) {
            $candidatePid = (int) $candidatePid;
            if ($candidatePid <= 0) {
                continue;
            }
            if (self::canOperateOnRegisteredPid($candidatePid, $processName, '', $pname)) {
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
     * 枚举指定前缀下当前仍存活的受管 Weline 进程 PID。
     *
     * @return list<int>
     */
    public static function getProcessIdsByPrefix(string $prefix): array
    {
        if (empty($prefix) || \strpos($prefix, self::WELINE_PROCESS_PREFIX) === false) {
            return [];
        }

        $pids = [];
        $currentPid = \getmypid();
        $nameIndex = self::readNameIndex();
        foreach ($nameIndex as $pname => $entries) {
            $taskName = '';
            try {
                $taskName = self::getTaskName($pname);
            } catch (\Throwable) {
                continue;
            }

            if (!\str_starts_with($taskName, $prefix) && !\str_starts_with($pname, '--name=' . $prefix)) {
                continue;
            }

            foreach ($entries as $entry) {
                $pid = (int) ($entry['pid'] ?? 0);
                if ($pid <= 0 || $pid === $currentPid) {
                    continue;
                }

                if (self::canOperateOnRegisteredPid($pid, $taskName !== '' ? $taskName : null, '', $pname)) {
                    $pids[$pid] = true;
                }
            }
        }
        return \array_map('intval', \array_keys($pids));
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
        return self::killByProcessNamePrefixes([$prefix]);
    }

    /**
     * 按多个进程名前缀一次性批量杀进程树。
     *
     * @param list<string> $prefixes
     * @return int 成功杀死的进程数
     */
    public static function killByProcessNamePrefixes(array $prefixes): int
    {
        $prefixes = \array_values(\array_unique(\array_filter(
            \array_map('strval', $prefixes),
            static fn (string $prefix): bool => $prefix !== ''
                && \strpos($prefix, self::WELINE_PROCESS_PREFIX) !== false
        )));
        // 安全校验：prefix 必须包含 weline-
        if ($prefixes === []) {
            return 0;
        }
        
        $targetPids = [];
        $currentPid = \getmypid();
        
        // 1. 从 name_index 读取所有进程
        $nameIndex = self::readNameIndex();
        
        // 2. 按前缀匹配收集所有 PID（一对多）
        foreach ($nameIndex as $pname => $entries) {
            $taskName = '';
            try {
                $taskName = self::getTaskName($pname);
            } catch (\Throwable) {
                $taskName = \str_starts_with($pname, '--name=')
                    ? \substr($pname, 7)
                    : $pname;
            }

            foreach ($prefixes as $prefix) {
                if (!\str_starts_with($taskName, $prefix) && !\str_starts_with($pname, '--name=' . $prefix)) {
                    continue;
                }

                foreach ($entries as $entry) {
                    $pid = (int) ($entry['pid'] ?? 0);
                    if ($pid <= 0 || $pid === $currentPid) {
                        continue;
                    }
                    $targetPids[$pid] = true;
                }

                break;
            }
        }
        if ($targetPids === []) {
            return 0;
        }

        $result = self::batchKillProcessTrees(\array_map('intval', \array_keys($targetPids)), true);
        
        return (int) ($result['killed'] ?? 0);
    }

    /**
     * 从进程命令行提取 --name=xxx 中的任务名。
     */
    private static function extractTaskNameFromProcessCommand(string $commandLine): string
    {
        $commandLine = \trim($commandLine);
        if ($commandLine === '') {
            return '';
        }
        if (!\preg_match('/--name=("([^"]+)"|\'([^\']+)\'|([^\\s"]+))/', $commandLine, $matches)) {
            return '';
        }
        $raw = (string) ($matches[2] ?? $matches[3] ?? $matches[4] ?? '');
        return \trim($raw);
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
    private static function executeWindowsCommandBypassShell(string $command, array &$output, int &$returnCode): bool
    {
        $isWin = \defined('IS_WIN')
            ? (bool) \constant('IS_WIN')
            : (\strtoupper(\substr(PHP_OS, 0, 3)) === 'WIN');
        if (!$isWin || !\function_exists('proc_open')) {
            return false;
        }

        [$command, $mergeStderr] = self::stripWindowsCommandRedirections($command);
        if ($command === '' || self::containsWindowsShellSyntax($command)) {
            return false;
        }

        $argv = self::tokenizeCommandLineArguments($command);
        if ($argv === []) {
            return false;
        }

        $program = \strtolower(\basename(\str_replace('\\', '/', (string) ($argv[0] ?? ''))));
        $directPrograms = [
            'powershell' => true,
            'powershell.exe' => true,
            'taskkill' => true,
            'taskkill.exe' => true,
            'tasklist' => true,
            'tasklist.exe' => true,
            'wmic' => true,
            'wmic.exe' => true,
            'netstat' => true,
            'netstat.exe' => true,
        ];
        if (!isset($directPrograms[$program])) {
            return false;
        }

        $descriptors = [
            0 => ['file', 'NUL', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @\proc_open($argv, $descriptors, $pipes, BP, null, ['bypass_shell' => true]);
        if (!\is_resource($process)) {
            return false;
        }

        $stdout = isset($pipes[1]) ? (string) (\stream_get_contents($pipes[1]) ?: '') : '';
        $stderr = isset($pipes[2]) ? (string) (\stream_get_contents($pipes[2]) ?: '') : '';
        foreach ([0, 1, 2] as $pipeIndex) {
            if (isset($pipes[$pipeIndex]) && \is_resource($pipes[$pipeIndex])) {
                @\fclose($pipes[$pipeIndex]);
            }
        }

        $returnCode = @\proc_close($process);
        if ($mergeStderr || $stderr !== '') {
            if ($stdout !== '' && !\str_ends_with($stdout, "\n") && !\str_ends_with($stdout, "\r")) {
                $stdout .= PHP_EOL;
            }
            $stdout .= $stderr;
        }

        $stdout = self::normalizeWindowsPowerShellPipeOutput($stdout);
        $output = $stdout !== ''
            ? (\preg_split('/\r\n|\r|\n/', \rtrim($stdout, "\r\n")) ?: [])
            : [];

        return true;
    }

    /**
     * @return array{0:string,1:bool}
     */
    private static function stripWindowsCommandRedirections(string $command): array
    {
        $mergeStderr = false;
        foreach (['/\s+2>\s*NUL\b/i', '/\s+1>\s*NUL\b/i', '/\s+>\s*NUL\b/i'] as $pattern) {
            $command = \preg_replace($pattern, '', $command) ?? $command;
        }
        $command = \preg_replace_callback(
            '/\s+2>\s*&1\b/i',
            static function () use (&$mergeStderr): string {
                $mergeStderr = true;
                return '';
            },
            $command
        ) ?? $command;

        return [\trim($command), $mergeStderr];
    }

    private static function containsWindowsShellSyntax(string $command): bool
    {
        $inQuotes = false;
        $length = \strlen($command);
        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];
            if ($char === '"') {
                $inQuotes = !$inQuotes;
                continue;
            }

            if (!$inQuotes && \in_array($char, ['|', '&', '<', '>'], true)) {
                return true;
            }
        }

        return $inQuotes;
    }

    public static function execute(string $command, array &$output = [], int &$returnCode = 0): bool
    {
        if (self::executeWindowsCommandBypassShell($command, $output, $returnCode)) {
            return $returnCode === 0;
        }

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
