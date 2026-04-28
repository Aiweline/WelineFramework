<?php
declare(strict_types=1);

/**
 * Weline Server - HTTP 重定向 Worker（仅 HTTPS 启用时由 Master 启动）
 *
 * 监听 HTTP 端口（默认 80），将请求 301 重定向到 HTTPS。
 * 用法: php http_redirect_worker.php <host> <http_port> <https_port> [instance_name] [--name=xxx]
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$host = $argv[1] ?? '127.0.0.1';
$httpPort = (int) ($argv[2] ?? 80);
$httpsPort = (int) ($argv[3] ?? 443);
$instanceName = $argv[4] ?? 'default';

// 解析命令行参数
$processName = '';
$controlPort = 0;  // 初始化为 0，会在下方从实例文件发现
$masterPid = 0;
$orchestratorEpoch = 0;
$orchestratorLaunchId = '';
foreach ($argv as $arg) {
    if (\str_starts_with($arg, '--name=')) {
        $processName = \substr($arg, 7);
    } elseif (\str_starts_with($arg, '--control-port=')) {
        $controlPort = (int)\substr($arg, 15);
    } elseif (\str_starts_with($arg, '--master-pid=')) {
        $masterPid = (int)\substr($arg, 13);
    } elseif (\str_starts_with($arg, '--epoch=')) {
        $orchestratorEpoch = (int)\substr($arg, 8);
    } elseif (\str_starts_with($arg, '--launch-id=')) {
        $orchestratorLaunchId = (string)\substr($arg, 12);
    }
}

// 检测根目录
$bp = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;
if (!\defined('BP')) {
    \define('BP', $bp);
}
if (!\defined('DS')) {
    \define('DS', DIRECTORY_SEPARATOR);
}

// resolveControlPort 依赖框架类与 BP 常量，必须先完成基础 bootstrap。
require_once BP . 'app' . DIRECTORY_SEPARATOR . 'autoload.php';

\Weline\Server\Log\LogConfig::bootstrapVerboseFromInstanceFile($instanceName);

// IPC 控制端口（从实例 JSON 发现，支持并发启动无序）
// 优先使用命令行参数 --control-port=，否则从实例文件自动发现
if ($controlPort <= 0) {
    $controlPort = \Weline\Server\IPC\ChildControl\SubprocessControlKernel::resolveControlPort($instanceName, 0, 30);
}
(new \Weline\Server\Service\LongRunningPhpRuntime())->apply();

// 解析 --frontend 参数
$isFrontend = \in_array('--frontend', $argv, true) || \in_array('-frontend', $argv, true);

// 初始化 WLS 统一错误捕获系统（Layer 1-3）
use Weline\Server\Log\Error\ErrorBootstrap;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Log\LogLevel;

if ($isFrontend && !\defined('WLS_FRONTEND_MODE')) {
    \define('WLS_FRONTEND_MODE', true);
}

$processTag = 'HttpRedirect:' . $httpPort . '@' . $instanceName;

ErrorBootstrap::init($processTag, [
    'http_port' => $httpPort,
    'https_port' => $httpsPort,
    'instance' => $instanceName,
    'process_name' => $processName,
]);

WlsLogger::getInstance()
    ->setStdoutEnabled(\Weline\Server\Log\LogConfig::isStdoutEnabled($isFrontend, \Weline\Server\Log\LogConfig::isDevMode()))
    ->setProcessTag($processTag);

// 进程日志文件（持久化，跨重启保留）
if ($processName) {
    \Weline\Server\Service\WlsLogService::prepareProcessLogFile($processName, $instanceName, $processTag);
}

// 注册 PID 到进程管理器
if ($processName) {
    \Weline\Framework\System\Process\Processer::setPid('--name=' . $processName, \getmypid());
    // 注册监听端口（启用快速端口→PID 查找）
    if ($httpPort > 0) {
        \Weline\Framework\System\Process\Processer::setProcessPorts('--name=' . $processName, [$httpPort]);
    }
}

// 读取环境配置
$envConfig = null;
$_envFile = BP . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
if (\is_file($_envFile)) {
    $envConfig = require $_envFile;
}
unset($_envFile);

$isDev = (\defined('DEV') && DEV) || (\defined('WLS_DEV_MODE') && WLS_DEV_MODE)
    || ($envConfig !== null && isset($envConfig['deploy']) && $envConfig['deploy'] === 'dev');
$supervisorEnabledRaw = \getenv('WLS_SUPERVISOR_ENABLED');
$supervisorEnabled = $supervisorEnabledRaw !== false
    && $supervisorEnabledRaw !== ''
    && \in_array(\strtolower((string) $supervisorEnabledRaw), ['1', 'true', 'yes', 'on'], true);


$context = \stream_context_create([
    'socket' => \Weline\Server\Socket\ListenSocketOptions::streamContextOptions([
        'backlog' => 256,
    ]),
]);

if (!\function_exists('wlsRedirectIsIpBindAddress')) {
    function wlsRedirectIsIpBindAddress(string $host): bool
    {
        $host = \trim($host);
        if ($host === '' || $host === '0.0.0.0' || $host === '::' || $host === '*') {
            return true;
        }

        return \filter_var($host, FILTER_VALIDATE_IP) !== false;
    }
}

if (!\function_exists('wlsRedirectBindListenSocket')) {
    /**
     * @return array{socket:resource|false,host:string,errno:int,errstr:string,fallback_used:bool}
     */
    function wlsRedirectBindListenSocket(string $host, int $port, $context): array
    {
        $host = \trim($host);
        if ($host === '' || $host === '*') {
            $host = '127.0.0.1';
        }

        $errno = 0;
        $errstr = '';
        $socket = @\stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );
        if ($socket || wlsRedirectIsIpBindAddress($host)) {
            return [
                'socket' => $socket,
                'host' => $host,
                'errno' => (int)$errno,
                'errstr' => (string)$errstr,
                'fallback_used' => false,
            ];
        }

        $fallbackHost = '127.0.0.1';
        $fallbackErrno = 0;
        $fallbackErrstr = '';
        $fallbackSocket = @\stream_socket_server(
            "tcp://{$fallbackHost}:{$port}",
            $fallbackErrno,
            $fallbackErrstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        return [
            'socket' => $fallbackSocket,
            'host' => $fallbackSocket ? $fallbackHost : $host,
            'errno' => $fallbackSocket ? (int)$errno : (int)$fallbackErrno,
            'errstr' => $fallbackSocket ? (string)$errstr : ((string)$errstr . '; fallback 127.0.0.1 failed: ' . (string)$fallbackErrstr),
            'fallback_used' => (bool)$fallbackSocket,
        ];
    }
}

