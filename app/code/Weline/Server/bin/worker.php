<?php
declare(strict_types=1);

/**
 * Weline Server Worker 独立进程
 * 
 * 用法: php worker.php <host> <port> <worker_id> [instance_name]
 * 
 * 该 Worker 进程集成框架路由，支持完整的 HTTP 请求处理
 * 包含健康检查接口 /_wls/health（仅本地访问）
 * 维护模式由框架自动处理
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

// 获取参数
$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 9981);
$workerId = (int) ($argv[3] ?? 1);
$instanceName = $argv[4] ?? 'default';

// 解析命令行参数
$processName = '';
$isFrontend = false;
$useReusePort = false;  // 是否使用 SO_REUSEPORT（Linux 直连模式）

foreach ($argv as $arg) {
    if (\str_starts_with($arg, '--name=')) {
        $processName = \substr($arg, 7);
    } elseif ($arg === '--frontend' || $arg === '-frontend') {
        $isFrontend = true;
    } elseif ($arg === '--reuseport' || $arg === '-reuseport') {
        $useReusePort = true;
    } elseif (\str_starts_with($arg, '--control-port=')) {
        $controlPort = (int)\substr($arg, 15);
    } elseif ($arg === '--maintenance') {
        $isMaintenanceWorker = true;
    } elseif (\str_starts_with($arg, '--master-pid=')) {
        $masterPid = (int)\substr($arg, 13);
    }
}

// IPC 控制端口（未显式传入时从实例文件推算）
if (!isset($controlPort)) {
    $controlPort = 0;
}
// Master PID（用于孤儿检测）
if (!isset($masterPid) || $masterPid <= 0) {
    $masterPid = 0;
}
// 是否为维护 Worker
if (!isset($isMaintenanceWorker)) {
    $isMaintenanceWorker = false;
}

// 检测根目录
$bp = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;
if (!\defined('BP')) {
    \define('BP', $bp);
}
if (!\defined('DS')) {
    \define('DS', DIRECTORY_SEPARATOR);
}

// 定义前端模式常量（供 WlsRuntime 使用）
if ($isFrontend && !\defined('WLS_FRONTEND_MODE')) {
    \define('WLS_FRONTEND_MODE', true);
}

// 预读 env.php 判断开发模式（在框架初始化前定义，供 WlsRequest 等使用）
$_wlsEnvFile = BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
$_wlsEnvConfig = \is_file($_wlsEnvFile) ? @include $_wlsEnvFile : [];
$_wlsDevMode = ($_wlsEnvConfig['deploy'] ?? '') === 'dev';
if (!\defined('WLS_DEV_MODE')) {
    \define('WLS_DEV_MODE', $_wlsDevMode);
}
unset($_wlsEnvFile, $_wlsEnvConfig, $_wlsDevMode);

// 统一自动加载：app/code 优先于 vendor（与 app/bootstrap.php 共用 app/autoload.php）
require_once BP . 'app' . DIRECTORY_SEPARATOR . 'autoload.php';

// ========== 进程日志文件（持久化，跨重启保留） ==========
// Worker 自身负责将错误和关键日志写入 var/process/{processName}.log
// 确保即使 Windows 隐藏窗口或 Linux 重定向丢失，日志也不会丢
$processLogFile = '';
if ($processName) {
    $processLogDir = BP . 'var' . DIRECTORY_SEPARATOR . 'process';
    if (!\is_dir($processLogDir)) {
        @\mkdir($processLogDir, 0777, true);
    }
    $processLogFile = $processLogDir . DIRECTORY_SEPARATOR . $processName . '.log';
    // 将 PHP error_log() 重定向到进程日志文件（追加模式）
    \ini_set('error_log', $processLogFile);
}

// 预先读取 env.php 中的 deploy 配置（备用方案，用于在 App::init() 之前检测 DEV 模式）
$envConfig = null;
$envFile = BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
if (\is_file($envFile)) {
    $envConfig = @include $envFile;
}

// Origin Token 回源校验配置（可选安全增强）
$originToken = '';
$originTokenValidationEnabled = false;
$originTokenHeader = 'X-Weline-Origin-Token';
$originTokenAllowLocal = true;
if (\is_array($envConfig)) {
    $originToken = (string)($envConfig['server']['origin_token'] ?? '');
    $originValidationConfig = $envConfig['server']['origin_token_validation'] ?? [];
    if (\is_array($originValidationConfig)) {
        $originTokenValidationEnabled = (bool)($originValidationConfig['enabled'] ?? false);
        $originTokenHeader = (string)($originValidationConfig['header'] ?? $originTokenHeader);
        $originTokenAllowLocal = (bool)($originValidationConfig['allow_local'] ?? true);
    }
}

// ========== WLS 高性能日志系统（缓冲模式） ==========
// 日志缓冲区（避免每次都 fwrite + fflush）
$logBuffer = [];
$logBufferSize = 0;
$logBufferMaxSize = 50;        // 缓冲区最大条数
$logBufferMaxBytes = 8192;     // 缓冲区最大字节数（8KB）
$logLastFlushTime = \microtime(true);
$logFlushInterval = 0.5;       // 刷新间隔（秒）

// 检测模式（只检测一次）
$isDev = false;
if (\defined('DEV') && DEV) {
    $isDev = true;
} elseif ($envConfig !== null && isset($envConfig['deploy']) && $envConfig['deploy'] === 'dev') {
    $isDev = true;
}
$shouldLog = $isFrontend || $isDev;

// 日志刷新函数
$flushLog = function (bool $force = false) use (&$logBuffer, &$logBufferSize, &$logLastFlushTime, $logBufferMaxSize, $logBufferMaxBytes, $logFlushInterval, $isDev, $processLogFile) {
    if (empty($logBuffer)) {
        return;
    }
    
    $now = \microtime(true);
    $shouldFlush = $force 
        || \count($logBuffer) >= $logBufferMaxSize 
        || $logBufferSize >= $logBufferMaxBytes
        || ($now - $logLastFlushTime) >= $logFlushInterval;
    
    if (!$shouldFlush) {
        return;
    }
    
    // 批量写入控制台
    $output = \implode('', $logBuffer);
    if (\defined('STDOUT') && \is_resource(STDOUT)) {
        \fwrite(STDOUT, $output);
        // 只在批量刷新时调用 fflush，大幅减少 I/O 次数
        \fflush(STDOUT);
    }
    
    // DEV 模式：写入进程日志文件（追加模式，跨重启保留，便于排查问题）
    // 去除 ANSI 颜色码后写入文件，便于阅读
    // 注：崩溃/致命错误由 error_log() 和 shutdown handler 始终记录，此处仅记录打印日志
    if ($isDev && $processLogFile) {
        $cleanOutput = \preg_replace('/\033\[[0-9;]*m/', '', $output);
        @\file_put_contents($processLogFile, $cleanOutput, \FILE_APPEND);
    }
    
    // DEV 模式：额外写入 wls.log（便于统一查看所有 Worker 日志）
    if ($isDev) {
        $logFile = BP . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'wls.log';
        $logDir = \dirname($logFile);
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }
        // 批量写入，减少文件锁竞争
        @\file_put_contents($logFile, $output, \FILE_APPEND);
    }
    
    // 重置缓冲区
    $logBuffer = [];
    $logBufferSize = 0;
    $logLastFlushTime = $now;
};

// WLS 日志函数（缓冲模式）
$wlsLog = function (string $message, string $level = 'INFO') use (&$logBuffer, &$logBufferSize, $shouldLog, $workerId, $instanceName, $flushLog) {
    if (!$shouldLog) {
        return; // 非日志模式，直接返回
    }
    
    $timestamp = \date('Y-m-d H:i:s');
    $color = match($level) {
        'ERROR'  => "\033[31m",        // 红色：错误
        'WARN'   => "\033[33m",        // 黄色：警告
        'INFO'   => "\033[36m",        // 青色：一般信息
        'IPC'    => "\033[95m",        // 亮洋红：IPC 通信
        'ROUTE'  => "\033[32m",        // 绿色：路由
        'DRAIN'  => "\033[93m",        // 亮黄色：排空/恢复
        'HEALTH' => "\033[94m",        // 亮蓝色：健康检查
        'STATS'  => "\033[90m",        // 暗灰色：统计
        'DEBUG'  => "\033[90m",        // 暗灰色：调试
        default  => "\033[0m",
    };
    $logMessage = "{$color}[{$timestamp}] [WLS] Worker #{$workerId} ({$instanceName}) [{$level}] {$message}\033[0m\n";
    
    // 添加到缓冲区
    $logBuffer[] = $logMessage;
    $logBufferSize += \strlen($logMessage);
    
    // ERROR 级别立即刷新
    if ($level === 'ERROR') {
        $flushLog(true);
    } else {
        // 检查是否需要刷新
        $flushLog(false);
    }
};
// ========== 日志系统结束 ==========

// 注册 PID 到 Processer（启用快速 PID 查找）
if ($processName) {
    \Weline\Framework\System\Process\Processer::setPid('--name=' . $processName, \getmypid());
    // 注册监听端口（启用快速端口→PID 查找）
    if ($port > 0) {
        \Weline\Framework\System\Process\Processer::setProcessPorts('--name=' . $processName, [$port]);
    }
}

// 初始化路由提示服务（用于 TCP 透传模式下的智能路由）
\Weline\Server\Service\RouteHintService::init($port, true, 3600);

// 初始化框架运行时
$runtime = null;
$runtimeError = null;

try {
    $wlsLog("Worker 启动，监听 tcp://{$host}:{$port}", 'INFO');
    $runtime = new \Weline\Framework\Runtime\WlsRuntime();
    $runtime->bootstrap();
    $wlsLog("框架运行时初始化成功", 'INFO');
} catch (\Throwable $e) {
    $runtimeError = $e->getMessage();
    $wlsLog("框架运行时初始化失败: " . $e->getMessage(), 'ERROR');
    \error_log('[WLS Worker] Bootstrap error: ' . $e->getMessage());
}

// ========== WLS 内存缓存配置（智能模式） ==========
$wlsCacheConfig = [];
if ($envConfig !== null && isset($envConfig['server']['cache'])) {
    $wlsCacheConfig = $envConfig['server']['cache'];
}

// 内存检测函数（与 worker_ssl.php 共用逻辑）
if (!\function_exists('getSystemFreeMemory')) {
    function getSystemFreeMemory(): int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = @\shell_exec('wmic OS get FreePhysicalMemory /value 2>nul');
            if ($output && \preg_match('/FreePhysicalMemory=(\d+)/', $output, $matches)) {
                return (int)$matches[1] * 1024;
            }
        } else {
            if (\is_readable('/proc/meminfo')) {
                $meminfo = @\file_get_contents('/proc/meminfo');
                if ($meminfo && \preg_match('/MemAvailable:\s*(\d+)\s*kB/i', $meminfo, $matches)) {
                    return (int)$matches[1] * 1024;
                }
                if ($meminfo) {
                    $free = 0;
                    if (\preg_match('/MemFree:\s*(\d+)\s*kB/i', $meminfo, $m)) $free += (int)$m[1];
                    if (\preg_match('/Cached:\s*(\d+)\s*kB/i', $meminfo, $m)) $free += (int)$m[1];
                    if (\preg_match('/Buffers:\s*(\d+)\s*kB/i', $meminfo, $m)) $free += (int)$m[1];
                    if ($free > 0) return $free * 1024;
                }
            }
            // macOS: vm_stat 仅 "Pages free" 偏小，需加上可回收的 inactive/speculative（与 Linux MemAvailable 语义一致）
            // 注意：macOS 可能输出千位逗号（如 "1,234,567"），需去掉逗号再转 int，否则会误判为内存严重不足
            $output = @\shell_exec('vm_stat 2>/dev/null');
            if ($output) {
                $pageSize = 4096;
                $parse = static function (string $text, string $key): int {
                    if (!\preg_match('/' . \preg_quote($key, '/') . ':\s*([\d,\.]+)/', $text, $m)) {
                        return 0;
                    }
                    return (int)\str_replace([',', '.'], '', $m[1]);
                };
                $free = $parse($output, 'Pages free');
                $inactive = $parse($output, 'Pages inactive');
                $speculative = $parse($output, 'Pages speculative');
                $availablePages = $free + $inactive + $speculative;
                if ($availablePages > 0) {
                    return $availablePages * $pageSize;
                }
            }
        }
        return 0;
    }
}

if (!\function_exists('getSystemTotalMemory')) {
    function getSystemTotalMemory(): int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = @\shell_exec('wmic ComputerSystem get TotalPhysicalMemory /value 2>nul');
            if ($output && \preg_match('/TotalPhysicalMemory=(\d+)/', $output, $matches)) {
                return (int)$matches[1];
            }
        } else {
            if (\is_readable('/proc/meminfo')) {
                $meminfo = @\file_get_contents('/proc/meminfo');
                if ($meminfo && \preg_match('/MemTotal:\s*(\d+)\s*kB/i', $meminfo, $matches)) {
                    return (int)$matches[1] * 1024;
                }
            }
            $output = @\shell_exec('sysctl -n hw.memsize 2>/dev/null');
            if ($output) return (int)\trim($output);
        }
        return 4 * 1024 * 1024 * 1024;
    }
}

if (!\function_exists('calculateCacheSize')) {
    function calculateCacheSize(string|int $configValue, int $defaultPercent, int $defaultMin, int $defaultMax): int
    {
        if (\is_int($configValue)) return $configValue;
        $configValue = \strtolower(\trim($configValue));
        if ($configValue === 'auto' || $configValue === '') {
            $totalMem = getSystemTotalMemory();
            $calculated = (int)($totalMem * $defaultPercent / 100);
            return \max($defaultMin, \min($defaultMax, $calculated));
        }
        if (\preg_match('/^(\d+(?:\.\d+)?)\s*(k|kb|m|mb|g|gb)?$/i', $configValue, $matches)) {
            $value = (float)$matches[1];
            $unit = \strtolower($matches[2] ?? '');
            return match($unit) {
                'k', 'kb' => (int)($value * 1024),
                'm', 'mb' => (int)($value * 1024 * 1024),
                'g', 'gb' => (int)($value * 1024 * 1024 * 1024),
                default => (int)$value,
            };
        }
        return $defaultMin;
    }
}

$staticFileCacheMaxTotalConfig = $wlsCacheConfig['static_file_max_total'] ?? 'auto';
$WLS_STATIC_CACHE_MAX_TOTAL = calculateCacheSize($staticFileCacheMaxTotalConfig, 2, 32 * 1024 * 1024, 256 * 1024 * 1024);
// H13: 提高默认值到 2MB，支持大型 JS 库如 CKEditor
$staticFileCacheMaxSizeConfig = $wlsCacheConfig['static_file_max_size'] ?? '2M';
$WLS_STATIC_CACHE_MAX_SIZE = calculateCacheSize($staticFileCacheMaxSizeConfig, 0, 512 * 1024, 10 * 1024 * 1024);
$WLS_CACHE_EVICTION_THRESHOLD = (int)($wlsCacheConfig['eviction_threshold'] ?? 5 * 1024 * 1024);

// 内存检查
$freeMemory = getSystemFreeMemory();
$requiredMemory = $WLS_STATIC_CACHE_MAX_TOTAL + 50 * 1024 * 1024;
if ($freeMemory > 0 && $freeMemory < $requiredMemory) {
    $freeMB = \round($freeMemory / 1024 / 1024, 1);
    $requiredMB = \round($requiredMemory / 1024 / 1024, 1);
    $wlsLog("内存不足警告：可用 {$freeMB}MB，需要 {$requiredMB}MB", 'WARN');
    if ($freeMemory < $requiredMemory * 0.5) {
        $wlsLog("内存严重不足，无法启动", 'ERROR');
        exit(1);
    }
    $WLS_STATIC_CACHE_MAX_TOTAL = (int)($freeMemory * 0.6);
    $wlsLog("自动缩减缓存至 " . \round($WLS_STATIC_CACHE_MAX_TOTAL / 1024 / 1024, 1) . "MB", 'WARN');
}

$wlsLog("内存缓存：上限 " . \round($WLS_STATIC_CACHE_MAX_TOTAL / 1024 / 1024, 1) . "MB，单文件 " . \round($WLS_STATIC_CACHE_MAX_SIZE / 1024, 1) . "KB", 'INFO');
// ========== 内存缓存配置结束 ==========

// 注册 shutdown handler 捕获致命错误（Fatal Error、TypeError、内存溢出等）
// 崩溃日志必须立即落盘（LOCK_EX），避免进程退出后丢失
$fatalErrorTypes = [\E_ERROR, \E_PARSE, \E_CORE_ERROR, \E_COMPILE_ERROR, \E_RECOVERABLE_ERROR, \E_USER_ERROR];
\register_shutdown_function(function() use ($wlsLog, $flushLog, $workerId, $port, $processLogFile, $instanceName, $fatalErrorTypes) {
    $error = \error_get_last();
    $isFatal = $error !== null && (
        \in_array($error['type'], $fatalErrorTypes, true)
        || $error['type'] === 0  // 部分 SAPI 下未捕获 Error/TypeError 报告为 0
    );
    if ($isFatal) {
        $errorMsg = "Worker 致命错误 [PID: " . \getmypid() . "] [Worker: {$workerId}] [Port: {$port}]: {$error['message']} in {$error['file']}:{$error['line']}";
    } else {
        // 无致命错误但进程即将退出：多为业务代码 die()/exit()，记录日志便于排查
        $exitMsg = "Worker 非致命退出 [PID: " . \getmypid() . "] [Worker: {$workerId}] [Port: {$port}] 可能为 die()/exit() 或信号终止";
        $wlsLog($exitMsg, 'WARN');
        $flushLog(true);
        if ($processLogFile) {
            @\file_put_contents($processLogFile, '[' . \date('Y-m-d H:i:s') . '] [EXIT] ' . $exitMsg . "\n", \FILE_APPEND | \LOCK_EX);
        }
        $line = '[' . \date('Y-m-d H:i:s') . '] [instance:' . $instanceName . '] [Worker:' . $workerId . '] [Port:' . $port . '] [EXIT] ' . $exitMsg . "\n";
        if (\defined('BP') && BP !== '') {
            $crashLogDir = BP . 'var' . DS . 'log';
            if (!\is_dir($crashLogDir)) {
                @\mkdir($crashLogDir, 0755, true);
            }
            @\file_put_contents($crashLogDir . DS . 'wls-worker-crash.log', $line, \FILE_APPEND | \LOCK_EX);
        }
        return;
    }
    $wlsLog($errorMsg, 'ERROR');
    $flushLog(true);
    \error_log('[WLS Worker FATAL] ' . $errorMsg);
    if ($processLogFile) {
        @\file_put_contents($processLogFile, '[' . \date('Y-m-d H:i:s') . '] [FATAL] ' . $errorMsg . "\n", \FILE_APPEND | \LOCK_EX);
    }
    // 统一崩溃日志：立即写入（LOCK_EX 确保落盘），失败时 fallback 到进程日志或系统临时目录
    $line = '[' . \date('Y-m-d H:i:s') . '] [instance:' . $instanceName . '] [Worker:' . $workerId . '] [Port:' . $port . '] ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'] . "\n";
    $written = false;
    if (\defined('BP') && BP !== '') {
        $crashLogDir = BP . 'var' . DS . 'log';
        if (!\is_dir($crashLogDir)) {
            @\mkdir($crashLogDir, 0755, true);
        }
        $crashLogFile = $crashLogDir . DS . 'wls-worker-crash.log';
        $written = @\file_put_contents($crashLogFile, $line, \FILE_APPEND | \LOCK_EX) !== false;
    }
    if (!$written && $processLogFile) {
        @\file_put_contents($processLogFile, '[' . \date('Y-m-d H:i:s') . '] [CRASH] ' . $line, \FILE_APPEND | \LOCK_EX);
    }
    if (!$written) {
        @\file_put_contents(\sys_get_temp_dir() . DS . 'wls-worker-crash.log', $line, \FILE_APPEND | \LOCK_EX);
    }
});

// ========== 检测 SO_REUSEPORT 支持 ==========
$isWindows = \PHP_OS_FAMILY === 'Windows';
$supportsReusePort = false;

if (!$isWindows && \defined('SO_REUSEPORT')) {
    if (PHP_OS === 'Linux') {
        $release = \php_uname('r');
        if (\version_compare($release, '3.9', '>=')) {
            $supportsReusePort = true;
        }
    } elseif (PHP_OS === 'Darwin') {
        // macOS 也支持 SO_REUSEPORT
        $supportsReusePort = true;
    }
}

// 如果显式指定了 --reuseport 但平台不支持，报错
if ($useReusePort && !$supportsReusePort) {
    $wlsLog("平台不支持 SO_REUSEPORT", 'ERROR');
    exit(1);
}

// ========== Socket 创建 ==========
$socket = null;

// 方案1：使用 socket 扩展创建支持 SO_REUSEPORT 的 socket（更可靠）
if ($useReusePort && $supportsReusePort && \function_exists('socket_create')) {
    $wlsLog("使用 socket 扩展创建 SO_REUSEPORT socket...", 'INFO');
    
    // 创建原始 socket
    $rawSocket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if (!$rawSocket) {
        $wlsLog("socket_create 失败: " . \socket_strerror(\socket_last_error()), 'ERROR');
        exit(1);
    }
    
    // 设置 SO_REUSEADDR
    if (!@\socket_set_option($rawSocket, SOL_SOCKET, SO_REUSEADDR, 1)) {
        $wlsLog("设置 SO_REUSEADDR 失败", 'WARN');
    }
    
    // 设置 SO_REUSEPORT
    if (!@\socket_set_option($rawSocket, SOL_SOCKET, SO_REUSEPORT, 1)) {
        $wlsLog("设置 SO_REUSEPORT 失败: " . \socket_strerror(\socket_last_error($rawSocket)), 'ERROR');
        @\socket_close($rawSocket);
        exit(1);
    }
    
    // 绑定地址
    if (!@\socket_bind($rawSocket, $host, $port)) {
        $wlsLog("socket_bind 失败: " . \socket_strerror(\socket_last_error($rawSocket)), 'ERROR');
        @\socket_close($rawSocket);
        exit(1);
    }
    
    // 开始监听
    if (!@\socket_listen($rawSocket, 102400)) {
        $wlsLog("socket_listen 失败: " . \socket_strerror(\socket_last_error($rawSocket)), 'ERROR');
        @\socket_close($rawSocket);
        exit(1);
    }
    
    // 将 socket 资源转换为 stream
    $socket = \socket_export_stream($rawSocket);
    if (!$socket) {
        $wlsLog("socket_export_stream 失败", 'ERROR');
        @\socket_close($rawSocket);
        exit(1);
    }
    
    $wlsLog("SO_REUSEPORT socket 创建成功，Worker #{$workerId} 监听 {$host}:{$port}", 'INFO');
    
} else {
    // 方案2：标准 stream_socket_server 方式
    $socketOptions = [
        'backlog' => 102400,
        'so_reuseaddr' => true,
    ];
    
    // Linux 下尝试启用 SO_REUSEPORT（通过 stream context）
    if ($supportsReusePort && !$useReusePort) {
        $socketOptions['so_reuseport'] = true;
        $wlsLog("尝试通过 stream_context 启用 SO_REUSEPORT", 'INFO');
    }
    
    $context = \stream_context_create([
        'socket' => $socketOptions
    ]);

    $socket = @\stream_socket_server(
        "tcp://{$host}:{$port}",
        $errno,
        $errstr,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        $context
    );

    if (!$socket) {
        $wlsLog("Socket 创建失败: {$errstr} (errno: {$errno})", 'ERROR');
        \error_log("[WLS Worker] Failed to create socket: {$errstr}");
        exit(1);
    }
}

$wlsLog("Socket 创建成功，开始监听连接", 'INFO');

\stream_set_blocking($socket, false);

// ========== IPC 控制通道：连接 Master 并注册 + 上报就绪 ==========
$ipcClient = null;
$ipcReceivedShutdown = false;
$ipcDraining = false; // 是否正在排水
$drainStartTime = 0;   // 排水开始时间戳
$maxDrainTime = 10;     // 排水最大等待时间（秒），超时后强制关闭所有连接退出

// 如果启用了维护模式
if ($isMaintenanceWorker) {
    try {
        \Weline\Framework\App\Env::getInstance()->setConfig('maintenance', true);
        $wlsLog("维护 Worker 模式已启用", 'INFO');
    } catch (\Throwable $e) {
        $wlsLog("设置维护模式失败: " . $e->getMessage(), 'WARN');
    }
}

// 获取控制端口
if ($controlPort <= 0) {
    $_instanceFile = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . $instanceName . '.json';
    if (\is_file($_instanceFile)) {
        $_instData = @\json_decode(\file_get_contents($_instanceFile), true);
        $controlPort = (int)($_instData['control_port'] ?? 0);
    }
    unset($_instanceFile, $_instData);
}

if ($controlPort > 0) {
    $ipcClient = new \Weline\Server\IPC\ControlClient();
    $ipcSelfTag = ($isMaintenanceWorker ? 'Maintenance' : 'Worker') . "#{$workerId}";
    $ipcClient->setSelfTag($ipcSelfTag);
    $ipcClient->setLogger(function (string $line) use ($wlsLog) {
        $wlsLog($line, 'IPC');
    });
    // DEV 模式下输出详细 IPC SEND/RECV 明细
    $ipcClient->setVerboseLog((\defined('DEV') && DEV) || (\defined('WLS_DEV_MODE') && WLS_DEV_MODE));
    if ($ipcClient->connect('127.0.0.1', $controlPort)) {
        $ipcRole = $isMaintenanceWorker ? \Weline\Server\IPC\ControlMessage::ROLE_MAINTENANCE : \Weline\Server\IPC\ControlMessage::ROLE_WORKER;
        $ipcClient->register($ipcRole, \getmypid(), $port, $workerId);
        $wlsLog("IPC 控制通道已连接 (控制端口: {$controlPort})", 'INFO');
        
        // 框架已初始化 + Socket 已创建 → 上报就绪
        $ipcClient->sendReady($ipcRole, $workerId, $port);
        $wlsLog("已上报就绪状态", 'INFO');
        
        // 设置消息处理器
        $ipcClient->onMessage(function (array $msg, \Weline\Server\IPC\ControlClient $client) use (&$shouldExit, &$ipcDraining, &$ipcReceivedShutdown, &$socket, &$drainStartTime, $wlsLog, $workerId) {
            $type = $msg['type'] ?? '';
            
            switch ($type) {
                case \Weline\Server\IPC\ControlMessage::TYPE_RELOAD:
                    // 代码重载：先清 opcache（共享内存级），确保新 Worker 加载最新文件
                    if (\function_exists('opcache_reset')) {
                        \opcache_reset();
                    }
                    \clearstatcache(true);
                    $shouldExit = true;
                    $ipcDraining = true;
                    $drainStartTime = \time();
                    if ($socket && \is_resource($socket)) {
                        @\fclose($socket);
                        $socket = null;
                    }
                    $wlsLog("收到 reload 命令，已清除 opcache 并关闭监听 socket，开始排水（最多等待 10 秒）...", 'INFO');
                    break;
                    
                case \Weline\Server\IPC\ControlMessage::TYPE_CACHE_CLEAR:
                    if (\function_exists('opcache_reset')) {
                        \opcache_reset();
                    }
                    \clearstatcache(true);
                    \Weline\Framework\Manager\ObjectManager::clearInstances();
                    if (\function_exists('handleStaticFile')) {
                        handleStaticFile('__CLEAR_CACHE__', '');
                    }
                    $wlsLog("收到 cache_clear 命令，已清理缓存", 'INFO');
                    break;
                    
                case \Weline\Server\IPC\ControlMessage::TYPE_SHUTDOWN:
                    $ipcReceivedShutdown = true;
                    $shouldExit = true;
                    $wlsLog("收到 shutdown 命令，准备退出", 'INFO');
                    break;
            }
        });
        
        // 设置断开处理器
        $ipcClient->onDisconnect(function (bool $receivedShutdown, \Weline\Server\IPC\ControlClient $client) use (&$ipcReceivedShutdown, $wlsLog, $instanceName, $controlPort, $workerId) {
            if ($receivedShutdown) {
                $wlsLog("Master 连接断开（已收到 shutdown，不复活）", 'INFO');
                return;
            }
            $wlsLog("Master 连接意外断开，尝试复活...", 'WARN');
            
            if ($workerId === 1) {
                $resurrector = new \Weline\Server\IPC\MasterResurrector(
                    \Weline\Server\IPC\ControlMessage::RESURRECTION_WORKER,
                    $instanceName,
                    '127.0.0.1',
                    $controlPort
                );
                if ($resurrector->shouldResurrect($receivedShutdown)) {
                    $resurrector->attemptResurrect();
                }
            }
            $client->tryReconnect();
        });
    } else {
        $wlsLog("IPC 控制通道连接失败 (控制端口: {$controlPort})，继续独立运行", 'WARN');
        $ipcClient = null;
    }
}
// ========== IPC 控制通道结束 ==========

$connections = [];
$requestCount = 0;
$activeRequests = 0; // 正在处理的请求数
$requestBuffers = [];
$connectionLastActivity = []; // 连接最后活动时间（用于超时清理）
$requestLogged = []; // 记录已输出日志的连接（前端模式使用）
$startTime = \time(); // 记录启动时间

// Keep-Alive 连接超时配置（秒）
$keepAliveTimeout = 60; // 默认 60 秒空闲超时
$connectionTimeoutCheckInterval = 5; // 每 5 秒检查一次超时连接
$lastTimeoutCheck = \time();

// 内存监控配置（防止内存泄漏导致 OOM）
$maxMemoryBytes = 256 * 1024 * 1024; // 256MB 内存上限
$memoryCheckInterval = 10; // 每 10 秒检查一次内存
$lastMemoryCheck = \time();
$memoryWarningThreshold = 0.8; // 80% 时告警

// 最大请求数限制（可选的内存保护措施）
$maxRequests = 10000; // 处理 10000 个请求后优雅重启（0=禁用）

// 重载日志输出函数
$logReload = function (string $method) use ($workerId, $instanceName) {
    $time = \date('Y-m-d H:i:s');
    if ($method === 'FLAG-CACHE' || $method === 'IPC-CACHE') {
        $message = "[{$time}] [WLS] Worker #{$workerId} ({$instanceName}) 已清理缓存（opcache + ObjectManager）[{$method}]";
    } else {
        $message = "[{$time}] [WLS] Worker #{$workerId} ({$instanceName}) 正在重载（优雅退出，由 Master 重启）[{$method}]";
    }
    \error_log($message);
    if (\defined('STDOUT') && \is_resource(STDOUT)) {
        \fwrite(STDOUT, "\033[33m{$message}\033[0m\n");
    }
};

// 是否需要优雅退出（重载时设置为 true）
$shouldExit = false;

// Worker 优雅退出函数（统一使用进程管理器清理）
// 优雅关闭配置
$gracefulShutdownTimeout = 30; // 等待活跃请求的最大时间（秒）

$gracefulExit = function (string $reason = '', bool $waitForRequests = true) use ($socket, &$connections, &$requestBuffers, &$connectionLastActivity, &$activeRequests, $processName, $gracefulShutdownTimeout, $wlsLog, $flushLog) {
    // 刷新日志缓冲区
    $flushLog(true);
    
    // 记录退出原因
    if ($reason) {
        \error_log("[WLS Worker] 退出原因: {$reason}");
        $wlsLog("优雅关闭: {$reason}", 'INFO');
    }
    
    // 停止接受新连接（关闭监听 socket；仅对有效 stream 调用 fclose，避免已关闭 resource 导致 TypeError）
    if (\is_resource($socket) && \get_resource_type($socket) === 'stream') {
        @\fclose($socket);
    }
    $wlsLog("已停止接受新连接", 'INFO');
    
    // 等待活跃请求完成
    if ($waitForRequests && !empty($connections)) {
        $waitStart = \time();
        $wlsLog("等待 " . \count($connections) . " 个活跃连接完成...", 'INFO');
        
        while (!empty($connections) && (\time() - $waitStart) < $gracefulShutdownTimeout) {
            // 继续处理现有连接的数据
            $read = $connections;
            $write = [];
            $except = [];
            
            $changed = @\stream_select($read, $write, $except, 1, 0);
            if ($changed === false) {
                break;
            }
            
            foreach ($read as $conn) {
                $connId = \get_resource_id($conn);
                $data = @\fread($conn, 65535);
                
                if ($data === false || $data === '') {
                    // 连接已关闭
                    if (\is_resource($conn) && \get_resource_type($conn) === 'stream') {
                        @\fclose($conn);
                    }
                    unset($connections[$connId]);
                    unset($requestBuffers[$connId]);
                    unset($connectionLastActivity[$connId]);
                }
            }
            
            // 每秒检查一次
            if (!empty($connections)) {
                $remaining = \count($connections);
                $elapsed = \time() - $waitStart;
                $wlsLog("等待中... 剩余 {$remaining} 个连接，已等待 {$elapsed}s", 'INFO');
            }
        }
        
        $elapsed = \time() - $waitStart;
        if (!empty($connections)) {
            $wlsLog("超时 ({$elapsed}s)，强制关闭 " . \count($connections) . " 个连接", 'WARN');
        } else {
            $wlsLog("所有连接已完成 ({$elapsed}s)", 'INFO');
        }
    }
    
    // 关闭剩余连接（仅对有效 stream 调用 fclose，避免已关闭 resource 导致 TypeError）
    foreach ($connections as $conn) {
        if (\is_resource($conn) && \get_resource_type($conn) === 'stream') {
            @\fclose($conn);
        }
    }
    
    // 清理连接相关数据
    $connections = [];
    $requestBuffers = [];
    $connectionLastActivity = [];
    
    // 刷新异步日志缓冲
    \Weline\Server\Service\AsyncLogger::flushAll();
    
    // 使用进程管理器清理 PID 文件
    if ($processName) {
        \Weline\Framework\System\Process\Processer::destroy('--name=' . $processName);
    }
    
    $wlsLog("Worker 已退出", 'INFO');
    exit(0);
};

// 信号处理（热更新支持，仅 Linux/Mac）
if (\function_exists('pcntl_signal')) {
    \pcntl_signal(SIGUSR1, function () use (&$shouldExit, &$ipcDraining, &$drainStartTime, &$socket, $logReload) {
        // 收到重载信号，标记优雅退出（Master 会重新启动新进程加载新代码）
        $shouldExit = true;
        $ipcDraining = true;
        $drainStartTime = \time();
        // 关闭监听 socket（不再接受新连接）
        if ($socket && \is_resource($socket)) {
            @\fclose($socket);
            $socket = null;
        }
        $logReload('SIGUSR1');
    });
    
    \pcntl_signal(SIGTERM, function () use ($gracefulExit) {
        $gracefulExit('收到 SIGTERM 信号');
    });
}

// Master 感知通过 IPC 控制通道（TCP 连接断开 = Master 死亡/重启，无需文件轮询）

// 连续错误计数器（Workerman 模式：避免单次错误导致进程退出）
$consecutiveErrors = 0;
$maxConsecutiveErrors = 100; // 连续 100 次错误才考虑重启（给予足够的恢复机会）

// ========== 孤儿检测：Master PID 存活检查 ==========
$lastMasterCheck = \time();
$masterCheckInterval = 5;
$masterDeadCount = 0;
$masterDeadThreshold = 3;

// 事件循环（Workerman 模式：外层 try-catch 防止意外退出）
while (true) {
    try {
    if (\function_exists('pcntl_signal_dispatch')) {
        \pcntl_signal_dispatch();
    }
    
    // 定期刷新日志缓冲区（避免日志堆积）
    $flushLog(false);
    
    $now = \time();
    
    // ========== 孤儿检测：定期检查 Master 是否存活 ==========
    if ($masterPid > 0 && !$ipcReceivedShutdown && ($now - $lastMasterCheck) >= $masterCheckInterval) {
        $lastMasterCheck = $now;
        $masterAlive = false;
        if (\function_exists('posix_kill')) {
            $masterAlive = @\posix_kill($masterPid, 0);
            // macOS/Linux: 非 root 进程探测 root 进程时可能返回 EPERM（进程存在但无权限）
            if (!$masterAlive && \function_exists('posix_get_last_error')) {
                $errno = (int)@\posix_get_last_error();
                $eperm = 1; // EPERM
                if ($errno === $eperm) {
                    $masterAlive = true;
                }
            }
        } elseif (!(\defined('IS_WIN') && IS_WIN)) {
            $masterAlive = @\file_exists("/proc/{$masterPid}");
            if (!$masterAlive) {
                @\exec("kill -0 {$masterPid} 2>/dev/null", $output, $code);
                $masterAlive = ($code === 0);
            }
        }
        
        if ($masterAlive) {
            $masterDeadCount = 0;
        } else {
            $masterDeadCount++;
            $wlsLog("Master PID {$masterPid} 不可达 ({$masterDeadCount}/{$masterDeadThreshold})", 'WARN');
            if ($masterDeadCount >= $masterDeadThreshold) {
                $ipcAlso = (!$ipcClient || !$ipcClient->isConnected());
                if ($ipcAlso) {
                    $wlsLog("Master PID {$masterPid} 已死亡且 IPC 断开，Worker 自行退出（孤儿保护）", 'WARN');
                    $gracefulExit('孤儿检测：Master 已死亡');
                }
            }
        }
    }
    
    // ========== IPC 控制通道处理 ==========
    if ($ipcClient && !$ipcClient->isConnected() && !$ipcReceivedShutdown) {
        $ipcClient->tryReconnect();
    }
    
    // 检查是否需要优雅退出（排水模式）
    if ($shouldExit) {
        if ($ipcDraining) {
            // ========== 排水模式：快速清理连接，加速退出 ==========
            // 1. 立即关闭所有空闲 Keep-Alive 连接（无请求数据）
            foreach ($connections as $cid => $cconn) {
                $hasReqData = isset($requestBuffers[$cid]) && $requestBuffers[$cid] !== '';
                if (!$hasReqData) {
                    @\fclose($cconn);
                    unset($connections[$cid], $requestBuffers[$cid], $connectionLastActivity[$cid], $requestLogged[$cid]);
                }
            }
            
            $drainElapsed = $drainStartTime > 0 ? (\time() - $drainStartTime) : 0;
            
            // 2. 所有连接已清空 → 排水完成
            if (empty($connections)) {
                if ($ipcClient && $ipcClient->isConnected()) {
                    $ipcClient->sendDrainingComplete($workerId, $port);
                }
                $wlsLog("排水完成（{$drainElapsed}秒），Worker 退出", 'INFO');
                $gracefulExit('热重载');
            }
            
            // 3. 排水超时 → 强制关闭所有剩余连接
            if ($drainElapsed >= $maxDrainTime) {
                $remaining = \count($connections);
                $wlsLog("排水超时（{$drainElapsed}秒 >= {$maxDrainTime}秒），强制关闭剩余 {$remaining} 个连接", 'WARN');
                foreach ($connections as $cid => $cconn) {
                    @\fclose($cconn);
                }
                $connections = [];
                $requestBuffers = [];
                $connectionLastActivity = [];
                $requestLogged = [];
                
                if ($ipcClient && $ipcClient->isConnected()) {
                    $ipcClient->sendDrainingComplete($workerId, $port);
                }
                $gracefulExit('热重载（超时强制退出）');
            }
        } elseif (empty($connections)) {
            // 非排水模式退出（如 shutdown 命令）
            $gracefulExit($ipcReceivedShutdown ? 'shutdown命令' : '热重载');
        }
    }
    
    // Keep-Alive 连接超时清理（定期检查并关闭空闲连接）
    if ($now - $lastTimeoutCheck >= $connectionTimeoutCheckInterval) {
        $lastTimeoutCheck = $now;
        foreach ($connections as $connId => $conn) {
            $lastActivity = $connectionLastActivity[$connId] ?? $now;
            $idleTime = $now - $lastActivity;
            
            // 如果连接空闲时间超过超时时间，关闭连接
            if ($idleTime >= $keepAliveTimeout) {
                @\fclose($conn);
                unset($connections[$connId]);
                unset($requestBuffers[$connId]);
                unset($connectionLastActivity[$connId]);
                unset($requestLogged[$connId]);
            }
        }
    }
    
    // 内存监控：检测内存使用是否超限，超限则优雅重启
    if ($now - $lastMemoryCheck >= $memoryCheckInterval) {
        $lastMemoryCheck = $now;
        $currentMemory = \memory_get_usage(true);
        $memoryPercent = $currentMemory / $maxMemoryBytes;
        
        // 超过内存上限，触发优雅重启
        if ($currentMemory >= $maxMemoryBytes) {
            $memoryMB = \round($currentMemory / 1024 / 1024, 2);
            $wlsLog("内存使用超限 ({$memoryMB}MB >= " . ($maxMemoryBytes / 1024 / 1024) . "MB)，触发优雅重启", 'WARN');
            $shouldExit = true;
        }
        // 达到告警阈值，输出警告日志
        elseif ($memoryPercent >= $memoryWarningThreshold) {
            $memoryMB = \round($currentMemory / 1024 / 1024, 2);
            $wlsLog("内存使用率较高: {$memoryMB}MB (" . \round($memoryPercent * 100) . "%)", 'WARN');
        }
        
        // 定期记录 Worker 状态到数据库
        try {
            \Weline\Server\Service\StatusLogService::logWorkerStatus([
                'instance' => $instanceName,
                'worker_id' => $workerId,
                'port' => $port,
                'pid' => \getmypid(),
                'connections' => \count($connections),
                'active_requests' => $activeRequests,
                'total_requests' => $requestCount,
                'memory_usage' => $currentMemory,
                'memory_peak' => \memory_get_peak_usage(true),
                'uptime' => $now - $startTime,
                'ssl' => false,
            ]);
        } catch (\Throwable $e) {
            // 忽略日志记录失败
        }
    }
    
    // 最大请求数限制：处理超过指定请求数后优雅重启（可选的内存保护）
    if ($maxRequests > 0 && $requestCount >= $maxRequests && empty($connections)) {
        $wlsLog("已处理 {$requestCount} 个请求，达到上限 {$maxRequests}，触发优雅重启", 'INFO');
        $shouldExit = true;
    }
    
    // 构建 stream_select 读数组
    $readSockets = [];
    if ($socket && \is_resource($socket)) {
        $readSockets[] = $socket;
    }
    $readSockets = \array_merge($readSockets, $connections);
    
    // 加入 IPC 控制 socket
    $ipcSocket = ($ipcClient && $ipcClient->isConnected()) ? $ipcClient->getSocket() : null;
    if ($ipcSocket && \is_resource($ipcSocket)) {
        $readSockets[] = $ipcSocket;
    }
    
    $read = $readSockets;
    $write = [];
    $except = [];
    
    $changed = @\stream_select($read, $write, $except, 0, 100000);
    
    if ($changed === false) {
        continue;
    }
    
    // 处理 IPC 控制通道消息
    if ($ipcSocket && \in_array($ipcSocket, $read, true)) {
        if ($ipcClient) {
            $ipcClient->handleReadable();
        }
    }
    
    // 新连接（排水后 $socket 为 null，不会进入此分支）
    if ($socket && \is_resource($socket) && \in_array($socket, $read, true)) {
        $conn = @\stream_socket_accept($socket, 0);
        if ($conn) {
            \stream_set_blocking($conn, false);
            $connId = \get_resource_id($conn);
            $connections[$connId] = $conn;
            $requestBuffers[$connId] = '';
            $connectionLastActivity[$connId] = \time(); // 记录连接建立时间
            // 注意：不在此处记录连接日志，以减少健康检查等短连接造成的日志噪音
            // 实际请求日志在接收到 HTTP 请求行时记录
        }
        $key = \array_search($socket, $read);
        unset($read[$key]);
    }
    
    // 处理连接
    foreach ($read as $conn) {
        $connId = \get_resource_id($conn);
        $data = @\fread($conn, 65535);
        
        if ($data === false || $data === '') {
            @\fclose($conn);
            unset($connections[$connId]);
            unset($requestBuffers[$connId]);
            unset($connectionLastActivity[$connId]);
            unset($requestLogged[$connId]);
            continue;
        }
        
        // 更新连接最后活动时间
        $connectionLastActivity[$connId] = \time();
        
        $requestBuffers[$connId] = ($requestBuffers[$connId] ?? '') . $data;
        
        // 请求缓冲区大小限制（防止恶意大请求导致内存耗尽）
        $maxRequestSize = 10 * 1024 * 1024; // 10MB
        if (\strlen($requestBuffers[$connId]) > $maxRequestSize) {
            $wlsLog("请求体过大，拒绝连接 (connId: {$connId}, size: " . \strlen($requestBuffers[$connId]) . ")", 'WARN');
            $errorResponse = "HTTP/1.1 413 Request Entity Too Large\r\n";
            $errorResponse .= "Content-Type: text/plain; charset=utf-8\r\n";
            $errorResponse .= "Connection: close\r\n";
            $errorResponse .= "Content-Length: 24\r\n";
            $errorResponse .= "\r\n";
            $errorResponse .= "Request Entity Too Large";
            @\fwrite($conn, $errorResponse);
            @\fclose($conn);
            unset($connections[$connId]);
            unset($requestBuffers[$connId]);
            unset($connectionLastActivity[$connId]);
            unset($requestLogged[$connId]);
            continue;
        }
        
        // 前端模式：在接收到请求的第一行时立即输出日志
        if ($isFrontend && !isset($requestLogged[$connId])) {
            // 尝试从缓冲区中提取请求行（第一行）
            $firstLineEnd = \strpos($requestBuffers[$connId], "\r\n");
            if ($firstLineEnd !== false) {
                $requestLine = \substr($requestBuffers[$connId], 0, $firstLineEnd);
                if (\preg_match('/^(\w+)\s+([^\s]+)/', $requestLine, $matches)) {
                    $method = $matches[1];
                    $uri = \parse_url($matches[2], PHP_URL_PATH) ?? '/';
                    $requestCount++;
                    $wlsLog("→ {$method} {$uri}", 'INFO');
                    // 立即刷新输出缓冲区
                    if (\defined('STDOUT') && \is_resource(STDOUT)) {
                        \fflush(STDOUT);
                    } elseif (\defined('STDERR') && \is_resource(STDERR)) {
                        \fflush(STDERR);
                    }
                    $requestLogged[$connId] = true;
                }
            }
        }
        
        if (!isRequestComplete($requestBuffers[$connId])) {
            continue;
        }
        
        $rawRequest = $requestBuffers[$connId];
        $requestBuffers[$connId] = '';
        if (!isset($requestLogged[$connId])) {
            $requestCount++;
        }
        unset($requestLogged[$connId]); // 清理标记（如果不存在也不会报错）
        $activeRequests++;
        
        // 解析请求 URI（用于日志，如果前端模式已输出则跳过）
        if (!$isFrontend) {
            $uri = '/';
            if (\preg_match('/^\w+\s+([^\s]+)/', $rawRequest, $matches)) {
                $uri = \parse_url($matches[1], PHP_URL_PATH) ?? '/';
            }
            $method = 'GET';
            if (\preg_match('/^(\w+)\s+/', $rawRequest, $matches)) {
                $method = $matches[1];
            }
            $wlsLog("收到请求: {$method} {$uri} (connId: {$connId}, requestCount: {$requestCount})", 'INFO');
        }
        
        // 设置 SSE 上下文（让控制器可以直接写入连接）
        \Weline\Framework\Http\Sse\SseContext::setConnection($conn);
        
        // 处理请求
        $handleStartTime = \microtime(true);
        $response = handleRequest(
            $rawRequest, $runtime, $runtimeError, $instanceName, $workerId, $port, 
            $requestCount, $activeRequests, \count($connections), $startTime, $wlsLog,
            $originToken,
            $originTokenValidationEnabled,
            $originTokenHeader,
            $originTokenAllowLocal
        );
        $handleDuration = \round((\microtime(true) - $handleStartTime) * 1000, 2);
        
        $activeRequests--;
        
        // 检查是否是 SSE 模式（如果是，响应已经流式发送，不需要再发送）
        $isSseMode = \Weline\Framework\Http\Sse\SseContext::isSseEnabled();
        
        if (!$isSseMode) {
            // 普通请求：发送完整响应
            // 注意：fwrite 不保证一次性写入所有数据，需要循环写入
            $responseLength = \strlen($response);
            $totalWritten = 0;
            // H13: 增加重试次数以支持大文件（如 CKEditor.js ~1.1MB）
            // 大文件在网络慢时需要更多重试次数
            $maxRetries = 1000; // 增加到 1000 次（最多 1 秒阻塞）
            $retryCount = 0;
            $consecutiveZeroWrites = 0; // 连续 0 写入计数
            $maxConsecutiveZeroWrites = 100; // 连续 100 次 0 写入才放弃
            
            while ($totalWritten < $responseLength && $retryCount < $maxRetries) {
                $chunk = \substr($response, $totalWritten);
                $written = @\fwrite($conn, $chunk);
                
                if ($written === false) {
                    // 写入失败，连接可能已断开，立即关闭并清理
                    $wlsLog("响应写入失败，连接已断开 (已写入: {$totalWritten}/{$responseLength})", 'WARN');
                    @\fclose($conn);
                    unset($connections[$connId]);
                    unset($requestBuffers[$connId]);
                    unset($connectionLastActivity[$connId]);
                    unset($requestLogged[$connId]);
                    continue 2; // 跳出 while 循环和 foreach 循环
                } elseif ($written === 0) {
                    // 暂时无法写入（缓冲区满），短暂休眠后重试
                    $retryCount++;
                    $consecutiveZeroWrites++;
                    
                    // 如果连续多次无法写入，可能是网络问题
                    if ($consecutiveZeroWrites >= $maxConsecutiveZeroWrites) {
                        $wlsLog("响应写入阻塞过久: {$totalWritten}/{$responseLength} bytes (连续 {$consecutiveZeroWrites} 次写入 0)", 'WARN');
                        break;
                    }
                    
                    \usleep(1000); // 1ms
                    continue;
                }
                
                $totalWritten += $written;
                $retryCount = 0; // 重置重试计数
                $consecutiveZeroWrites = 0; // 重置连续 0 写入计数
            }
            
            // 记录不完整写入的警告，并强制关闭连接
            if ($totalWritten < $responseLength) {
                $wlsLog("响应写入不完整: {$totalWritten}/{$responseLength} bytes (retries: {$retryCount}, zeroWrites: {$consecutiveZeroWrites})", 'WARN');
                // H14: 写入不完整时必须关闭连接，否则 Keep-Alive 会导致 ERR_CONTENT_LENGTH_MISMATCH
                @\fclose($conn);
                unset($connections[$connId]);
                unset($requestBuffers[$connId]);
                unset($connectionLastActivity[$connId]);
                unset($requestLogged[$connId]);
                continue; // 跳过后续的 Keep-Alive 处理
            }
        } else {
            // SSE 模式：响应已流式发送，记录日志
            $wlsLog("SSE 流式响应完成 (connId: {$connId})", 'INFO');
        }
        
        // 重置 SSE 上下文
        \Weline\Framework\Http\Sse\SseContext::reset();
        
        // 更新连接最后活动时间（请求处理完成）
        $connectionLastActivity[$connId] = \time();
        
        // SSE 连接通常是长连接，但处理完成后应该关闭
        // 普通请求根据 Keep-Alive 决定
        $shouldClose = $isSseMode || !isKeepAlive($rawRequest) || $ipcDraining; // 排水模式强制关闭连接
        if ($shouldClose) {
            @\fclose($conn);
            unset($connections[$connId]);
            unset($requestBuffers[$connId]);
            unset($connectionLastActivity[$connId]);
            unset($requestLogged[$connId]);
        }
    }
    
    // 重置连续错误计数（本轮循环成功完成）
    $consecutiveErrors = 0;
    
    } catch (\Throwable $loopException) {
        // Workerman 模式：捕获所有异常，防止 Worker 意外退出
        $consecutiveErrors++;
        $errorMessage = $loopException->getMessage();
        $errorFile = $loopException->getFile();
        $errorLine = $loopException->getLine();
        
        // 记录错误日志
        \error_log("[WLS Worker #{$workerId}] 事件循环异常 ({$consecutiveErrors}/{$maxConsecutiveErrors}): {$errorMessage} in {$errorFile}:{$errorLine}");
        $wlsLog("事件循环异常: {$errorMessage}", 'ERROR');
        
        // 刷新日志缓冲区
        $flushLog(true);
        
        // 如果连续错误过多，优雅退出让 Master 重启
        if ($consecutiveErrors >= $maxConsecutiveErrors) {
            \error_log("[WLS Worker #{$workerId}] 连续错误过多，优雅退出");
            $gracefulExit("连续错误过多 ({$consecutiveErrors} 次)");
        }
        
        // 短暂休眠后继续（避免错误风暴）
        \usleep(10000); // 10ms
        continue;
    }
}

function isRequestComplete(string $data): bool
{
    $headerEnd = \strpos($data, "\r\n\r\n");
    if ($headerEnd === false) {
        return false;
    }
    
    if (\preg_match('/Content-Length:\s*(\d+)/i', $data, $matches)) {
        $contentLength = (int) $matches[1];
        $bodyStart = $headerEnd + 4;
        $currentBodyLength = \strlen($data) - $bodyStart;
        return $currentBodyLength >= $contentLength;
    }
    
    return true;
}

function isKeepAlive(string $rawRequest): bool
{
    // HTTP/1.1 默认启用 Keep-Alive，除非显式指定 Connection: close
    $isHttp11 = \strpos($rawRequest, 'HTTP/1.1') !== false;
    
    // 检查 Connection 头
    if (\preg_match('/Connection:\s*(\S+)/i', $rawRequest, $matches)) {
        $connection = \strtolower(\trim($matches[1]));
        // 如果显式指定 close，则关闭连接
        if ($connection === 'close') {
            return false;
        }
        // 如果显式指定 keep-alive，则保持连接
        if ($connection === 'keep-alive') {
            return true;
        }
    }
    
    // HTTP/1.1 默认 Keep-Alive，HTTP/1.0 默认关闭
    return $isHttp11;
}

function getHeaderValue(string $rawRequest, string $headerName): ?string
{
    $pattern = '/^' . \preg_quote($headerName, '/') . ':\s*([^\r\n]+)/im';
    if (\preg_match($pattern, $rawRequest, $matches)) {
        $value = \trim($matches[1]);
        return $value === '' ? null : $value;
    }
    return null;
}

function handleRequest(
    string $rawRequest,
    ?\Weline\Framework\Runtime\WlsRuntime $runtime,
    ?string $runtimeError,
    string $instanceName,
    int $workerId,
    int $port,
    int $requestCount,
    int $activeRequests,
    int $connectionCount,
    int $startTime,
    callable $wlsLog,
    string $originToken,
    bool $originTokenValidationEnabled,
    string $originTokenHeader,
    bool $originTokenAllowLocal
): string {
    // 解析请求 URI
    $uri = '/';
    if (\preg_match('/^\w+\s+([^\s]+)/', $rawRequest, $matches)) {
        $uri = \parse_url($matches[1], PHP_URL_PATH) ?? '/';
    }
    $method = 'GET';
    if (\preg_match('/^(\w+)\s+/', $rawRequest, $matches)) {
        $method = $matches[1];
    }
    
    // 获取客户端 IP
    $clientIp = '127.0.0.1';
    $cfConnectingIp = getHeaderValue($rawRequest, 'CF-Connecting-IP');
    if ($cfConnectingIp !== null) {
        $clientIp = $cfConnectingIp;
    } elseif (\preg_match('/X-Real-IP:\s*([^\r\n]+)/i', $rawRequest, $matches)) {
        $clientIp = \trim($matches[1]);
    } elseif (\preg_match('/X-Forwarded-For:\s*([^\r\n,]+)/i', $rawRequest, $matches)) {
        $clientIp = \trim($matches[1]);
    }
    
    // 判断是否本地请求
    $localIps = ['127.0.0.1', '::1', 'localhost'];
    $isLocal = \in_array($clientIp, $localIps, true) || \strpos($clientIp, '192.168.') === 0 || \strpos($clientIp, '10.') === 0;
    
    // ========== 健康检查接口（仅本地访问，不受维护模式影响） ==========
    if ($uri === '/_wls/health') {
        // 检查请求是否要求 Keep-Alive（HTTP/1.1 默认 keep-alive）
        $isHttp11 = \strpos($rawRequest, 'HTTP/1.1') !== false;
        $hasClose = \stripos($rawRequest, 'Connection: close') !== false;
        $keepAlive = $isHttp11 && !$hasClose;
        
        if (!$isLocal) {
            // 非本地请求：返回 403（极简响应）
            return $keepAlive
                ? "HTTP/1.1 403 Forbidden\r\nContent-Length: 9\r\nConnection: keep-alive\r\n\r\nForbidden"
                : "HTTP/1.1 403 Forbidden\r\nContent-Length: 9\r\nConnection: close\r\n\r\nForbidden";
        }
        
        // 高性能健康检查：使用极简响应，避免 json_encode/memory_get_usage 开销
        // 完整信息可通过 /_wls/health?detail=1 获取
        $wantsDetail = \strpos($rawRequest, 'detail=1') !== false || \strpos($rawRequest, 'detail=true') !== false;
        
        if ($wantsDetail) {
            // 详细模式：返回完整信息
            $health = [
                'status' => 'healthy',
                'instance' => $instanceName,
                'worker_id' => $workerId,
                'port' => $port,
                'connections' => $connectionCount,
                'active_requests' => $activeRequests - 1,
                'total_requests' => $requestCount,
                'memory_usage' => \memory_get_usage(true),
                'memory_peak' => \memory_get_peak_usage(true),
                'uptime' => \time() - $startTime,
                'php_version' => PHP_VERSION,
                'timestamp' => \time(),
            ];
            $body = \json_encode($health, JSON_UNESCAPED_UNICODE);
            $len = \strlen($body);
            return $keepAlive
                ? "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {$len}\r\nConnection: keep-alive\r\n\r\n{$body}"
                : "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {$len}\r\nConnection: close\r\n\r\n{$body}";
        }
        
        // 极简模式（默认）：直接返回静态字符串，最大性能
        return $keepAlive
            ? "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: keep-alive\r\n\r\nOK"
            : "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK";
    }
    // ========== 健康检查接口结束 ==========

    // ========== Origin Token 回源校验（可选）==========
    if ($originTokenValidationEnabled && $originToken !== '') {
        $isLocalClient = $isLocal;
        if (!$originTokenAllowLocal || !$isLocalClient) {
            $receivedToken = getHeaderValue($rawRequest, $originTokenHeader) ?? '';
            if (!\hash_equals($originToken, $receivedToken)) {
                $forbiddenBody = '{"error":true,"message":"Origin token validation failed"}';
                return "HTTP/1.1 403 Forbidden\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: " . \strlen($forbiddenBody) . "\r\nConnection: close\r\n\r\n{$forbiddenBody}";
            }
        }
    }
    // ========== Origin Token 回源校验结束 ==========
    
    // ========== 静态文件处理（WLS 模式特有） ==========
    $staticResponse = handleStaticFile($uri, $rawRequest);
    if ($staticResponse !== null) {
        $cacheInfo = $WLS_LAST_STATIC_CACHE ?? null;
        $cacheStatus = $cacheInfo['status'] ?? 'miss';
        $cacheUri = $cacheInfo['uri'] ?? $uri;
        $wlsLog(__('静态文件缓存: %{1} %{2}', [\strtoupper($cacheStatus), $cacheUri]), 'INFO');
        return $staticResponse;
    }
    // ========== 静态文件处理结束 ==========
    
    // 如果运行时初始化失败，返回错误
    if ($runtime === null) {
        $wlsLog("运行时未初始化，返回错误: {$runtimeError}", 'ERROR');
        $errorBody = \json_encode([
            'error' => true,
            'message' => 'Runtime initialization failed',
            'detail' => $runtimeError,
        ], JSON_UNESCAPED_UNICODE);
        
        return "HTTP/1.1 500 Internal Server Error\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: " . \strlen($errorBody) . "\r\nConnection: close\r\n\r\n" . $errorBody;
    }
    
    $wlsLog("准备进入框架处理: {$method} {$uri}", 'INFO');
    try {
        // 创建 WLS 请求对象（框架会自动处理维护模式）
        try {
            $wlsLog("开始创建 WlsRequest 对象", 'INFO');
            $request = \Weline\Framework\Http\WlsRequest::fromRaw($rawRequest, [
                'WLS_INSTANCE' => $instanceName,
                'WLS_WORKER_ID' => $workerId,
                'WLS_PORT' => $port,
                'WLS_REQUEST_COUNT' => $requestCount,
            ]);
            $wlsLog("WlsRequest 对象创建成功: " . \get_class($request), 'INFO');
        } catch (\Throwable $reqE) {
            $wlsLog("创建 WlsRequest 失败: " . $reqE->getMessage() . " (" . $reqE->getFile() . ":" . $reqE->getLine() . ")", 'ERROR');
            throw $reqE;
        }
        
        $wlsLog("调用 runtime->handle()，URI: {$uri}, 后端: " . ($request->isBackend() ? 'YES' : 'NO'), 'INFO');
        $handleStartTime = \microtime(true);
        try {
            $result = $runtime->handle($request);
            $handleEndTime = \microtime(true);
            $handleDuration = \round(($handleEndTime - $handleStartTime) * 1000, 2);
            $wlsLog("runtime->handle() 完成，耗时: {$handleDuration}ms，结果类型: " . \gettype($result), 'INFO');
        } catch (\Throwable $handleE) {
            $wlsLog("runtime->handle() 异常: " . $handleE->getMessage() . " (" . $handleE->getFile() . ":" . $handleE->getLine() . ")", 'ERROR');
            throw $handleE;
        }
        
        // 检查 $result 是否已经是 HTTP 响应字符串（例如重定向响应）
        // 如果以 "HTTP/" 开头，说明已经是完整的 HTTP 响应，直接返回
        if (\is_string($result) && \str_starts_with($result, 'HTTP/')) {
            $wlsLog("返回已格式化的 HTTP 响应（可能是重定向）", 'INFO');
            $sni = \Weline\Server\Service\RouteHintService::extractSniFromRawRequest($rawRequest);
            $result = \Weline\Server\Service\RouteHintService::addHintToResponse($result, $sni);
            // HEAD 请求只返回头，不返回 body
            if (\strtoupper($method) === 'HEAD') {
                $headerEnd = \strpos($result, "\r\n\r\n");
                if ($headerEnd !== false) {
                    $result = \substr($result, 0, $headerEnd + 4);
                }
            }
            return $result;
        }
        
        // WLS 模式下控制器通过 return 返回 body，header() 无效；需对 body trim 并可从 JSON 的 code 解析出状态码
        $result = \is_string($result) ? \trim($result) : (string) $result;
        $statusCode = 200;
        $first = \ltrim($result);
        if (($first[0] ?? '') === '{') {
            $decoded = \json_decode($result, true);
            if (\is_array($decoded) && \array_key_exists('code', $decoded)) {
                $code = (int) $decoded['code'];
                if ($code >= 400 && $code < 600) {
                    $statusCode = $code;
                }
            }
        }
        
        $response = \Weline\Framework\Http\WlsResponse::fromContent($result, $statusCode);
        
        // 添加路由提示头（用于 TCP 透传模式下的智能路由）
        $sni = \Weline\Server\Service\RouteHintService::extractSniFromRawRequest($rawRequest);
        \Weline\Server\Service\RouteHintService::addHintToWlsResponse($response, $sni);
        
        // 添加 WLS 调试响应头（供开发工具面板使用）
        $response->setHeader('X-WLS-Worker-Id', (string) $workerId);
        $response->setHeader('X-WLS-Worker-Port', (string) $port);
        $response->setHeader('X-WLS-Worker-PID', (string) \getmypid());
        $response->setHeader('X-WLS-Instance', $instanceName);
        $response->setHeader('X-WLS-Request-Count', (string) $requestCount);
        $response->setHeader('X-WLS-Memory', (string) \round(\memory_get_usage(true) / 1024 / 1024, 2));
        $response->setHeader('X-WLS-Uptime', (string) (\time() - $startTime));
        
        // 添加性能数据到响应头（便于在浏览器开发者工具中查看）
        $isDev = \defined('DEV') && DEV;
        if ($handleDuration >= 500 || $isDev) {
            $response->setHeader('X-WLS-Performance-Total', (string) \round($handleDuration, 2));
            $response->setHeader('X-WLS-Performance-Warning', $handleDuration >= 1000 ? 'SLOW' : 'OK');
        }
        
        // 临时禁用 gzip 压缩以排除压缩问题
        // $acceptEncoding = $request->getHeader('Accept-Encoding');
        // if ($acceptEncoding && \is_string($acceptEncoding)) {
        //     $response->compress($acceptEncoding);
        // }
        
        $httpResponse = $response->toHttpString($request->isKeepAlive());
        
        // HTTP 规范：HEAD 请求应该返回与 GET 请求相同的响应头，但不返回响应体
        // Content-Length 头部应该保留，告知客户端如果是 GET 请求会返回多大的内容
        if (\strtoupper($method) === 'HEAD') {
            $headerEnd = \strpos($httpResponse, "\r\n\r\n");
            if ($headerEnd !== false) {
                // 只保留响应头部分（包括末尾的 \r\n\r\n）
                $httpResponse = \substr($httpResponse, 0, $headerEnd + 4);
            }
        }
        
        return $httpResponse;
    } catch (\Throwable $e) {
        
        $wlsLog("请求处理错误: " . $e->getMessage() . " (文件: " . $e->getFile() . ":" . $e->getLine() . ")", 'ERROR');
        \error_log('[WLS Worker] Request error: ' . $e->getMessage());
        
        $statusCode = 500;
        $errorMessage = $e->getMessage() ?: 'Internal Server Error';
        
        if ($e instanceof \Weline\Framework\App\Exception) {
            $code = $e->getCode();
            if ($code >= 400 && $code < 600) {
                $statusCode = $code;
            }
        }
        
        $isDev = \defined('DEV') && DEV;
        if ($isDev || (\defined('DEBUG') && DEBUG)) {
            $errorBody = \json_encode([
                'error' => true,
                'message' => $errorMessage,
                'exception' => \get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => \explode("\n", $e->getTraceAsString()),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            // 生产模式：非 App\Exception 不暴露内部错误细节
            $safeMessage = ($e instanceof \Weline\Framework\App\Exception) ? $errorMessage : 'Internal Server Error';
            $errorBody = \json_encode([
                'error' => true,
                'message' => $safeMessage,
            ], JSON_UNESCAPED_UNICODE);
        }
        
        $response = new \Weline\Framework\Http\WlsResponse($errorBody, $statusCode);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        
        return $response->toHttpString(false);
    }
}

/**
 * 处理静态文件请求（WLS 模式特有）
 * 
 * 在 WLS 模式下，PHP 的 header() 和 readfile() 不起作用，
 * 需要在 Worker 层面直接读取文件并返回 HTTP 响应字符串。
 * 
 * 内存缓存策略：
 * - 小于 1MB 的文件缓存到内存，避免重复读取磁盘
 * - 缓存有效期 7 天（基于文件修改时间验证）
 * - 大于 1MB 的文件直接从磁盘读取（避免内存占用过大）
 * 
 * @param string $uri 请求 URI
 * @param string $rawRequest 原始请求（用于获取 If-Modified-Since 等头部）
 * @return string|null 如果是静态文件则返回 HTTP 响应字符串，否则返回 null
 */
