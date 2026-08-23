<?php

declare(strict_types=1);

namespace Weline\StorageOss\Driver;

use Weline\Framework\Http\Sse\SseContext;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Storage\Api\Data\StorageConfigSnapshot;
use Weline\Storage\Api\Data\StorageObjectReference;
use Weline\Storage\Api\Data\StorageObjectStat;
use Weline\Storage\Api\Runtime\StorageRequestResourceFactoryInterface;
use Weline\Storage\Api\Runtime\StorageRequestResourceRegistryInterface;
use Weline\Storage\Api\StorageDriverInterface;
use Weline\Storage\Api\StorageReadHandle;
use Weline\Storage\Api\StorageWriteHandle;
use Weline\StorageOss\Service\AliyunOssClientFactory;
use Weline\StorageOss\Service\AliyunOssMultipartUpload;
use Weline\StorageOss\Service\OssMultipartCleanupRecorder;

final class AliyunOssDriver implements StorageDriverInterface
{
    public function __construct(
        private readonly StorageConfigSnapshot $snapshot,
        private readonly AliyunOssClientFactory $clients,
        private readonly StorageRequestResourceRegistryInterface $resources,
        private readonly StorageRequestResourceFactoryInterface $resourceFactory,
        private readonly OssMultipartCleanupRecorder $multipartCleanup,
    ) {
    }

