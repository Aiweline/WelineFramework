<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ChildControl\ChildMasterGuard;
use Weline\Server\IPC\ChildControl\ChildControlClientInterface;
use Weline\Server\IPC\ControlClient;
use Weline\Server\IPC\ControlMessage;

/**
 * WLS Gateway - 多项目统一入口反向代理
 *
 * 基于 SNI（Server Name Indication）实现域名路由：
 * - 监听 443 端口
 * - 根据 SSL 握手中的域名，转发到对应项目的内网端口
 * - 支持动态添加/移除项目
 * - 支持 IPC 动态路由更新
 *
 * 使用场景：
 * 1. 开发环境：多个项目共享 443 端口
 * 2. 生产环境：单 IP 多域名部署
 */
class WlsGateway
{
    private const MASTER_PID_CHECK_INTERVAL_SEC = 5;

    /**
     * 路由表：域名 => 后端地址
     *
     * @var array<string, array{host: string, port: int, ssl: bool, priority: int}>
     */
    private array $routes = [];

    /**
     * 默认后端（当域名不匹配时）
     */
    private ?array $defaultBackend = null;

    /**
     * 监听地址
     */
    private string $listenHost = '0.0.0.0';

    /**
     * 监听端口
     */
    private int $listenPort = 443;

    private string $instanceName = 'default';

    /**
     * IPC 控制端口
     */
    private int $controlPort = 0;

    /**
     * Master PID
     */
    private int $masterPid = 0;
    private int $lastMasterPidCheck = 0;
    private ?ChildMasterGuard $masterGuard = null;

    /**
     * 是否启用动态路由
     */
    private bool $dynamicRoutingEnabled = false;

    /**
     * IPC 连接资源
     */
    private ?ChildControlClientInterface $ipcClient = null;

    /**
     * IPC 读取缓冲区
     */
    private int $ipcEpoch = 0;

    private string $ipcLaunchId = '';

    private string $ipcSlotId = '';

    private string $ipcLeaseId = '';

    private int $ipcGeneration = 0;

    private bool $running = true;

    private bool $readyAcknowledged = false;

    private float $lastReadySentAt = 0.0;

    /**
     * 添加路由规则
     *
     * @param string $domain 域名（支持通配符 *.example.com）
     * @param string $backendHost 后端主机
     * @param int $backendPort 后端端口
     * @param bool $backendSsl 后端是否使用 SSL
     * @param int $priority 优先级（数字越大优先级越高）
     */
    public function addRoute(string $domain, string $backendHost, int $backendPort, bool $backendSsl = true, int $priority = 0): void
    {
        $this->routes[$domain] = [
            'host' => $backendHost,
            'port' => $backendPort,
            'ssl' => $backendSsl,
            'priority' => $priority,
        ];
    }

    /**
     * 设置默认后端
     */
    public function setDefaultBackend(string $host, int $port, bool $ssl = true): void
    {
        $this->defaultBackend = [
            'host' => $host,
            'port' => $port,
            'ssl' => $ssl,
        ];
    }

    /**
     * 从配置文件加载路由
     *
     * 配置格式：
     * ```php
     * return [
     *     'gateway' => [
     *         'listen' => '0.0.0.0:443',
     *         'routes' => [
     *             'project-a.com' => ['host' => '127.0.0.1', 'port' => 10443],
     *             'project-b.com' => ['host' => '127.0.0.1', 'port' => 10444],
     *             '*.dev.local' => ['host' => '127.0.0.1', 'port' => 10445],
     *         ],
     *         'default' => ['host' => '127.0.0.1', 'port' => 10443],
     *     ],
     * ];
     * ```
     */
    public function loadConfig(array $config): void
    {
        // 解析监听地址
        if (isset($config['listen'])) {
            [$host, $port] = explode(':', $config['listen']);
            $this->listenHost = $host;
            $this->listenPort = (int) $port;
        }

        // 加载路由
        foreach ($config['routes'] ?? [] as $domain => $backend) {
            $this->addRoute(
                $domain,
                $backend['host'],
                $backend['port'],
                $backend['ssl'] ?? true
            );
        }

        // 设置默认后端
        if (isset($config['default'])) {
            $this->setDefaultBackend(
                $config['default']['host'],
                $config['default']['port'],
                $config['default']['ssl'] ?? true
            );
        }
    }