function handleStaticFile(string $uri, string $rawRequest): ?string
{
    global $WLS_STATIC_CACHE_MAX_TOTAL, $WLS_STATIC_CACHE_MAX_SIZE, $WLS_CACHE_EVICTION_THRESHOLD, $WLS_LAST_STATIC_CACHE;
    $WLS_LAST_STATIC_CACHE = null;
    
    // ========== 静态文件内存缓存（冷热淘汰策略） ==========
    static $staticFileCache = [];
    static $staticFileCacheTotalSize = 0;
    static $staticFileCacheMaxAge = 86400 * 7;
    
    $maxTotal = $WLS_STATIC_CACHE_MAX_TOTAL ?? 100 * 1024 * 1024;
    $maxSize = $WLS_STATIC_CACHE_MAX_SIZE ?? 1024 * 1024;
    $evictionThreshold = $WLS_CACHE_EVICTION_THRESHOLD ?? 5 * 1024 * 1024;
    
    // 特殊命令：清理内存缓存
    if ($uri === '__CLEAR_CACHE__') {
        $count = \count($staticFileCache);
        $size = $staticFileCacheTotalSize;
        $staticFileCache = [];
        $staticFileCacheTotalSize = 0;
        return "cleared:{$count}:{$size}";
    }
    
    // 特殊命令：获取缓存状态
    if ($uri === '__CACHE_STATUS__') {
        return \json_encode([
            'count' => \count($staticFileCache),
            'size' => $staticFileCacheTotalSize,
            'max_total' => $maxTotal,
        ]);
    }
    
    // 冷热淘汰函数
    $evictColdCache = static function (int $neededSpace) use (&$staticFileCache, &$staticFileCacheTotalSize, $maxTotal, $evictionThreshold): void {
        $targetSize = $maxTotal - $evictionThreshold - $neededSpace;
        if ($staticFileCacheTotalSize <= $targetSize) return;
        
        $now = \time();
        $candidates = [];
        foreach ($staticFileCache as $path => $item) {
            $hits = $item['hits'] ?? 0;
            $lastAccess = $item['last_access'] ?? $item['cached_at'];
            $age = $now - $lastAccess;
            $recencyBonus = \max(0, 100 - (int)($age / 60));
            $score = $hits * 10 + $recencyBonus;
            $candidates[] = ['path' => $path, 'score' => $score, 'size' => $item['size']];
        }
        \usort($candidates, fn($a, $b) => $a['score'] <=> $b['score']);
        foreach ($candidates as $c) {
            if ($staticFileCacheTotalSize <= $targetSize) break;
            if (isset($staticFileCache[$c['path']])) {
                $staticFileCacheTotalSize -= $staticFileCache[$c['path']]['size'];
                unset($staticFileCache[$c['path']]);
            }
        }
    };
    
    // 静态文件扩展名列表
    static $staticExtensions = [
        'css', 'js', 'map',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp',
        'woff', 'woff2', 'eot', 'ttf', 'otf',
        'mp4', 'mp3', 'webm', 'ogg', 'm3u8',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'json', 'xml',
        'zip', 'rar', '7z', 'gz', 'tar',
    ];
    
    // MIME 类型映射
    static $mimeTypes = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'map' => 'application/json',
        'json' => 'application/json; charset=utf-8',
        'xml' => 'application/xml; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'bmp' => 'image/bmp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'eot' => 'application/vnd.ms-fontobject',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'audio/ogg',
        'mp3' => 'audio/mpeg',
        'm3u8' => 'application/vnd.apple.mpegurl',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'gz' => 'application/gzip',
    ];
    
    // 解析文件扩展名（去除查询字符串）
    $uriPath = \parse_url($uri, PHP_URL_PATH) ?? $uri;
    $extension = \strtolower(\pathinfo($uriPath, PATHINFO_EXTENSION));
    
    // 不是静态文件，交给框架处理
    if (empty($extension) || !\in_array($extension, $staticExtensions, true)) {
        return null;
    }
    
    // 安全检查：防止目录遍历
    $normalizedUri = \str_replace(['../', '..\\'], '', $uriPath);
    $normalizedUri = \ltrim($normalizedUri, '/\\');
    
    // 查找文件位置（按优先级）
    $filename = null;
    $searchPaths = [];
    
    // 1. pub 目录（发布的静态资源）
    $searchPaths[] = BP . 'pub' . DS . $normalizedUri;
    
    // 2. app/code 目录（模块视图资源）
    // URL 格式: /Vendor/Module/view/... -> app/code/Vendor/Module/view/...
    $searchPaths[] = BP . 'app' . DS . 'code' . DS . \str_replace('/', DS, $normalizedUri);
    
    // 3. vendor 目录
    $searchPaths[] = BP . 'vendor' . DS . \str_replace('/', DS, $normalizedUri);
    
    // 4. 根目录
    $searchPaths[] = BP . \str_replace('/', DS, $normalizedUri);
    
    foreach ($searchPaths as $path) {
        // 修复双斜杠
        $path = \str_replace([DS . DS, '//'], DS, $path);
        if (\is_file($path) && \is_readable($path)) {
            $filename = $path;
            break;
        }
    }
    
    // 文件不存在，交给框架处理（可能是动态生成的资源）
    if ($filename === null) {
        return null;
    }
    
    // 默认标记为 MISS（非内存缓存命中）
    $WLS_LAST_STATIC_CACHE = [
        'status' => 'miss',
        'uri' => $uriPath,
        'path' => $filename,
    ];
    
    // 获取文件修改时间
    $mtime = \filemtime($filename);
    $lastModified = \gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
    $etag = '"' . \md5($filename . $mtime) . '"';
    
    // 检查缓存验证（304 Not Modified）- 精简响应头
    if (\preg_match('/If-Modified-Since:\s*([^\r\n]+)/i', $rawRequest, $matches)) {
        $ifModifiedSince = \trim($matches[1]);
        if ($ifModifiedSince === $lastModified) {
            return "HTTP/1.1 304 Not Modified\r\nETag: {$etag}\r\nConnection: keep-alive\r\n\r\n";
        }
    }
    
    if (\preg_match('/If-None-Match:\s*([^\r\n]+)/i', $rawRequest, $matches)) {
        $ifNoneMatch = \trim($matches[1]);
        if ($ifNoneMatch === $etag) {
            return "HTTP/1.1 304 Not Modified\r\nETag: {$etag}\r\nConnection: keep-alive\r\n\r\n";
        }
    }
    
    // 获取文件大小
    $fileSize = \filesize($filename);
    
    // 获取 MIME 类型
    $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';
    
    // 缓存控制（静态资源可以长期缓存）
    $maxAge = 86400 * 7; // 7 天
    $expires = \gmdate('D, d M Y H:i:s', \time() + $maxAge) . ' GMT';
    
    // 大文件阈值（超过此大小使用流式传输标记）
    $largeFileThreshold = 2 * 1024 * 1024; // 2MB
    
    // 检查 Range 请求（用于视频/音频断点续传）
    $rangeStart = 0;
    $rangeEnd = $fileSize - 1;
    $isRangeRequest = false;
    
    if (\preg_match('/Range:\s*bytes=(\d*)-(\d*)/i', $rawRequest, $matches)) {
        $isRangeRequest = true;
        if ($matches[1] !== '') {
            $rangeStart = (int) $matches[1];
        }
        if ($matches[2] !== '') {
            $rangeEnd = (int) $matches[2];
        }
        // 验证范围
        if ($rangeStart > $rangeEnd || $rangeStart >= $fileSize) {
            return "HTTP/1.1 416 Range Not Satisfiable\r\n" .
                   "Content-Range: bytes */{$fileSize}\r\n" .
                   "Connection: close\r\n" .
                   "\r\n";
        }
        $rangeEnd = \min($rangeEnd, $fileSize - 1);
    }
    
    $contentLength = $rangeEnd - $rangeStart + 1;
    
    // 小文件：直接读取并返回（精简响应头，移除冗余信息）
    if ($fileSize <= $largeFileThreshold && !$isRangeRequest) {
        $content = null;
        $fromCache = false;
        $now = \time();
        
        // ========== 内存缓存策略（冷热淘汰） ==========
        if ($fileSize <= $maxSize) {
            // 检查缓存是否存在且有效
            if (isset($staticFileCache[$filename])) {
                $cached = $staticFileCache[$filename];
                if ($cached['mtime'] === $mtime && ($now - $cached['cached_at']) < $staticFileCacheMaxAge) {
                    $content = $cached['content'];
                    $fromCache = true;
                    $WLS_LAST_STATIC_CACHE['status'] = 'hit';
                    // 更新访问统计（冷热计数）
                    $staticFileCache[$filename]['hits'] = ($cached['hits'] ?? 0) + 1;
                    $staticFileCache[$filename]['last_access'] = $now;
                } else {
                    $staticFileCacheTotalSize -= $cached['size'];
                    unset($staticFileCache[$filename]);
                }
            }
            
            if ($content === null) {
                $content = @\file_get_contents($filename);
                if ($content === false) {
                    return null;
                }
                
                // 剩余空间不足时启动冷热淘汰
                $remainingSpace = $maxTotal - $staticFileCacheTotalSize;
                if ($remainingSpace - $fileSize < $evictionThreshold) {
                    $evictColdCache($fileSize);
                }
                
                if ($staticFileCacheTotalSize + $fileSize <= $maxTotal) {
                    $staticFileCache[$filename] = [
                        'content' => $content,
                        'mtime' => $mtime,
                        'size' => $fileSize,
                        'cached_at' => $now,
                        'hits' => 1,
                        'last_access' => $now,
                    ];
                    $staticFileCacheTotalSize += $fileSize;
                }
            }
        } else {
            $content = @\file_get_contents($filename);
            if ($content === false) {
                return null;
            }
        }
        
        // 使用实际内容长度，避免 Content-Length 与实际内容不匹配
        $actualContentLength = \strlen($content);
        
        // H14: 验证内容长度与文件大小是否一致
        if ($actualContentLength !== $fileSize && !$fromCache) {
            // 文件可能在读取过程中被修改，重新读取
            $content = @\file_get_contents($filename);
            if ($content === false) {
                return null;
            }
            $actualContentLength = \strlen($content);
        }
        
        $response = "HTTP/1.1 200 OK\r\n";
        $response .= "Content-Type: {$mimeType}\r\n";
        $response .= "Content-Length: {$actualContentLength}\r\n";
        $response .= "Cache-Control: public, max-age={$maxAge}\r\n";
        $response .= "ETag: {$etag}\r\n";
        $response .= "Accept-Ranges: bytes\r\n";
        $response .= "Connection: keep-alive\r\n";
        // WLS 内存缓存状态标识（HIT=内存缓存命中, MISS=磁盘读取）
        $response .= "X-WLS-Static-Cache: " . ($fromCache ? 'HIT' : 'MISS') . "\r\n";
        $response .= "X-WLS-File-Size: {$fileSize}\r\n";
        $response .= "X-WLS-Content-Length: {$actualContentLength}\r\n";
        $response .= "\r\n";
        $response .= $content;
        
        // H14: 最终验证响应完整性
        $headerEndPos = \strpos($response, "\r\n\r\n");
        $actualBodyLen = \strlen($response) - $headerEndPos - 4;
        if ($actualBodyLen !== $actualContentLength) {
            return "HTTP/1.1 500 Internal Server Error\r\n" .
                   "Content-Type: text/plain\r\n" .
                   "Content-Length: 25\r\n" .
                   "Connection: close\r\n" .
                   "\r\n" .
                   "Response build error: {$actualBodyLen}/{$actualContentLength}";
        }
        
        return $response;
    }
    
    // 大文件或 Range 请求：使用分块读取
    $fp = @\fopen($filename, 'rb');
    if ($fp === false) {
        return null;
    }
    
    // 定位到起始位置
    if ($rangeStart > 0) {
        \fseek($fp, $rangeStart);
    }
    
    // 构建精简响应头
    if ($isRangeRequest) {
        $response = "HTTP/1.1 206 Partial Content\r\n";
        $response .= "Content-Range: bytes {$rangeStart}-{$rangeEnd}/{$fileSize}\r\n";
    } else {
        $response = "HTTP/1.1 200 OK\r\n";
    }
    
    // H13: 根据客户端请求设置正确的 Connection 头
    $isHttp11 = \strpos($rawRequest, 'HTTP/1.1') !== false;
    $hasCloseHeader = \stripos($rawRequest, 'Connection: close') !== false;
    $keepAlive = $isHttp11 && !$hasCloseHeader;
    $connectionHeader = $keepAlive ? 'keep-alive' : 'close';
    
    $response .= "Content-Type: {$mimeType}\r\n";
    $response .= "Content-Length: {$contentLength}\r\n";
    $response .= "Cache-Control: public, max-age={$maxAge}\r\n";
    $response .= "ETag: {$etag}\r\n";
    $response .= "Accept-Ranges: bytes\r\n";
    $response .= "Connection: {$connectionHeader}\r\n";
    // WLS 内存缓存状态标识（DISK=大文件/Range请求，不缓存直接磁盘读取）
    $response .= "X-WLS-Static-Cache: DISK\r\n";
    $response .= "X-WLS-File-Size: {$fileSize}\r\n";
    $response .= "\r\n";
    
    // H14: 分块读取文件内容（每次 64KB）
    // 注意：必须确保实际读取的字节数与 Content-Length 匹配
    $chunkSize = 64 * 1024;
    $remaining = $contentLength;
    $totalRead = 0;
    
    while ($remaining > 0 && !\feof($fp)) {
        $readSize = \min($chunkSize, $remaining);
        $chunk = \fread($fp, $readSize);
        if ($chunk === false) {
            // 读取失败，关闭文件并返回错误
            \fclose($fp);
            return "HTTP/1.1 500 Internal Server Error\r\n" .
                   "Content-Type: text/plain\r\n" .
                   "Content-Length: 21\r\n" .
                   "Connection: close\r\n" .
                   "\r\n" .
                   "File read error: {$uri}";
        }
        $chunkLen = \strlen($chunk);
        if ($chunkLen === 0) {
            // EOF 提前到达，可能是文件在读取过程中被修改
            break;
        }
        $response .= $chunk;
        $remaining -= $chunkLen;
        $totalRead += $chunkLen;
    }
    
    \fclose($fp);
    
    // H14: 验证实际读取的字节数是否与 Content-Length 匹配
    // 如果不匹配，需要修正响应或返回错误
    if ($totalRead !== $contentLength) {
        // 文件可能在读取过程中被修改，返回错误而不是不完整的响应
        return "HTTP/1.1 500 Internal Server Error\r\n" .
               "Content-Type: text/plain\r\n" .
               "Content-Length: 35\r\n" .
               "Connection: close\r\n" .
               "\r\n" .
               "File changed during read: {$totalRead}/{$contentLength}";
    }
    
    return $response;
}
