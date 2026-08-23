<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

use Weline\Storage\Api\Runtime\StorageClientLeaseInterface;
use Weline\Storage\Api\Runtime\StorageRequestResourceRegistryInterface;

final class StorageClientLease implements StorageClientLeaseInterface
{
    private ?object $client;
    private bool $closed = false;
    private readonly ?\Closure $closeCallback;

    public function __construct(
        object $client,
        private readonly StorageRequestResourceRegistryInterface $registry,
        ?callable $closeCallback = null,
    ) {
        $this->client = $client;
        $this->closeCallback = $closeCallback === null ? null : \Closure::fromCallable($closeCallback);
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

    public function client(): object
    {
        if ($this->closed || $this->client === null) {
            throw new \RuntimeException((string)__('存储 Client lease 已关闭。'));
        }
        return $this->client;
    }

    public function resourceKind(): string { return 'storage.sdk_client'; }

    public function close(): void
    {
        if ($this->closed) { return; }
        $this->closed = true;
        $client = $this->client;
        $this->client = null;
        try {
            if ($client !== null && $this->closeCallback !== null) { ($this->closeCallback)($client); }
        } catch (\Throwable $throwable) {
            $this->registry->deferCleanupFailure($throwable);
            throw $throwable;
        } finally {
            $this->registry->release($this);
        }
    }

    public function isClosed(): bool { return $this->closed; }

    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
        }
    }
}
