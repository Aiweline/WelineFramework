<?php

declare(strict_types=1);

namespace Weline\StorageOss\Service;

use Weline\Framework\Runtime\RequestResourceInterface;
use Weline\Storage\Api\Data\StorageConfigSnapshot;
use Weline\Storage\Api\Runtime\StorageRequestResourceRegistryInterface;

/** Request-owned multipart upload. close() means abort unless completed. */
final class AliyunOssMultipartUpload implements RequestResourceInterface
{
    private bool $closed = false;
    private ?string $uploadId;

    public function __construct(
        private readonly StorageConfigSnapshot $snapshot,
        private readonly string $objectKey,
        string $uploadId,
        private readonly AliyunOssClientFactory $clients,
        private readonly OssMultipartCleanupRecorder $cleanup,
        private readonly StorageRequestResourceRegistryInterface $resources,
    ) {
        if (trim($uploadId) === '') {
            throw new \InvalidArgumentException((string)__('OSS multipart upload id 不能为空。'));
        }
        $this->uploadId = $uploadId;
        try {
            $this->resources->register($this);
        } catch (\Throwable) {
            // The remote upload already exists. Registry implementations are
            // not required to close a rejected resource, so abort explicitly;
            // close() is idempotent when the production registry already did.
            try {
                $this->close();
            } catch (\Throwable) {
                // close() has recorded cleanup debt and deferred a safe failure.
            }
            throw new \RuntimeException((string)__('OSS multipart 资源注册失败，上传已中止或记录清理债务。'));
        }
    }

    public function resourceKind(): string { return 'storage.multipart_upload'; }
    public function uploadId(): string
    {
        if ($this->closed || $this->uploadId === null) {
            throw new \RuntimeException((string)__('OSS multipart 上传已结束。'));
        }
        return $this->uploadId;
    }

    public function complete(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->uploadId = null;
        $this->resources->release($this);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $uploadId = (string)$this->uploadId;
        $this->closed = true;
        $this->uploadId = null;
        try {
            $this->clients->client()->abortMultipartUpload(
                $this->clients->bucket(),
                $this->clients->prefixedKey($this->objectKey),
                $uploadId,
            );
        } catch (\Throwable $failure) {
            $recorded = false;
            try {
                $this->cleanup->record($this->snapshot, $this->objectKey, $uploadId, $failure);
                $recorded = true;
            } catch (\Throwable) {
                $this->resources->deferCleanupFailure(new \RuntimeException(
                    (string)__('OSS multipart 清理债务记录失败。'),
                ));
            }
            // Never retain or chain the SDK exception: its message may contain
            // an object key, request id, endpoint, or signed request details.
            $this->resources->deferCleanupFailure(new \RuntimeException(
                (string)__('OSS multipart 远端中止失败。'),
            ));
            throw new \RuntimeException(
                (string)__($recorded
                    ? 'OSS multipart 中止失败，已记录清理债务。'
                    : 'OSS multipart 中止失败，且清理债务记录失败。'),
            );
        } finally {
            $this->resources->release($this);
        }
    }

    public function isClosed(): bool { return $this->closed; }

    public function __destruct()
    {
        try { $this->close(); } catch (\Throwable) {}
    }
}
