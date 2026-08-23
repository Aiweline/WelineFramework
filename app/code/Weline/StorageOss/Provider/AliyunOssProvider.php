<?php

declare(strict_types=1);

namespace Weline\StorageOss\Provider;

use Weline\Framework\Manager\ObjectManager;
use Weline\Storage\Api\Data\StorageConfigSnapshot;
use Weline\Storage\Api\Data\StorageConfigField;
use Weline\Storage\Api\Data\StorageObjectReference;
use Weline\Storage\Api\Runtime\StorageRequestResourceFactoryInterface;
use Weline\Storage\Api\Runtime\StorageRequestResourceRegistryInterface;
use Weline\Storage\Api\StorageDriverConfigurationProviderInterface;
use Weline\Storage\Api\StorageDriverInterface;
use Weline\Storage\Api\StorageUrlAdapterInterface;
use Weline\Storage\Api\StorageWriteHandle;
use Weline\StorageOss\Driver\AliyunOssDriver;
use Weline\StorageOss\Service\AliyunOssClientFactory;
use Weline\StorageOss\Service\OssMultipartCleanupRecorder;
use Weline\StorageOss\Url\AliyunOssUrlAdapter;

final class AliyunOssProvider implements StorageDriverConfigurationProviderInterface
{
    public function providerCode(): string
    {
        return 'oss::aliyun';
    }

    public function displayName(): string
    {
        return (string)__('阿里云 OSS');
    }

    public function configurationFields(): array
    {
        return [
            new StorageConfigField('access_key_id', (string)__('AccessKey ID'), required: true),
            new StorageConfigField(
                'access_key_secret',
                (string)__('AccessKey Secret'),
                StorageConfigField::TYPE_PASSWORD,
                true,
                secret: true,
            ),
            new StorageConfigField(
                'endpoint',
                (string)__('Endpoint'),
                required: true,
                placeholder: 'oss-cn-hangzhou.aliyuncs.com',
                span: 4,
            ),
            new StorageConfigField('bucket', (string)__('Bucket'), required: true, span: 4),
            new StorageConfigField('prefix', (string)__('前缀路径'), placeholder: 'uploads', span: 4),
            new StorageConfigField(
                'visibility',
                (string)__('磁盘可见性'),
                StorageConfigField::TYPE_SELECT,
                true,
                defaultValue: 'public',
                options: [
                    'public' => (string)__('公开'),
                    'private' => (string)__('私有'),
                ],
                span: 4,
            ),
            new StorageConfigField(
                'cdn_domain',
                (string)__('CDN / 公开域名'),
                placeholder: 'https://media.example.com',
                span: 8,
            ),
            new StorageConfigField(
                'is_cname',
                (string)__('Endpoint 为 CNAME'),
                StorageConfigField::TYPE_CHECKBOX,
                defaultValue: false,
                span: 4,
            ),
            new StorageConfigField(
                'use_ssl',
                (string)__('使用 HTTPS'),
                StorageConfigField::TYPE_CHECKBOX,
                defaultValue: true,
            ),
            new StorageConfigField(
                'max_object_bytes',
                (string)__('单对象字节上限'),
                StorageConfigField::TYPE_NUMBER,
                defaultValue: StorageWriteHandle::DEFAULT_MAX_TOTAL_BYTES,
                span: 4,
            ),
        ];
    }

