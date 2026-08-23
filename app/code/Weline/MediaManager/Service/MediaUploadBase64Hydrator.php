<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service;

use Weline\Storage\Api\Runtime\StorageRequestResourceFactoryInterface;
use Weline\Storage\Api\Runtime\StorageRequestStreamInterface;
use Weline\Storage\Api\Runtime\StorageTemporaryFileInterface;

/**
 * Small compatibility-only Worker API upload bridge.
 *
 * Normal browser uploads use the same-origin multipart controller. Keeping this
 * fallback bounded to 1 MiB prevents JSON/base64 payloads from multiplying
 * memory use in a persistent WLS worker.
 */
final class MediaUploadBase64Hydrator
{
    public const MAX_BYTES = 1024 * 1024;
    private const DECODE_CHUNK_BYTES = 64 * 1024;

    public function __construct(private readonly StorageRequestResourceFactoryInterface $resourceFactory)
    {
    }

    /**
     * @param array<string,mixed> $params
     * @return array{
     *     files:list<array<string,mixed>>,
     *     temporary_paths:list<string>,
     *     temporary_resources:list<StorageTemporaryFileInterface>
     * }
     */
    public function hydrate(array $params): array
    {
        $uploads = $params['upload_base64'] ?? $params['_files'] ?? null;
        if (!is_array($uploads) || $uploads === []) {
            return ['files' => [], 'temporary_paths' => [], 'temporary_resources' => []];
        }
        if (!array_is_list($uploads)) {
            throw new \InvalidArgumentException((string)__('上传文件载荷无效。'));
        }
        if (count($uploads) > MediaAssetUploadService::MAX_UPLOAD_FILES) {
            throw new \InvalidArgumentException((string)__('单次上传文件数量超过限制。'));
        }

        $files = [];
        $created = [];
        $temporaryResources = [];
        $totalBytes = 0;
        $totalEncodedBytes = 0;
        $maxEncodedBytes = 4 * (int)ceil(self::MAX_BYTES / 3);
        try {
            foreach ($uploads as $item) {
                if (!is_array($item)) {
                    throw new \InvalidArgumentException((string)__('上传文件载荷无效。'));
                }
                foreach (['name', 'type', 'data'] as $field) {
                    if (array_key_exists($field, $item) && !is_string($item[$field])) {
                        throw new \InvalidArgumentException((string)__('上传文件载荷无效。'));
                    }
                }
                $data = (string)($item['data'] ?? '');
                if (str_starts_with($data, 'data:') && str_contains($data, ',')) {
                    $data = substr($data, (int)strpos($data, ',') + 1);
                }
                $encodedBytes = strlen($data);
                $totalEncodedBytes += $encodedBytes;
                if ($encodedBytes > $maxEncodedBytes || $totalEncodedBytes > $maxEncodedBytes) {
                    throw new \InvalidArgumentException((string)__('上传文件超过大小限制。'));
                }
                if (!$this->isCanonicalBase64($data)) {
                    throw new \InvalidArgumentException((string)__('上传文件内容无效。'));
                }

                $temporary = $this->resourceFactory->temporaryFile(sys_get_temp_dir(), 'mmup_');
                $temporaryResources[] = $temporary;
                $tmp = $temporary->path();
                $stream = fopen($tmp, 'wb');
                if ($stream === false) {
                    throw new \RuntimeException((string)__('无法创建上传临时文件。'));
                }
                $target = $this->resourceFactory->stream($stream);
                try {
                    $bytes = $this->decodeIntoStream($data, $target, self::MAX_BYTES - $totalBytes);
                } finally {
                    $target->close();
                }
                $totalBytes += $bytes;
                $created[] = $tmp;
                $files[] = [
                    'name' => (string)($item['name'] ?? 'upload.bin'),
                    'type' => (string)($item['type'] ?? 'application/octet-stream'),
                    'tmp_name' => $tmp,
                    'error' => UPLOAD_ERR_OK,
                    'size' => $bytes,
                    'metadata' => $this->normalizeMetadata($item['metadata'] ?? []),
                ];
            }
        } catch (\Throwable $throwable) {
            $this->cleanup($temporaryResources);
            throw $throwable;
        }

        return [
            'files' => $files,
            'temporary_paths' => $created,
            'temporary_resources' => $temporaryResources,
        ];
    }

    /** @param list<StorageTemporaryFileInterface|string> $temporaryFiles */
    public function cleanup(array $temporaryFiles): void
    {
        $failure = null;
        foreach (array_reverse($temporaryFiles) as $temporary) {
            try {
                if ($temporary instanceof StorageTemporaryFileInterface) {
                    $temporary->close();
                    continue;
                }
                // Old callers/tests may still pass a path during the migration.
                if (is_string($temporary) && $temporary !== '' && is_file($temporary) && !@unlink($temporary)) {
                    throw new \RuntimeException((string)__('删除上传临时文件失败。'));
                }
            } catch (\Throwable $throwable) {
                $failure ??= $throwable;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    private function isCanonicalBase64(string $data): bool
    {
        $length = strlen($data);
        if ($length % 4 !== 0) {
            return false;
        }
        if (preg_match('/^[A-Za-z0-9+\/]*={0,2}$/D', $data) !== 1) {
            return false;
        }
        $paddingAt = strpos($data, '=');

        return $paddingAt === false || $paddingAt >= $length - 2;
    }

    private function decodeIntoStream(
        string $encoded,
        StorageRequestStreamInterface $target,
        int $remainingBytes,
    ): int {
        if ($remainingBytes < 1) {
            throw new \InvalidArgumentException((string)__('上传文件超过大小限制。'));
        }
        $stream = $target->stream();
        $encodedLength = strlen($encoded);
        $decodedBytes = 0;
        for ($offset = 0; $offset < $encodedLength; $offset += self::DECODE_CHUNK_BYTES) {
            $chunk = substr($encoded, $offset, self::DECODE_CHUNK_BYTES);
            $binary = base64_decode($chunk, true);
            if ($binary === false) {
                throw new \InvalidArgumentException((string)__('上传文件内容无效。'));
            }
            $chunkBytes = strlen($binary);
            $decodedBytes += $chunkBytes;
            if ($decodedBytes > self::MAX_BYTES || $decodedBytes > $remainingBytes) {
                throw new \InvalidArgumentException((string)__('上传文件超过大小限制。'));
            }
            $written = 0;
            while ($written < $chunkBytes) {
                $count = fwrite($stream, substr($binary, $written));
                if ($count === false || $count === 0) {
                    throw new \RuntimeException((string)__('无法写入上传临时文件。'));
                }
                $written += $count;
            }
        }

        return $decodedBytes;
    }

    /** @return array<string,mixed> */
    private function normalizeMetadata(mixed $metadata): array
    {
        if (!is_array($metadata)) {
            return [];
        }
        $result = [];
        foreach (['display_name', 'default_alt', 'description', 'default_caption'] as $key) {
            if (array_key_exists($key, $metadata)) {
                if ($metadata[$key] !== null && !is_scalar($metadata[$key])) {
                    throw new \InvalidArgumentException((string)__('文件资源元数据格式无效。'));
                }
                $result[$key] = trim((string)$metadata[$key]);
            }
        }
        return $result;
    }
}
