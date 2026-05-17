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

if (!\function_exists('wlsNormalizeMemoryLimit')) {
    function wlsNormalizeMemoryLimit(mixed $value, string $default = '256M'): string
    {
        if (\is_int($value) || \is_float($value)) {
            $value = (string) (int) $value;
        }
        $value = \strtoupper(\trim((string) $value));
        $default = \strtoupper(\trim($default)) ?: '256M';
        if ($value === '') {
            return $default;
        }
        if ($value === '-1') {
            return '-1';
        }
        if (\preg_match('/^[1-9]\d*$/', $value)) {
            return $value . 'M';
        }
        if (\preg_match('/^[1-9]\d*(?:K|M|G)$/', $value)) {
            return $value;
        }
        return $default;
    }
}

$wlsMemoryLimit = '256M';
@\ini_set('memory_limit', $wlsMemoryLimit);

// ========== 参数解析 ==========
$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 443);
// 注意：workerBasePort 应该由 Master 传入，这里的默认值仅作兜底
// 实际端口由 Master 通过 IPC 动态通知，不依赖此默认值
$workerBasePort = (int) ($argv[3] ?? 10000);
$workerCount = (int) ($argv[4] ?? 2);
$instanceName = $argv[5] ?? 'default';

if ($workerCount <= 0) {
    \fwrite(STDERR, "[Dispatcher] Worker count must be > 0\n");
    exit(1);
}

// 解析 --name、--frontend、--control-port 参数
$processName = '';
$isFrontend = false;
$controlPort = 0;  // 初始化为 0，会在下方从实例文件发现
$masterPid = 0;
$orchestratorEpoch = 0;
$orchestratorLaunchId = '';
foreach ($argv as $arg) {
    if (\str_starts_with($arg, '--name=')) {
        $processName = \substr($arg, 7);
    } elseif ($arg === '--frontend' || $arg === '-frontend' || $arg === '--win' || $arg === '-win') {
        $isFrontend = true;
    } elseif (\str_starts_with($arg, '--control-port=')) {
        $controlPort = (int)\substr($arg, 15);
    } elseif (\str_starts_with($arg, '--master-pid=')) {
        $masterPid = (int)\substr($arg, 13);
    } elseif (\str_starts_with($arg, '--epoch=')) {
        $orchestratorEpoch = (int)\substr($arg, 8);
    } elseif (\str_starts_with($arg, '--launch-id=')) {
        $orchestratorLaunchId = (string)\substr($arg, 12);
    } elseif (\str_starts_with($arg, '--memory-limit=')) {
        $wlsMemoryLimit = wlsNormalizeMemoryLimit(\substr($arg, 15));
    }
}
@\ini_set('memory_limit', $wlsMemoryLimit);

// ========== 初始化 ==========
$bp = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;
if (!\defined('BP')) {
    \define('BP', $bp);
}
if (!\defined('DS')) {
    \define('DS', DIRECTORY_SEPARATOR);
}

// 先完成自动加载；控制面解析与框架 bootstrap 可能较慢，主端口须尽快 listen，否则客户端会得到 ERR_CONNECTION_REFUSED（无法进入 503 启动页）。
require_once BP . 'app' . DIRECTORY_SEPARATOR . 'autoload.php';

// ========== 主端口尽早 listen（先于 resolveControlPort / WlsRuntime）==========
if (!\function_exists('wlsDispatcherIsIpBindAddress')) {
    function wlsDispatcherIsIpBindAddress(string $host): bool
    {
        $host = \trim($host);
        if ($host === '' || $host === '0.0.0.0' || $host === '::' || $host === '*') {
            return true;
        }

        return \filter_var($host, FILTER_VALIDATE_IP) !== false;
    }
}