    public function openRead(string $objectKey): StorageReadHandle
    {
        $objectKey = $this->normalizeKey($objectKey);
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            throw new \RuntimeException((string)__('当前 PHP 运行时未启用 OSS 流式读取能力。'));
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->clients->requestTimeoutSeconds(),
                'follow_location' => 0,
                'ignore_errors' => false,
                'header' => "Connection: close\r\n",
            ],
        ]);
        // Suppress the native warning because it includes the signed URL.
        $stream = @fopen($this->clients->signedReadUrl($objectKey), 'rb', false, $context);
        if ($stream === false) {
            throw new \RuntimeException((string)__('打开 OSS 读取流失败。'));
        }

        return new StorageReadHandle($stream, $this->resources);
    }

    public function openWrite(string $objectKey, array $options = []): StorageWriteHandle
    {
        $objectKey = $this->normalizeKey($objectKey);
        $overwrite = (bool)($options['overwrite'] ?? false);
        if (!$overwrite) {
            $this->clients->assertForbidOverwriteSupported();
        }
        $temporary = $this->resourceFactory->temporaryFile(sys_get_temp_dir(), 'weline-oss-write-');
        $stream = fopen($temporary->path(), 'wb');
        if ($stream === false) {
            $temporary->close();
            throw new \RuntimeException((string)__('无法打开 OSS 上传流。'));
        }

        return new StorageWriteHandle(
            $stream,
            function () use ($temporary, $objectKey, $options, $overwrite): StorageObjectStat {
                $sdkOptions = [];
                $mimeType = trim((string)($options['content_type'] ?? $options['mime_type'] ?? ''));
                if ($mimeType !== '') {
                    $sdkOptions[\OSS\OssClient::OSS_CONTENT_TYPE] = $mimeType;
                }
                if (!$overwrite) {
                    $sdkOptions[\OSS\OssClient::OSS_HEADERS]['x-oss-forbid-overwrite'] = 'true';
                }
                try {
                    $this->assertClientConnected();
                    // PHP caches stat() results per process. WLS workers are
                    // long-lived, so refresh the completed staging file before
                    // deriving its immutable commit metadata or handing it to
                    // the SDK.
                    clearstatcache(true, $temporary->path());
                    $bytes = filesize($temporary->path());
                    $bytes = $bytes === false ? 0 : max(0, (int)$bytes);
                    $sha256 = $this->sha256Path($temporary->path());
                    $commitId = hash('sha256', implode("\0", [
                        'weline-oss-commit-v1',
                        $this->snapshot->diskCode,
                        (string)$this->snapshot->configRevision,
                        $objectKey,
                        (string)$bytes,
                        strtolower($mimeType),
                        $sha256,
                    ]));
                    $sdkOptions[\OSS\OssClient::OSS_HEADERS]['x-oss-meta-weline-sha256'] = $sha256;
                    $sdkOptions[\OSS\OssClient::OSS_HEADERS]['x-oss-meta-weline-commit'] = $commitId;

                    // A previous request may have committed the remote object
                    // but died before FileAsset persistence. Adopt it only when
                    // immutable content metadata proves it is this exact write.
                    if ($overwrite || !$this->matchesCommittedObject(
                        $objectKey,
                        $bytes,
                        $mimeType,
                        $sha256,
                        $commitId,
                    )) {
                        $threshold = $this->boundedMultipartThreshold();
                        try {
                            if ($bytes >= $threshold) {
                                $this->multipartUpload(
                                    $temporary->path(),
                                    $objectKey,
                                    $bytes,
                                    $sdkOptions,
                                    $mimeType,
                                    $sha256,
                                    $commitId,
                                );
                            } else {
                                $this->run(
                                    fn () => $this->clients->client()->uploadFile(
                                        $this->clients->bucket(),
                                        $this->clients->prefixedKey($objectKey),
                                        $temporary->path(),
                                        $sdkOptions,
                                    ),
                                    '提交 OSS 对象失败。',
                                );
                            }
                        } catch (\Throwable $uploadFailure) {
                            if (!$this->matchesCommittedObject(
                                $objectKey,
                                $bytes,
                                $mimeType,
                                $sha256,
                                $commitId,
                            )) {
                                throw $uploadFailure;
                            }
                        }
                    }
                    $result = new StorageObjectStat(
                        new StorageObjectReference($this->snapshot->diskCode, $objectKey),
                        $bytes,
                        $mimeType !== '' ? $mimeType : null,
                        time(),
                    );
                } catch (\Throwable $failure) {
                    try {
                        $temporary->close();
                    } catch (\Throwable) {
                        // StorageTemporaryFile has already recorded cleanup debt.
                    }
                    throw $failure;
                }

                try {
                    $temporary->close();
                } catch (\Throwable) {
                    // The remote commit is already durable. Report it to the caller so
                    // FileAsset can be committed; request cleanup will surface the debt
                    // and drain this worker instead of creating an untracked OSS object.
                }

                return $result;
            },
            static fn () => $temporary->close(),
            $this->resources,
            $this->maxWriteBytes($options),
        );
    }

    public function exists(string $objectKey): bool
    {
        $objectKey = $this->normalizeKey($objectKey);
        return (bool)$this->run(
            fn () => $this->clients->client()->doesObjectExist(
                $this->clients->bucket(),
                $this->clients->prefixedKey($objectKey),
            ),
            '检查 OSS 对象失败。',
        );
    }

    public function stat(string $objectKey): StorageObjectStat
    {
        $objectKey = $this->normalizeKey($objectKey);
        $metadata = $this->run(
            fn () => $this->clients->client()->getObjectMeta(
                $this->clients->bucket(),
                $this->clients->prefixedKey($objectKey),
            ),
            '读取 OSS 对象元数据失败。',
        );
        $metadata = is_array($metadata) ? array_change_key_case($metadata, CASE_LOWER) : [];
        $modified = isset($metadata['last-modified']) ? strtotime((string)$metadata['last-modified']) : false;
        return new StorageObjectStat(
            new StorageObjectReference($this->snapshot->diskCode, $objectKey),
            max(0, (int)($metadata['content-length'] ?? 0)),
            isset($metadata['content-type']) ? (string)$metadata['content-type'] : null,
            $modified === false ? null : $modified,
            isset($metadata['etag']) ? trim((string)$metadata['etag'], '"') : null,
        );
    }

    public function delete(string $objectKey): bool
    {
        $objectKey = $this->normalizeKey($objectKey);
        $this->run(
            fn () => $this->clients->client()->deleteObject(
                $this->clients->bucket(),
                $this->clients->prefixedKey($objectKey),
            ),
            '删除 OSS 对象失败。',
        );
        return true;
    }

    public function copy(string $fromObjectKey, string $toObjectKey): StorageObjectReference
    {
        $fromObjectKey = $this->normalizeKey($fromObjectKey);
        $toObjectKey = $this->normalizeKey($toObjectKey);
        $this->clients->assertForbidOverwriteSupported();
        $options = [
            \OSS\OssClient::OSS_HEADERS => ['x-oss-forbid-overwrite' => 'true'],
        ];
        $this->run(
            fn () => $this->clients->client()->copyObject(
                $this->clients->bucket(),
                $this->clients->prefixedKey($fromObjectKey),
                $this->clients->bucket(),
                $this->clients->prefixedKey($toObjectKey),
                $options,
            ),
            '复制 OSS 对象失败。',
        );
        return new StorageObjectReference($this->snapshot->diskCode, $toObjectKey);
    }

    public function move(string $fromObjectKey, string $toObjectKey): StorageObjectReference
    {
        $reference = $this->copy($fromObjectKey, $toObjectKey);
        try {
            $this->delete($fromObjectKey);
        } catch (\Throwable $sourceDeleteFailure) {
            // A remote delete can succeed and still end in a timeout before the
            // SDK receives the response. Reconcile with a fresh existence check
            // before deciding whether the move actually committed. Never delete
            // the copied target while the source state is still unknown.
            try {
                $sourceStillExists = $this->exists($fromObjectKey);
            } catch (\Throwable) {
                throw new \RuntimeException(
                    (string)__('移动 OSS 对象的源对象删除状态不确定，目标副本已保留。'),
                );
            }
            if (!$sourceStillExists) {
                return $reference;
            }
            try {
                $this->delete($toObjectKey);
            } catch (\Throwable) {
                throw new \RuntimeException(
                    (string)__('移动 OSS 对象未完成，源对象已保留，目标副本清理状态不确定。'),
                );
            }
            throw new \RuntimeException(
                (string)__('移动 OSS 对象失败，源对象已保留。'),
                0,
                $sourceDeleteFailure,
            );
        }
        return $reference;
    }

    public function makeDirectory(string $objectKey): bool
    {
        $objectKey = $this->normalizeKey($objectKey);
        $this->clients->assertForbidOverwriteSupported();
        $options = [
            \OSS\OssClient::OSS_HEADERS => ['x-oss-forbid-overwrite' => 'true'],
        ];
        $this->run(
            fn () => $this->clients->client()->putObject(
                $this->clients->bucket(),
                $this->clients->prefixedDirectoryKey($objectKey),
                '',
                $options,
            ),
            '创建 OSS 目录标记失败。',
        );
        return true;
    }

    public function deleteDirectory(string $objectKey): bool
    {
        $directory = $this->normalizeKey($objectKey);
        $directoryMarker = $this->clients->prefixedDirectoryKey($directory);
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $providerKeys = [$directoryMarker];
            foreach ($this->list($directory, true) as $stat) {
                $suffix = ($stat->metadata['type'] ?? 'file') === 'directory' ? '/' : '';
                $providerKeys[] = $suffix === ''
                    ? $this->clients->prefixedKey($stat->object->objectKey)
                    : $this->clients->prefixedDirectoryKey($stat->object->objectKey);
            }
            foreach (array_chunk(array_values(array_unique($providerKeys)), 1000) as $chunk) {
                $this->run(
                    fn () => $this->clients->client()->deleteObjects($this->clients->bucket(), $chunk),
                    '删除 OSS 目录失败。',
                );
                SchedulerSystem::yield();
            }

            // A concurrent writer may have added an object between the first
            // listing and deletion. Reconcile once instead of reporting a
            // successful recursive delete while provider objects remain.
            $remaining = $this->list($directory, true);
            $markerExists = (bool)$this->run(
                fn () => $this->clients->client()->doesObjectExist(
                    $this->clients->bucket(),
                    $directoryMarker,
                ),
                '确认 OSS 目录删除结果失败。',
            );
            if ($remaining === [] && !$markerExists) {
                return true;
            }
            SchedulerSystem::yield();
        }

        return false;
    }

    public function list(string $prefix = '', bool $recursive = false): array
    {
        $prefix = $this->normalizeOptionalPrefix($prefix);
        $config = $this->clients->config();
        $maxPages = max(1, min(100, (int)($config['max_list_pages'] ?? 20)));
        $maxItems = max(1, min(100000, (int)($config['max_list_items'] ?? 20000)));
        $providerPrefix = $this->listProviderPrefix($prefix);
        $marker = '';
        $items = [];

        for ($page = 1; $page <= $maxPages; ++$page) {
            $options = ['prefix' => $providerPrefix, 'max-keys' => 1000];
            if (!$recursive) {
                $options['delimiter'] = '/';
            }
            if ($marker !== '') {
                $options['marker'] = $marker;
            }
            $result = $this->run(
                fn () => $this->clients->client()->listObjects($this->clients->bucket(), $options),
                '枚举 OSS 目录失败。',
            );

            if (!$recursive && is_object($result) && method_exists($result, 'getPrefixList')) {
                foreach ((array)($result->getPrefixList() ?? []) as $prefixInfo) {
                    if (!is_object($prefixInfo) || !method_exists($prefixInfo, 'getPrefix')) {
                        continue;
                    }
                    $this->appendListItem($items, (string)$prefixInfo->getPrefix(), true, 0, null, $maxItems);
                }
            }
            if (is_object($result) && method_exists($result, 'getObjectList')) {
                foreach ((array)($result->getObjectList() ?? []) as $object) {
                    if (!is_object($object) || !method_exists($object, 'getKey')) {
                        continue;
                    }
                    $key = (string)$object->getKey();
                    $isDirectory = str_ends_with($key, '/');
                    $size = method_exists($object, 'getSize') ? max(0, (int)$object->getSize()) : 0;
                    $lastModified = method_exists($object, 'getLastModified')
                        ? strtotime((string)$object->getLastModified())
                        : false;
                    $this->appendListItem(
                        $items,
                        $key,
                        $isDirectory,
                        $size,
                        $lastModified === false ? null : $lastModified,
                        $maxItems,
                    );
                }
            }

            $truncatedValue = is_object($result) && method_exists($result, 'getIsTruncated')
                ? $result->getIsTruncated()
                : false;
            $truncated = $truncatedValue === true || $truncatedValue === 1
                || $truncatedValue === '1' || $truncatedValue === 'true';
            if (!$truncated) {
                return array_values($items);
            }
            $nextMarker = is_object($result) && method_exists($result, 'getNextMarker')
                ? (string)$result->getNextMarker()
                : '';
            if ($nextMarker === '' || $nextMarker === $marker) {
                throw new \RuntimeException((string)__('OSS 分页标记未前进，已停止枚举。'));
            }
            $marker = $nextMarker;
            SchedulerSystem::yield();
        }

        throw new \RuntimeException((string)__('OSS 目录枚举超过配置页数上限。'));
    }

    /** @param array<string,StorageObjectStat> $items */
    private function appendListItem(
        array &$items,
        string $providerKey,
        bool $directory,
        int $bytes,
        ?int $lastModified,
        int $maxItems,
    ): void {
        $relative = $this->clients->relativeKey($providerKey);
        if ($relative === null) {
            return;
        }
        $relative = trim($relative, '/');
        if ($relative === '') {
            return;
        }
        if (count($items) >= $maxItems) {
            throw new \RuntimeException((string)__('OSS 目录枚举超过配置条目上限。'));
        }
        $items[$relative] = new StorageObjectStat(
            new StorageObjectReference($this->snapshot->diskCode, $relative),
            $directory ? 0 : $bytes,
            $directory ? 'application/x-directory' : null,
            $lastModified,
            null,
            ['type' => $directory ? 'directory' : 'file'],
        );
    }

    private function listProviderPrefix(string $prefix): string
    {
        $configPrefix = trim(str_replace('\\', '/', (string)($this->clients->config()['prefix'] ?? '')), '/');
        $parts = array_filter([$configPrefix, $prefix], static fn (string $part): bool => $part !== '');
        $value = implode('/', $parts);
        return $value === '' ? '' : rtrim($value, '/') . '/';
    }

    private function normalizeKey(string $objectKey): string
    {
        $objectKey = trim(str_replace('\\', '/', $objectKey), '/');
        StorageObjectReference::assertObjectKey($objectKey);
        return $objectKey;
    }

    private function normalizeOptionalPrefix(string $prefix): string
    {
        $prefix = trim(str_replace('\\', '/', $prefix), '/');
        if ($prefix !== '') {
            StorageObjectReference::assertObjectKey($prefix);
        }
        return $prefix;
    }

    /** @param array<string,mixed> $sdkOptions */
    private function multipartUpload(
        string $path,
        string $objectKey,
        int $bytes,
        array $sdkOptions,
        string $mimeType,
        string $sha256,
        string $commitId,
    ): void {
        $client = $this->clients->client();
        $bucket = $this->clients->bucket();
        $providerKey = $this->clients->prefixedKey($objectKey);
        $uploadId = $this->run(
            fn () => $client->initiateMultipartUpload($bucket, $providerKey, $sdkOptions),
            '初始化 OSS multipart 上传失败。',
        );
        if (!is_string($uploadId) || trim($uploadId) === '') {
            throw new \RuntimeException((string)__('OSS multipart 初始化未返回 upload id。'));
        }
        $multipart = new AliyunOssMultipartUpload(
            $this->snapshot,
            $objectKey,
            $uploadId,
            $this->clients,
            $this->multipartCleanup,
            $this->resources,
        );
        try {
            $partSize = $this->boundedPartSize();
            $partCount = (int)ceil($bytes / $partSize);
            if ($partCount < 1 || $partCount > 10000) {
                throw new \RuntimeException((string)__('OSS multipart 分片数量超过上限。'));
            }
            $parts = [];
            $partNumber = 1;
            for ($offset = 0; $offset < $bytes; $offset += $partSize, ++$partNumber) {
                $this->assertClientConnected();
                $length = min($partSize, $bytes - $offset);
                $partOptions = [
                    \OSS\OssClient::OSS_FILE_UPLOAD => $path,
                    \OSS\OssClient::OSS_PART_NUM => $partNumber,
                    \OSS\OssClient::OSS_SEEK_TO => $offset,
                    \OSS\OssClient::OSS_LENGTH => $length,
                ];
                $etag = $this->run(
                    fn () => $client->uploadPart(
                        $bucket,
                        $providerKey,
                        $multipart->uploadId(),
                        $partOptions,
                    ),
                    '上传 OSS multipart 分片失败。',
                );
                if (!is_string($etag) || trim($etag) === '') {
                    throw new \RuntimeException((string)__('OSS multipart 分片未返回 ETag。'));
                }
                $parts[] = ['PartNumber' => $partNumber, 'ETag' => $etag];
                SchedulerSystem::yield();
            }
            try {
                $this->run(
                    fn () => $client->completeMultipartUpload(
                        $bucket,
                        $providerKey,
                        $multipart->uploadId(),
                        $parts,
                        $sdkOptions,
                    ),
                    '合并 OSS multipart 分片失败。',
                );
            } catch (\Throwable $completionFailure) {
                // The remote service may commit successfully while its response
                // is lost. Reconcile before attempting abort so a completed
                // upload is not misclassified as cleanup debt.
                if (!$this->matchesCommittedObject(
                    $objectKey,
                    $bytes,
                    $mimeType,
                    $sha256,
                    $commitId,
                )) {
                    throw $completionFailure;
                }
            }
            $multipart->complete();
        } catch (\Throwable $failure) {
            try { $multipart->close(); } catch (\Throwable) {}
            throw $failure;
        }
    }

    private function boundedMultipartThreshold(): int
    {
        return max(5 * 1024 * 1024, min(5 * 1024 * 1024 * 1024, (int)(
            $this->clients->config()['multipart_threshold_bytes'] ?? 8 * 1024 * 1024
        )));
    }

    /** @param array<string,mixed> $options */
    private function maxWriteBytes(array $options): int
    {
        $configured = filter_var(
            $this->clients->config()['max_object_bytes'] ?? StorageWriteHandle::DEFAULT_MAX_TOTAL_BYTES,
            FILTER_VALIDATE_INT,
        );
        if ($configured === false || $configured < 1 || $configured > StorageWriteHandle::MAX_TOTAL_BYTES) {
            throw new \RuntimeException((string)__('OSS 单对象字节上限配置无效。'));
        }
        if (!array_key_exists('max_bytes', $options)) {
            return (int)$configured;
        }
        $requested = filter_var($options['max_bytes'], FILTER_VALIDATE_INT);
        if ($requested === false || $requested < 1) {
            throw new \InvalidArgumentException((string)__('存储写入字节上限无效。'));
        }
        return min((int)$configured, (int)$requested);
    }

    private function boundedPartSize(): int
    {
        return max(100 * 1024, min(5 * 1024 * 1024 * 1024, (int)(
            $this->clients->config()['multipart_part_bytes'] ?? 8 * 1024 * 1024
        )));
    }

    private function sha256Path(string $path): string
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException((string)__('OSS 上传校验流无法打开。'));
        }
        $handle = new StorageReadHandle($stream, $this->resources);
        $hash = hash_init('sha256');
        $emptyReads = 0;
        try {
            while (!$handle->eof()) {
                $this->assertClientConnected();
                $chunk = $handle->read(1024 * 1024);
                if ($chunk === '') {
                    if (++$emptyReads >= 3) {
                        throw new \RuntimeException((string)__('OSS 上传校验流连续无数据进展。'));
                    }
                    SchedulerSystem::yield();
                    continue;
                }
                $emptyReads = 0;
                hash_update($hash, $chunk);
                SchedulerSystem::yield();
            }
        } finally {
            $handle->close();
        }

        return hash_final($hash);
    }

    private function matchesCommittedObject(
        string $objectKey,
        int $bytes,
        string $mimeType,
        string $sha256,
        string $commitId,
    ): bool {
        try {
            $metadata = $this->clients->client()->getObjectMeta(
                $this->clients->bucket(),
                $this->clients->prefixedKey($objectKey),
            );
        } catch (\Throwable) {
            return false;
        }
        if (!is_array($metadata)) {
            return false;
        }
        $metadata = array_change_key_case($metadata, CASE_LOWER);
        $storedSha = strtolower(trim((string)(
            $metadata['x-oss-meta-weline-sha256']
                ?? $metadata['weline-sha256']
                ?? ''
        )));
        $storedCommit = strtolower(trim((string)(
            $metadata['x-oss-meta-weline-commit']
                ?? $metadata['weline-commit']
                ?? ''
        )));
        $storedMime = strtolower(trim((string)($metadata['content-type'] ?? '')));

        return (int)($metadata['content-length'] ?? -1) === $bytes
            && hash_equals($sha256, $storedSha)
            && hash_equals($commitId, $storedCommit)
            && ($mimeType === '' || hash_equals(strtolower($mimeType), $storedMime));
    }

    private function run(callable $operation, string $message): mixed
    {
        try {
            return $operation();
        } catch (\Throwable) {
            throw new \RuntimeException((string)__($message));
        }
    }

    private function assertClientConnected(): void
    {
        $aborted = function_exists('connection_aborted') && connection_aborted();
        if (!$aborted
            && defined('WLS_MODE')
            && WLS_MODE
            && is_callable(SseContext::getAliveCallback())
        ) {
            $aborted = !SseContext::isConnectionAlive();
        }
        if ($aborted) {
            throw new \RuntimeException((string)__('客户端已断开，OSS 上传已取消。'));
        }
    }
}
