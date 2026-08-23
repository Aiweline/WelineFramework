<?php

declare(strict_types=1);

namespace Weline\Storage\Api;

use Weline\Framework\Http\Sse\SseContext;
use Weline\Framework\Runtime\RequestResourceInterface;
use Weline\Storage\Api\Runtime\StorageRequestResourceRegistryInterface;

final class StorageReadHandle implements RequestResourceInterface
{
    /** @var resource|null */
    private mixed $stream;
    private bool $closed = false;
    private readonly ?\Closure $afterClose;

    /** @param resource $stream */
    public function __construct(
        mixed $stream,
        private readonly StorageRequestResourceRegistryInterface $registry,
        ?callable $afterClose = null,
    )
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException((string)__('读取句柄必须包装有效流。'));
        }
        $this->stream = $stream;
        $this->afterClose = $afterClose === null ? null : \Closure::fromCallable($afterClose);
        try {
            $this->registry->register($this);
        } catch (\Throwable $registrationFailure) {
            if (!$this->closed) {
                try {
                    $this->close();
                } catch (\Throwable) {
                    // close() defers cleanup debt to the request registry.
                }
            }
            throw $registrationFailure;
        }
    }

    public function resourceKind(): string
    {
        return 'storage.read_stream';
    }

    /** @return resource */
    public function resource(): mixed
    {
        if ($this->closed || !is_resource($this->stream)) {
            throw new \RuntimeException((string)__('读取句柄已经关闭。'));
        }
        return $this->stream;
    }

    public function read(int $length = 65536): string
    {
        if ($length < 1 || $length > 8 * 1024 * 1024) {
            throw new \InvalidArgumentException((string)__('读取分块大小无效。'));
        }
        if (self::clientDisconnected()) {
            throw new \RuntimeException((string)__('客户端已断开，存储读取已取消。'));
        }
        $chunk = fread($this->resource(), $length);
        if ($chunk === false) {
            throw new \RuntimeException((string)__('读取存储流失败。'));
        }
        return $chunk;
    }

    public function eof(): bool
    {
        return feof($this->resource());
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $stream = $this->stream;
        $this->stream = null;
        $failure = null;
        try {
            if (is_resource($stream) && !@fclose($stream)) {
                $failure = new \RuntimeException((string)__('关闭存储读取流失败。'));
            }
            try {
                if ($this->afterClose !== null) {
                    ($this->afterClose)();
                }
            } catch (\Throwable $throwable) {
                $failure ??= $throwable;
            }
        } finally {
            $this->registry->release($this);
        }
        if ($failure !== null) {
            $this->registry->deferCleanupFailure($failure);
            throw $failure;
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
        }
    }

    private static function clientDisconnected(): bool
    {
        if (function_exists('connection_aborted') && connection_aborted()) {
            return true;
        }
        // Queue/CLI/background Fibers may run inside a WLS process without an
        // HTTP transport callback. Absence of that callback means there is no
        // client connection to cancel against; it must not be interpreted as
        // a disconnected browser. HTTP request Fibers install an alive
        // callback at entry, so their cancellation remains immediate.
        return defined('WLS_MODE')
            && WLS_MODE
            && is_callable(SseContext::getAliveCallback())
            && !SseContext::isConnectionAlive();
    }
}
