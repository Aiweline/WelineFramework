<?php

declare(strict_types=1);

namespace Weline\Storage\Api\Data;

final readonly class StorageUrlOptions
{
    public const KIND_PUBLIC = 'public';
    public const KIND_TEMPORARY = 'temporary';
    public const KIND_IMAGE_VARIANT = 'image_variant';

    public function __construct(
        public string $kind = self::KIND_PUBLIC,
        public int $ttlSeconds = 3600,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $format = null,
        public ?string $fit = null,
    ) {
        if (!in_array($kind, [self::KIND_PUBLIC, self::KIND_TEMPORARY, self::KIND_IMAGE_VARIANT], true)) {
            throw new \InvalidArgumentException((string)__('URL 解析类型无效。'));
        }
        if ($ttlSeconds < 1 || $ttlSeconds > 86400) {
            throw new \InvalidArgumentException((string)__('临时 URL 有效期必须在 1 到 86400 秒之间。'));
        }
        if (
            ($width !== null && ($width < 1 || $width > 10000))
            || ($height !== null && ($height < 1 || $height > 10000))
        ) {
            throw new \InvalidArgumentException((string)__('图片变体尺寸必须在 1 到 10000 之间。'));
        }
        if (($format !== null && preg_match('/^[a-z0-9][a-z0-9_-]{0,15}$/i', $format) !== 1)
            || ($fit !== null && preg_match('/^[a-z0-9][a-z0-9_-]{0,15}$/i', $fit) !== 1)
        ) {
            throw new \InvalidArgumentException((string)__('图片变体格式或适配模式无效。'));
        }
    }
}
