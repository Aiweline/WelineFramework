<?php

declare(strict_types=1);

namespace Weline\Storage\Api\Data;

final readonly class StorageObjectStat
{
    /** @param array<string,scalar|null> $metadata */
    public function __construct(
        public StorageObjectReference $object,
        public int $bytes,
        public ?string $mimeType = null,
        public ?int $lastModified = null,
        public ?string $etag = null,
        public array $metadata = [],
    ) {
        if ($bytes < 0) {
            throw new \InvalidArgumentException((string)__('对象字节数不能小于零。'));
        }
        if ($lastModified !== null && $lastModified < 0) {
            throw new \InvalidArgumentException((string)__('对象修改时间无效。'));
        }
        if ($mimeType !== null
            && (strlen($mimeType) > 255 || preg_match('/[\x00-\x1F\x7F]/', $mimeType) === 1)
        ) {
            throw new \InvalidArgumentException((string)__('对象 MIME 类型无效。'));
        }
        if ($etag !== null
            && (strlen($etag) > 512 || preg_match('/[\x00-\x1F\x7F]/', $etag) === 1)
        ) {
            throw new \InvalidArgumentException((string)__('对象 ETag 无效。'));
        }
        if (count($metadata) > 64) {
            throw new \InvalidArgumentException((string)__('对象元数据超过限制。'));
        }
        foreach ($metadata as $name => $value) {
            if (!is_string($name)
                || preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{0,127}$/', $name) !== 1
                || (!is_scalar($value) && $value !== null)
                || (is_string($value)
                    && (strlen($value) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1))
            ) {
                throw new \InvalidArgumentException((string)__('对象元数据无效。'));
            }
        }
    }
}
