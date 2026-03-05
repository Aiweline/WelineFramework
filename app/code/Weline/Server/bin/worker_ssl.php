<?php
declare(strict_types=1);

/**
 * Weline Server Worker 独立进程 (SSL/HTTPS)
 * 
 * 用法: php worker_ssl.php <host> <port> <worker_id> <instance_name> <ssl_cert> <ssl_key>
 * 
 * 该 Worker 进程集成框架路由，支持完整的 HTTPS 请求处理
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
$sslCert = $argv[5] ?? '';
$sslKey = $argv[6] ?? '';

// 解析命令行参数
$processName = '';
$isFrontend = false;
$useReusePort = false;  // 是否使用 SO_REUSEPORT（Linux 直连模式）
$deferSsl = false;      // 延迟 SSL 模式（用于 TCP 透传架构，先接受 TCP 连接，再手动启用 SSL）
$orchestratorEpoch = 0;
$orchestratorLaunchId = '';

foreach ($argv as $arg) {
    if (\str_starts_with($arg, '--name=')) {
        $processName = \substr($arg, 7);
    } elseif ($arg === '--frontend' || $arg === '-frontend') {
        $isFrontend = true;
    } elseif ($arg === '--reuseport' || $arg === '-reuseport') {
        $useReusePort = true;
    } elseif ($arg === '--defer-ssl' || $arg === '-defer-ssl') {
        $deferSsl = true;
    } elseif (\str_starts_with($arg, '--control-port=')) {
        $controlPort = (int)\substr($arg, 15);
    } elseif ($arg === '--maintenance') {
        $isMaintenanceWorker = true;
    } elseif (\str_starts_with($arg, '--master-pid=')) {
        $masterPid = (int)\substr($arg, 13);
    } elseif (\str_starts_with($arg, '--epoch=')) {
        $orchestratorEpoch = (int)\substr($arg, 8);
    } elseif (\str_starts_with($arg, '--launch-id=')) {
        $orchestratorLaunchId = (string)\substr($arg, 12);
    } elseif (\str_starts_with($arg, '--ssl-cert=')) {
        $sslCert = \substr($arg, 11);
    } elseif (\str_starts_with($arg, '--ssl-key=')) {
        $sslKey = \substr($arg, 10);
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

// 将相对路径转换为绝对路径
if ($sslCert && !\preg_match('/^[a-zA-Z]:[\\\\\\/]|^\//', $sslCert)) {
    $sslCert = $bp . \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sslCert);
}
if ($sslKey && !\preg_match('/^[a-zA-Z]:[\\\\\\/]|^\//', $sslKey)) {
    $sslKey = $bp . \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sslKey);
}

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

// 初始化 WLS 统一错误捕获系统（Layer 1-3）
use Weline\Server\Log\Error\ErrorBootstrap;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Log\LogLevel;

ErrorBootstrap::init('WorkerSSL#' . $workerId . ':' . $port, [
    'worker_id' => $workerId,
    'port' => $port,
    'instance' => $instanceName,
    'process_name' => $processName,
    'is_maintenance' => $isMaintenanceWorker,
    'ssl' => true,
]);

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

// ========== 日志系统：直接使用 WlsLogger ==========
// 检测模式（只检测一次）
$isDev = false;
if (\defined('DEV') && DEV) {
    $isDev = true;
} elseif ($envConfig !== null && isset($envConfig['deploy']) && $envConfig['deploy'] === 'dev') {
    $isDev = true;
}

// 前台模式：启用控制台输出
if ($isFrontend) {
    WlsLogger::getInstance()
        ->setStdoutEnabled(true)
        ->setProcessTag('WorkerSSL#' . $workerId . ':' . $port);
}
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
    WlsLogger::info_("Worker 启动，监听 ssl://{$host}:{$port}");
    $runtime = new \Weline\Framework\Runtime\WlsRuntime();
    $runtime->bootstrap();
    WlsLogger::info_("框架运行时初始化成功");
} catch (\Throwable $e) {
    $runtimeError = $e->getMessage();
    WlsLogger::error_("框架运行时初始化失败: " . $e->getMessage());
    w_log_error('[WLS Worker SSL] Bootstrap error: ' . $e->getMessage());
}

// ========== WLS 内存缓存配置（智能模式） ==========
// 读取 env 配置中的 WLS 缓存配置
$wlsCacheConfig = [];
if ($envConfig !== null && isset($envConfig['server']['cache'])) {
    $wlsCacheConfig = $envConfig['server']['cache'];
}

/**
 * 检查 shell_exec 函数是否可用
 * @return bool
 */
function isShellExecAvailable(): bool
{
    static $available = null;
    if ($available === null) {
        $available = \function_exists('shell_exec') 
            && !\in_array('shell_exec', \array_map('trim', \explode(',', \ini_get('disable_functions') ?: '')), true);
    }
    return $available;
}

/**
 * 获取系统可用内存（字节）
 * @return int 可用内存字节数，获取失败返回 0
 */
function getSystemFreeMemory(): int
{
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows: 使用 wmic 获取可用内存（如果 shell_exec 可用）
        if (isShellExecAvailable()) {
            $output = @\shell_exec('wmic OS get FreePhysicalMemory /value 2>nul');
            if ($output && \preg_match('/FreePhysicalMemory=(\d+)/', $output, $matches)) {
                return (int)$matches[1] * 1024; // KB 转 bytes
            }
        }
        // 回退：返回 0（使用默认值）
        return 0;
    } else {
        // Linux/Mac: 读取 /proc/meminfo 或使用 free 命令
        if (\is_readable('/proc/meminfo')) {
            $meminfo = @\file_get_contents('/proc/meminfo');
            if ($meminfo && \preg_match('/MemAvailable:\s*(\d+)\s*kB/i', $meminfo, $matches)) {
                return (int)$matches[1] * 1024; // KB 转 bytes
            }
            // 回退：MemFree + Cached + Buffers
            if ($meminfo) {
                $free = 0;
                if (\preg_match('/MemFree:\s*(\d+)\s*kB/i', $meminfo, $m)) {
                    $free += (int)$m[1];
                }
                if (\preg_match('/Cached:\s*(\d+)\s*kB/i', $meminfo, $m)) {
                    $free += (int)$m[1];
                }
                if (\preg_match('/Buffers:\s*(\d+)\s*kB/i', $meminfo, $m)) {
                    $free += (int)$m[1];
                }
                if ($free > 0) {
                    return $free * 1024;
                }
            }
        }
        // Mac: vm_stat 仅 "Pages free" 偏小，需加上可回收的 inactive/speculative（与 Linux MemAvailable 语义一致）
        // 注意：macOS 可能输出千位逗号（如 "1,234,567"），需去掉逗号再转 int，否则会误判为内存严重不足
        if (isShellExecAvailable()) {
            $output = @\shell_exec('vm_stat 2>/dev/null');
            if ($output) {
                $pageSize = 4096; // macOS 页面大小（Intel/Apple Silicon 均为 4KB）
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
    }
    return 0;
}

/**
 * 获取系统总内存（字节）
 * @return int 总内存字节数
 */
function getSystemTotalMemory(): int
{
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows: 使用 wmic 获取总内存（如果 shell_exec 可用）
        if (isShellExecAvailable()) {
            $output = @\shell_exec('wmic ComputerSystem get TotalPhysicalMemory /value 2>nul');
            if ($output && \preg_match('/TotalPhysicalMemory=(\d+)/', $output, $matches)) {
                return (int)$matches[1];
            }
        }
        // 回退：返回默认值
        return 4 * 1024 * 1024 * 1024; // 4GB
    } else {
        if (\is_readable('/proc/meminfo')) {
            $meminfo = @\file_get_contents('/proc/meminfo');
            if ($meminfo && \preg_match('/MemTotal:\s*(\d+)\s*kB/i', $meminfo, $matches)) {
                return (int)$matches[1] * 1024;
            }
        }
        // Mac（如果 shell_exec 可用）
        if (isShellExecAvailable()) {
            $output = @\shell_exec('sysctl -n hw.memsize 2>/dev/null');
            if ($output) {
                return (int)\trim($output);
            }
        }
    }
    // 默认返回 4GB
    return 4 * 1024 * 1024 * 1024;
}

/**
 * 智能计算缓存大小
 * @param string $configValue 配置值：'auto'、'50M'、'100MB'、数字（字节）
 * @param int $defaultPercent 默认百分比（相对于系统内存）
 * @param int $defaultMin 默认最小值（字节）
 * @param int $defaultMax 默认最大值（字节）
 * @return int 缓存大小（字节）
 */
function calculateCacheSize(string|int $configValue, int $defaultPercent, int $defaultMin, int $defaultMax): int
{
    // 数字直接返回
    if (\is_int($configValue)) {
        return $configValue;
    }
    
    $configValue = \strtolower(\trim($configValue));
    
    // 'auto' 或空：智能计算
    if ($configValue === 'auto' || $configValue === '') {
        $totalMem = getSystemTotalMemory();
        $calculated = (int)($totalMem * $defaultPercent / 100);
        return \max($defaultMin, \min($defaultMax, $calculated));
    }
    
    // 解析带单位的值：50M, 100MB, 1G, 1GB
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
    
    // 解析失败，返回默认最小值
    return $defaultMin;
}

// 计算静态文件缓存大小
// 默认：系统内存的 2%，最小 32MB，最大 256MB
$staticFileCacheMaxTotalConfig = $wlsCacheConfig['static_file_max_total'] ?? 'auto';
$WLS_STATIC_CACHE_MAX_TOTAL = calculateCacheSize($staticFileCacheMaxTotalConfig, 2, 32 * 1024 * 1024, 256 * 1024 * 1024);

