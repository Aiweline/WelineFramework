<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Session\Server\SessionProtocol;

/**
 * 不依赖「进程可识别为 Weline」与 token 文件名猜测：直连 Session/Memory 协议探测是否可复用。
 */
final class SharedStateProtocolProbe
{
    private const CONNECT_TIMEOUT_SEC = 0.15;
    private const READ_TIMEOUT_SEC = 0.25;
    private const SHUTDOWN_READ_TIMEOUT_SEC = 0.5;

    /**
     * 单次 TCP 直连 + 读 token + 鉴权后 PING（不经 ConnectionPool）。
     * 用于共享侧车就绪轮询，避免连接池失败退避带来的探测抖动与额外等待。
     */
    public static function pingWithTokenBasename(string $host, int $port, string $tokenBasename): bool
    {
        $host = \trim($host);
        $tokenBasename = \trim($tokenBasename);
        if ($host === '' || $port <= 0 || $tokenBasename === '') {
            return false;
        }
        $tokenBasename = \basename($tokenBasename);
        if ($tokenBasename === '' || $tokenBasename === '.' || $tokenBasename === '..') {
            return false;
        }
        if (!\defined('BP')) {
            return false;
        }
        $path = BP . 'var' . \DIRECTORY_SEPARATOR . 'session' . \DIRECTORY_SEPARATOR . $tokenBasename;
        if (!\is_file($path) || !\is_readable($path)) {
            return false;
        }
        $secret = self::readSecretFromTokenFile($path);
        if ($secret === '' || \strlen($secret) > 8192) {
            return false;
        }

        return self::rawAuthThenPing($host, $port, $secret);
    }

    public static function shutdownWithTokenBasename(
        string $host,
        int $port,
        string $tokenBasename,
        ?string $consumerCode = null,
        array $params = []
    ): bool {
        $host = \trim($host);
        $tokenBasename = \trim($tokenBasename);
        if ($host === '' || $port <= 0 || $tokenBasename === '') {
            return false;
        }
        $tokenBasename = \basename($tokenBasename);
        if ($tokenBasename === '' || $tokenBasename === '.' || $tokenBasename === '..') {
            return false;
        }
        if (!\defined('BP')) {
            return false;
        }
        $path = BP . 'var' . \DIRECTORY_SEPARATOR . 'session' . \DIRECTORY_SEPARATOR . $tokenBasename;
        if (!\is_file($path) || !\is_readable($path)) {
            return false;
        }
        $secret = self::readSecretFromTokenFile($path);
        if ($secret === '' || \strlen($secret) > 8192) {
            return false;
        }

        return self::rawAuthThenShutdown($host, $port, $secret, $consumerCode, $params);
    }

    public static function findWorkingTokenBasename(string $host, int $port, string $defaultBasename): ?string
    {
        $host = \trim($host);
        if ($host === '' || $port <= 0) {
            return null;
        }

        $defaultBasename = \trim($defaultBasename);
        if ($defaultBasename === '') {
            $defaultBasename = $port === 19971 ? 'memory_server.token' : 'session_server.token';
        }

        if (self::rawPingOnly($host, $port)) {
            return $defaultBasename;
        }

        if (!\defined('BP')) {
            return null;
        }

        $dir = BP . 'var' . \DIRECTORY_SEPARATOR . 'session' . \DIRECTORY_SEPARATOR;
        if (!\is_dir($dir)) {
            return null;
        }

        $paths = \glob($dir . '*.token') ?: [];
        \sort($paths, \SORT_STRING);
        $n = 0;
        foreach ($paths as $path) {
            if (++$n > 48) {
                break;
            }
            $secret = self::readSecretFromTokenFile($path);
            if ($secret === '' || \strlen($secret) > 8192) {
                continue;
            }
            if (self::rawAuthThenPing($host, $port, $secret)) {
                return \basename($path);
            }
        }

        return null;
    }

    /**
     * @return resource|null
     */
    private static function openTcp(string $host, int $port)
    {
        $errno = 0;
        $errstr = '';
        $inSchedulerFiber = SchedulerSystem::isSchedulerActive() && \Fiber::getCurrent() !== null;
        $socket = @\stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, self::CONNECT_TIMEOUT_SEC);
        if ($socket === false) {
            return null;
        }
        if ($inSchedulerFiber) {
            // 在 Master/Worker Fiber 场景避免阻塞式 fread 长时间卡住主循环。
            \stream_set_blocking($socket, false);
            \stream_set_timeout($socket, 0, (int) (self::READ_TIMEOUT_SEC * 1_000_000));
        } else {
            \stream_set_blocking($socket, true);
            \stream_set_timeout($socket, 0, (int) (self::READ_TIMEOUT_SEC * 1_000_000));
        }

