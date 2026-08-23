<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestResourceInterface;
use Weline\Storage\Api\Runtime\StorageRequestResourceRegistryInterface;
use Weline\Storage\Api\StorageWriteHandle;

final class StorageRequestResourceRegistry implements StorageRequestResourceRegistryInterface
{
    private const MAX_ACTIVE_RESOURCES = 2048;
    private const MAX_DEFERRED_FAILURES = 64;
    /** @var array<int,RequestResourceInterface> */
    private array $resources = [];
    /** @var list<\Throwable> */
    private array $deferredCleanupFailures = [];
    private bool $cleanupRegistered = false;
    private bool $closingAll = false;
    private ?\Throwable $requestCleanupFailure = null;
    private int $deferredFailureOverflow = 0;

    public function register(RequestResourceInterface $resource): void
    {
        $id = spl_object_id($resource);
        if (isset($this->resources[$id])) {
            return;
        }
        if (count($this->resources) >= self::MAX_ACTIVE_RESOURCES) {
            try {
                if ($resource instanceof StorageWriteHandle) {
                    $resource->abort();
                } else {
                    $resource->close();
                }
            } catch (\Throwable $cleanupFailure) {
                $this->deferCleanupFailure($cleanupFailure);
            }
            throw new \RuntimeException((string)__('单个请求的存储资源句柄数超过上限。'));
        }
        $this->resources[$id] = $resource;
        StorageRuntimeDiagnostics::resourceOpened($resource->resourceKind());

        if (!$this->cleanupRegistered && RequestContext::isInitialized()) {
            try {
                RequestContext::onCleanup(fn () => $this->closeAll(), 'Weline_Storage.resources');
                $this->cleanupRegistered = true;
            } catch (\Throwable $registrationFailure) {
                unset($this->resources[$id]);
                StorageRuntimeDiagnostics::resourceClosed($resource->resourceKind());
                try {
                    if ($resource instanceof StorageWriteHandle) {
                        $resource->abort();
                    } else {
                        $resource->close();
                    }
                } catch (\Throwable $cleanupFailure) {
                    $this->deferCleanupFailure($cleanupFailure);
                    throw new \RuntimeException(
                        (string)__('Storage 请求资源注册失败且资源无法关闭。'),
                        0,
                        $registrationFailure,
                    );
                }
                throw $registrationFailure;
            }
        }
    }

    public function release(RequestResourceInterface $resource): void
    {
        $id = spl_object_id($resource);
        if (!isset($this->resources[$id])) {
            return;
        }
        unset($this->resources[$id]);
        StorageRuntimeDiagnostics::resourceClosed($resource->resourceKind());
    }

    public function activeCount(): int
    {
        return count($this->resources);
    }

    public function deferCleanupFailure(\Throwable $throwable): void
    {
        if (!$this->closingAll) {
            if (count($this->deferredCleanupFailures) < self::MAX_DEFERRED_FAILURES) {
                $this->deferredCleanupFailures[] = $throwable;
            } else {
                ++$this->deferredFailureOverflow;
            }
        }
    }

    public function closeAll(): void
    {
        $failures = $this->deferredCleanupFailures;
        $failureCount = count($failures) + $this->deferredFailureOverflow;
        if ($this->requestCleanupFailure !== null) {
            $failures[] = $this->requestCleanupFailure;
            ++$failureCount;
        }
        $this->deferredCleanupFailures = [];
        $this->deferredFailureOverflow = 0;
        $this->closingAll = true;
        $resources = array_reverse($this->resources, true);
        foreach ($resources as $id => $resource) {
            try {
                if ($resource instanceof StorageWriteHandle) {
                    $resource->abort();
                } else {
                    $resource->close();
                }
            } catch (\Throwable $throwable) {
                $failures[] = $throwable;
                ++$failureCount;
            } finally {
                if (isset($this->resources[$id])) {
                    unset($this->resources[$id]);
                    StorageRuntimeDiagnostics::resourceClosed($resource->resourceKind());
                }
            }
        }
        $this->closingAll = false;
        $this->cleanupRegistered = false;

        if ($failures !== []) {
            $firstFailureInRequest = $this->requestCleanupFailure === null;
            $this->requestCleanupFailure ??= $failures[0];
            if ($firstFailureInRequest) {
                StorageRuntimeDiagnostics::cleanupFailed($failures[0], max(1, $failureCount));
            }
            throw new \RuntimeException(
                (string)__('Storage 请求资源清理失败：%{1} 个资源未正常关闭。', [max(1, $failureCount)]),
                0,
                $failures[0],
            );
        }
        StorageRuntimeDiagnostics::cleanupSucceeded();
    }
}