// 单文件最大缓存大小（H13: 提高默认值到 2MB，支持大型 JS 库如 CKEditor）
$staticFileCacheMaxSizeConfig = $wlsCacheConfig['static_file_max_size'] ?? '2M';
$WLS_STATIC_CACHE_MAX_SIZE = calculateCacheSize($staticFileCacheMaxSizeConfig, 0, 512 * 1024, 10 * 1024 * 1024);

// 缓存淘汰临界值：剩余多少字节时开始淘汰
$WLS_CACHE_EVICTION_THRESHOLD = (int)($wlsCacheConfig['eviction_threshold'] ?? 5 * 1024 * 1024); // 默认 5MB

// 检查启动时内存是否足够
$freeMemory = getSystemFreeMemory();
$requiredMemory = $WLS_STATIC_CACHE_MAX_TOTAL + 50 * 1024 * 1024; // 缓存 + 50MB 预留

if ($freeMemory > 0 && $freeMemory < $requiredMemory) {
    $freeMB = \round($freeMemory / 1024 / 1024, 1);
    $requiredMB = \round($requiredMemory / 1024 / 1024, 1);
    $cacheMB = \round($WLS_STATIC_CACHE_MAX_TOTAL / 1024 / 1024, 1);
    
    WlsLogger::warning_("内存不足警告：系统可用内存 {$freeMB}MB，WLS 需要 {$requiredMB}MB（缓存 {$cacheMB}MB + 50MB 预留）");
    
    // 如果严重不足（低于需求的 50%），报错退出
    if ($freeMemory < $requiredMemory * 0.5) {
        WlsLogger::error_("内存严重不足，无法启动。请增加系统内存或减少 env.php 中的 server.cache.static_file_max_total 配置");
        exit(1);
    }
    
    // 自动缩减缓存大小
    $newCacheSize = (int)($freeMemory * 0.6); // 使用 60% 的可用内存
    $newCacheMB = \round($newCacheSize / 1024 / 1024, 1);
    WlsLogger::warning_("自动缩减静态文件缓存至 {$newCacheMB}MB");
    $WLS_STATIC_CACHE_MAX_TOTAL = $newCacheSize;
}

WlsLogger::info_("内存缓存配置：静态文件缓存上限 " . \round($WLS_STATIC_CACHE_MAX_TOTAL / 1024 / 1024, 1) . "MB，单文件上限 " . \round($WLS_STATIC_CACHE_MAX_SIZE / 1024, 1) . "KB，淘汰阈值 " . \round($WLS_CACHE_EVICTION_THRESHOLD / 1024 / 1024, 1) . "MB");
// ========== 内存缓存配置结束 ==========

// 注册补充 shutdown handler（检测 die()/exit() 非正常退出）
// 注：致命错误由 ErrorBootstrap 统一处理，此处仅处理非致命退出
\register_shutdown_function(function() use ($workerId, $port, $instanceName) {
    $error = \error_get_last();
    $fatalErrorTypes = [\E_ERROR, \E_PARSE, \E_CORE_ERROR, \E_COMPILE_ERROR, \E_RECOVERABLE_ERROR, \E_USER_ERROR];
    
    // 致命错误由 ErrorBootstrap 处理，不在此重复
    if ($error !== null && \in_array($error['type'], $fatalErrorTypes, true)) {
        return;
    }
    
    // 无致命错误但进程即将退出：多为业务代码 die()/exit() 或信号终止
    $exitMsg = "Worker SSL 非致命退出，可能为 die()/exit() 或信号终止";
    WlsLogger::warning_($exitMsg);
    WlsLogger::flush_(true);
});

// 确定最高支持的 TLS 版本
$cryptoMethod = STREAM_CRYPTO_METHOD_TLS_SERVER;
if (\defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER')) {
    $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_3_SERVER | STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_1_SERVER | STREAM_CRYPTO_METHOD_TLSv1_0_SERVER;
} elseif (\defined('STREAM_CRYPTO_METHOD_TLSv1_2_SERVER')) {
    $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_1_SERVER | STREAM_CRYPTO_METHOD_TLSv1_0_SERVER;
}

// 检测 SO_REUSEPORT 支持（Linux 3.9+，允许多进程监听同一端口）
$isWindows = \PHP_OS_FAMILY === 'Windows';
$supportsReusePort = false;

// 检测 SO_REUSEPORT 支持
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
    WlsLogger::error_("平台不支持 SO_REUSEPORT");
    exit(1);
}

// ========== Socket 创建 ==========

$socket = null;

// 延迟 SSL 时共用：accept 后根据首包判断 HTTP 重定向或启用 SSL（同端口 http→https）
if ($deferSsl) {
    $deferSslOptions = [
        'local_cert' => $sslCert,
        'local_pk' => $sslKey,
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
        'disable_compression' => true,
        'crypto_method' => $cryptoMethod,
        'ciphers' => 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384:HIGH:!aNULL:!MD5:!RC4',
        'single_dh_use' => true,
        'honor_cipher_order' => true,
        'SNI_enabled' => false,
    ];
}

// 特权端口权限检查（macOS/Linux）
if (\PHP_OS !== 'WINNT' && $port < 1024) {
    $euid = \function_exists('posix_geteuid') ? (int)\posix_geteuid() : -1;
    if ($euid !== 0 && $euid !== -1) {
        WlsLogger::error_("错误：尝试绑定特权端口 {$port} 但当前进程不是 root (euid: {$euid})");
        WlsLogger::error_("请使用 sudo php bin/w server:start 启动服务器");
        exit(1);
    }
}

// 方案1a：SO_REUSEPORT + 延迟 SSL（同端口 HTTP→HTTPS 重定向，与方案2b 行为一致）
if ($useReusePort && $supportsReusePort && $deferSsl && \function_exists('socket_create')) {
    WlsLogger::info_("使用 SO_REUSEPORT + 延迟 SSL，监听 tcp://{$host}:{$port}（同端口 HTTP→HTTPS 重定向）");
    $rawSocket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if (!$rawSocket) {
        WlsLogger::error_("socket_create 失败: " . \socket_strerror(\socket_last_error()));
        exit(1);
    }
    if (!@\socket_set_option($rawSocket, SOL_SOCKET, SO_REUSEADDR, 1)) {
        WlsLogger::warning_("设置 SO_REUSEADDR 失败");
    }
    if (!@\socket_set_option($rawSocket, SOL_SOCKET, SO_REUSEPORT, 1)) {
        WlsLogger::error_("设置 SO_REUSEPORT 失败: " . \socket_strerror(\socket_last_error($rawSocket)));
        @\socket_close($rawSocket);
        exit(1);
    }
    if (!@\socket_bind($rawSocket, $host, $port)) {
        $errCode = \socket_last_error($rawSocket);
        $errMsg = \socket_strerror($errCode);
        WlsLogger::error_("socket_bind 失败: ({$errCode}) {$errMsg}");
        @\socket_close($rawSocket);
        exit(1);
    }
    if (!@\socket_listen($rawSocket, 102400)) {
        WlsLogger::error_("socket_listen 失败: " . \socket_strerror(\socket_last_error($rawSocket)));
        @\socket_close($rawSocket);
        exit(1);
    }
    $socket = \socket_export_stream($rawSocket);
    if (!$socket) {
        WlsLogger::error_("socket_export_stream 失败");
        @\socket_close($rawSocket);
        exit(1);
    }
    // 不在此 socket 上启用 SSL，由 accept 后按首包处理
    WlsLogger::info_("SO_REUSEPORT + 延迟 SSL socket 创建成功，Worker #{$workerId} 监听 {$host}:{$port}");

// 方案1b：使用 socket 扩展创建支持 SO_REUSEPORT 的 socket（直接 SSL，无同端口重定向）
} elseif ($useReusePort && $supportsReusePort && \function_exists('socket_create')) {
    WlsLogger::info_("使用 socket 扩展创建 SO_REUSEPORT socket...");
    
    // 创建原始 socket
    $rawSocket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if (!$rawSocket) {
        WlsLogger::error_("socket_create 失败: " . \socket_strerror(\socket_last_error()));
        exit(1);
    }
    
    // 设置 SO_REUSEADDR
    if (!@\socket_set_option($rawSocket, SOL_SOCKET, SO_REUSEADDR, 1)) {
        WlsLogger::warning_("设置 SO_REUSEADDR 失败");
    }
    
    // 设置 SO_REUSEPORT
    if (!@\socket_set_option($rawSocket, SOL_SOCKET, SO_REUSEPORT, 1)) {
        WlsLogger::error_("设置 SO_REUSEPORT 失败: " . \socket_strerror(\socket_last_error($rawSocket)));
        @\socket_close($rawSocket);
        exit(1);
    }
    
    // 绑定地址
    if (!@\socket_bind($rawSocket, $host, $port)) {
        $errCode = \socket_last_error($rawSocket);
        $errMsg = \socket_strerror($errCode);
        WlsLogger::error_("socket_bind 失败: ({$errCode}) {$errMsg}");
        
        // 如果端口被占用，直接退出进程，不再重试
        if ($errCode === 10048 || $errCode === 98) { // Windows: 10048, Linux: 98
            @\socket_close($rawSocket);
            exit(1); // 退出码 1 通知 Master 启动失败
        }
        
        @\socket_close($rawSocket);
        exit(1);
    }
    
    // 开始监听
    if (!@\socket_listen($rawSocket, 102400)) {
        WlsLogger::error_("socket_listen 失败: " . \socket_strerror(\socket_last_error($rawSocket)));
        @\socket_close($rawSocket);
        exit(1);
    }
    
    // 将 socket 资源转换为 stream
    $socket = \socket_export_stream($rawSocket);
    if (!$socket) {
        WlsLogger::error_("socket_export_stream 失败");
        @\socket_close($rawSocket);
        exit(1);
    }
    
    // 启用 SSL 加密（手动处理）
    $sslContext = \stream_context_create([
        'ssl' => [
            'local_cert' => $sslCert,
            'local_pk' => $sslKey,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'disable_compression' => true,
            'crypto_method' => $cryptoMethod,
            'ciphers' => 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384:HIGH:!aNULL:!MD5:!RC4',
            'single_dh_use' => true,
            'honor_cipher_order' => true,
        ]
    ]);
    \stream_context_set_params($socket, \stream_context_get_params($sslContext));
    
    WlsLogger::info_("SO_REUSEPORT socket 创建成功，Worker #{$workerId} 监听 {$host}:{$port}");
    
} elseif ($deferSsl) {
    // 方案2b：延迟 SSL 模式（TCP 透传架构）
    // 先监听纯 TCP，在 accept 后手动启用 SSL（同端口 HTTP→HTTPS 重定向）
    $socketOptions = [
        'backlog' => 102400,
        'so_reuseaddr' => true,
    ];

    $context = \stream_context_create([
        'socket' => $socketOptions,
        'ssl' => $deferSslOptions,
    ]);

    // 监听纯 TCP（延迟 SSL）
    $socket = @\stream_socket_server(
        "tcp://{$host}:{$port}",
        $errno,
        $errstr,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        $context
    );

    if (!$socket) {
        WlsLogger::error_("Socket 创建失败 (defer-ssl): {$errstr} (errno: {$errno})");
        w_log_error("[WLS Worker SSL] Failed to create socket (defer-ssl): {$errstr}");
        exit(1);
    }
    
    WlsLogger::info_("延迟 SSL 模式: 监听 tcp://{$host}:{$port}，accept 后手动启用 SSL");
    
} else {
    // 方案2：标准 stream_socket_server 方式
    $socketOptions = [
        'backlog' => 102400,  // 增大 backlog 提高并发
        'so_reuseaddr' => true,
    ];

    // Linux 下尝试启用 SO_REUSEPORT（通过 stream context，可能不被支持）
    if ($supportsReusePort && !$useReusePort) {
        $socketOptions['so_reuseport'] = true;
        WlsLogger::info_("尝试通过 stream_context 启用 SO_REUSEPORT");
    }

    $context = \stream_context_create([
        'socket' => $socketOptions,
        'ssl' => [
            'local_cert' => $sslCert,
            'local_pk' => $sslKey,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'disable_compression' => true,
            'crypto_method' => $cryptoMethod,
            'ciphers' => 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384:HIGH:!aNULL:!MD5:!RC4',
            'single_dh_use' => true,
            'honor_cipher_order' => true,
        ]
    ]);

    $socket = @\stream_socket_server(
        "ssl://{$host}:{$port}",
        $errno,
        $errstr,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        $context
    );

    if (!$socket) {
        WlsLogger::error_("Socket 创建失败: {$errstr} (errno: {$errno})");
        w_log_error("[WLS Worker SSL] Failed to create socket: {$errstr}");
        exit(1);
    }
}

