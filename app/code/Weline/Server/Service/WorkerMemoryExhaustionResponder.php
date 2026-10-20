<?php
declare(strict_types=1);

namespace Weline\Server\Service;

/**
 * 进程因 Allowed memory size 退出前，把与准入门相同的 503 写到当前 HTTP/1 连接。
 *
 * PHP 的内存致命错误不能被 try/catch。启动时占住一块备用内存并最先注册 shutdown；
 * 致命时先放开备用内存，仅当该连接还没写出响应字节时写入 503。
 */
final class WorkerMemoryExhaustionResponder
{
    private const RESERVE_BYTES = 1048576;

    private static string $reserve = '';

    private static string $payload = '';

    private static bool $armed = false;

    private static int $activeFiberId = 0;

    /** @var array<int, array{stream: resource|null, sent: bool}> */
    private static array $byFiber = [];

    public static function arm(): void
    {
        if (self::$armed) {
            return;
        }
        self::$payload = self::payload();
        self::$reserve = \str_repeat('R', self::RESERVE_BYTES);
        \register_shutdown_function([self::class, 'onShutdown']);
        self::$armed = true;
    }

    public static function attach(mixed $stream): void
    {
        $fiber = \Fiber::getCurrent();
        $id = $fiber instanceof \Fiber ? \spl_object_id($fiber) : 0;
        self::$activeFiberId = $id;
        self::$byFiber[$id] = [
            'stream' => \is_resource($stream) ? $stream : null,
            'sent' => false,
        ];
    }

    public static function activateFiber(\Fiber $fiber): void
    {
        self::$activeFiberId = \spl_object_id($fiber);
    }

    public static function markBytesSent(mixed $stream): void
    {
        if (!\is_resource($stream)) {
            return;
        }
        foreach (self::$byFiber as $id => $row) {
            if (($row['stream'] ?? null) === $stream) {
                unset(self::$byFiber[$id]);
                if (self::$activeFiberId === $id) {
                    self::$activeFiberId = 0;
                }
            }
        }
    }

    public static function detach(mixed $stream): void
    {
        if (!\is_resource($stream)) {
            return;
        }
        foreach (self::$byFiber as $id => $row) {
            if (($row['stream'] ?? null) === $stream) {
                unset(self::$byFiber[$id]);
                if (self::$activeFiberId === $id) {
                    self::$activeFiberId = 0;
                }
            }
        }
    }

    public static function payload(): string
    {
        if (self::$payload !== '') {
            return self::$payload;
        }
        if (\function_exists('wlsFiberAdmissionUnavailableResponse')) {
            return wlsFiberAdmissionUnavailableResponse('memory_pressure');
        }
        $body = 'Service Unavailable';

        return 'HTTP/1.1 503 Service Unavailable' . "\r\n"
            . 'Content-Type: text/plain; charset=utf-8' . "\r\n"
            . 'Retry-After: 2' . "\r\n"
            . 'X-WLS-Admission: memory_pressure' . "\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n"
            . 'Connection: close' . "\r\n\r\n"
            . $body;
    }

    public static function onShutdown(): void
    {
        $reserve = self::$reserve;
        self::$reserve = '';
        unset($reserve);

        $error = \error_get_last();
        if (!\is_array($error) || !\str_contains((string)($error['message'] ?? ''), 'Allowed memory size')) {
            return;
        }
        $row = self::$byFiber[self::$activeFiberId] ?? null;
        if (!\is_array($row) || ($row['sent'] ?? false) === true) {
            return;
        }
        $stream = $row['stream'] ?? null;
        if (!\is_resource($stream)) {
            return;
        }
        $payload = self::$payload !== '' ? self::$payload : self::payload();
        @\stream_set_blocking($stream, true);
        @\stream_set_timeout($stream, 1);
        @\fwrite($stream, $payload);
        @\fflush($stream);
    }
}