    /**
     * 根据域名匹配后端
     */
    private function matchBackend(string $domain): ?array
    {
        // 精确匹配
        if (isset($this->routes[$domain])) {
            return $this->routes[$domain];
        }

        // 通配符匹配（按优先级排序）
        $wildcardMatches = [];
        foreach ($this->routes as $pattern => $backend) {
            if (str_contains($pattern, '*')) {
                $regex = '/^' . str_replace(['*', '.'], ['.*', '\\.'], $pattern) . '$/i';
                if (preg_match($regex, $domain)) {
                    $wildcardMatches[] = $backend;
                }
            }
        }

        // 按优先级排序，返回最高优先级的匹配
        if (!empty($wildcardMatches)) {
            usort($wildcardMatches, function($a, $b) {
                return ($b['priority'] ?? 0) - ($a['priority'] ?? 0);
            });
            return $wildcardMatches[0];
        }

        // 返回默认后端
        return $this->defaultBackend;
    }

    /**
     * 设置 IPC 配置
     */
    public function setIpcConfig(int $controlPort, int $masterPid): void
    {
        $this->controlPort = $controlPort;
        $this->masterPid = $masterPid;
    }

    public function setMasterGuard(?ChildMasterGuard $guard): void
    {
        $this->masterGuard = $guard;
    }

    public function setIpcIdentity(
        int $epoch,
        string $launchId,
        string $slotId,
        string $leaseId,
        int $generation
    ): void {
        $this->ipcEpoch = $epoch;
        $this->ipcLaunchId = $launchId;
        $this->ipcSlotId = $slotId;
        $this->ipcLeaseId = $leaseId;
        $this->ipcGeneration = $generation;
    }

    /**
     * 设置监听地址
     */
    public function setListenAddress(string $host, int $port): void
    {
        $this->listenHost = $host;
        $this->listenPort = $port;
    }

    public function setInstanceName(string $instanceName): void
    {
        $instanceName = \trim($instanceName);
        $this->instanceName = $instanceName !== '' ? $instanceName : 'default';
    }

    /**
     * 启用动态路由
     */
    public function enableDynamicRouting(): void
    {
        if ($this->controlPort <= 0 || $this->masterPid <= 0) {
            throw new \RuntimeException('IPC 配置未设置，无法启用动态路由');
        }

        $client = new ControlClient();
        $client->setSelfTag('Gateway');
        $client->rememberRegistration(
            ControlMessage::ROLE_GATEWAY,
            \getmypid(),
            $this->listenPort,
            1,
            $this->ipcEpoch,
            $this->ipcLaunchId,
            ControlMessage::PROCESS_KIND_FRAMEWORK,
            '',
            $this->instanceName,
            $this->ipcLaunchId
        );
        $client->markReadyState(false);
        $client->onMessage(function (array $message, ChildControlClientInterface $client): void {
            unset($client);
            $this->handleIpcMessage($message);
        });
        $client->onDisconnect(function (bool $receivedShutdown, ChildControlClientInterface $client): void {
            unset($client);
            $this->readyAcknowledged = false;
            if ($receivedShutdown) {
                $this->running = false;
            }
        });

        if (!$client->connect('127.0.0.1', $this->controlPort)) {
            $lastError = \method_exists($client, 'getLastConnectError') ? $client->getLastConnectError() : '';
            throw new \RuntimeException('无法连接 Master IPC' . ($lastError !== '' ? ': ' . $lastError : ''));
        }

        if (!$client->register(
            ControlMessage::ROLE_GATEWAY,
            \getmypid(),
            $this->listenPort,
            1,
            $this->ipcEpoch,
            $this->ipcLaunchId,
            ControlMessage::PROCESS_KIND_FRAMEWORK,
            '',
            $this->instanceName,
            $this->ipcLaunchId
        )) {
            $client->close();
            throw new \RuntimeException('向 Master IPC 注册 Gateway 失败');
        }
        if (!$client->flushPendingWrites(1.0)) {
            $client->close();
            throw new \RuntimeException('向 Master IPC 发送 Gateway 注册消息失败');
        }

        $this->ipcClient = $client;
        $this->dynamicRoutingEnabled = true;
        echo "动态路由已启用，已连接到 Master IPC\n";
    }

