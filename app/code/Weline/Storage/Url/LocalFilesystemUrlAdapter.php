<?php

declare(strict_types=1);

namespace Weline\Storage\Url;

use Weline\Storage\Api\Data\ResolvedStorageUrl;
use Weline\Storage\Api\Data\StorageObjectReference;
use Weline\Storage\Api\Data\StorageUrlOptions;
use Weline\Storage\Api\StorageUrlAdapterInterface;

final readonly class LocalFilesystemUrlAdapter implements StorageUrlAdapterInterface
{
    private string $diskCode;
    private string $baseUrl;

    public function __construct(string $diskCode, string $baseUrl)
    {
        $baseUrl = trim($baseUrl);
        $isRelative = str_starts_with($baseUrl, '/') && !str_starts_with($baseUrl, '//');
        $isHttp = preg_match('#^https?://#i', $baseUrl) === 1;
        if (
            $baseUrl === ''
            || (!$isRelative && !$isHttp)
            || preg_match('/[\x00-\x1F\x7F]/', $baseUrl) === 1
        ) {
            throw new \InvalidArgumentException((string)__('本地存储公共基础 URL 无效。'));
        }
        if ($isHttp) {
            $parts = parse_url($baseUrl);
            if (
                !is_array($parts)
                || !isset($parts['host'])
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['query'])
                || isset($parts['fragment'])
            ) {
                throw new \InvalidArgumentException((string)__('本地存储公共基础 URL 无效。'));
            }
        } elseif (str_contains($baseUrl, '?') || str_contains($baseUrl, '#')) {
            throw new \InvalidArgumentException((string)__('本地存储公共基础 URL 无效。'));
        }
        $this->diskCode = $diskCode;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function publicUrl(StorageObjectReference $object, StorageUrlOptions $options): ResolvedStorageUrl
    {
        $this->assertObject($object);
        return new ResolvedStorageUrl($this->buildUrl($object->objectKey), StorageUrlOptions::KIND_PUBLIC, true);
    }

    public function temporaryUrl(StorageObjectReference $object, StorageUrlOptions $options): ResolvedStorageUrl
    {
        $this->assertObject($object);
        // PUB/media is directly web-readable and cannot enforce expiry or an
        // access decision. Returning its public URL as "temporary" would leak
        // a private FileAsset, so private local assets fail closed until a
        // protected-route URL adapter is explicitly installed.
        throw new \RuntimeException((string)__('本地公开目录不支持私有文件临时 URL。'));
    }

    public function imageVariantUrl(StorageObjectReference $object, StorageUrlOptions $options): ResolvedStorageUrl
    {
        $this->assertObject($object);
        return new ResolvedStorageUrl(
            $this->buildUrl($object->objectKey),
            StorageUrlOptions::KIND_IMAGE_VARIANT,
            true,
            null,
            ['width' => $options->width, 'height' => $options->height, 'format' => $options->format],
        );
    }

    private function buildUrl(string $objectKey): string
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', str_replace('\\', '/', $objectKey))));
        return rtrim($this->baseUrl, '/') . '/' . $encoded;
    }

    private function assertObject(StorageObjectReference $object): void
    {
        if ($object->diskCode !== $this->diskCode) {
            throw new \InvalidArgumentException((string)__('存储 URL 适配器收到了其他磁盘的对象。'));
        }
    }
}
