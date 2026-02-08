<?php
declare(strict_types=1);

namespace Weline\Framework\System\Process\Driver;

/**
 * 进程驱动抽象基类
 * 
 * 提供所有驱动共享的默认实现和辅助方法。
 * 子类只需覆盖特定于操作系统的方法。
 * 
 * 参考 Symfony Process 组件和 AMPHP Process 的跨平台最佳实践：
 * - 函数可用性检测结果缓存（避免重复调用 ini_get）
 * - 统一的 socket 端口检测（跨平台，毫秒级）
 * - 多层回退的命令执行策略
 * - 完善的信号常量定义
 */
abstract class AbstractProcessDriver implements ProcessDriverInterface
{
    /**
     * 可用函数缓存（单例级别，避免每次调用都检测）
     * 参考 Symfony Process 的做法：构造时检测一次，后续直接使用
     */
    private static ?array $availableFunctionsCache = null;
    
    /**
     * POSIX 信号常量（参考 Symfony Process $exitCodes 表）
     */
    public const SIGTERM = 15;   // 优雅终止
    public const SIGKILL = 9;    // 强制终止
    public const SIGINT  = 2;    // 中断信号
    public const SIGHUP  = 1;    // 挂起信号
    
    /**
     * 进程杀死时的默认超时（毫秒）
     * 参考 Symfony Process 的 TIMEOUT_PRECISION，给予进程足够的退出时间
     */
    protected const KILL_TIMEOUT_MS = 1000;
    
    /**
     * socket 端口检测超时（秒）
     * 参考 Symfony Process 的做法：使用短超时避免阻塞
     */
    protected const SOCKET_CONNECT_TIMEOUT = 0.1;
    
    /**
     * 检测可用的进程执行函数（带缓存）
     * 
     * 参考 Symfony Process 的做法：
     * - 在构造函数中检测 proc_open 是否可用
     * - 同时检查 disable_functions，处理共享主机环境
     * 
     * @return array{exec: bool, shell_exec: bool, proc_open: bool, popen: bool, pcntl_fork: bool, posix_kill: bool}
     */
    protected function detectAvailableFunctions(): array
    {
        if (self::$availableFunctionsCache !== null) {
            return self::$availableFunctionsCache;
        }
        
        $disabled = \array_map('trim', \explode(',', \ini_get('disable_functions') ?: ''));
        
        self::$availableFunctionsCache = [
            'exec'        => \function_exists('exec') && !\in_array('exec', $disabled, true),
            'shell_exec'  => \function_exists('shell_exec') && !\in_array('shell_exec', $disabled, true),
            'proc_open'   => \function_exists('proc_open') && !\in_array('proc_open', $disabled, true),
            'popen'       => \function_exists('popen') && !\in_array('popen', $disabled, true),
            'pcntl_fork'  => \function_exists('pcntl_fork') && !\in_array('pcntl_fork', $disabled, true),
            'posix_kill'  => \function_exists('posix_kill') && !\in_array('posix_kill', $disabled, true),
            'posix_getpgid' => \function_exists('posix_getpgid') && !\in_array('posix_getpgid', $disabled, true),
        ];
        
        return self::$availableFunctionsCache;
    }
    
    /**
     * 重置函数缓存（测试用）
     */
    public static function resetFunctionsCache(): void
    {
        self::$availableFunctionsCache = null;
    }
    
    /**
     * 执行命令并返回输出
     * 
     * 多层回退策略（参考 Symfony Process）：
     * 1. exec()     - 最常用，直接获取输出和退出码
     * 2. proc_open  - 更灵活，可控制 stdin/stdout/stderr
     * 3. shell_exec - 最后手段，无法获取退出码
     * 
     * @param string $command 命令
     * @param array &$output 输出数组（引用）
     * @param int &$exitCode 退出码（引用）
     * @return bool 是否成功执行
     */
    protected function executeCommand(string $command, array &$output = [], int &$exitCode = 0): bool
    {
        $functions = $this->detectAvailableFunctions();
        
        if ($functions['exec']) {
            @\exec($command, $output, $exitCode);
            return true;
        }
        
        if ($functions['proc_open']) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            // 参考 Symfony：使用 set_error_handler 捕获 proc_open 错误
            $lastError = null;
            \set_error_handler(function ($type, $msg) use (&$lastError) {
                $lastError = $msg;
                return true;
            });
            try {
                $process = \proc_open($command, $descriptors, $pipes);
            } finally {
                \restore_error_handler();
            }
            if (\is_resource($process)) {
                $result = \stream_get_contents($pipes[1]);
                \fclose($pipes[0]);
                \fclose($pipes[1]);
                \fclose($pipes[2]);
                $exitCode = \proc_close($process);
                $output = $result !== false && $result !== '' 
                    ? \explode("\n", \rtrim($result, "\n")) 
                    : [];
                return true;
            }
        }
        
