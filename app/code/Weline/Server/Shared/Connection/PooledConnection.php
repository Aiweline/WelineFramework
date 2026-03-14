<?php

declare(strict_types=1);

namespace Weline\Server\Shared\Connection;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Shared\Contract\PooledConnectionInterface;

class PooledConnection implements PooledConnectionInterface
{
    private mixed $socket = null;
    private string $buffer = '';
    private bool $authenticated = false;
    private ?string $authToken = null;
    private int $authTokenMtime = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $connectTimeout = 1.0,
        private readonly float $timeout = 2.0,
        private readonly string $tokenFilePath = ''
    ) {
    }

    public function connect(): bool
    {
        if ($this->isConnected() && $this->authenticated) {
            return true;
        }

        $errno = 0;
        $errstr = '';
        // Linux: 使用 context 明确超时，避免 default_socket_timeout 或系统行为导致长时间阻塞
        $timeoutSec = (float) $this->connectTimeout;
        if (\defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Linux' && $timeoutSec > 2.0) {
            $timeoutSec = 2.0;
        }
        $ctx = @\stream_context_create([
            'socket' => [
                'tcp_nodelay' => true,
            ],
        ]);
        $socket = @\stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $timeoutSec,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if (!$socket) {
            $this->log("Connect failed: {$errstr} ({$errno})");
            return false;
        }

        \stream_set_timeout(
            $socket,
            (int)$this->timeout,
            (int)(($this->timeout - (int)$this->timeout) * 1000000)
        );
        \stream_set_blocking($socket, true);

        $this->socket = $socket;
        $this->buffer = '';
        $this->authenticated = false;

        if (!$this->authenticate()) {
            $this->close();
            return false;
        }
        return true;
    }

    public function isConnected(): bool
    {
        return $this->socket !== null && \is_resource($this->socket) && !\feof($this->socket);
    }

    public function send(string $payload): bool
    {
        if (!$this->isConnected() && !$this->connect()) {
            return false;
        }
        $total = \strlen($payload);
        $offset = 0;
        while ($offset < $total) {
            $written = @\fwrite($this->socket, \substr($payload, $offset));
            if ($written === false || $written === 0) {
                $this->close();
                return false;
            }
            $offset += $written;
        }
        return true;
    }

    public function read(): ?array
    {
        if (!$this->isConnected()) {
            return null;
        }
        $start = \microtime(true);
        while (true) {
            if (\microtime(true) - $start > $this->timeout) {
                return null;
            }
            $chunk = @\fread($this->socket, 65536);
            if ($chunk === false) {
                $this->close();
                return null;
            }
            if ($chunk === '') {
                if (\feof($this->socket)) {
                    $this->close();
                    return null;
                }
                SchedulerSystem::usleep(1000);
                continue;
            }
            $this->buffer .= $chunk;
            $messages = SessionProtocol::extractMessages($this->buffer);
            if (!empty($messages)) {
                return $messages[0];
            }
        }
    }

    public function ping(): bool
    {
        if (!$this->send(SessionProtocol::buildPing())) {
            return false;
        }
        $response = $this->read();
        return \is_array($response)
            && SessionProtocol::isSuccess($response)
            && SessionProtocol::getData($response) === 'pong';
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            @\fclose($this->socket);
            $this->socket = null;
        }
        $this->buffer = '';
        $this->authenticated = false;
    }

    private function authenticate(): bool
    {
        $token = $this->loadToken();
        if ($token === null) {
            $this->authenticated = true;
            return true;
        }
        if ($this->tryAuthenticateWithToken($token)) {
            $this->authenticated = true;
            return true;
        }

        // WLS 常驻进程下，服务重启会轮换 token；认证失败时强制刷新 token 后重试一次。
        $freshToken = $this->loadToken(true);
        if ($freshToken !== null && $freshToken !== $token && $this->tryAuthenticateWithToken($freshToken)) {
            $this->authenticated = true;
            return true;
        }

        $this->authenticated = false;
        return false;
    }

    private function tryAuthenticateWithToken(string $token): bool
    {
        if (!$this->send(SessionProtocol::buildAuth($token))) {
            return false;
        }
        $response = $this->read();
        return \is_array($response) && SessionProtocol::isSuccess($response);
    }

    private function loadToken(bool $forceReload = false): ?string
    {
        if (!$forceReload && $this->authToken !== null && !$this->isTokenFileChanged()) {
            return $this->authToken;
        }
        if ($this->tokenFilePath === '' || !\is_file($this->tokenFilePath)) {
            $this->authToken = null;
            $this->authTokenMtime = 0;
            return null;
        }
        $mtime = (int)(@\filemtime($this->tokenFilePath) ?: 0);
        $token = @\file_get_contents($this->tokenFilePath);
        if ($token === false || $token === '') {
            $this->authToken = null;
            $this->authTokenMtime = $mtime;
            return null;
        }
        $this->authToken = \trim($token);
        $this->authTokenMtime = $mtime;
        return $this->authToken;
    }

    private function isTokenFileChanged(): bool
    {
        if ($this->tokenFilePath === '' || !\is_file($this->tokenFilePath)) {
            return false;
        }
        $mtime = (int)(@\filemtime($this->tokenFilePath) ?: 0);
        return $mtime !== $this->authTokenMtime;
    }

    private function log(string $message): void
    {
        WlsLogger::info_('[PooledConnection] ' . $message);
    }
}