        return $socket;
    }

    private static function rawPingOnly(string $host, int $port): bool
    {
        $socket = self::openTcp($host, $port);
        if ($socket === null) {
            return false;
        }
        $buffer = '';
        try {
            if (@\fwrite($socket, SessionProtocol::buildPing()) === false) {
                return false;
            }
            $msg = self::readNextMessage($socket, $buffer, self::READ_TIMEOUT_SEC);

            return $msg !== null
                && SessionProtocol::isSuccess($msg)
                && SessionProtocol::getData($msg) === 'pong';
        } finally {
            @\fclose($socket);
        }
    }

    private static function rawAuthThenPing(string $host, int $port, string $secret): bool
    {
        $socket = self::openTcp($host, $port);
        if ($socket === null) {
            return false;
        }
        $buffer = '';
        try {
            if (@\fwrite($socket, SessionProtocol::buildAuth($secret)) === false) {
                return false;
            }
            $msg = self::readNextMessage($socket, $buffer, self::READ_TIMEOUT_SEC);
            if ($msg === null || !SessionProtocol::isSuccess($msg)) {
                return false;
            }
            $buffer = '';
            if (@\fwrite($socket, SessionProtocol::buildPing()) === false) {
                return false;
            }
            $msg = self::readNextMessage($socket, $buffer, self::READ_TIMEOUT_SEC);

            return $msg !== null
                && SessionProtocol::isSuccess($msg)
                && SessionProtocol::getData($msg) === 'pong';
        } finally {
            @\fclose($socket);
        }
    }

    private static function rawAuthThenShutdown(
        string $host,
        int $port,
        string $secret,
        ?string $consumerCode,
        array $params
    ): bool {
        $socket = self::openTcp($host, $port);
        if ($socket === null) {
            return false;
        }
        $buffer = '';
        try {
            if (@\fwrite($socket, SessionProtocol::buildAuth($secret)) === false) {
                return false;
            }
            $msg = self::readNextMessage($socket, $buffer, self::SHUTDOWN_READ_TIMEOUT_SEC);
            if ($msg === null || !SessionProtocol::isSuccess($msg)) {
                return false;
            }
            $buffer = '';
            if (@\fwrite($socket, SessionProtocol::buildShutdown($consumerCode, $params)) === false) {
                return false;
            }
            $msg = self::readNextMessage($socket, $buffer, self::SHUTDOWN_READ_TIMEOUT_SEC);

            return $msg !== null && SessionProtocol::isSuccess($msg);
        } finally {
            @\fclose($socket);
        }
    }

    /**
     * @param resource $socket
     * @return array<string, mixed>|null
     */
    private static function readNextMessage($socket, string &$buffer, float $timeoutSec): ?array
    {
        $deadline = \microtime(true) + $timeoutSec;
        $inSchedulerFiber = SchedulerSystem::isSchedulerActive() && \Fiber::getCurrent() !== null;
        while (\microtime(true) < $deadline) {
            $messages = SessionProtocol::extractMessages($buffer);
            if ($messages !== []) {
                return $messages[0];
            }
            $chunk = @\fread($socket, 65536);
            if ($chunk === false) {
                if ($inSchedulerFiber) {
                    SchedulerSystem::usleep(2000);
                    continue;
                }
                $messages = SessionProtocol::extractMessages($buffer);

                return $messages[0] ?? null;
            }
            if ($chunk === '') {
                if (@\feof($socket)) {
                    $messages = SessionProtocol::extractMessages($buffer);

                    return $messages[0] ?? null;
                }
                SchedulerSystem::usleep(2000);

                continue;
            }
            $buffer .= $chunk;
        }
        $messages = SessionProtocol::extractMessages($buffer);

        return $messages[0] ?? null;
    }

    private static function readSecretFromTokenFile(string $path): string
    {
        $raw = @\file_get_contents($path);
        if ($raw === false) {
            return '';
        }

        $raw = \trim($raw);
        if ($raw === '') {
            return '';
        }

        $parts = \explode(':', $raw, 2);
        return \trim((string)($parts[0] ?? ''));
    }
}
