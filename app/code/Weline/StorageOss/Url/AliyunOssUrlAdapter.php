<?php

declare(strict_types=1);

namespace Weline\StorageOss\Url;

use Weline\Storage\Api\Data\ResolvedStorageUrl;
use Weline\Storage\Api\Data\StorageConfigSnapshot;
use Weline\Storage\Api\Data\StorageObjectReference;
use Weline\Storage\Api\Data\StorageUrlOptions;
use Weline\Storage\Api\StorageUrlAdapterInterface;
use Weline\StorageOss\Service\AliyunOssClientFactory;

final class AliyunOssUrlAdapter implements StorageUrlAdapterInterface
{
    public function __construct(
        private readonly StorageConfigSnapshot $snapshot,
        private readonly AliyunOssClientFactory $clients,
    ) {
    }

    public function publicUrl(StorageObjectReference $object, StorageUrlOptions $options): ResolvedStorageUrl
    {
        $this->assertObject($object);
        if ($this->visibility() !== 'public') {
            throw new \RuntimeException((string)__('私有 OSS 磁盘不支持公开 URL。'));
        }
        return new ResolvedStorageUrl(
            $this->baseObjectUrl($object->objectKey),
            StorageUrlOptions::KIND_PUBLIC,
            true,
        );
    }

    public function temporaryUrl(StorageObjectReference $object, StorageUrlOptions $options): ResolvedStorageUrl
    {
        $this->assertObject($object);
        $url = $this->sign($object->objectKey, $options->ttlSeconds);
        return new ResolvedStorageUrl(
            $url,
            StorageUrlOptions::KIND_TEMPORARY,
            false,
            time() + $options->ttlSeconds,
            ['signed' => true, 'config_revision' => $this->snapshot->configRevision],
        );
    }

    public function imageVariantUrl(StorageObjectReference $object, StorageUrlOptions $options): ResolvedStorageUrl
    {
        $this->assertObject($object);
        $process = $this->imageProcess($options);
        if ($process === '') {
            return $this->visibility() === 'public'
                ? new ResolvedStorageUrl(
                    $this->baseObjectUrl($object->objectKey),
                    StorageUrlOptions::KIND_IMAGE_VARIANT,
                    true,
                )
                : new ResolvedStorageUrl(
                    $this->sign($object->objectKey, $options->ttlSeconds),
                    StorageUrlOptions::KIND_IMAGE_VARIANT,
                    false,
                    time() + $options->ttlSeconds,
                    ['signed' => true],
                );
        }

        if ($this->visibility() !== 'public') {
            $sdkOptions = [\OSS\OssClient::OSS_PROCESS => $process];
            $url = $this->sign($object->objectKey, $options->ttlSeconds, $sdkOptions);
            return new ResolvedStorageUrl(
                $url,
                StorageUrlOptions::KIND_IMAGE_VARIANT,
                false,
                time() + $options->ttlSeconds,
                ['signed' => true, 'width' => $options->width, 'height' => $options->height],
            );
        }

        return new ResolvedStorageUrl(
            $this->baseObjectUrl($object->objectKey) . '?x-oss-process=' . rawurlencode($process),
            StorageUrlOptions::KIND_IMAGE_VARIANT,
            true,
            null,
            ['width' => $options->width, 'height' => $options->height, 'format' => $options->format],
        );
    }

    /** @param array<string,mixed> $sdkOptions */
    private function sign(string $objectKey, int $ttlSeconds, array $sdkOptions = []): string
    {
        try {
            return (string)$this->clients->client()->signUrl(
                $this->clients->bucket(),
                $this->clients->prefixedKey($objectKey),
                $ttlSeconds,
                'GET',
                $sdkOptions,
            );
        } catch (\Throwable) {
            throw new \RuntimeException((string)__('生成 OSS 临时 URL 失败。'));
        }
    }

    private function baseObjectUrl(string $objectKey): string
    {
        $config = $this->clients->config();
        $cdnDomain = trim((string)($config['cdn_domain'] ?? ''));
        $configuredBase = $cdnDomain !== ''
            ? $cdnDomain
            : trim((string)($config['public_base_url'] ?? ''));
        if ($configuredBase !== '') {
            $base = $this->normalizedBaseUrl($configuredBase, $config);
        } else {
            $endpoint = $this->clients->endpointHost();
            $scheme = $this->scheme($config);
            $base = (bool)($config['is_cname'] ?? false)
                ? $scheme . $endpoint
                : $scheme . $this->clients->bucket() . '.' . $endpoint;
        }
        return rtrim($base, '/') . '/' . $this->encodedKey($this->clients->prefixedKey($objectKey));
    }

    private function imageProcess(StorageUrlOptions $options): string
    {
        $resize = [];
        $mode = match (strtolower((string)$options->fit)) {
            'cover', 'fill' => 'fill',
            'contain', 'fit', 'inside' => 'lfit',
            default => 'lfit',
        };
        if ($options->width !== null || $options->height !== null) {
            $resize[] = 'm_' . $mode;
            if ($options->width !== null) {
                $resize[] = 'w_' . $options->width;
            }
            if ($options->height !== null) {
                $resize[] = 'h_' . $options->height;
            }
        }
        $operations = $resize === [] ? [] : ['image/resize,' . implode(',', $resize)];
        $format = strtolower(trim((string)$options->format));
        if ($format !== '') {
            if (!in_array($format, ['jpg', 'jpeg', 'png', 'webp', 'avif'], true)) {
                throw new \InvalidArgumentException((string)__('不支持的 OSS 图片输出格式。'));
            }
            $operations[] = 'image/format,' . ($format === 'jpeg' ? 'jpg' : $format);
        }
        return implode('/', $operations);
    }

    private function visibility(): string
    {
        return $this->snapshot->visibility();
    }

    /** @param array<string,mixed> $config */
    private function scheme(array $config): string
    {
        $endpoint = strtolower($this->clients->endpoint());
        if (str_starts_with($endpoint, 'https://')) {
            return 'https://';
        }
        if (str_starts_with($endpoint, 'http://')) {
            return 'http://';
        }
        return ($config['use_ssl'] ?? true) ? 'https://' : 'http://';
    }

    /** @param array<string,mixed> $config */
    private function normalizedBaseUrl(string $configuredBase, array $config): string
    {
        $url = preg_match('#^https?://#i', $configuredBase) === 1
            ? $configuredBase
            : $this->scheme($config) . $configuredBase;
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || !isset($parts['host'])
            || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !$this->validHost((string)$parts['host'])
            || (isset($parts['port']) && ((int)$parts['port'] < 1 || (int)$parts['port'] > 65535))
        ) {
            throw new \RuntimeException((string)__('OSS 公共域名配置无效。'));
        }
        return rtrim($url, '/');
    }

    private function validHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        return strlen($host) <= 253
            && preg_match(
                '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i',
                $host,
            ) === 1;
    }

    private function assertObject(StorageObjectReference $object): void
    {
        if ($object->diskCode !== $this->snapshot->diskCode) {
            throw new \InvalidArgumentException((string)__('存储 URL 适配器收到了其他磁盘的对象。'));
        }
    }

    private function encodedKey(string $objectKey): string
    {
        return implode('/', array_map('rawurlencode', explode('/', str_replace('\\', '/', $objectKey))));
    }
}
