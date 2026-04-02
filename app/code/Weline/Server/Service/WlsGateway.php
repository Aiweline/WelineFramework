<?php
declare(strict_types=1);

namespace Weline\Server\Service;

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

    /**
     * IPC 控制端口
     */
    private int $controlPort = 0;

    /**
     * Master PID
     */
    private int $masterPid = 0;

    /**
     * 是否启用动态路由
     */
    private bool $dynamicRoutingEnabled = false;

    /**
     * IPC 连接资源
     */
    private $ipcSocket = null;

    /**
     * IPC 读取缓冲区
     */
    private string $ipcBuffer = '';

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

    /**
     * 设置监听地址
     */
    public function setListenAddress(string $host, int $port): void
    {
        $this->listenHost = $host;
        $this->listenPort = $port;
    }

    /**
     * 启用动态路由
     */
    public function enableDynamicRouting(): void
    {
        if ($this->controlPort <= 0 || $this->masterPid <= 0) {
            throw new \RuntimeException('IPC 配置未设置，无法启用动态路由');
        }

        // 连接 Master IPC 控制端口
        $this->ipcSocket = @stream_socket_client(
            "tcp://127.0.0.1:{$this->controlPort}",
            $errno,
            $errstr,
            5
        );

        if (!$this->ipcSocket) {
            throw new \RuntimeException("无法连接 Master IPC: {$errstr} ({$errno})");
        }

        stream_set_blocking($this->ipcSocket, false);

        // 发送 register 消息
        $registerMsg = \Weline\Server\IPC\ControlMessage::register(
            \Weline\Server\IPC\ControlMessage::ROLE_GATEWAY,
            getmypid(),
            $this->listenPort
        );
        fwrite($this->ipcSocket, $registerMsg);

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
            case \Weline\Server\IPC\ControlMessage::TYPE_PROXY_ADD_ROUTE:
                $this->addRoute(
                    $message['domain'] ?? '',
                    $message['backend_host'] ?? '',
                    (int) ($message['backend_port'] ?? 0),
                    (bool) ($message['backend_ssl'] ?? true),
                    (int) ($message['priority'] ?? 0)
                );
                echo "IPC: 添加路由 {$message['domain']}\n";
                break;

            case \Weline\Server\IPC\ControlMessage::TYPE_PROXY_REMOVE_ROUTE:
                $this->removeRoute($message['domain'] ?? '');
                echo "IPC: 移除路由 {$message['domain']}\n";
                break;

            case \Weline\Server\IPC\ControlMessage::TYPE_PROXY_RELOAD:
                $this->reloadRoutes($message['routes'] ?? []);
                echo "IPC: 重载路由表\n";
                break;

            case \Weline\Server\IPC\ControlMessage::TYPE_SHUTDOWN:
                echo "IPC: 收到停止信号\n";
                exit(0);
                break;
        }
    }

    /**
     * 检查并处理 IPC 消息
     */
    private function checkIpcMessages(): void
    {
        if (!$this->dynamicRoutingEnabled || !$this->ipcSocket) {
            return;
        }

        // 读取 IPC 数据
        $data = @fread($this->ipcSocket, 8192);
        if ($data !== false && $data !== '') {
            $this->ipcBuffer .= $data;

            // 提取完整消息
            $messages = \Weline\Server\IPC\ControlMessage::extractMessages($this->ipcBuffer);
            foreach ($messages as $message) {
                $this->handleIpcMessage($message);
            }
        }

        // 检查连接是否关闭
        if (feof($this->ipcSocket)) {
            echo "IPC 连接已关闭\n";
            fclose($this->ipcSocket);
            $this->ipcSocket = null;
            $this->dynamicRoutingEnabled = false;
        }
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
        if ($this->dynamicRoutingEnabled && $this->ipcSocket) {
            echo "动态路由已启用\n";
        }

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

        echo "Gateway 已启动，等待连接...\n";

        // 事件循环
        while (true) {
            // 检查 IPC 消息
            $this->checkIpcMessages();

            // 接受客户端连接
            $client = @stream_socket_accept($socket, 0);
            if ($client) {
                $this->handleClient($client);
            }

            usleep(1000); // 1ms
        }
    }

    /**
     * 处理客户端连接
     */
    private function handleClient($client): void
    {
        // 读取 ClientHello 获取 SNI
        $domain = $this->extractSNI($client);
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
        $this->relay($client, $backendConn);
    }

    /**
     * 从 TLS ClientHello 中提取 SNI
     */
    private function extractSNI($socket): ?string
    {
        // 读取 TLS 握手数据
        $data = fread($socket, 4096);
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

        if (ord($data[0]) !== 0x16) {
            return null; // 不是 TLS 握手
        }

        // 查找 SNI 扩展（简化版）
        if (preg_match('/\x00\x00.{2}(.{2})(.+?)\x00/s', $data, $matches)) {
            $sniLength = unpack('n', $matches[1])[1];
            $sni = substr($matches[2], 0, $sniLength);
            return $sni;
        }

        return null;
    }

    /**
     * 双向数据转发
     */
    private function relay($client, $backend): void
    {
        stream_set_blocking($client, false);
        stream_set_blocking($backend, false);

        $timeout = 300; // 5分钟超时
        $start = time();

        while (true) {
            // 超时检查
            if (time() - $start > $timeout) {
                break;
            }

            // 客户端 → 后端
            $data = fread($client, 8192);
            if ($data !== false && $data !== '') {
                fwrite($backend, $data);
                $start = time(); // 重置超时
            }

            // 后端 → 客户端
            $data = fread($backend, 8192);
            if ($data !== false && $data !== '') {
                fwrite($client, $data);
                $start = time(); // 重置超时
            }

            // 连接关闭检查
            if (feof($client) || feof($backend)) {
                break;
            }

            usleep(1000); // 1ms
        }

        fclose($client);
        fclose($backend);
    }
}