    /**
     * 移除路由
     */
    public function removeRoute(string $domain): void
    {
        unset($this->routes[$domain]);
        echo "已移除路由: {$domain}\n";
    }

    /**
     * 重载路由表
     *
     * @param array $routes 路由数组
     */
    public function reloadRoutes(array $routes): void
    {
        // 清空现有路由
        $this->routes = [];

        // 加载新路由
        foreach ($routes as $route) {
            $this->addRoute(
                $route['domain'],
                $route['backend_host'],
                $route['backend_port'],
                (bool) ($route['backend_ssl'] ?? true),
                (int) ($route['priority'] ?? 0)
            );
        }

        echo "路由表已重载，共 " . count($routes) . " 条规则\n";
    }

    /**
     * 处理 IPC 消息
     */
    private function handleIpcMessage(array $message): void
    {
        $type = $message['type'] ?? '';

        switch ($type) {
            case ControlMessage::TYPE_PROXY_ADD_ROUTE:
                $this->addRoute(
                    $message['domain'] ?? '',
                    $message['backend_host'] ?? '',
                    (int) ($message['backend_port'] ?? 0),
                    (bool) ($message['backend_ssl'] ?? true),
                    (int) ($message['priority'] ?? 0)
                );
                echo "IPC: 添加路由 {$message['domain']}\n";
                break;

            case ControlMessage::TYPE_PROXY_REMOVE_ROUTE:
                $this->removeRoute($message['domain'] ?? '');
                echo "IPC: 移除路由 {$message['domain']}\n";
                break;

            case ControlMessage::TYPE_PROXY_RELOAD:
                $this->reloadRoutes($message['routes'] ?? []);
                echo "IPC: 重载路由表\n";
                break;

            case ControlMessage::TYPE_SHUTDOWN:
                echo "IPC: 收到停止信号\n";
                $this->running = false;
                break;

            case ControlMessage::TYPE_ACK:
                break;

            case ControlMessage::TYPE_ACK_READY:
            case ControlMessage::TYPE_READY_ACK:
                if (\array_key_exists('accepted', $message) && !(bool)($message['accepted'] ?? false)) {
                    $this->running = false;
                    break;
                }
                $this->readyAcknowledged = true;
                break;
        }
    }

    private function sendReady(): void
    {
        if (!$this->dynamicRoutingEnabled || !$this->ipcClient || $this->readyAcknowledged) {
            return;
        }
        if (!$this->ipcClient->isConnected()) {
            return;
        }

        $this->ipcClient->sendReady(
            ControlMessage::ROLE_GATEWAY,
            1,
            $this->listenPort,
            $this->ipcEpoch,
            $this->ipcLaunchId,
            $this->ipcLaunchId
        );
        $this->ipcClient->flushPendingWrites(1.0);
        $this->lastReadySentAt = \microtime(true);
    }

    private function resetIpcConnectionState(): void
    {
        $this->ipcClient?->close();
        $this->ipcClient = null;
        $this->dynamicRoutingEnabled = false;
        $this->readyAcknowledged = false;
        $this->lastReadySentAt = 0.0;
    }

    private function reconnectIpcIfNeeded(): void
    {
        if ($this->controlPort <= 0 || $this->masterPid <= 0) {
            return;
        }
        if ($this->ipcClient !== null && $this->ipcClient->isConnected()) {
            return;
        }
        if ($this->ipcClient !== null && $this->ipcClient->tryReconnect()) {
            if (!$this->readyAcknowledged) {
                $this->sendReady();
            }
            return;
        }

        try {
            $this->enableDynamicRouting();
            $this->sendReady();
        } catch (\Throwable $e) {
            echo "IPC 閲嶈繛澶辫触: {$e->getMessage()}\n";
            $this->resetIpcConnectionState();
        }
    }

    /**
     * 检查并处理 IPC 消息
     */
    private function checkIpcMessages(): void
    {
        if (!$this->ipcClient || !$this->ipcClient->isConnected()) {
            $this->reconnectIpcIfNeeded();
            return;
        }

        if (!$this->dynamicRoutingEnabled) {
            return;
        }

        $this->ipcClient->handleReadable();
        $this->ipcClient->handleWritable();
        if (!$this->ipcClient->isConnected()) {
            return;
        }
        if ($this->ipcClient->hasReceivedShutdown()) {
            $this->running = false;
            return;
        }
        if ($this->ipcClient->isReadyStateConfirmed()) {
            $this->readyAcknowledged = true;
        }

        if (!$this->readyAcknowledged && (\microtime(true) - $this->lastReadySentAt) >= 1.0) {
            $this->sendReady();
        }
    }