WlsLogger::info_("Socket 创建成功，开始监听连接");

\stream_set_blocking($socket, false);

// ========== IPC 控制通道：连接 Master 并注册 + 上报就绪 ==========
$ipcClient = null;
$ipcReceivedShutdown = false;
$ipcDraining = false; // 是否正在排水（收到 reload 后关闭监听 socket，处理剩余请求）
$drainStartTime = 0;   // 排水开始时间戳
$maxDrainTime = 10;     // 排水最大等待时间（秒），超时后强制关闭所有连接退出

// 如果启用了维护模式
if ($isMaintenanceWorker) {
    try {
        \Weline\Framework\App\Env::getInstance()->setConfig('system.maintenance', true);
        WlsLogger::info_("维护 Worker 模式已启用");
    } catch (\Throwable $e) {
        WlsLogger::warning_("设置维护模式失败: " . $e->getMessage());
    }
}

// 获取控制端口
if ($controlPort <= 0) {
    // 从实例文件读取
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
    // DEV 模式下输出详细 IPC SEND/RECV 明细
    $ipcClient->setVerboseLog((\defined('DEV') && DEV) || (\defined('WLS_DEV_MODE') && WLS_DEV_MODE));
    if ($ipcClient->connect('127.0.0.1', $controlPort)) {
        $ipcRole = $isMaintenanceWorker ? \Weline\Server\IPC\ControlMessage::ROLE_MAINTENANCE : \Weline\Server\IPC\ControlMessage::ROLE_WORKER;
        $ipcClient->register($ipcRole, \getmypid(), $port, $workerId, $orchestratorEpoch, $orchestratorLaunchId);
        WlsLogger::info_("IPC 控制通道已连接 (控制端口: {$controlPort})");
        
        // 框架已初始化 + Socket 已创建 → 上报就绪
        $ipcClient->sendReady($ipcRole, $workerId, $port, $orchestratorEpoch, $orchestratorLaunchId);
        WlsLogger::info_("已上报就绪状态");
        
        // 设置消息处理器
        $ipcClient->onMessage(function (array $msg, \Weline\Server\IPC\ControlClient $client) use (&$shouldExit, &$ipcDraining, &$ipcReceivedShutdown, &$socket, &$drainStartTime, $workerId) {
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
                    // 关闭监听 socket（不再接受新连接）
                    if ($socket && \is_resource($socket)) {
                        @\fclose($socket);
                        $socket = null;
                    }
                    WlsLogger::info_("收到 reload 命令，已清除 opcache 并关闭监听 socket，开始排水（最多等待 10 秒）...");
                    break;
                    
                case \Weline\Server\IPC\ControlMessage::TYPE_CACHE_CLEAR:
                    // 缓存清理：原地执行，不重启
                    if (\function_exists('opcache_reset')) {
                        \opcache_reset();
                    }
                    \clearstatcache(true);
                    \Weline\Framework\Manager\ObjectManager::clearInstances();
                    // 清理 WLS 内置的静态文件内存缓存
                    if (\function_exists('handleStaticFile')) {
                        handleStaticFile('__CLEAR_CACHE__', '');
                    }
                    WlsLogger::info_("收到 cache_clear 命令，已清理缓存（opcache + ObjectManager + 静态文件缓存）");
                    break;
                    
                case \Weline\Server\IPC\ControlMessage::TYPE_DRAIN:
                    // 排水模式：停止接受新连接，完成现有请求后退出
                    $shouldExit = true;
                    $ipcDraining = true;
                    $drainStartTime = \time();
                    // 关闭监听 socket（不再接受新连接）
                    if ($socket && \is_resource($socket)) {
                        @\fclose($socket);
                        $socket = null;
                    }
                    WlsLogger::info_("收到 drain 命令，已关闭监听 socket，开始排水...");
                    break;
                    
                case \Weline\Server\IPC\ControlMessage::TYPE_SHUTDOWN:
                    // 主动终结：优雅退出
                    $ipcReceivedShutdown = true;
                    $shouldExit = true;
                    WlsLogger::info_("收到 shutdown 命令，准备退出");
                    break;
            }
        });
        
        // 设置断开处理器
        $ipcClient->onDisconnect(function (bool $receivedShutdown, \Weline\Server\IPC\ControlClient $client) use (&$ipcReceivedShutdown, &$shouldExit, $instanceName, $controlPort, $workerId) {
            // 已收到 shutdown 或正在退出，不做任何复活/重连操作
            if ($receivedShutdown || $ipcReceivedShutdown || $shouldExit) {
                WlsLogger::info_("Master 连接断开（已收到 shutdown，不复活）");
                return;
            }
            WlsLogger::warning_("Master 连接意外断开，控制面已收口，不执行子进程复活。");
            // 尝试重连
            $client->tryReconnect();
        });
    } else {
        WlsLogger::warning_("IPC 控制通道连接失败 (控制端口: {$controlPort})，继续独立运行");
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
$writeBuffers = []; // H4: 应用层发送缓冲区（Workerman 方案）
$writableConnections = []; // H4: 需要监听可写事件的连接
$pendingPeek = []; // H12: 等待 peek 首包的连接（非阻塞协议检测）
$pendingPeekStartTimes = []; // H12: peek 开始时间（用于超时检测）
$pendingHandshakes = []; // H5: 正在进行 SSL 握手的连接
$pendingClose = []; // H11: 待关闭的连接（缓冲区发送完再关闭）
$handshakeStartTimes = []; // H5: 握手开始时间（用于超时检测）
$startTime = \time(); // 记录启动时间

// Keep-Alive 连接超时配置（秒）
$keepAliveTimeout = 60; // 默认 60 秒空闲超时
$connectionTimeoutCheckInterval = 5; // 每 5 秒检查一次超时连接
$lastTimeoutCheck = \time();

// 重载日志输出函数
$logReload = function (string $method) use ($workerId, $instanceName) {
    $time = \date('Y-m-d H:i:s');
    // 根据方法类型显示不同消息
    if ($method === 'FLAG-CACHE' || $method === 'IPC-CACHE') {
        $message = "[{$time}] [WLS-SSL] Worker #{$workerId} ({$instanceName}) 已清理缓存（opcache + ObjectManager）[{$method}]";
    } else {
        $message = "[{$time}] [WLS-SSL] Worker #{$workerId} ({$instanceName}) 正在重载（优雅退出，由 Master 重启）[{$method}]";
    }
    w_log_info($message);
    // 前台模式时输出到控制台
    if (\defined('STDOUT') && \is_resource(STDOUT)) {
        \fwrite(STDOUT, "\033[33m{$message}\033[0m\n");
    }
};

// 是否需要优雅退出（重载时设置为 true）
$shouldExit = false;

// Worker 优雅退出函数
$gracefulExit = function (string $reason = '') use ($socket, &$connections, &$requestBuffers, &$connectionLastActivity, $processName, &$ipcClient, $workerId, $port, $isMaintenanceWorker) {
    // 刷新日志缓冲区
    WlsLogger::flush_(true);
    
    // 记录退出原因
    if ($reason) {
        w_log_info("[WLS-SSL Worker] 退出原因: {$reason}");
    }
    
    // 关闭所有连接（仅对有效 stream 调用 fclose，避免已关闭或无效 resource 导致 TypeError）
    foreach ($connections as $conn) {
        if (\is_resource($conn) && \get_resource_type($conn) === 'stream') {
            @\fclose($conn);
        }
    }
    if (\is_resource($socket) && \get_resource_type($socket) === 'stream') {
        @\fclose($socket);
    }
    
    // 清理连接相关数据
    $connections = [];
    $requestBuffers = [];
    $connectionLastActivity = [];
    
    // 通知 Master 即将退出（先发送退出原因，再发送 exited）
    if ($ipcClient && $ipcClient->isConnected()) {
        $exitRole = $isMaintenanceWorker ? \Weline\Server\IPC\ControlMessage::ROLE_MAINTENANCE : \Weline\Server\IPC\ControlMessage::ROLE_WORKER;
        $exitReason = $reason !== '' ? $reason : 'graceful';
        @$ipcClient->send(\Weline\Server\IPC\ControlMessage::exitReason($exitReason, 0));
        $ipcClient->send(\Weline\Server\IPC\ControlMessage::exited($exitRole, \getmypid(), $port, $workerId));
        WlsLogger::info_("已发送 exit_reason + exited 消息给 Master");
    }
    
    // 使用进程管理器清理 PID 文件
    if ($processName) {
        \Weline\Framework\System\Process\Processer::destroy('--name=' . $processName);
    }
    
    exit(0);
};

// 信号处理（热更新支持，仅 Linux/Mac）
// 注意：子进程不处理 SIGINT（Ctrl+C），由 Master 通过 IPC 广播 SHUTDOWN 通知退出
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
$masterCheckInterval = 5; // 每 5 秒检查一次
$masterDeadCount = 0;     // Master 连续不可达计数
$masterDeadThreshold = 3; // 连续 3 次（15 秒）确认 Master 死亡后自行退出

// 事件循环（Workerman 模式：外层 try-catch 防止意外退出）
while (true) {
    try {
    if (\function_exists('pcntl_signal_dispatch')) {
        \pcntl_signal_dispatch();
    }
    
    // 定期刷新日志缓冲区（避免日志堆积）
    WlsLogger::flush_(false);
    
    $now = \time();
    
    // ========== 孤儿检测（IPC 优先）：定期检查 Master 是否存活 ==========
    if ($masterPid > 0 && !$ipcReceivedShutdown && ($now - $lastMasterCheck) >= $masterCheckInterval) {
        $lastMasterCheck = $now;
        
        // IPC 连接正常 → Master 存活，无需 PID 检测
        if ($ipcClient && $ipcClient->isConnected()) {
            $masterDeadCount = 0;
        } else {
            // IPC 断开，用 PID 检测确认 Master 是否真的死了
            $masterAlive = false;
            if (\function_exists('posix_kill')) {
                // signal 0 只检查进程是否存在，不发送实际信号
                $masterAlive = @\posix_kill($masterPid, 0);
                // macOS/Linux: 非 root 进程探测 root 进程时可能返回 EPERM（进程存在但无权限）
                if (!$masterAlive && \function_exists('posix_get_last_error')) {
                    $errno = (int)@\posix_get_last_error();
                    $eperm = 1; // EPERM
                    if ($errno === $eperm) {
                        $masterAlive = true;
                    }
                }
            } elseif (\defined('IS_WIN') && IS_WIN) {
                // Windows: 使用 Processer::isRunningByPid() 检测
                $masterAlive = \Weline\Framework\System\Process\Processer::isRunningByPid($masterPid);
            } else {
                // 兜底：通过 /proc 或 kill -0 检测
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
                WlsLogger::warning_("Master PID {$masterPid} 不可达且 IPC 断开 ({$masterDeadCount}/{$masterDeadThreshold})");
                if ($masterDeadCount >= $masterDeadThreshold) {
                    WlsLogger::warning_("Master PID {$masterPid} 已死亡，Worker 自行退出（孤儿保护）");
                    $gracefulExit('孤儿检测：Master 已死亡');
                }
            }
        }
    }
    
    // ========== IPC 控制通道处理 ==========
    // 如果有 IPC 客户端且连接断开了，尝试重连
    if ($ipcClient && !$ipcClient->isConnected() && !$ipcReceivedShutdown) {
        $ipcClient->tryReconnect();
    }
    
    // 检查是否需要优雅退出（排水模式）
    if ($shouldExit) {
        if ($ipcDraining) {
            // ========== 排水模式：快速清理连接，加速退出 ==========
            // 1. 立即关闭所有空闲 Keep-Alive 连接（无请求数据、无写缓冲、非握手中）
            foreach ($connections as $cid => $cconn) {
                $hasReqData = isset($requestBuffers[$cid]) && $requestBuffers[$cid] !== '';
                $hasWriteData = isset($writeBuffers[$cid]) && $writeBuffers[$cid] !== '';
                $isPendingHs = isset($pendingHandshakes[$cid]);
                if (!$hasReqData && !$hasWriteData && !$isPendingHs) {
                    @\fclose($cconn);
                    unset($connections[$cid], $requestBuffers[$cid], $connectionLastActivity[$cid],
                          $requestLogged[$cid], $writeBuffers[$cid], $writableConnections[$cid], $pendingClose[$cid]);
                }
            }
            
            $drainElapsed = $drainStartTime > 0 ? (\time() - $drainStartTime) : 0;
            
            // 2. 所有连接已清空 → 排水完成
            if (empty($connections) && empty($pendingHandshakes)) {
                if ($ipcClient && $ipcClient->isConnected()) {
                    $ipcClient->sendDrainingComplete($workerId, $port);
                }
                WlsLogger::info_("排水完成（{$drainElapsed}秒），Worker 退出");
                $gracefulExit('热重载');
            }
            
            // 3. 排水超时 → 强制关闭所有剩余连接
            if ($drainElapsed >= $maxDrainTime) {
                $remaining = \count($connections) + \count($pendingHandshakes);
                WlsLogger::warning_("排水超时（{$drainElapsed}秒 >= {$maxDrainTime}秒），强制关闭剩余 {$remaining} 个连接");
                foreach ($connections as $cid => $cconn) {
                    @\fclose($cconn);
                }
                foreach ($pendingHandshakes as $cid => $hsInfo) {
                    if (\is_resource($hsInfo['conn'])) {
                        @\fclose($hsInfo['conn']);
                    }
                }
                $connections = [];
                $pendingPeek = [];
                $pendingPeekStartTimes = [];
                $pendingHandshakes = [];
                $requestBuffers = [];
                $connectionLastActivity = [];
                $requestLogged = [];
                $writeBuffers = [];
                $writableConnections = [];
                $pendingClose = [];
                $handshakeStartTimes = [];
                
                if ($ipcClient && $ipcClient->isConnected()) {
                    $ipcClient->sendDrainingComplete($workerId, $port);
                }
                $gracefulExit('热重载（超时强制退出）');
            }
        } elseif (empty($connections) && empty($pendingHandshakes)) {
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
                // H12: 修复 Content-Length mismatch - 超时清理前检查写缓冲区
                // 如果缓冲区有数据，说明还在发送响应，不能关闭连接
                $hasBufferedData = isset($writeBuffers[$connId]) && $writeBuffers[$connId] !== '';
                if ($hasBufferedData) {
                    // 缓冲区有数据，跳过关闭，等待数据发送完成
                    // 但更新超时时间，避免无限等待
                    if ($idleTime >= $keepAliveTimeout * 3) {
                        // 超过 3 倍超时时间仍未发送完成，强制关闭（防止僵尸连接）
                        WlsLogger::warning_("连接超时且缓冲区有数据，强制关闭 (connId: {$connId}, 剩余: " . \strlen($writeBuffers[$connId]) . " 字节)");
                        @\fclose($conn);
                        unset($connections[$connId]);
                        unset($requestBuffers[$connId]);
                        unset($connectionLastActivity[$connId]);
                        unset($requestLogged[$connId]);
                        unset($writeBuffers[$connId]);
                        unset($writableConnections[$connId]);
                        unset($pendingClose[$connId]);
                    }
                    continue; // 跳过正常超时关闭
                }
                
                @\fclose($conn);
                unset($connections[$connId]);
                unset($requestBuffers[$connId]);
                unset($connectionLastActivity[$connId]);
                unset($requestLogged[$connId]);
                // 清理写缓冲区相关状态（虽然此时应该为空）
                unset($writeBuffers[$connId]);
                unset($writableConnections[$connId]);
                unset($pendingClose[$connId]);
            }
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
                'memory_usage' => \memory_get_usage(true),
                'memory_peak' => \memory_get_peak_usage(true),
                'uptime' => $now - $startTime,
                'ssl' => true,
            ]);
        } catch (\Throwable $e) {
            // 忽略日志记录失败
        }
    }
    
    // H12: 将等待 peek 的连接加入监听列表（非阻塞协议检测）
    $pendingPeekConns = [];
    foreach ($pendingPeek as $connId => $info) {
        if (\is_resource($info['conn']) && \get_resource_type($info['conn']) === 'stream') {
            $pendingPeekConns[$connId] = $info['conn'];
        } else {
            unset($pendingPeek[$connId]);
            unset($pendingPeekStartTimes[$connId]);
        }
    }
    
    // H5: 将握手进行中的连接也加入监听列表
    // 同时验证所有资源是否仍然有效（防止 stream_select 错误）
    $pendingConns = [];
    foreach ($pendingHandshakes as $connId => $info) {
        if (\is_resource($info['conn']) && \get_resource_type($info['conn']) === 'stream') {
            $pendingConns[$connId] = $info['conn'];
        } else {
            // 资源已无效，标记为需要清理
            unset($pendingHandshakes[$connId]);
            unset($handshakeStartTimes[$connId]);
        }
    }
    
    // 验证 $connections 中的资源
    $validConnections = [];
    foreach ($connections as $connId => $conn) {
        if (\is_resource($conn) && \get_resource_type($conn) === 'stream') {
            $validConnections[$connId] = $conn;
        } else {
            // 资源已无效，清理
            unset($connections[$connId]);
            unset($requestBuffers[$connId]);
            unset($connectionLastActivity[$connId]);
            unset($requestLogged[$connId]);
        }
    }
    
    // 验证 $writableConnections 中的资源
    $validWritableConnections = [];
    foreach ($writableConnections as $connId => $conn) {
        if (\is_resource($conn) && \get_resource_type($conn) === 'stream') {
            $validWritableConnections[$connId] = $conn;
        } else {
            unset($writableConnections[$connId]);
            unset($writeBuffers[$connId]);
        }
    }
    
    // 构建 stream_select 读数组
    $readSockets = [];
    if ($socket && \is_resource($socket)) {
        $readSockets[] = $socket; // 监听 socket（排水后已关闭则不加入）
    }
    // H12: 加入待 peek 的连接（监听首包到达）
    $readSockets = \array_merge($readSockets, $validConnections, $pendingConns, $pendingPeekConns);
    
    // 加入 IPC 控制 socket
    $ipcSocket = ($ipcClient && $ipcClient->isConnected()) ? $ipcClient->getSocket() : null;
    if ($ipcSocket && \is_resource($ipcSocket)) {
        $readSockets[] = $ipcSocket;
    }
    
    $read = $readSockets;
    // H4: 保留 connId 键，避免 stream_select 修改 $write 后与 writeConnIdMap 错位
    $write = $validWritableConnections;
    $except = [];
    
    $changed = @\stream_select($read, $write, $except, 0, 100000);
    
    if ($changed === false) {
        // stream_select 失败，可能是资源问题，记录错误但继续
        $error = \error_get_last();
        WlsLogger::warning_("stream_select 失败: " . ($error['message'] ?? 'unknown'));
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
            $connId = \get_resource_id($conn);
            $peerName = @\stream_socket_get_name($conn, true);
            
            // H12: 延迟 SSL 模式下完全非阻塞处理（解决 Windows SSL 握手慢问题）
            if ($deferSsl) {
                // 设置非阻塞模式，将连接加入 pendingPeek 队列等待首包
                \stream_set_blocking($conn, false);
                $pendingPeek[$connId] = [
                    'conn' => $conn,
                    'peerName' => $peerName,
                    'buffer' => '', // HTTP 请求缓冲（用于 HTTP→HTTPS 重定向）
                ];
                $pendingPeekStartTimes[$connId] = \microtime(true);
                // 不立即加入 connections，等 peek/握手完成后再加入
            } else {
                \stream_set_blocking($conn, false);
                $connections[$connId] = $conn;
                $requestBuffers[$connId] = '';
                $connectionLastActivity[$connId] = \time();
            }
        }
        $key = \array_search($socket, $read);
        unset($read[$key]);
    }
    
    // H12: 处理等待 peek 首包的连接（非阻塞协议检测）
    $peekTimeout = 5.0; // 5 秒 peek 超时（比握手超时一致）
    $completedPeeks = [];
    $failedPeeks = [];
    
    foreach ($pendingPeek as $connId => $peekInfo) {
        $conn = $peekInfo['conn'];
        $peerName = $peekInfo['peerName'];
        $startTime_pk = $pendingPeekStartTimes[$connId] ?? \microtime(true);
        
        // 检查是否在 read 中（有数据可读）
        $hasData = false;
        foreach ($read as $r) {
            if (\is_resource($r) && \get_resource_id($r) === $connId) {
                $hasData = true;
                break;
            }
        }
        
        // 检查超时
        $elapsed = \microtime(true) - $startTime_pk;
        if ($elapsed > $peekTimeout) {
            $failedPeeks[] = $connId;
            WlsLogger::warning_("Peek 超时: {$peerName} (connId: {$connId})");
            continue;
        }
        
        if (!$hasData) {
            // 无数据可读，继续等待
            continue;
        }
        
        // 非阻塞 peek 首包
        $peek = @\stream_socket_recvfrom($conn, 8, \STREAM_PEEK);
        
        if ($peek === false || $peek === '') {
            // 无数据或错误，检查连接是否关闭
            if (@\feof($conn)) {
                $failedPeeks[] = $connId;
            }
            continue;
        }
        
        // 有数据，判断协议类型
        if (\strlen($peek) >= 4) {
            $firstByte = \ord($peek[0]);
            $isPlainHttp = ($firstByte !== 0x16) && (
                \substr($peek, 0, 4) === 'GET '
                || \substr($peek, 0, 5) === 'POST '
                || \substr($peek, 0, 5) === 'HEAD '
                || \substr($peek, 0, 4) === 'PUT '
                || (\strlen($peek) >= 6 && \substr($peek, 0, 6) === 'PATCH ')
                || (\strlen($peek) >= 7 && \substr($peek, 0, 7) === 'DELETE ')
                || (\strlen($peek) >= 8 && \substr($peek, 0, 8) === 'OPTIONS ')
            );
            
            if ($isPlainHttp) {
                // HTTP 请求，执行 301 重定向到 HTTPS
                $raw = '';
                while (\strpos($raw, "\r\n\r\n") === false && \strlen($raw) < 65536) {
                    $chunk = @\fread($conn, 8192);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $raw .= $chunk;
                }
                $path = '/';
                $redirectHost = $host . ':' . $port;
                if (\preg_match('/^\w+\s+(\S+)\s+/', $raw, $m)) {
                    $path = \parse_url($m[1], \PHP_URL_PATH);
                    if ($path === false || $path === null) {
                        $path = $m[1];
                    }
                    if ($path === false || $path === null || $path === '') {
                        $path = '/';
                    }
                }
                if (\preg_match('/\r\nHost:\s*([^\r\n]+)/i', $raw, $h)) {
                    $redirectHost = \trim($h[1]);
                }
                $redirectPort = (int) $port;
                if (\strpos($redirectHost, ':') !== false) {
                    $redirectUrl = "https://{$redirectHost}{$path}";
                } else {
                    $redirectUrl = ($redirectPort === 443)
                        ? "https://{$redirectHost}{$path}"
                        : "https://{$redirectHost}:{$redirectPort}{$path}";
                }
                $body = '';
                $response = "HTTP/1.1 301 Moved Permanently\r\nLocation: {$redirectUrl}\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: " . \strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
                @\fwrite($conn, $response);
                @\fclose($conn);
                $completedPeeks[] = $connId;
                continue;
            }
        }
        
        // SSL/TLS 握手请求，启动 SSL 握手
        // 为 accept 的连接设置 SSL context（关键步骤！）
        foreach ($deferSslOptions as $optName => $optValue) {
            \stream_context_set_option($conn, 'ssl', $optName, $optValue);
        }
        
        // 尝试启动 SSL 握手
        $cryptoResult = @\stream_socket_enable_crypto($conn, true, $cryptoMethod);
        
        if ($cryptoResult === true) {
            // 握手立即完成
            if ($isDev) {
                WlsLogger::info_("SSL 握手成功: {$peerName} (connId: {$connId})");
            }
            $connections[$connId] = $conn;
            $requestBuffers[$connId] = '';
            $connectionLastActivity[$connId] = \time();
            $completedPeeks[] = $connId;
        } elseif ($cryptoResult === 0) {
            // 握手进行中，移入 pendingHandshakes
            $pendingHandshakes[$connId] = [
                'conn' => $conn,
                'peerName' => $peerName,
            ];
            $handshakeStartTimes[$connId] = \microtime(true);
            $connections[$connId] = $conn;
            $requestBuffers[$connId] = '';
            $connectionLastActivity[$connId] = \time();
            $completedPeeks[] = $connId;
        } else {
            // 握手失败
            $error = \error_get_last();
            $errorMsg = $error['message'] ?? 'unknown';
            WlsLogger::warning_("SSL 握手失败: {$peerName} (connId: {$connId}) - {$errorMsg}");
            $failedPeeks[] = $connId;
        }
    }
    
    // 清理已完成的 peek
    foreach ($completedPeeks as $connId) {
        unset($pendingPeek[$connId]);
        unset($pendingPeekStartTimes[$connId]);
    }
    
    // 清理失败的 peek
    foreach ($failedPeeks as $connId) {
        if (isset($pendingPeek[$connId]['conn'])) {
            @\fclose($pendingPeek[$connId]['conn']);
        }
        unset($pendingPeek[$connId]);
        unset($pendingPeekStartTimes[$connId]);
    }
    
    // H5: 处理握手进行中的连接（非阻塞继续握手）
    $handshakeTimeout = 5.0; // 5秒握手超时
    $completedHandshakes = [];
    $failedHandshakes = [];
    
    foreach ($pendingHandshakes as $connId => $handshakeInfo) {
        $conn = $handshakeInfo['conn'];
        $peerName = $handshakeInfo['peerName'];
        $startTime_hs = $handshakeStartTimes[$connId] ?? \microtime(true);
        
        // 检查超时
        $elapsed = \microtime(true) - $startTime_hs;
        if ($elapsed > $handshakeTimeout) {
            $failedHandshakes[] = $connId;
            WlsLogger::warning_("SSL 握手超时: {$peerName} (connId: {$connId})");
            continue;
        }
        
        // 继续尝试握手
        $cryptoResult = @\stream_socket_enable_crypto($conn, true, $cryptoMethod);
        
        if ($cryptoResult === true) {
            // 握手完成
            $completedHandshakes[] = $connId;
            if ($isDev) {
                WlsLogger::info_("SSL 握手成功: {$peerName} (connId: {$connId})");
            }
        } elseif ($cryptoResult === 0) {
            // 握手仍在进行中，继续等待
        } else {
            // 握手失败
            $error = \error_get_last();
            $errorMsg = $error['message'] ?? 'unknown';
            $failedHandshakes[] = $connId;
            WlsLogger::warning_("SSL 握手失败: {$peerName} (connId: {$connId}) - {$errorMsg}");
        }
    }
    
    // 清理完成的握手
    foreach ($completedHandshakes as $connId) {
        unset($pendingHandshakes[$connId]);
        unset($handshakeStartTimes[$connId]);
    }
    
    // 清理失败的握手
    foreach ($failedHandshakes as $connId) {
        if (isset($pendingHandshakes[$connId]['conn'])) {
            @\fclose($pendingHandshakes[$connId]['conn']);
        }
        unset($pendingHandshakes[$connId]);
        unset($handshakeStartTimes[$connId]);
        unset($connections[$connId]);
        unset($requestBuffers[$connId]);
        unset($connectionLastActivity[$connId]);
        unset($requestLogged[$connId]);
    }
    
    // 处理连接
    foreach ($read as $conn) {
        $connId = \get_resource_id($conn);
        
        // H12: 跳过等待 peek 的连接（协议检测阶段）
        if (isset($pendingPeek[$connId])) {
            continue;
        }
        
        // H5: 跳过握手进行中的连接
        if (isset($pendingHandshakes[$connId])) {
            continue;
        }
        
        // H5: 跳过已经被清理的连接（握手失败后被移除）
        if (!isset($connections[$connId])) {
            continue;
        }
        
        $data = @\fread($conn, 65535);
        
        // H7: 修复非阻塞流空数据判断
        // fread 返回 false 表示错误
        // fread 返回空字符串只表示暂无数据（非阻塞模式），不是连接关闭
        // 需要用 feof() 检查连接是否真正关闭
        if ($data === false) {
            // 读取错误，关闭连接
            @\fclose($conn);
            unset($connections[$connId]);
            unset($requestBuffers[$connId]);
            unset($connectionLastActivity[$connId]);
            unset($requestLogged[$connId]);
            unset($writeBuffers[$connId]);
            unset($writableConnections[$connId]);
            continue;
        }
        
        if ($data === '') {
            // 暂无数据，检查连接是否已关闭
            if (\feof($conn)) {
                // 连接已关闭
                @\fclose($conn);
                unset($connections[$connId]);
                unset($requestBuffers[$connId]);
                unset($connectionLastActivity[$connId]);
                unset($requestLogged[$connId]);
                unset($writeBuffers[$connId]);
                unset($writableConnections[$connId]);
            }
            // 暂无数据，跳过但不关闭连接（客户端可能正在等待响应）
            continue;
        }
        
        // 更新连接最后活动时间
        $connectionLastActivity[$connId] = \time();
        
        $requestBuffers[$connId] = ($requestBuffers[$connId] ?? '') . $data;
        
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
                    WlsLogger::info_("→ {$method} {$uri}");
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
        
        $isComplete = isRequestComplete($requestBuffers[$connId]);
        if (!$isComplete) {
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
            WlsLogger::info_("收到请求: {$method} {$uri} (connId: {$connId}, requestCount: {$requestCount})");
        }
        
        // 设置 SSE 上下文（让控制器可以直接写入连接）
        \Weline\Framework\Http\Sse\SseContext::setConnection($conn);
        
        // 处理请求
        $response = handleRequest(
            $rawRequest, $runtime, $runtimeError, $instanceName, $workerId, $port, 
            $requestCount, $activeRequests, \count($connections), $startTime,
            $originToken,
            $originTokenValidationEnabled,
            $originTokenHeader,
            $originTokenAllowLocal
        );
        
        $activeRequests--;
        
        // 检查是否是 SSE 模式（如果是，响应已经流式发送，不需要再发送）
        $isSseMode = \Weline\Framework\Http\Sse\SseContext::isSseEnabled();
        
        if (!$isSseMode) {
            // H6+H10: 响应队列模式 - 高并发非阻塞
            // 每个连接有自己的缓冲区（connId 级别隔离）
            // HTTP/1.1 Keep-Alive 要求同一连接上响应按顺序发送
            // 如果缓冲区已有数据，直接追加（保证顺序），由后续循环统一发送
            
            $responseLen = \strlen($response);
            $hasBufferedData = isset($writeBuffers[$connId]) && $writeBuffers[$connId] !== '';
            
            if ($hasBufferedData) {
                // 缓冲区有数据（上个响应未发完），直接追加到队列末尾
                // 不阻塞，下次事件循环会继续发送
                $writeBuffers[$connId] .= $response;
                $writableConnections[$connId] = $conn;
                goto skip_sync_write;
            }
            
            // 小响应：使用非阻塞模式
            $totalWritten = 0;
            
            // 立即尝试写入（最多重试几次，避免阻塞太久）
            $immediateRetries = 0;
            $maxImmediateRetries = 10; // 减少重试次数，提升并发
            
            while ($totalWritten < $responseLen && $immediateRetries < $maxImmediateRetries) {
                $remaining = \substr($response, $totalWritten);
                $written = @\fwrite($conn, $remaining);
                
                if ($written === false) {
                    // 写入失败，可能连接已断开
                    @\fclose($conn);
                    unset($connections[$connId]);
                    unset($requestBuffers[$connId]);
                    unset($connectionLastActivity[$connId]);
                    unset($requestLogged[$connId]);
                    unset($writeBuffers[$connId]);
                    unset($writableConnections[$connId]);
                    \Weline\Framework\Http\Sse\SseContext::reset();
                    continue 2;
                }
                
                if ($written === 0) {
                    // 缓冲区满或暂时不可写，退出立即写入
                    break;
                }
                
                $totalWritten += $written;
                $immediateRetries++;
            }
            
            // 如果全部写完，不需要入队
            if ($totalWritten >= $responseLen) {
                goto skip_sync_write;
            }
            
            // 没写完，剩余部分放入缓冲区，下次循环继续写
            $remainingBytes = $responseLen - $totalWritten;
            $writeBuffers[$connId] = \substr($response, $totalWritten);
            $writableConnections[$connId] = $conn;
            
            // 调试日志：追踪大响应的缓冲区状态
            if ($responseLen > 100000) { // 大于 100KB 的响应
                WlsLogger::debug_("大响应入缓冲区: connId={$connId}, 总长度={$responseLen}, 已写={$totalWritten}, 剩余={$remainingBytes}, URI=" . ($requestUri ?? 'unknown'));
            }
            
            goto skip_sync_write;
            
            // === 以下是旧的同步写入逻辑（已弃用，保留作为参考） ===
            $totalWritten = 0;
            $writeStartTime = \microtime(true);
            $writeTimeout = 30.0; // 30秒超时
            $maxRetries = 1000; // 最大重试次数（防止死循环）
            $retries = 0;
            
            while ($totalWritten < $responseLen && $retries < $maxRetries) {
                $elapsed = \microtime(true) - $writeStartTime;
                if ($elapsed > $writeTimeout) {
                    WlsLogger::warning_("响应写入超时 (connId: {$connId})，已写入 {$totalWritten}/{$responseLen} 字节");
                    @\fclose($conn);
                    unset($connections[$connId]);
                    unset($requestBuffers[$connId]);
                    unset($connectionLastActivity[$connId]);
                    unset($requestLogged[$connId]);
                    \Weline\Framework\Http\Sse\SseContext::reset();
                    continue 2; // 跳过当前连接处理
                }
                
                // 在 fwrite 之前先用 stream_select 检查可写性
                // 这样可以避免 SSL 连接上的 fwrite 阻塞问题（特别是在 Windows 上）
                $write = [$conn];
                $read = [];
                $except = [];
                // 使用短超时（10ms），避免长时间阻塞事件循环
                $selectResult = @\stream_select($read, $write, $except, 0, 10000);
                
                if ($selectResult === false) {
                    // stream_select 失败，放弃写入
                    WlsLogger::warning_("stream_select 失败 (connId: {$connId})");
                    @\fclose($conn);
                    unset($connections[$connId]);
                    unset($requestBuffers[$connId]);
                    unset($connectionLastActivity[$connId]);
                    unset($requestLogged[$connId]);
                    \Weline\Framework\Http\Sse\SseContext::reset();
                    continue 2;
                }
                
                // 如果连接不可写，增加重试计数并继续等待
                if (empty($write)) {
                    $retries++;
                    continue;
                }
                
                // 连接可写，执行 fwrite
                $remaining = \substr($response, $totalWritten);
                $written = @\fwrite($conn, $remaining);
                
                if ($written === false) {
                    WlsLogger::warning_("响应写入失败 (connId: {$connId})");
                    @\fclose($conn);
                    unset($connections[$connId]);
                    unset($requestBuffers[$connId]);
                    unset($connectionLastActivity[$connId]);
                    unset($requestLogged[$connId]);
                    \Weline\Framework\Http\Sse\SseContext::reset();
                    continue 2;
                }
                
                if ($written === 0) {
                    // 即使 stream_select 说可写，fwrite 仍然返回 0
                    // 增加重试计数并继续
                    $retries++;
                    continue;
                }
                
                $totalWritten += $written;
            }
            
            if ($retries >= $maxRetries) {
                WlsLogger::warning_("响应写入重试次数超限 (connId: {$connId})，已写入 {$totalWritten}/{$responseLen} 字节");
                @\fclose($conn);
                unset($connections[$connId]);
                unset($requestBuffers[$connId]);
                unset($connectionLastActivity[$connId]);
                unset($requestLogged[$connId]);
                \Weline\Framework\Http\Sse\SseContext::reset();
                continue;
            }
            
            skip_sync_write: // H4: goto 标签，跳过同步写入逻辑
        } else {
            // SSE 模式：响应已流式发送，记录日志
            WlsLogger::info_("SSE 流式响应完成 (connId: {$connId})");
        }
        
        // 重置 SSE 上下文
        \Weline\Framework\Http\Sse\SseContext::reset();
        
        // 更新连接最后活动时间（请求处理完成）
        $connectionLastActivity[$connId] = \time();
        
        // H11: 修复 Content-Length mismatch - 只有缓冲区为空才能关闭连接
        // SSE 连接通常是长连接，但处理完成后应该关闭
        // 普通请求根据 Keep-Alive 决定
        $keepAlive = isKeepAlive($rawRequest);
        $shouldClose = $isSseMode || !$keepAlive || $ipcDraining; // 排水模式强制关闭连接，不保持 Keep-Alive
        
        if ($shouldClose) {
            // 检查缓冲区是否为空
            $hasBufferedData = isset($writeBuffers[$connId]) && $writeBuffers[$connId] !== '';
            
            if ($hasBufferedData) {
                // 缓冲区有数据，标记为"待关闭"，等缓冲区发送完再关闭
                $pendingClose[$connId] = true;
            } else {
                // 缓冲区为空，可以安全关闭
                @\fclose($conn);
                unset($connections[$connId]);
                unset($requestBuffers[$connId]);
                unset($connectionLastActivity[$connId]);
                unset($requestLogged[$connId]);
                unset($writeBuffers[$connId]);
                unset($writableConnections[$connId]);
            }
        }
    }
    
    // H4: 处理可写连接（Workerman 风格的应用层缓冲区）
    // H9: 修复 stream_select 重新索引数组导致 connId 错误的 bug
    // stream_select 会把关联数组 [101 => $conn1] 变成 [0 => $conn1]
    // PHP 8.4 不允许 (int)$resource 强制转换，必须用 get_resource_id()
    foreach ($write as $conn) {
        $connId = \get_resource_id($conn);  // PHP 8.0+ 获取资源 ID
        
        if (!isset($writeBuffers[$connId])) {
            continue;
        }

        // H14: 优化大缓冲区发送 - 在一次事件循环中尽可能多地写入数据
        // 这可以显著加速大文件的发送，避免 Content-Length mismatch
        $initialBufferLen = \strlen($writeBuffers[$connId]);
        $totalWrittenThisLoop = 0;
        $maxWriteAttempts = 50; // 防止死循环
        $writeAttempts = 0;
        
        while (isset($writeBuffers[$connId]) && $writeBuffers[$connId] !== '' && $writeAttempts < $maxWriteAttempts) {
            $writeAttempts++;
            $buffer = $writeBuffers[$connId];
            $bufferLen = \strlen($buffer);
            
            $written = @\fwrite($conn, $buffer);
            
            if ($written === false) {
                // 写入失败，关闭连接并清理
                WlsLogger::warning_("缓冲区写入失败 (connId: {$connId}, 剩余: {$bufferLen} 字节)");
                @\fclose($conn);
                unset($connections[$connId]);
                unset($requestBuffers[$connId]);
                unset($connectionLastActivity[$connId]);
                unset($requestLogged[$connId]);
                unset($writeBuffers[$connId]);
                unset($writableConnections[$connId]);
                unset($pendingClose[$connId]);
                break;
            }
            
            // H13: 即使 fwrite 返回 0（缓冲区满），也更新活动时间
            $connectionLastActivity[$connId] = \time();
            
            if ($written === 0) {
                // 缓冲区满，暂时无法写入更多数据，退出循环等待下次事件
                break;
            }
            
            $totalWrittenThisLoop += $written;
            
            // 更新缓冲区（移除已写入的数据）
            $writeBuffers[$connId] = \substr($buffer, $written);
            
            // 如果缓冲区已空，移除可写监听
            if ($writeBuffers[$connId] === '' || $writeBuffers[$connId] === false) {
                unset($writeBuffers[$connId]);
                unset($writableConnections[$connId]);
                
                // H11: 检查是否有待关闭的连接，缓冲区空了就可以关闭
                if (isset($pendingClose[$connId])) {
                    @\fclose($conn);
                    unset($connections[$connId]);
                    unset($requestBuffers[$connId]);
                    unset($connectionLastActivity[$connId]);
                    unset($requestLogged[$connId]);
                    unset($pendingClose[$connId]);
                }
                break;
            }
        }
        
        // 调试日志：追踪大缓冲区的发送状态
        if ($initialBufferLen > 100000 && $totalWrittenThisLoop > 0) {
            $remainingLen = isset($writeBuffers[$connId]) ? \strlen($writeBuffers[$connId]) : 0;
            WlsLogger::debug_("大缓冲区批量发送: connId={$connId}, 初始={$initialBufferLen}, 本轮写入={$totalWrittenThisLoop}, 剩余={$remainingLen}, 尝试次数={$writeAttempts}");
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
        w_log_error("[WLS-SSL Worker #{$workerId}] 事件循环异常 ({$consecutiveErrors}/{$maxConsecutiveErrors}): {$errorMessage} in {$errorFile}:{$errorLine}");
        WlsLogger::error_("事件循环异常: {$errorMessage}");
        
        // 刷新日志缓冲区
        WlsLogger::flush_(true);
        
        // 如果连续错误过多，优雅退出让 Master 重启
        if ($consecutiveErrors >= $maxConsecutiveErrors) {
            w_log_error("[WLS-SSL Worker #{$workerId}] 连续错误过多，优雅退出");
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
                'ssl' => true,
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
        WlsLogger::info_(__('静态文件缓存: %{1} %{2}', [\strtoupper($cacheStatus), $cacheUri]));
        return $staticResponse;
    }
    // ========== 静态文件处理结束 ==========
    
    // 如果运行时初始化失败，返回错误
    if ($runtime === null) {
        WlsLogger::error_("运行时未初始化，返回错误: {$runtimeError}");
        $errorBody = \json_encode([
            'error' => true,
            'message' => 'Runtime initialization failed',
            'detail' => $runtimeError,
        ], JSON_UNESCAPED_UNICODE);
        
        return "HTTP/1.1 500 Internal Server Error\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: " . \strlen($errorBody) . "\r\nConnection: close\r\n\r\n" . $errorBody;
    }
    
    WlsLogger::info_("准备进入框架处理: {$method} {$uri}");
    try {
        // 创建 WLS 请求对象（框架会自动处理维护模式）
        $request = \Weline\Framework\Http\WlsRequest::fromRaw($rawRequest, [
            'WLS_INSTANCE' => $instanceName,
            'WLS_WORKER_ID' => $workerId,
            'WLS_PORT' => $port,
            'WLS_REQUEST_COUNT' => $requestCount,
            'HTTPS' => 'on',
            'REQUEST_SCHEME' => 'https',
        ]);
        
        $result = $runtime->handle($request);
        
        // H9: 释放 PHP Session 文件锁，防止同一 Session 的后续请求被阻塞
        // 在 WLS 常驻进程模式下，session_start() 会锁定 session 文件
        // 必须在请求处理完成后立即释放锁，否则同一 session 的并发请求会被阻塞
        if (\session_status() === PHP_SESSION_ACTIVE) {
            \session_write_close();
        }
        
        // WLS 模式下控制器通过 return 返回 body；对 body trim 并可从 JSON 的 code 解析出状态码
        if (\is_string($result) && \str_starts_with($result, 'HTTP/')) {
            // 合并 Runtime 保存的 Cookie（在 StateManager reset 前提取的副本）
            // 例如：登录 POST 设置了 Session Cookie → 302 重定向 → Cookie 必须随重定向响应一起发送
            $pendingCookies = $runtime->consumePendingCookies();
            if (!empty($pendingCookies)) {
                $headerEnd = \strpos($result, "\r\n\r\n");
                if ($headerEnd !== false) {
                    $cookieHeaders = '';
                    foreach ($pendingCookies as $cookie) {
                        $parts = [\urlencode($cookie['name']) . '=' . \urlencode($cookie['value'])];
                        if (isset($cookie['expire']) && $cookie['expire'] !== 0) { $parts[] = 'Expires=' . \gmdate('D, d M Y H:i:s T', $cookie['expire']); }
                        if (!empty($cookie['path']))     { $parts[] = 'Path=' . $cookie['path']; }
                        if (!empty($cookie['domain']))   { $parts[] = 'Domain=' . $cookie['domain']; }
                        if (!empty($cookie['secure']))   { $parts[] = 'Secure'; }
                        if (!empty($cookie['httpOnly'])) { $parts[] = 'HttpOnly'; }
                        if (!empty($cookie['sameSite'])) { $parts[] = 'SameSite=' . $cookie['sameSite']; }
                        $cookieHeaders .= 'Set-Cookie: ' . \implode('; ', $parts) . "\r\n";
                    }
                    $result = \substr($result, 0, $headerEnd) . "\r\n" . $cookieHeaders . \substr($result, $headerEnd);
                }
            }
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
        
        // WLS 模式核心：将 Runtime 保存的 Cookie/Header 合并进 HTTP 响应
        // 框架内部（Session、Cookie 类等）通过 HeaderCollector 设置响应头和 Cookie，
        // 但 WLS 模式下 PHP 内置的 header()/setcookie() 无效。
        // WlsRuntime 在 StateManager 重置前将 HeaderCollector 副本保存到 pendingCookies/pendingHeaders。
        $pendingCookies2 = $runtime->consumePendingCookies();
        foreach ($pendingCookies2 as $cookie) {
            $parts = [\urlencode($cookie['name']) . '=' . \urlencode($cookie['value'])];
            if (isset($cookie['expire']) && $cookie['expire'] !== 0) { $parts[] = 'Expires=' . \gmdate('D, d M Y H:i:s T', $cookie['expire']); }
            if (!empty($cookie['path']))     { $parts[] = 'Path=' . $cookie['path']; }
            if (!empty($cookie['domain']))   { $parts[] = 'Domain=' . $cookie['domain']; }
            if (!empty($cookie['secure']))   { $parts[] = 'Secure'; }
            if (!empty($cookie['httpOnly'])) { $parts[] = 'HttpOnly'; }
            if (!empty($cookie['sameSite'])) { $parts[] = 'SameSite=' . $cookie['sameSite']; }
            $response->addCookieHeader(\implode('; ', $parts));
        }
        $pendingHeaders2 = $runtime->consumePendingHeaders();
        foreach ($pendingHeaders2 as $name => $value) {
            if (\is_string($value)) { $response->setHeader($name, $value); }
        }
        
        // 添加路由提示头（用于 TCP 透传模式下的智能路由）
        $sni = \Weline\Server\Service\RouteHintService::extractSniFromRawRequest($rawRequest);
        \Weline\Server\Service\RouteHintService::addHintToWlsResponse($response, $sni);
        
        $acceptEncoding = $request->getHeader('Accept-Encoding');
        if ($acceptEncoding && \is_string($acceptEncoding)) {
            $response->compress($acceptEncoding);
        }
        
        $httpString = $response->toHttpString($request->isKeepAlive());
        
        // HTTP 规范：HEAD 请求应该返回与 GET 请求相同的响应头，但不返回响应体
        // Content-Length 头部应该保留，告知客户端如果是 GET 请求会返回多大的内容
        if (\strtoupper($method) === 'HEAD') {
            $headerEnd = \strpos($httpString, "\r\n\r\n");
            if ($headerEnd !== false) {
                // 只保留响应头部分（包括末尾的 \r\n\r\n）
                $httpString = \substr($httpString, 0, $headerEnd + 4);
            }
        }
        
        return $httpString;
        
    } catch (\Throwable $e) {
        WlsLogger::error_("请求处理错误: " . $e->getMessage() . " (文件: " . $e->getFile() . ":" . $e->getLine() . ")");
        w_log_error('[WLS Worker SSL] Request error: ' . $e->getMessage());
        
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
        
        // H9: 异常情况下也要释放 Session 锁
        if (\session_status() === PHP_SESSION_ACTIVE) {
            \session_write_close();
        }
        
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
 * - 小于配置阈值的文件缓存到内存，避免重复读取磁盘
 * - 缓存有效期 7 天（基于文件修改时间验证）
 * - 大于配置阈值的文件直接从磁盘读取（避免内存占用过大）
 * 
 * H13: 修复 Content-Length mismatch
 * - 根据客户端请求设置正确的 Connection 头
 * - 支持 Range 请求用于大文件断点续传
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
    // 缓存格式：[filepath => ['content' => string, 'mtime' => int, 'size' => int, 'cached_at' => int, 'hits' => int, 'last_access' => int]]
    static $staticFileCache = [];
    static $staticFileCacheTotalSize = 0;
    static $staticFileCacheMaxAge = 86400 * 7;  // 缓存有效期：7 天
    
    // 使用全局配置（如果已设置）
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
            'max_size' => $maxSize,
            'eviction_threshold' => $evictionThreshold,
        ]);
    }
    
    /**
     * 冷热淘汰：当缓存接近上限时，淘汰最冷的缓存项
     * 评分公式：score = hits * 10 + recency_bonus
     * recency_bonus = max(0, 100 - (now - last_access) / 60) // 最近访问加分
     */
    $evictColdCache = static function (int $neededSpace) use (&$staticFileCache, &$staticFileCacheTotalSize, $maxTotal, $evictionThreshold): void {
        // 计算需要释放多少空间
        $targetSize = $maxTotal - $evictionThreshold - $neededSpace;
        if ($staticFileCacheTotalSize <= $targetSize) {
            return; // 空间足够，无需淘汰
        }
        
        $now = \time();
        $candidates = [];
        
        // 计算每个缓存项的冷热分数
        foreach ($staticFileCache as $path => $item) {
            $hits = $item['hits'] ?? 0;
            $lastAccess = $item['last_access'] ?? $item['cached_at'];
            $age = $now - $lastAccess;
            
            // 分数越低越冷（优先淘汰）
            $recencyBonus = \max(0, 100 - (int)($age / 60)); // 每分钟减 1 分
            $score = $hits * 10 + $recencyBonus;
            
            $candidates[] = [
                'path' => $path,
                'score' => $score,
                'size' => $item['size'],
            ];
        }
        
        // 按分数升序排序（最冷的在前）
        \usort($candidates, fn($a, $b) => $a['score'] <=> $b['score']);
        
        // 淘汰最冷的缓存项直到空间足够
        foreach ($candidates as $candidate) {
            if ($staticFileCacheTotalSize <= $targetSize) {
                break;
            }
            
            $path = $candidate['path'];
            if (isset($staticFileCache[$path])) {
                $staticFileCacheTotalSize -= $staticFileCache[$path]['size'];
                unset($staticFileCache[$path]);
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
    
    // ========== 内存缓存策略（冷热淘汰） ==========
    $content = null;
    $fromCache = false;
    $now = \time();
    
    // 只有小于配置阈值的文件才缓存到内存
    if ($fileSize <= $maxSize) {
        // 检查缓存是否存在且有效
        if (isset($staticFileCache[$filename])) {
            $cached = $staticFileCache[$filename];
            // 验证：文件修改时间一致 且 缓存未过期
            if ($cached['mtime'] === $mtime && ($now - $cached['cached_at']) < $staticFileCacheMaxAge) {
                $content = $cached['content'];
                    $fromCache = true;
                    $WLS_LAST_STATIC_CACHE['status'] = 'hit';
                // 更新访问统计（冷热计数）
                $staticFileCache[$filename]['hits'] = ($cached['hits'] ?? 0) + 1;
                $staticFileCache[$filename]['last_access'] = $now;
            } else {
                // 缓存失效，移除旧缓存
                $staticFileCacheTotalSize -= $cached['size'];
                unset($staticFileCache[$filename]);
            }
        }
        
        // 缓存未命中，从磁盘读取并缓存
        if ($content === null) {
            $content = @\file_get_contents($filename);
            if ($content === false) {
                return null; // 读取失败，交给框架处理
            }
            
            // 检查是否需要淘汰：剩余空间不足时启动冷热淘汰
            $remainingSpace = $maxTotal - $staticFileCacheTotalSize;
            if ($remainingSpace - $fileSize < $evictionThreshold) {
                // 剩余空间低于阈值，启动冷热淘汰
                $evictColdCache($fileSize);
            }
            
            // 再次检查空间是否足够（淘汰后）
            if ($staticFileCacheTotalSize + $fileSize <= $maxTotal) {
                // 添加到缓存
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
            // 如果空间仍不足，不缓存该文件（但仍返回内容）
        }
    } else {
        // 大于配置阈值的文件不缓存，直接读取
        $content = @\file_get_contents($filename);
        if ($content === false) {
            return null; // 读取失败，交给框架处理
        }
    }
    
    // 计算内容长度
    $contentLength = \strlen($content);
    
    // H14: 验证内容长度与文件大小是否一致（诊断 Content-Length mismatch）
    if ($contentLength !== $fileSize && !$fromCache) {
        // 文件可能在读取过程中被修改，重新读取
        $content = @\file_get_contents($filename);
        if ($content === false) {
            return null;
        }
        $contentLength = \strlen($content);
    }
    
    // H13: 根据客户端请求设置正确的 Connection 头
    // HTTP/1.1 默认 keep-alive，除非客户端显式请求 close
    $isHttp11 = \strpos($rawRequest, 'HTTP/1.1') !== false;
    $hasCloseHeader = \stripos($rawRequest, 'Connection: close') !== false;
    $keepAlive = $isHttp11 && !$hasCloseHeader;
    $connectionHeader = $keepAlive ? 'keep-alive' : 'close';
    
    // 构建精简的 HTTP 响应（静态文件不需要 cookie、server 等冗余头部）
    $response = "HTTP/1.1 200 OK\r\n";
    $response .= "Content-Type: {$mimeType}\r\n";
    $response .= "Content-Length: {$contentLength}\r\n";
    $response .= "Cache-Control: public, max-age={$maxAge}\r\n";
    $response .= "ETag: {$etag}\r\n";
    $response .= "Connection: {$connectionHeader}\r\n";
    // WLS 内存缓存状态标识（HIT=内存缓存命中, MISS=磁盘读取）
    $response .= "X-WLS-Static-Cache: " . ($fromCache ? 'HIT' : 'MISS') . "\r\n";
    // H13: 添加文件大小调试头（帮助诊断 Content-Length mismatch）
    $response .= "X-WLS-File-Size: {$fileSize}\r\n";
    // H14: 添加内容长度验证头
    $response .= "X-WLS-Content-Length: {$contentLength}\r\n";
    $response .= "\r\n";
    $response .= $content;
    
    // H14: 最终验证响应完整性
    $expectedResponseLen = \strlen($response);
    $headerEndPos = \strpos($response, "\r\n\r\n");
    $actualBodyLen = $expectedResponseLen - $headerEndPos - 4;
    if ($actualBodyLen !== $contentLength) {
        // 响应构建错误，返回错误响应
        return "HTTP/1.1 500 Internal Server Error\r\n" .
               "Content-Type: text/plain\r\n" .
               "Content-Length: 32\r\n" .
               "Connection: close\r\n" .
               "\r\n" .
               "Response construction error: {$actualBodyLen} != {$contentLength}";
    }
    
    return $response;
}