$bindResult = wlsRedirectBindListenSocket((string)$host, $httpPort, $context);
$socket = $bindResult['socket'];

if (!$socket) {
    WlsLogger::error_("Failed to bind {$host}:{$httpPort}: {$bindResult['errstr']}");
    exit(1);
}
$requestedHost = (string)$host;
$host = (string)$bindResult['host'];
if ($bindResult['fallback_used']) {
    WlsLogger::warning_("Bind fallback: {$requestedHost}:{$httpPort} failed, listening on {$host}:{$httpPort}");
}

\stream_set_blocking($socket, false);

// 启动日志（与 Dispatcher/Worker 风格一致）
WlsLogger::info_("Started on tcp://{$host}:{$httpPort}");
WlsLogger::info_("Instance: {$instanceName}, HTTP→HTTPS 301 Redirect, Target port: {$httpsPort}, PID: " . \getmypid());
WlsLogger::info_("DEV=" . ($isDev ? 'ON' : 'OFF') . ", Frontend=" . ($isFrontend ? 'ON' : 'OFF'));

// ========== IPC 控制通道 ==========
$ipcReceivedShutdown = false;
$orphanGuard = new \Weline\Server\IPC\ChildControl\MasterOrphanGuard();
$kernel = null;
$controlPort = \Weline\Server\IPC\ChildControl\SubprocessControlKernel::resolveControlPort($instanceName, $controlPort);
if ($controlPort > 0 || $supervisorEnabled) {
    $identity = new \Weline\Server\IPC\ChildControl\ChildProcessIdentity(
        \Weline\Server\IPC\ControlMessage::ROLE_REDIRECT,
        \getmypid(),
        $httpPort,
        0,
        $orchestratorEpoch,
        $orchestratorLaunchId
    );
    $handler = new \Weline\Server\IPC\ChildControl\Handler\RedirectControlHandler(
        static function (bool $shutdown) use (&$ipcReceivedShutdown): void {
            $ipcReceivedShutdown = $shutdown;
        }
    );
    $kernel = new \Weline\Server\IPC\ChildControl\SubprocessControlKernel(
        $identity,
        $handler,
        'Redirect',
        $isDev,
        $instanceName
    );
    if ($kernel->connectAndRegister($controlPort)) {
        if ($isDev || $isFrontend) {
            $client = $kernel->getClient();
            if ($client !== null) {
                WlsLogger::getInstance()->setIpcLogSink(static function (string $line, string $level, string $tag) use ($client): void {
                    if ($client->isConnected()) {
                        $client->sendLogLine($line, $level, $tag);
                    }
                });
            }
        }
    } else {
        
    }
}
// ========== IPC 控制通道结束 ==========

