<?php
declare(strict_types=1);

namespace Weline\Server\IPC;

use Weline\Server\Log\WlsLogger;

/**
 * 使用 PHP socket 扩展的 Master IPC 控制服务器（Windows 兼容性版本）
 *
 * 替代 stream_socket_server，解决某些 Windows 环境下 stream_socket_server 无法正常 accept 连接的问题
 *
 * @author Aiweline
 */
class MasterControlServerSocket
{
    private $serverSocket = null;
    private int $port = 0;
    private string $host = '127.0.0.1';
    private array $clients = [];

    /**
     * 启动 IPC 服务器（使用原生 socket）
     */
    public function start(string $host, int $port): bool
    {
        $this->host = $host;
        $this->port = $port;

        // 创建 socket
        $this->serverSocket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->serverSocket === false) {
            $errorCode = \socket_last_error();
            WlsLogger::error_("[IPC-Master] socket_create failed: ({$errorCode}) " . \socket_strerror($errorCode));
            return false;
        }

        // 设置 SO_REUSEADDR
        if (!@\socket_set_option($this->serverSocket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            $errorCode = \socket_last_error($this->serverSocket);
            WlsLogger::error_("[IPC-Master] socket_set_option SO_REUSEADDR failed: ({$errorCode}) " . \socket_strerror($errorCode));
            @\socket_close($this->serverSocket);
            return false;
        }

        // 绑定
        if (!@\socket_bind($this->serverSocket, $host, $port)) {
            $errorCode = \socket_last_error($this->serverSocket);
            WlsLogger::error_("[IPC-Master] socket_bind failed on {$host}:{$port}: ({$errorCode}) " . \socket_strerror($errorCode));
            @\socket_close($this->serverSocket);
            return false;
        }

        // 监听
        if (!@\socket_listen($this->serverSocket, 1024)) {
            $errorCode = \socket_last_error($this->serverSocket);
            WlsLogger::error_("[IPC-Master] socket_listen failed: ({$errorCode}) " . \socket_strerror($errorCode));
            @\socket_close($this->serverSocket);
            return false;
        }

        // 设置非阻塞
        if (!@\socket_set_nonblock($this->serverSocket)) {
            $errorCode = \socket_last_error($this->serverSocket);
            WlsLogger::warning_("[IPC-Master] socket_set_nonblock failed (non-critical): ({$errorCode}) " . \socket_strerror($errorCode));
            // 不返回 false，因为这个可以继续工作
        }

        WlsLogger::info_("[IPC-Master-Socket] 服务器已启动在 {$host}:{$port} (使用原生 socket API)");
        return true;
    }

    /**
     * 接受新连接
     */
    public function acceptClient(): ?array
    {
        if ($this->serverSocket === null) {
            return null;
        }

        $conn = @\socket_accept($this->serverSocket);
        if ($conn === false) {
            // 这是正常的（非阻塞模式下没有连接）
            return null;
        }

        \socket_set_nonblock($conn);

        $peerAddr = '';
        $peerPort = 0;
        @\socket_getpeername($conn, $peerAddr, $peerPort);

        WlsLogger::info_("[IPC-Master-Socket] 新客户端连接: {$peerAddr}:{$peerPort}");

        return [
            'socket' => $conn,
            'peer_addr' => $peerAddr,
            'peer_port' => $peerPort,
        ];
    }

    /**
     * 获取服务器 socket（用于 socket_select）
     */
    public function getServerSocket()
    {
        return $this->serverSocket;
    }

    /**
     * 关闭所有连接
     */
    public function close(): void
    {
        if ($this->serverSocket !== null) {
            @\socket_close($this->serverSocket);
            $this->serverSocket = null;
        }
    }

    public function getPort(): int
    {
        return $this->port;
    }
}
