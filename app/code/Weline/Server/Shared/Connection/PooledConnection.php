<?php

declare(strict_types=1);

namespace Weline\Server\Shared\Connection;

use Weline\Server\Log\WlsLogger;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Shared\Contract\PooledConnectionInterface;

class PooledConnection implements PooledConnectionInterface
{
    private mixed $socket = null;
    private string $buffer = '';
    private bool $authenticated = false;
    private ?string $authToken = null;

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
        $socket = @\stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->connectTimeout
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
                \usleep(1000);
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
        if (!$this->send(SessionProtocol::buildAuth($token))) {
            return false;
        }
        $response = $this->read();
        if (!\is_array($response) || !SessionProtocol::isSuccess($response)) {
            return false;
        }
        $this->authenticated = true;
        return true;
    }

    private function loadToken(): ?string
    {
        if ($this->authToken !== null) {
            return $this->authToken;
        }
        if ($this->tokenFilePath === '' || !\is_file($this->tokenFilePath)) {
            return null;
        }
        $token = @\file_get_contents($this->tokenFilePath);
        if ($token === false || $token === '') {
            return null;
        }
        $this->authToken = \trim($token);
        return $this->authToken;
    }

    private function log(string $message): void
    {
        WlsLogger::info_('[PooledConnection] ' . $message);
    }
}