if (!\function_exists('wlsDispatcherBindListenSocket')) {
    /**
     * @param \Socket|resource $socket
     * @return array{success:bool,host:string,error_code:int,error_msg:string,fallback_used:bool}
     */
    function wlsDispatcherBindListenSocket($socket, string $host, int $port): array
    {
        $host = \trim($host);
        if ($host === '' || $host === '*') {
            $host = '0.0.0.0';
        }

        if (@\socket_bind($socket, $host, $port)) {
            return [
                'success' => true,
                'host' => $host,
                'error_code' => 0,
                'error_msg' => '',
                'fallback_used' => false,
            ];
        }

        $errorCode = \socket_last_error($socket);
        $errorMsg = \socket_strerror($errorCode);
        if (wlsDispatcherIsIpBindAddress($host)) {
            return [
                'success' => false,
                'host' => $host,
                'error_code' => $errorCode,
                'error_msg' => $errorMsg,
                'fallback_used' => false,
            ];
        }

        $fallbackHost = '127.0.0.1';
        if (@\socket_bind($socket, $fallbackHost, $port)) {
            return [
                'success' => true,
                'host' => $fallbackHost,
                'error_code' => $errorCode,
                'error_msg' => $errorMsg,
                'fallback_used' => true,
            ];
        }

        $fallbackErrorCode = \socket_last_error($socket);
        return [
            'success' => false,
            'host' => $host,
            'error_code' => $fallbackErrorCode,
            'error_msg' => $errorMsg . '; fallback 127.0.0.1 failed: (' . $fallbackErrorCode . ') ' . \socket_strerror($fallbackErrorCode),
            'fallback_used' => false,
        ];
    }
}

$socket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($socket === false) {
    $errorCode = \socket_last_error();
    $errorMsg = \socket_strerror($errorCode);
    \fwrite(STDERR, "[Dispatcher] socket_create failed: ({$errorCode}) {$errorMsg}\n");
    exit(1);
}
$reuseResult = \Weline\Server\Socket\ListenSocketOptions::applyRawListenSocketReuseOption($socket);
if (!$reuseResult['success']) {
    \fwrite(STDERR, "[Dispatcher] socket_set_option {$reuseResult['label']} failed: ({$reuseResult['errno']}) {$reuseResult['error']}\n");
    \socket_close($socket);
    exit(1);
}
$bindResult = wlsDispatcherBindListenSocket($socket, (string)$host, $port);
if (!$bindResult['success']) {
    $errorCode = (int)$bindResult['error_code'];
    $errorMsg = (string)$bindResult['error_msg'];
    \fwrite(STDERR, "[Dispatcher] socket_bind failed on {$host}:{$port}: ({$errorCode}) {$errorMsg}\n");
    \socket_close($socket);
    exit(1);
}
$requestedHost = (string)$host;
$host = (string)$bindResult['host'];
if ($bindResult['fallback_used']) {
    \fwrite(STDERR, "[Dispatcher] socket_bind fallback: {$requestedHost}:{$port} failed, listening on {$host}:{$port}\n");
}
if (@\socket_listen($socket, 1024) === false) {
    $errorCode = \socket_last_error($socket);
    $errorMsg = \socket_strerror($errorCode);
    \fwrite(STDERR, "[Dispatcher] socket_listen failed: ({$errorCode}) {$errorMsg}\n");
    \socket_close($socket);
    exit(1);
}
\socket_set_nonblock($socket);

\Weline\Server\Log\LogConfig::bootstrapVerboseFromInstanceFile($instanceName);

// IPC 控制端口（从实例 JSON 发现，支持并发启动无序）
// 优先使用命令行参数 --control-port=，否则从实例文件自动发现
// resolveControlPort 会轮询等待 Master 写入实例信息（最多 30 秒）
if ($controlPort <= 0) {
    $controlPort = \Weline\Server\IPC\ChildControl\SubprocessControlKernel::resolveControlPort($instanceName, 0, 30);
}
$supervisorEnabledRaw = \getenv('WLS_SUPERVISOR_ENABLED');
$supervisorEnabled = $supervisorEnabledRaw !== false
    && $supervisorEnabledRaw !== ''
    && \in_array(\strtolower((string) $supervisorEnabledRaw), ['1', 'true', 'yes', 'on'], true);

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

(new \Weline\Server\Service\LongRunningPhpRuntime())->apply();

// 初始化 WLS 统一错误捕获系统（Layer 1-3）
use Weline\Server\Log\Error\ErrorBootstrap;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Log\LogLevel;

$processTag = 'Dispatcher:' . $port . '@' . $instanceName;

ErrorBootstrap::init($processTag, [
    'port' => $port,
    'worker_base_port' => $workerBasePort,
    'worker_count' => $workerCount,
    'instance' => $instanceName,
    'process_name' => $processName,
]);