    private function checkMasterPidAlive(): void
    {
        if ($this->masterGuard !== null && $this->masterGuard->shouldExit()) {
            echo "Master lease/PID 已失效，Gateway 自行退出: " . $this->masterGuard->getLastExitReason() . "\n";
            $this->running = false;
            return;
        }

        if ($this->masterPid <= 0) {
            return;
        }

        $now = \time();
        if (($now - $this->lastMasterPidCheck) < self::MASTER_PID_CHECK_INTERVAL_SEC) {
            return;
        }
        $this->lastMasterPidCheck = $now;

        if (Processer::isRunningByPid($this->masterPid)) {
            return;
        }

        echo "Master PID {$this->masterPid} 已不存在，Gateway 自行退出\n";
        $this->running = false;
    }

    /**
     * @param resource $listenSocket
     */
    private function waitForActivity($listenSocket): void
    {
        $read = [$listenSocket];
        $ipcSocket = $this->ipcClient?->getSocket();
        if (\is_resource($ipcSocket)) {
            $read[] = $ipcSocket;
        }

        $write = null;
        if ($this->ipcClient?->hasPendingWrites() && \is_resource($ipcSocket)) {
            $write = [$ipcSocket];
        }
        $except = null;
        @\stream_select($read, $write, $except, 0, 1000);
    }

    /**
     * 启动 Gateway
     */
    public function start(): void
    {
        echo "WLS Gateway 启动中...\n";
        echo "监听: {$this->listenHost}:{$this->listenPort}\n";
        echo "路由规则:\n";
        foreach ($this->routes as $domain => $backend) {
            echo "  {$domain} => {$backend['host']}:{$backend['port']}\n";
        }

        // 如果启用动态路由，先连接 IPC
        if ($this->dynamicRoutingEnabled && $this->ipcClient) {
            echo "动态路由已启用\n";
        }

        $this->masterGuard?->assertAliveOrExit('Gateway listen 前 Master 自治检查');

        // 创建 TCP Socket
        $socket = stream_socket_server(
            "tcp://{$this->listenHost}:{$this->listenPort}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );

        if (!$socket) {
            throw new \RuntimeException("无法启动 Gateway: {$errstr} ({$errno})");
        }

        stream_set_blocking($socket, false);
        $this->sendReady();

        echo "Gateway 已启动，等待连接...\n";

        // 事件循环
        while ($this->running) {
            // 检查 IPC 消息
            $this->checkIpcMessages();
            if (!$this->running) {
                break;
            }
            $this->checkMasterPidAlive();
            if (!$this->running) {
                break;
            }

            // 接受客户端连接
            $client = @stream_socket_accept($socket, 0);
            if ($client) {
                $this->handleClient($client);
            }

            $this->waitForActivity($socket);
        }

        fclose($socket);
    }

    /**
     * 处理客户端连接
     */
    private function handleClient($client): void
    {
        // 读取 ClientHello 获取 SNI
        $initialData = '';
        // Read ClientHello once for SNI, then forward the same bytes to the backend.
        $domain = $this->extractSNI($client, $initialData);
        if (!$domain) {
            echo "无法提取 SNI，关闭连接\n";
            fclose($client);
            return;
        }

        echo "收到连接: {$domain}\n";

        // 匹配后端
        $backend = $this->matchBackend($domain);
        if (!$backend) {
            echo "未找到匹配的后端: {$domain}\n";
            fclose($client);
            return;
        }

        echo "转发到: {$backend['host']}:{$backend['port']}\n";

        // 连接后端
        $backendConn = @stream_socket_client(
            "tcp://{$backend['host']}:{$backend['port']}",
            $errno,
            $errstr,
            5
        );

        if (!$backendConn) {
            echo "无法连接后端: {$errstr} ({$errno})\n";
            fclose($client);
            return;
        }

        // 双向转发
        if ($initialData !== '') {
            fwrite($backendConn, $initialData);
        }

        $this->relay($client, $backendConn);
    }

    /**
     * 从 TLS ClientHello 中提取 SNI
     */
    private function extractSNI($socket, string &$initialData = ''): ?string
    {
        // 读取 TLS 握手数据
        stream_set_blocking($socket, false);
        $read = [$socket];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, 1, 0);
        if ($ready !== 1) {
            return null;
        }

        $data = fread($socket, 4096);
        $initialData = \is_string($data) ? $data : '';
        if (!$data) {
            return null;
        }

        // 简化的 SNI 解析（实际应该更严格）
        // TLS ClientHello 格式：
        // - Byte 0: Content Type (0x16 = Handshake)
        // - Byte 1-2: TLS Version
        // - Byte 3-4: Length
        // - Byte 5: Handshake Type (0x01 = ClientHello)
        // - ...
        // - SNI Extension (Type 0x0000)

        if (\strlen($data) < 5 || \ord($data[0]) !== 0x16) {
            return null; // 不是 TLS 握手
        }

        // 查找 SNI 扩展（简化版）
        $recordLength = $this->readUint16($data, 3);
        if ($recordLength <= 0 || \strlen($data) < 9 || \ord($data[5]) !== 0x01) {
            return null;
        }

        $offset = 9; // record header + handshake header
        $offset += 2; // client version
        $offset += 32; // random
        if (!isset($data[$offset])) {
            return null;
        }

        $sessionIdLength = \ord($data[$offset]);
        $offset += 1 + $sessionIdLength;
        if ($offset + 2 > \strlen($data)) {
            return null;
        }

        $cipherSuitesLength = $this->readUint16($data, $offset);
        $offset += 2 + $cipherSuitesLength;
        if (!isset($data[$offset])) {
            return null;
        }

        $compressionMethodsLength = \ord($data[$offset]);
        $offset += 1 + $compressionMethodsLength;
        if ($offset + 2 > \strlen($data)) {
            return null;
        }

        $extensionsLength = $this->readUint16($data, $offset);
        $offset += 2;
        $extensionsEnd = \min(\strlen($data), $offset + $extensionsLength);

        while ($offset + 4 <= $extensionsEnd) {
            $extensionType = $this->readUint16($data, $offset);
            $extensionLength = $this->readUint16($data, $offset + 2);
            $offset += 4;
            if ($offset + $extensionLength > $extensionsEnd) {
                return null;
            }

            if ($extensionType === 0x0000) {
                return $this->extractServerNameFromExtension(\substr($data, $offset, $extensionLength));
            }

            $offset += $extensionLength;
        }

        return null;
    }