$connections = [];
$requestBuffers = [];

// 优雅退出函数（统一使用进程管理器清理）
$gracefulExit = function (string $reason = '') use ($socket, &$connections, $processName, &$kernel, $httpPort) {
    if ($reason) {
        WlsLogger::warning_("退出: {$reason}");
    }
    
    // 通知 Master 即将退出（IPC exited 消息）
    if ($kernel && $kernel->isConnected()) {
        $kernel->sendExited();
        WlsLogger::info_("已发送 exited 消息给 Master");
    }
    
    // 关闭 IPC 客户端
    if ($kernel) {
        $kernel->close();
    }
    
    foreach ($connections as $conn) {
        @\fclose($conn);
    }
    @\fclose($socket);
    
    // 使用进程管理器清理 PID 文件
    if ($processName) {
        \Weline\Framework\System\Process\Processer::destroy('--name=' . $processName);
    }
    
    exit(0);
};

// 信号处理（仅 Linux/Mac）
// 注意：子进程不处理 SIGINT（Ctrl+C），由 Master 通过 IPC 广播 SHUTDOWN 通知退出
// Daemon 下向已关闭连接写数据会触发 SIGPIPE 导致进程退出，与 Nginx 一致忽略 SIGPIPE
if (\function_exists('pcntl_signal')) {
    if (\defined('SIGPIPE')) {
        \pcntl_signal(SIGPIPE, SIG_IGN);
    }
    \pcntl_signal(SIGINT, SIG_IGN);
    \pcntl_signal(SIGTERM, function () use ($gracefulExit) {
        $gracefulExit('收到 SIGTERM 信号');
    });
}

