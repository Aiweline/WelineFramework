<?php

declare(strict_types=1);

namespace Weline\Storage\Api;

use Weline\Framework\Http\Sse\SseContext;
use Weline\Framework\Runtime\RequestResourceInterface;
use Weline\Storage\Api\Data\StorageObjectStat;
use Weline\Storage\Api\Runtime\StorageRequestResourceRegistryInterface;

final class StorageWriteHandle implements RequestResourceInterface
{
    private const MAX_WRITE_CHUNK_BYTES = 8 * 1024 * 1024;
    public const DEFAULT_MAX_TOTAL_BYTES = 5 * 1024 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 5 * 1024 * 1024 * 1024 * 1024;
    /** @var resource|null */
    private mixed $stream;
    private bool $closed = false;
    private bool $aborted = false;
    private int $bytesWritten = 0;
    private ?StorageObjectStat $result = null;
    private readonly \Closure $completeCallback;
    private readonly \Closure $abortCallback;

    /**
     * @param resource $stream
     * @param callable():StorageObjectStat $completeCallback
     * @param callable():void $abortCallback
     */
    public function __construct(
        mixed $stream,
        callable $completeCallback,
        callable $abortCallback,
        private readonly StorageRequestResourceRegistryInterface $registry,
        private readonly int $maxTotalBytes = self::DEFAULT_MAX_TOTAL_BYTES,
    ) {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException((string)__('写入句柄必须包装有效流。'));
        }
        if ($maxTotalBytes < 1 || $maxTotalBytes > self::MAX_TOTAL_BYTES) {
            throw new \InvalidArgumentException((string)__('存储写入总字节上限无效。'));
        }
        $this->stream = $stream;
        $this->completeCallback = \Closure::fromCallable($completeCallback);
        $this->abortCallback = \Closure::fromCallable($abortCallback);
        try {
            $this->registry->register($this);
        } catch (\Throwable $registrationFailure) {
            if (!$this->closed) {
                try {
                    $this->abort();
                } catch (\Throwable) {
                    // abort() defers cleanup debt to the request registry.
                }
            }
            throw $registrationFailure;
        }
    }

    public function resourceKind(): string
    {
        return 'storage.write_stream';
    }

    /** @return resource */
    public function resource(): mixed
    {
        if ($this->closed || !is_resource($this->stream)) {
            throw new \RuntimeException((string)__('写入句柄已经关闭。'));
        }
        return $this->stream;
    }

    public function write(string $chunk): int
    {
        if (self::clientDisconnected()) {
            throw new \RuntimeException((string)__('客户端已断开，存储写入已取消。'));
        }
        $length = strlen($chunk);
        if ($length > self::MAX_WRITE_CHUNK_BYTES) {
            throw new \InvalidArgumentException((string)__('单次存储写入块超过限制。'));
        }
        if ($length === 0) {
            return 0;
        }
        if ($length > $this->maxTotalBytes - $this->bytesWritten) {
            throw new \RuntimeException((string)__('存储对象超过允许的总字节上限。'));
        }
        $stream = $this->resource();
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($stream, $offset === 0 ? $chunk : substr($chunk, $offset));
            if ($written === false || $written < 1) {
                throw new \RuntimeException((string)__('写入存储流失败。'));
            }
            $offset += $written;
        }
        $this->bytesWritten += $offset;
        return $offset;
    }

    public function complete(): StorageObjectStat
    {
        $this->close();
        if (!$this->result instanceof StorageObjectStat) {
            throw new \RuntimeException((string)__('存储写入没有返回对象统计。'));
        }
        return $this->result;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $stream = $this->stream;
        $this->stream = null;
        try {
            if (self::clientDisconnected()) {
                throw new \RuntimeException((string)__('客户端已断开，存储写入已取消。'));
            }
            if (is_resource($stream)) {
                $flushed = fflush($stream);
                if (!$flushed) {
                    throw new \RuntimeException((string)__('刷新存储写入流失败。'));
                }
                $streamStat = fstat($stream);
                if (!is_array($streamStat) || !isset($streamStat['size'])) {
                    throw new \RuntimeException((string)__('无法验证存储写入流大小。'));
                }
                $actualBytes = max(0, (int)$streamStat['size']);
                if ($actualBytes > $this->maxTotalBytes) {
                    throw new \RuntimeException((string)__('存储对象超过允许的总字节上限。'));
                }
                if ($actualBytes !== $this->bytesWritten) {
                    throw new \RuntimeException((string)__('存储写入流字节数与句柄记录不一致。'));
                }
                if (!@fclose($stream)) {
                    throw new \RuntimeException((string)__('关闭存储写入流失败。'));
                }
            }
            $result = ($this->completeCallback)();
            if (!$result instanceof StorageObjectStat) {
                throw new \RuntimeException((string)__('存储写入完成回调返回值无效。'));
            }
            $this->result = $result;
        } catch (\Throwable $throwable) {
            $this->aborted = true;
            if (is_resource($stream)) {
                @fclose($stream);
            }
            try {
                ($this->abortCallback)();
            } catch (\Throwable $cleanupFailure) {
                $this->registry->deferCleanupFailure($cleanupFailure);
            }
            throw $throwable;
        } finally {
            $this->registry->release($this);
        }
    }

    public function abort(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->aborted = true;
        $stream = $this->stream;
        $this->stream = null;
        $failure = null;
        try {
            if (is_resource($stream) && !@fclose($stream)) {
                $failure = new \RuntimeException((string)__('关闭已取消的存储写入流失败。'));
            }
            try {
                ($this->abortCallback)();
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

    public function wasAborted(): bool
    {
        return $this->aborted;
    }

    public function __destruct()
    {
        try {
            $this->abort();
        } catch (\Throwable) {
        }
    }

    private static function clientDisconnected(): bool
    {
        if (function_exists('connection_aborted') && connection_aborted()) {
            return true;
        }
        // A persistent WLS process also executes queue/CLI/background Fibers.
        // Those Fibers intentionally have no HTTP alive callback and therefore
        // must not be treated as an aborted browser request.
        return defined('WLS_MODE')
            && WLS_MODE
            && is_callable(SseContext::getAliveCallback())
            && !SseContext::isConnectionAlive();
    }
}