        if ($functions['shell_exec']) {
            $result = @\shell_exec($command);
            if ($result !== null) {
                $output = $result !== '' 
                    ? \explode("\n", \rtrim($result, "\n")) 
                    : [];
                $exitCode = 0;
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 使用 socket 检测端口是否被占用（跨平台，高效）
     * 
     * 参考 Symfony/AMPHP 的做法：
     * - 使用 stream_socket_client 尝试连接
     * - 连接成功 = 端口在使用
     * - 连接被拒绝 = 端口未使用
     * - 超时/其他错误 = 需要回退到系统命令
     * 
     * @param int $port 端口号
     * @param string $host 主机地址
     * @return bool|null true=在使用, false=未使用, null=无法确定（需要回退）
     */
    protected function socketPortCheck(int $port, string $host = '127.0.0.1'): ?bool
    {
        $socket = @\stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            static::SOCKET_CONNECT_TIMEOUT,
            \STREAM_CLIENT_CONNECT
        );
        
        if ($socket !== false) {
            @\fclose($socket);
            return true; // 端口有响应 = 在使用
        }
        
        // Windows: WSAECONNREFUSED = 10061
        // Linux:   ECONNREFUSED = 111
        // macOS:   ECONNREFUSED = 61
        if (\in_array($errno, [10061, 111, 61], true)) {
            return false; // 连接被拒绝 = 端口未使用
        }
        
        // 也可能是进程正在监听但繁忙（errno=0 超时等），尝试 bind 测试
        // 注意：socket_create 需要 PHP sockets 扩展，未加载时会 Fatal Error
        if (\function_exists('socket_create')) {
            $testSocket = @\socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP);
            if ($testSocket !== false) {
                @\socket_set_option($testSocket, \SOL_SOCKET, \SO_REUSEADDR, 1);
                $bindResult = @\socket_bind($testSocket, $host, $port);
                @\socket_close($testSocket);
                if ($bindResult) {
                    return false; // 可以绑定 = 端口未使用
                }
                return true; // 无法绑定 = 端口在使用
            }
        }
        
        return null; // 无法确定，需要回退到系统命令
    }
    
    /**
     * 默认的进程信息结构
     * 
     * @param int $pid
     * @return array
     */
    protected function getDefaultProcessInfo(int $pid): array
    {
        return [
            'pid' => $pid,
            'exists' => false,
            'name' => '',
            'command' => '',
            'memory' => '',
            'cpu' => '',
            'start_time' => ''
        ];
    }
    
    /**
     * 等待指定毫秒
     * 
     * @param int $ms 毫秒
     */
    protected function waitMs(int $ms): void
    {
        \usleep($ms * 1000);
    }
    
    /**
     * 检查 PID 是否有效
     * 
     * @param int $pid
     * @return bool
     */
    protected function isValidPid(int $pid): bool
    {
        return $pid > 0;
    }
    
    /**
     * 转义用于 shell 命令的字符串
     * 
     * @param string $arg
     * @return string
     */
    protected function escapeShellArg(string $arg): string
    {
        return \escapeshellarg($arg);
    }
    
    /**
     * 安全地清理 PID 值
     * 
     * @param mixed $value 原始 PID 值
     * @return int 清理后的 PID，无效返回 0
     */
    protected function sanitizePid(mixed $value): int
    {
        if ($value === null || $value === '' || $value === false) {
            return 0;
        }
        $pid = (int) \trim((string) $value);
        return $pid > 0 ? $pid : 0;
    }
    
    /**
     * 使用 proc_open 启动进程并获取 PID（跨平台）
     * 
     * 参考 Symfony Process 的核心做法：
     * - proc_open 是唯一可靠的跨平台进程启动方式
     * - 使用 set_error_handler 捕获启动错误
     * - Windows 上使用 bypass_shell 选项避免 cmd.exe 包装
     * 
     * @param string $command 命令
     * @param string|null $cwd 工作目录
     * @param array $options proc_open 选项
     * @return array{process: resource|false, pid: int, pipes: array} 
     */
    protected function procOpenProcess(string $command, ?string $cwd = null, array $options = []): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],   // stdin
            1 => ['pipe', 'w'],   // stdout
            2 => ['pipe', 'w'],   // stderr
        ];
        
        // 参考 Symfony Process：使用 bypass_shell 避免 Windows cmd.exe 包装问题
        $defaultOptions = ['suppress_errors' => true];
        if ('\\' === \DIRECTORY_SEPARATOR) {
            $defaultOptions['bypass_shell'] = true;
        }
        $options = \array_merge($defaultOptions, $options);
        
        // 参考 Symfony：使用 set_error_handler 捕获 proc_open 内部错误
        $lastError = null;
        \set_error_handler(function ($type, $msg) use (&$lastError) {
            $lastError = $msg;
            return true;
        });
        
        try {
            $process = @\proc_open($command, $descriptors, $pipes, $cwd, null, $options);
        } finally {
            \restore_error_handler();
        }
        
        $pid = 0;
        if (\is_resource($process)) {
            $status = @\proc_get_status($process);
            $pid = isset($status['pid']) ? (int) $status['pid'] : 0;
        }
        
        return [
            'process' => $process ?: false,
            'pid' => $pid,
            'pipes' => $pipes ?? [],
            'error' => $lastError,
        ];
    }
    
    /**
     * 关闭 proc_open 返回的管道
     * 
     * @param array $pipes 管道数组
     */
    protected function closePipes(array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                @\fclose($pipe);
            }
        }
    }
    
    /**
     * 清除端口检测缓存（默认空实现，子类可覆盖）
     * 
     * @param int|null $port 指定端口，null 清除全部
     */
    public function clearPortCache(?int $port = null): void
    {
        // 默认实现：无缓存，无需清除
        // 子类如 WindowsProcessDriver、LinuxProcessDriver 有缓存时覆盖此方法
    }
}