    /**
     * 双向数据转发
     */
    private function extractServerNameFromExtension(string $extension): ?string
    {
        if (\strlen($extension) < 5) {
            return null;
        }

        $listLength = $this->readUint16($extension, 0);
        $offset = 2;
        $end = \min(\strlen($extension), $offset + $listLength);
        while ($offset + 3 <= $end) {
            $nameType = \ord($extension[$offset]);
            $nameLength = $this->readUint16($extension, $offset + 1);
            $offset += 3;
            if ($offset + $nameLength > $end) {
                return null;
            }

            if ($nameType === 0) {
                $name = \strtolower(\trim(\substr($extension, $offset, $nameLength)));
                return $name !== '' ? $name : null;
            }

            $offset += $nameLength;
        }

        return null;
    }

    private function readUint16(string $data, int $offset): int
    {
        if ($offset < 0 || $offset + 2 > \strlen($data)) {
            return 0;
        }

        $value = \unpack('n', \substr($data, $offset, 2));
        return (int)($value[1] ?? 0);
    }

    private function relay($client, $backend): void
    {
        stream_set_blocking($client, false);
        stream_set_blocking($backend, false);

        $timeout = 300; // 5分钟超时
        $start = time();

        while (true) {
            if (time() - $start > $timeout || feof($client) || feof($backend)) {
                break;
            }

            $read = [$client, $backend];
            $write = null;
            $except = null;
            $ready = @\stream_select($read, $write, $except, 1, 0);
            if ($ready === false) {
                break;
            }
            if ($ready === 0) {
                continue;
            }

            foreach ($read as $socket) {
                $data = fread($socket, 8192);
                if ($data === false || $data === '') {
                    continue;
                }

                if ($socket === $client) {
                    fwrite($backend, $data);
                } else {
                    fwrite($client, $data);
                }
                $start = time();
            }
        }

        fclose($client);
        fclose($backend);
    }
}
