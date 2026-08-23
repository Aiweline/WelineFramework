<?php

declare(strict_types=1);

namespace Weline\StorageOss\Service;

use Weline\Storage\Api\Data\StorageConfigSnapshot;
use Weline\Storage\Api\Data\StorageObjectReference;
use Weline\Storage\Api\Runtime\StorageClientLeaseInterface;
use Weline\Storage\Api\Runtime\StorageRequestResourceFactoryInterface;

/** Request-scoped factory. It must never be retained by a process-scoped provider. */
final class AliyunOssClientFactory
{
    private ?StorageClientLeaseInterface $lease = null;
    private ?bool $forbidOverwriteSupported = null;

    public function __construct(
        private readonly StorageConfigSnapshot $snapshot,
        private readonly StorageRequestResourceFactoryInterface $resources,
    ) {
    }

    public function client(): object
    {
        if ($this->lease !== null && !$this->lease->isClosed()) {
            return $this->lease->client();
        }
        if (!class_exists(\OSS\OssClient::class)) {
            throw new \RuntimeException((string)__('阿里云 OSS SDK 未安装。'));
        }

        $config = $this->config();
        $accessKeyId = trim((string)($config['access_key_id'] ?? ''));
        $accessKeySecret = (string)($config['access_key_secret'] ?? '');
        $endpoint = $this->endpoint();
        if ($accessKeyId === '' || $accessKeySecret === '' || $endpoint === '') {
            throw new \RuntimeException((string)__('阿里云 OSS 凭据或 Endpoint 未完整配置。'));
        }

        try {
            $explicitHttps = str_starts_with(strtolower($endpoint), 'https://');
            $explicitHttp = str_starts_with(strtolower($endpoint), 'http://');
            $client = new \OSS\OssClient(
                $accessKeyId,
                $accessKeySecret,
                $this->endpointWithoutScheme(),
                (bool)($config['is_cname'] ?? false),
                ($config['security_token'] ?? '') !== '' ? (string)$config['security_token'] : null,
            );
            if (method_exists($client, 'setUseSSL')) {
                $client->setUseSSL($explicitHttps || (!$explicitHttp && (bool)($config['use_ssl'] ?? true)));
            }
            if (method_exists($client, 'setConnectTimeout')) {
                $client->setConnectTimeout($this->boundedTimeout($config['connect_timeout_seconds'] ?? 5));
            }
            if (method_exists($client, 'setTimeout')) {
                $client->setTimeout($this->boundedTimeout($config['request_timeout_seconds'] ?? 30));
            }
            if (method_exists($client, 'setMaxTries')) {
                $client->setMaxTries(max(0, min(2, (int)($config['max_retries'] ?? 1))));
            }
        } catch (\Throwable) {
            throw new \RuntimeException((string)__('初始化阿里云 OSS Client 失败。'));
        }

        $this->lease = $this->resources->clientLease($client);
        return $client;
    }