WlsLogger::getInstance()
    ->setStdoutEnabled(\Weline\Server\Log\LogConfig::isStdoutEnabled($isFrontend, \Weline\Server\Log\LogConfig::isDevMode()))
    ->setProcessTag($processTag);

if ($processName) {
    \Weline\Server\Service\WlsLogService::prepareProcessLogFile($processName, $instanceName, $processTag);
    \Weline\Framework\System\Process\Processer::setPid('--name=' . $processName, \getmypid());
    if ($port > 0) {
        \Weline\Framework\System\Process\Processer::setProcessPorts('--name=' . $processName, [$port]);
    }
}

// Daemon 下向已关闭连接写数据会触发 SIGPIPE 导致进程退出，与 Nginx 一致忽略 SIGPIPE
if (\function_exists('pcntl_signal') && \defined('SIGPIPE')) {
    \pcntl_signal(SIGPIPE, SIG_IGN);
}

// 使用 WlsRuntime 完整初始化框架
$runtimeError = null;
try {
    $runtime = new \Weline\Framework\Runtime\WlsRuntime();
    $runtime->bootstrap();
} catch (\Throwable $e) {
    $runtimeError = $e->getMessage();
    WlsLogger::getInstance()->error("框架初始化失败: " . $e->getMessage());
}

// 读取 env 配置
$envConfig = $_wlsEnvConfig;
unset($_wlsEnvFile, $_wlsEnvConfig, $_wlsDevMode);

// ========== 启动 Dispatcher（主端口已在上方 listen）==========
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
$wlsConfig = \is_array($envConfig['wls'] ?? null) ? $envConfig['wls'] : [];
$startupProtectionConfig = \is_array($wlsConfig['startup_protection'] ?? null) ? $wlsConfig['startup_protection'] : [];
$dispatcherConfig = \is_array($wlsConfig['dispatcher'] ?? null) ? $wlsConfig['dispatcher'] : [];
$dispatcher->configure([
    'sni_routing_enabled' => true,
    'learning_mode_enabled' => true,
    'connection_timeout' => 300,
    'main_loop_unblocked_log_every' => \Weline\Server\Service\MainLoopUnblockedLogConfig::resolve($wlsConfig, ['dispatcher']),
    'main_loop_unblocked_log_interval_sec' => \Weline\Server\Service\MainLoopUnblockedLogConfig::resolveInterval($wlsConfig, ['dispatcher']),
    'startup_protection_enabled' => (bool)($startupProtectionConfig['enabled'] ?? true),
    'startup_protection_window_sec' => (float)($startupProtectionConfig['window_sec'] ?? 45.0),
    'startup_protection_ready_ratio' => (float)($startupProtectionConfig['ready_ratio'] ?? 0.0),
    'startup_protection_min_ready' => (int)($startupProtectionConfig['min_ready'] ?? 1),
    'spin_wait_max_seconds' => (float)($dispatcherConfig['spin_wait_max_seconds'] ?? 3.0),
    'homepage_warmup_enabled' => (bool)($dispatcherConfig['homepage_warmup_enabled'] ?? true),
    'cache' => [
        'default_ttl' => 3600,
        'connection_ttl' => 120,
    ],
]);

// DEV 模式：通过 WLS_DEV_MODE 常量或 DEV 常量判断
$_dispatcherDevMode = (\defined('WLS_DEV_MODE') && WLS_DEV_MODE) || (\defined('DEV') && DEV);
$dispatcher->setDevMode($_dispatcherDevMode);
unset($_dispatcherDevMode);

WlsLogger::info_("Dispatcher 启动，监听 tcp://{$host}:{$port}，预计 Worker 数: {$workerCount}（实际端口由 Master 动态通知）");

// 连接 IPC 控制通道
$dispatcher->setLifecycleTokens($orchestratorEpoch, $orchestratorLaunchId);

if ($controlPort > 0 || $supervisorEnabled) {
    $dispatcher->connectIpc($controlPort);
}

// 传入 Master PID 用于孤儿检测
if ($masterPid > 0) {
    $dispatcher->setMasterPid($masterPid);
}

$dispatcher->run();
