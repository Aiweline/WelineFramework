<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

use Weline\Storage\Api\Runtime\StorageRequestResourceRegistryInterface;
use Weline\Storage\Api\Runtime\StorageRequestStreamInterface;

/**
 * Request-owned PHP stream used at framework/module boundaries.
 *
 * Drivers expose StorageReadHandle/StorageWriteHandle. This smaller wrapper is
 * for upload-source files and bounded proxy temporary streams that must also be
 * visible to the WLS request cleanup registry.
 */
final class StorageRequestStream implements StorageRequestStreamInterface
{
    public const KIND_LOCAL_FILE = 'storage.local_file';
    public const KIND_PROXY_FILE = 'storage.proxy_file';

    private bool $closed = false;

    /** @param resource $stream */
    public function __construct(
        private mixed $stream,
        private readonly StorageRequestResourceRegistryInterface $registry,
        private readonly string $kind = self::KIND_LOCAL_FILE,
        private readonly ?\Closure $closer = null,
    ) {
        if (!is_resource($this->stream)) {
            throw new \InvalidArgumentException((string)__('请求流无效。'));
        }
        if (!in_array($this->kind, [self::KIND_LOCAL_FILE, self::KIND_PROXY_FILE], true)) {
            // Ownership transfers at this boundary. Even invalid internal
            // metadata must not leave the already-open source stream behind
            // in a persistent WLS worker.
            try {
                $this->close();
            } catch (\Throwable) {
                // close() records the failure in the request registry so the
                // resetter can drain the worker; keep the validation error as
                // the immediate caller-facing failure.
            }
            throw new \InvalidArgumentException((string)__('请求流类型无效。'));
        }
        try {
            $this->registry->register($this);
        } catch (\Throwable $registrationFailure) {
            if (!$this->closed) {
                try {
                    $this->close();
                } catch (\Throwable) {
                    // The cleanup failure is deferred by close(); the
                    // registration failure remains the primary exception.
                }
            }
            throw $registrationFailure;
        }
    }

    /** @return resource */
    public function stream(): mixed
    {
        if ($this->closed || !is_resource($this->stream)) {
            throw new \RuntimeException((string)__('请求流已关闭。'));
        }

        return $this->stream;
    }

    public function resourceKind(): string
    {
        return $this->kind;
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
            if (is_resource($stream)) {
                if ($this->closer !== null) {
                    try {
                        ($this->closer)($stream);
                    } catch (\Throwable $throwable) {
                        $failure = $throwable;
                    }
                }
                if (is_resource($stream) && !@fclose($stream)) {
                    $failure ??= new \RuntimeException((string)__('关闭请求流失败。'));
                }
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
}