    public function bucket(): string
    {
        $bucket = trim((string)($this->config()['bucket'] ?? ''));
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])$/', $bucket) !== 1) {
            throw new \RuntimeException((string)__('阿里云 OSS Bucket 格式无效。'));
        }
        return $bucket;
    }

    public function endpoint(): string
    {
        $endpoint = trim((string)($this->config()['endpoint'] ?? ''));
        if ($endpoint === '') {
            throw new \RuntimeException((string)__('阿里云 OSS Endpoint 未配置。'));
        }
        $candidate = preg_match('#^https?://#i', $endpoint) === 1 ? $endpoint : 'https://' . $endpoint;
        $parts = parse_url($candidate);
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
            throw new \RuntimeException((string)__('阿里云 OSS Endpoint 格式无效。'));
        }
        return rtrim($endpoint, '/');
    }

    public function endpointHost(): string
    {
        $endpoint = $this->endpoint();
        $candidate = preg_match('#^https?://#i', $endpoint) === 1 ? $endpoint : 'https://' . $endpoint;
        $parts = parse_url($candidate);
        if (!is_array($parts) || !isset($parts['host'])) {
            throw new \RuntimeException((string)__('阿里云 OSS Endpoint 格式无效。'));
        }
        $host = (string)$parts['host'];
        $host = str_contains($host, ':') ? '[' . $host . ']' : $host;
        return $host . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
    }

    /** @return array<string,mixed> */
    public function config(): array
    {
        return $this->snapshot->driverConfig();
    }

    public function prefixedKey(string $objectKey): string
    {
        StorageObjectReference::assertObjectKey($objectKey);
        $prefix = $this->prefix();
        $key = $prefix === '' ? $objectKey : $prefix . '/' . $objectKey;
        if (strlen($key) > 1023) {
            throw new \InvalidArgumentException((string)__('阿里云 OSS 对象键超过长度限制。'));
        }
        return $key;
    }

    public function prefixedDirectoryKey(string $objectKey): string
    {
        return $this->prefixedKey(rtrim($objectKey, '/')) . '/';
    }

    public function relativeKey(string $providerKey): ?string
    {
        $providerKey = ltrim(str_replace('\\', '/', $providerKey), '/');
        $prefix = $this->prefix();
        if ($prefix === '') {
            return $providerKey;
        }
        if ($providerKey === $prefix) {
            return '';
        }
        $needle = $prefix . '/';
        return str_starts_with($providerKey, $needle) ? substr($providerKey, strlen($needle)) : null;
    }

    public function signedReadUrl(string $objectKey, int $ttlSeconds = 300): string
    {
        try {
            return (string)$this->client()->signUrl(
                $this->bucket(),
                $this->prefixedKey($objectKey),
                max(30, min(3600, $ttlSeconds)),
                'GET',
            );
        } catch (\Throwable) {
            throw new \RuntimeException((string)__('创建 OSS 读取流失败。'));
        }
    }

    public function requestTimeoutSeconds(): int
    {
        return $this->boundedTimeout($this->config()['request_timeout_seconds'] ?? 30);
    }

    /**
     * OSS ignores x-oss-forbid-overwrite when bucket versioning is enabled or suspended.
     * Verify the bucket capability once per request before promising no-overwrite semantics.
     */
    public function assertForbidOverwriteSupported(): void
    {
        if ($this->forbidOverwriteSupported === true) {
            return;
        }
        if ($this->forbidOverwriteSupported === false) {
            throw new \RuntimeException((string)__('启用版本控制的 OSS Bucket 不支持可靠的禁止覆盖语义。'));
        }

        try {
            $client = $this->client();
            if (!method_exists($client, 'getBucketVersioning')) {
                throw new \RuntimeException('getBucketVersioning unavailable');
            }
            $versioning = $client->getBucketVersioning($this->bucket());
            if (is_object($versioning) && method_exists($versioning, 'getStatus')) {
                $versioning = $versioning->getStatus();
            }
            if ($versioning !== null && !is_string($versioning)) {
                throw new \RuntimeException('getBucketVersioning returned an unsupported value');
            }
            $status = strtolower(trim((string)$versioning));
            if (!in_array($status, ['', 'enabled', 'suspended'], true)) {
                throw new \RuntimeException('getBucketVersioning returned an unknown status');
            }
        } catch (\Throwable) {
            throw new \RuntimeException((string)__('无法验证 OSS Bucket 的禁止覆盖能力。'));
        }

        $this->forbidOverwriteSupported = !in_array($status, ['enabled', 'suspended'], true);
        if (!$this->forbidOverwriteSupported) {
            throw new \RuntimeException((string)__('启用版本控制的 OSS Bucket 不支持可靠的禁止覆盖语义。'));
        }
    }

    private function endpointWithoutScheme(): string
    {
        return $this->endpointHost();
    }

    private function boundedTimeout(mixed $value): int
    {
        return max(1, min(120, (int)$value));
    }

    private function prefix(): string
    {
        $prefix = trim(str_replace('\\', '/', (string)($this->config()['prefix'] ?? '')), '/');
        if ($prefix === '') {
            return '';
        }
        if (strlen($prefix) > 768) {
            throw new \InvalidArgumentException((string)__('阿里云 OSS Prefix 超过长度限制。'));
        }
        StorageObjectReference::assertObjectKey($prefix);
        return $prefix;
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
}
