<?php
declare(strict_types=1);

/**
 * Weline Server - 统一 Dispatcher
 *
 * TCP 代理模式，将请求转发给 Worker 处理。
 * 实现「单口入口 + 多 Worker 负载均衡」。
 *
 * 用法: php dispatcher.php <host> <port> <worker_base_port> <worker_count> <instance_name> [--name=process_name] [--frontend]
 *
 * 架构:
 *   客户端 → Dispatcher:443 (TCP) → Worker:10443/10444/... → 响应回传
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

// ========== 参数解析 ==========
$host = $argv[1] ?? '0.0.0.0';
$port = (int) ($argv[2] ?? 443);
$workerBasePort = (int) ($argv[3] ?? 10443);
$workerCount = (int) ($argv[4] ?? 2);
$instanceName = $argv[5] ?? 'default';

if ($workerCount <= 0) {
    \fwrite(STDERR, "[Dispatcher] Worker count must be > 0\n");
    exit(1);
}

// 解析 --name、--frontend、--control-port 参数
$processName = '';
$isFrontend = false;
$controlPort = 0;
$masterPid = 0;
foreach ($argv as $arg) {
    if (\str_starts_with($arg, '--name=')) {
        $processName = \substr($arg, 7);
    } elseif ($arg === '--frontend' || $arg === '-frontend') {
        $isFrontend = true;
    } elseif (\str_starts_with($arg, '--control-port=')) {
        $controlPort = (int)\substr($arg, 15);
    } elseif (\str_starts_with($arg, '--master-pid=')) {
        $masterPid = (int)\substr($arg, 13);
    }
}

// ========== 初始化 ==========
$bp = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;
if (!\defined('BP')) {
    \define('BP', $bp);
}
if (!\defined('DS')) {
    \define('DS', DIRECTORY_SEPARATOR);
}

// 定义前端模式常量
if ($isFrontend && !\defined('WLS_FRONTEND_MODE')) {
    \define('WLS_FRONTEND_MODE', true);
}

// 预读 env.php 判断开发模式
$_wlsEnvFile = BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
$_wlsEnvConfig = \is_file($_wlsEnvFile) ? @include $_wlsEnvFile : [];
$_wlsDevMode = ($_wlsEnvConfig['deploy'] ?? '') === 'dev';
if (!\defined('WLS_DEV_MODE')) {
    \define('WLS_DEV_MODE', $_wlsDevMode);
}

// 统一自动加载
require_once BP . 'app' . DIRECTORY_SEPARATOR . 'autoload.php';

// 使用 WlsRuntime 完整初始化框架
$runtimeError = null;
try {
    $runtime = new \Weline\Framework\Runtime\WlsRuntime();
    $runtime->bootstrap();
} catch (\Throwable $e) {
    $runtimeError = $e->getMessage();
    \fwrite(STDERR, "[Dispatcher] 框架初始化失败: " . $e->getMessage() . "\n");
    \error_log('[WLS Dispatcher] Bootstrap error: ' . $e->getMessage());
}

// 读取 env 配置
$envConfig = $_wlsEnvConfig;
unset($_wlsEnvFile, $_wlsEnvConfig, $_wlsDevMode);

// 日志函数
$dispatcherLog = function (string $message, string $level = 'INFO') use ($isFrontend, $envConfig, $port) {
    $timestamp = \date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [Dispatcher] Port:{$port} [{$level}] {$message}\n";
    
    $isDev = false;
    if (\defined('DEV') && DEV) {
        $isDev = true;
    } elseif (\defined('WLS_DEV_MODE') && WLS_DEV_MODE) {
        $isDev = true;
    } elseif ($envConfig !== null && isset($envConfig['deploy']) && $envConfig['deploy'] === 'dev') {
        $isDev = true;
    }
    
    // 颜色映射（按级别，每种级别一种颜色，便于快速区分）
    $color = match($level) {
        'ERROR'    => "\033[91m",        // 亮红色：错误（严重）
        'BAN'      => "\033[91;1m",     // 亮红色+粗体：IP 封禁（SSL 握手失败）
        'SSL_FAIL' => "\033[91m",       // 亮红色：疑似 SSL 握手失败
        'WARN'     => "\033[33m",       // 黄色：警告
        'INFO'     => "\033[36m",       // 青色：一般信息
        'IPC'      => "\033[95m",       // 亮洋红：IPC 通信
        'ROUTE'    => "\033[92m",       // 亮绿色：新连接/路由分配
        'CLOSE'    => "\033[37m",       // 白色：连接关闭（低优先级）
        'DRAIN'    => "\033[93m",       // 亮黄色：排空/恢复
        'HEALTH'   => "\033[94m",       // 亮蓝色：健康检查/探活
        'STATS'    => "\033[96m",       // 亮青色：统计摘要（醒目）
        'DEBUG'    => "\033[90m",       // 暗灰色：调试详情
        default    => "\033[0m",
    };
    
    // 重要级别始终显示；DEBUG/ROUTE/CLOSE/HEALTH/STATS 仅在前端或 DEV 模式显示
    $alwaysShow = \in_array($level, ['ERROR', 'BAN', 'SSL_FAIL', 'WARN', 'INFO', 'IPC', 'DRAIN'], true);
    $shouldPrint = $alwaysShow || $isFrontend || $isDev;
    
    if ($shouldPrint) {
        if (\defined('STDOUT') && \is_resource(STDOUT)) {
            \fwrite(STDOUT, $color . $logMessage . "\033[0m");
            \fflush(STDOUT);
        } elseif (\defined('STDERR') && \is_resource(STDERR)) {
            \fwrite(STDERR, $color . $logMessage . "\033[0m");
            \fflush(STDERR);
        } else {
            echo $color . $logMessage . "\033[0m";
            if (\ob_get_level() > 0) {
                \ob_flush();
            }
            \flush();
        }
    }
    
    if ($isDev) {
        $logFile = BP . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'wls.log';
        $logDir = \dirname($logFile);
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }
        // 日志文件写入纯文本（剥离 ANSI 码）
        @\file_put_contents($logFile, $logMessage, \FILE_APPEND | \LOCK_EX);
    }
};

// ========== 创建 TCP Socket（使用 socket 扩展）==========
$socket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($socket === false) {
    $errorCode = \socket_last_error();
    $errorMsg = \socket_strerror($errorCode);
    \fwrite(STDERR, "[Dispatcher] socket_create failed: ({$errorCode}) {$errorMsg}\n");
    exit(1);
}

// 设置 SO_REUSEADDR
\socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

    // 绑定地址
    if (!@\socket_bind($socket, $host, $port)) {
        $errorCode = \socket_last_error($socket);
        $errorMsg = \socket_strerror($errorCode);
        \fwrite(STDERR, "[Dispatcher] socket_bind failed on {$host}:{$port}: ({$errorCode}) {$errorMsg}\n");
        \socket_close($socket);
        // 如果端口被占用，直接退出，由 Master 处理
        exit(1);
    }

// 监听
if (@\socket_listen($socket, 1024) === false) {
    $errorCode = \socket_last_error($socket);
    $errorMsg = \socket_strerror($errorCode);
    \fwrite(STDERR, "[Dispatcher] socket_listen failed: ({$errorCode}) {$errorMsg}\n");
    \socket_close($socket);
    exit(1);
}

// 设置非阻塞
\socket_set_nonblock($socket);

// ========== 启动 Dispatcher ==========
$dispatcher = new \Weline\Server\Dispatcher\Dispatcher(
    $socket,
    '127.0.0.1', // Worker 主机地址（内网）
    $workerBasePort,
    $workerCount,
    $instanceName,
    $processName,
    $port
);

// 配置
$dispatcher->configure([
    'sni_routing_enabled' => true,
    'learning_mode_enabled' => true,
    'connection_timeout' => 300,
    'cache' => [
        'default_ttl' => 3600,
        'connection_ttl' => 120,
    ],
]);

// 设置日志函数
$dispatcher->setLogFunction($dispatcherLog);

// DEV 模式：通过 WLS_DEV_MODE 常量或 DEV 常量判断
$_dispatcherDevMode = (\defined('WLS_DEV_MODE') && WLS_DEV_MODE) || (\defined('DEV') && DEV);
$dispatcher->setDevMode($_dispatcherDevMode);
unset($_dispatcherDevMode);

$dispatcherLog("Dispatcher 启动，监听 tcp://{$host}:{$port}，Worker 端口范围: {$workerBasePort} - " . ($workerBasePort + $workerCount - 1), 'INFO');

// 连接 IPC 控制通道
$dispatcher->connectIpc($controlPort);

// 传入 Master PID 用于孤儿检测
if ($masterPid > 0) {
    $dispatcher->setMasterPid($masterPid);
}

$dispatcher->run();