    public function normalizeConfiguration(array $input, array $previous = []): array
    {
        $accessKeyId = $this->text($input['access_key_id'] ?? $previous['access_key_id'] ?? '', 128);
        $accessKeySecret = $this->text(
            ($input['access_key_secret'] ?? '') !== ''
                ? $input['access_key_secret']
                : ($previous['access_key_secret'] ?? ''),
            2048,
            false,
        );
        if ($accessKeyId === '' || $accessKeySecret === '') {
            throw new \InvalidArgumentException((string)__('阿里云 OSS 凭据未完整配置。'));
        }

        $endpoint = $this->text(
            $input['endpoint'] ?? $previous['endpoint'] ?? 'oss-cn-hangzhou.aliyuncs.com',
            512,
        );
        $this->assertEndpoint($endpoint);
        $bucket = strtolower($this->text($input['bucket'] ?? $previous['bucket'] ?? '', 63));
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])$/D', $bucket) !== 1) {
            throw new \InvalidArgumentException((string)__('阿里云 OSS Bucket 格式无效。'));
        }
        $prefix = trim(str_replace('\\', '/', $this->text(
            $input['prefix'] ?? $previous['prefix'] ?? '',
            768,
        )), '/');
        if ($prefix !== '') {
            StorageObjectReference::assertObjectKey($prefix);
        }
        $visibility = strtolower($this->text($input['visibility'] ?? $previous['visibility'] ?? 'public', 16));
        if (!in_array($visibility, ['public', 'private'], true)) {
            throw new \InvalidArgumentException((string)__('存储可见性无效'));
        }
        $cdnDomain = $this->text($input['cdn_domain'] ?? $previous['cdn_domain'] ?? '', 2048);
        $publicBaseUrl = $this->text($previous['public_base_url'] ?? '', 2048);
        if ($cdnDomain !== '') {
            $this->assertPublicBaseUrl($cdnDomain);
        }
        if ($publicBaseUrl !== '') {
            $this->assertPublicBaseUrl($publicBaseUrl);
        }

        return [
            'access_key_id' => $accessKeyId,
            'access_key_secret' => $accessKeySecret,
            'endpoint' => $endpoint,
            'bucket' => $bucket,
            'prefix' => $prefix,
            'use_ssl' => $this->boolean($input, 'use_ssl', $previous, true),
            'visibility' => $visibility,
            'cdn_domain' => $cdnDomain,
            'public_base_url' => $publicBaseUrl,
            'is_cname' => $this->boolean($input, 'is_cname', $previous, false),
            'security_token' => $this->text($previous['security_token'] ?? '', 8192, false),
            'connect_timeout_seconds' => $this->boundedInt($previous['connect_timeout_seconds'] ?? 5, 1, 120),
            'request_timeout_seconds' => $this->boundedInt($previous['request_timeout_seconds'] ?? 30, 1, 120),
            'max_list_pages' => $this->boundedInt($previous['max_list_pages'] ?? 20, 1, 100),
            'max_list_items' => $this->boundedInt($previous['max_list_items'] ?? 20000, 1, 100000),
            'multipart_threshold_bytes' => $this->boundedInt(
                $previous['multipart_threshold_bytes'] ?? 8 * 1024 * 1024,
                5 * 1024 * 1024,
                5 * 1024 * 1024 * 1024,
            ),
            'multipart_part_bytes' => $this->boundedInt(
                $previous['multipart_part_bytes'] ?? 8 * 1024 * 1024,
                100 * 1024,
                5 * 1024 * 1024 * 1024,
            ),
            'max_retries' => $this->boundedInt($previous['max_retries'] ?? 1, 0, 2),
            'max_object_bytes' => $this->boundedInt(
                $input['max_object_bytes']
                    ?? $previous['max_object_bytes']
                    ?? StorageWriteHandle::DEFAULT_MAX_TOTAL_BYTES,
                1,
                StorageWriteHandle::MAX_TOTAL_BYTES,
            ),
        ];
    }

    public function secretConfigurationKeys(): array
    {
        return ['access_key_secret', 'security_token'];
    }

    public function objectNamespaceFingerprint(array $config): string
    {
        $normalized = $this->normalizeConfiguration([], $config);
        $endpoint = preg_match('#^https?://#i', $normalized['endpoint']) === 1
            ? $normalized['endpoint']
            : 'https://' . $normalized['endpoint'];
        $parts = parse_url($endpoint);
        $host = strtolower((string)($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        return hash('sha256', implode("\0", [
            $this->providerCode(),
            $host . $port,
            $normalized['bucket'],
            $normalized['prefix'],
            $normalized['visibility'],
            $normalized['is_cname'] ? 'cname' : 'bucket_host',
        ]));
    }

    public function createDriver(
        StorageConfigSnapshot $snapshot,
        StorageRequestResourceRegistryInterface $resources,
    ): StorageDriverInterface {
        // Provider instances may be discovered during process warmup. Resolve
        // request-owned collaborators only at disk creation time, inside the
        // active Request/Fiber scope, so the compiled provider registry never
        // retains a resource registry, SDK lease, ORM model, or cleanup state.
        $resourceFactory = ObjectManager::getInstance(StorageRequestResourceFactoryInterface::class);
        $multipartCleanup = ObjectManager::getInstance(OssMultipartCleanupRecorder::class);
        return new AliyunOssDriver(
            $snapshot,
            new AliyunOssClientFactory($snapshot, $resourceFactory),
            $resources,
            $resourceFactory,
            $multipartCleanup,
        );
    }

    public function createUrlAdapter(
        StorageConfigSnapshot $snapshot,
        StorageRequestResourceRegistryInterface $resources,
    ): StorageUrlAdapterInterface {
        $resourceFactory = ObjectManager::getInstance(StorageRequestResourceFactoryInterface::class);
        return new AliyunOssUrlAdapter(
            $snapshot,
            new AliyunOssClientFactory($snapshot, $resourceFactory),
        );
    }

    private function text(mixed $value, int $maxBytes, bool $trim = true): string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new \InvalidArgumentException((string)__('阿里云 OSS 配置值无效。'));
        }
        $value = (string)$value;
        $value = $trim ? trim($value) : $value;
        if (
            strlen($value) > $maxBytes
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new \InvalidArgumentException((string)__('阿里云 OSS 配置值无效。'));
        }
        return $value;
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $previous */
    private function boolean(array $input, string $key, array $previous, bool $default): bool
    {
        $value = array_key_exists($key, $input) ? $input[$key] : ($previous[$key] ?? $default);
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && in_array($value, [0, 1], true)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'off', 'no', ''], true)) {
                return false;
            }
        }
        throw new \InvalidArgumentException((string)__('阿里云 OSS 布尔配置值无效。'));
    }

    private function boundedInt(mixed $value, int $minimum, int $maximum): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException((string)__('阿里云 OSS 数值配置无效。'));
        }
        $value = (int)$value;
        if ($value < $minimum || $value > $maximum) {
            throw new \InvalidArgumentException((string)__('阿里云 OSS 数值配置超出范围。'));
        }
        return $value;
    }

    private function assertEndpoint(string $endpoint): void
    {
        $url = preg_match('#^https?://#i', $endpoint) === 1 ? $endpoint : 'https://' . $endpoint;
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || !isset($parts['host'])
            || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !in_array((string)($parts['path'] ?? ''), ['', '/'], true)
            || !$this->validHost((string)$parts['host'])
            || (isset($parts['port']) && ((int)$parts['port'] < 1 || (int)$parts['port'] > 65535))
        ) {
            throw new \InvalidArgumentException((string)__('阿里云 OSS Endpoint 格式无效。'));
        }
    }

    private function assertPublicBaseUrl(string $baseUrl): void
    {
        $url = preg_match('#^https?://#i', $baseUrl) === 1 ? $baseUrl : 'https://' . $baseUrl;
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
            throw new \InvalidArgumentException((string)__('OSS 公共域名配置无效。'));
        }
    }

    private function validHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        return strlen($host) <= 253
            && preg_match(
                '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/iD',
                $host,
            ) === 1;
    }
}