while (true) {
    if (\function_exists('pcntl_signal_dispatch')) {
        \pcntl_signal_dispatch();
    }
    
    // 检查 shutdown
    if ($ipcReceivedShutdown) {
        $gracefulExit('收到 IPC shutdown 命令');
    }
    
    // ========== 孤儿检测（IPC 优先） ==========
    if ($orphanGuard->shouldExit(
        $masterPid,
        $kernel !== null && $kernel->isConnected(),
        $ipcReceivedShutdown,
        'Redirect'
    )) {
        WlsLogger::warning_("Master PID {$masterPid} 已死亡，自行退出（孤儿保护）");
        $gracefulExit('孤儿检测：Master 已死亡');
    }
    
    // IPC 重连
    if ($kernel && !$kernel->isConnected() && !$ipcReceivedShutdown) {
        $kernel->reconnect();
    }

    // 构建 stream_select 读数组
    $readSockets = \array_merge([$socket], $connections);
    
    // 加入 IPC 控制 socket
    $ipcSocket = ($kernel && $kernel->isConnected()) ? $kernel->getSocket() : null;
    if ($ipcSocket && \is_resource($ipcSocket)) {
        $readSockets[] = $ipcSocket;
    }

    $read = $readSockets;
    $write = [];
    if ($ipcSocket && $kernel && $kernel->hasPendingWrites()) {
        $write[] = $ipcSocket;
    }
    $except = [];
    $changed = @\stream_select($read, $write, $except, 0, 100000);

    if ($changed === false) {
        continue;
    }

    // 处理 IPC 控制通道消息（处理后从 $read 移除，防止被 HTTP 连接循环误关闭）
    if ($ipcSocket && \in_array($ipcSocket, $read, true)) {
        if ($kernel) {
            $kernel->tick();
        }
        $ipcKey = \array_search($ipcSocket, $read, true);
        if ($ipcKey !== false) {
            unset($read[$ipcKey]);
        }
    }
    if ($ipcSocket && \in_array($ipcSocket, $write, true) && $kernel) {
        $kernel->flushWrites();
    }
    
    if (\in_array($socket, $read, true)) {
        $conn = @\stream_socket_accept($socket, 0);
        if ($conn) {
            \stream_set_blocking($conn, false);
            $connId = \get_resource_id($conn);
            $connections[$connId] = $conn;
            $requestBuffers[$connId] = '';
        }
        $key = \array_search($socket, $read);
        unset($read[$key]);
    }

    foreach ($read as $conn) {
        $connId = \get_resource_id($conn);
        $data = @\fread($conn, 65535);

        if ($data === false || $data === '') {
            @\fclose($conn);
            unset($connections[$connId], $requestBuffers[$connId]);
            continue;
        }

        $requestBuffers[$connId] = ($requestBuffers[$connId] ?? '') . $data;
        $buf = $requestBuffers[$connId];

        if (\strpos($buf, "\r\n\r\n") === false) {
            continue;
        }

        $requestHeaders = \explode("\r\n", $buf);
        $requestLine = $requestHeaders[0] ?? '';
        $path = '/';
        $hostHeader = $host . ':' . $httpPort;

        if (\preg_match('/^\w+\s+(\S+)/', $requestLine, $m)) {
            $path = \parse_url($m[1], PHP_URL_PATH) ?: '/';
        }
        foreach ($requestHeaders as $line) {
            if (\stripos($line, 'Host:') === 0) {
                $hostHeader = \trim(\substr($line, 5));
                break;
            }
        }

        $localCaDir = BP . 'var' . DS . 'server' . DS . '_local_ca' . DS;
        $localCaAssets = [
            '/_wls/local-ca/rootCA.pem' => [
                'path' => $localCaDir . 'rootCA.pem',
                'content_type' => 'application/x-x509-ca-cert',
            ],
            '/_wls/local-ca/rootCA.crl' => [
                'path' => $localCaDir . 'rootCA.crl',
                'content_type' => 'application/pkix-crl',
            ],
        ];

        if (isset($localCaAssets[$path])) {
            $asset = $localCaAssets[$path];
            $assetBody = \is_file($asset['path']) ? (string) @\file_get_contents($asset['path']) : '';
            $statusLine = $assetBody !== '' ? '200 OK' : '404 Not Found';
            $response = "HTTP/1.1 {$statusLine}\r\n";
            $response .= "Content-Type: {$asset['content_type']}\r\n";
            $response .= "Cache-Control: public, max-age=300\r\n";
            $response .= "Content-Length: " . \strlen($assetBody) . "\r\n";
            $response .= "Connection: close\r\n\r\n";
            $response .= $assetBody;

            @\fwrite($conn, $response);
            @\fclose($conn);
            unset($connections[$connId], $requestBuffers[$connId]);
            continue;
        }

        $redirectHost = $hostHeader;
        if (\strpos($redirectHost, ':') !== false) {
            $redirectHost = \explode(':', $redirectHost, 2)[0];
        }
        $redirectPort = $httpsPort;
        $redirectUrl = ($redirectPort === 443)
            ? "https://{$redirectHost}{$path}"
            : "https://{$redirectHost}:{$redirectPort}{$path}";

        $body = '';
        $response = "HTTP/1.1 301 Moved Permanently\r\n";
        $response .= "Location: {$redirectUrl}\r\n";
        $response .= "Content-Type: text/html; charset=utf-8\r\n";
        $response .= "Content-Length: " . \strlen($body) . "\r\n";
        $response .= "Connection: close\r\n\r\n";
        $response .= $body;

        @\fwrite($conn, $response);
        @\fclose($conn);
        unset($connections[$connId], $requestBuffers[$connId]);
    }
}
