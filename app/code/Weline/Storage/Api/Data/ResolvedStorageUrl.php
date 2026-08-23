<?php

declare(strict_types=1);

namespace Weline\Storage\Api\Data;

final readonly class ResolvedStorageUrl
{
    /** @param array<string,scalar|null> $attributes */
    public function __construct(
        public string $url,
        public string $kind,
        public bool $cacheable,
        public ?int $expiresAt = null,
        public array $attributes = [],
    ) {
        if ($url === '' || strlen($url) > 16384 || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            throw new \InvalidArgumentException((string)__('存储 URL 不能为空。'));
        }
        $isLocalPath = str_starts_with($url, '/') && !str_starts_with($url, '//');
        $parts = $isLocalPath ? null : parse_url($url);
        $isHttpUrl = is_array($parts)
            && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            && trim((string)($parts['host'] ?? '')) !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);
        if (!$isLocalPath && !$isHttpUrl) {
            throw new \InvalidArgumentException((string)__('存储 URL 必须是站内绝对路径或 HTTP(S) URL。'));
        }
        if (!in_array($kind, [
            StorageUrlOptions::KIND_PUBLIC,
            StorageUrlOptions::KIND_TEMPORARY,
            StorageUrlOptions::KIND_IMAGE_VARIANT,
        ], true)) {
            throw new \InvalidArgumentException((string)__('存储 URL 类型无效。'));
        }
        if ($kind === StorageUrlOptions::KIND_TEMPORARY && ($expiresAt === null || $cacheable)) {
            throw new \InvalidArgumentException((string)__('临时 URL 必须带过期时间且不可共享缓存。'));
        }
        if (count($attributes) > 32) {
            throw new \InvalidArgumentException((string)__('存储 URL 属性超过限制。'));
        }
        foreach ($attributes as $name => $value) {
            if (!is_string($name)
                || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $name) !== 1
                || (!is_scalar($value) && $value !== null)
                || (is_string($value)
                    && (strlen($value) > 512 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1))
            ) {
                throw new \InvalidArgumentException((string)__('存储 URL 属性无效。'));
            }
        }
    }
}
