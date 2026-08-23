<?php

declare(strict_types=1);

namespace Weline\Storage\Provider;

use Weline\Storage\Api\Data\StorageConfigSnapshot;
use Weline\Storage\Api\Data\StorageConfigField;
use Weline\Storage\Api\Runtime\StorageRequestResourceRegistryInterface;
use Weline\Storage\Api\StorageDriverConfigurationProviderInterface;
use Weline\Storage\Api\StorageDriverInterface;
use Weline\Storage\Api\StorageUrlAdapterInterface;
use Weline\Storage\Api\StorageWriteHandle;
use Weline\Storage\Driver\LocalFilesystemDriver;
use Weline\Storage\Url\LocalFilesystemUrlAdapter;

final class LocalFilesystemProvider implements StorageDriverConfigurationProviderInterface
{
    public function providerCode(): string
    {
        return 'local::filesystem';
    }

    public function displayName(): string
    {
        return (string)__('本地文件系统');
    }

    public function configurationFields(): array
    {
        return [
            new StorageConfigField(
                'root_path',
                (string)__('根目录路径'),
                required: true,
                placeholder: rtrim((string)PUB, '/\\') . DIRECTORY_SEPARATOR . 'media',
            ),
            new StorageConfigField(
                'base_url',
                (string)__('基础 URL'),
                required: true,
                placeholder: '/pub/media',
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
        $rootPath = trim((string)($input['root_path'] ?? $previous['root_path'] ?? ''));
        if ($rootPath === '') {
            $rootPath = rtrim((string)PUB, '/\\') . DIRECTORY_SEPARATOR . 'media';
        }
        $isAbsolute = str_starts_with($rootPath, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/D', $rootPath) === 1;
        $isFilesystemRoot = rtrim($rootPath, '/\\') === ''
            || preg_match('/^[A-Za-z]:[\\\\\/]?$/D', $rootPath) === 1;
        if (
            !$isAbsolute
            || $isFilesystemRoot
            || strlen($rootPath) > 4096
            || preg_match('/[\x00-\x1F\x7F]/', $rootPath) === 1
        ) {
            throw new \InvalidArgumentException((string)__('本地存储根目录路径无效。'));
        }

        $baseUrl = trim((string)($input['base_url'] ?? $previous['base_url'] ?? '/pub/media'));
        // Constructor performs the canonical URL validation without network I/O.
        new LocalFilesystemUrlAdapter('local::filesystem::configuration_validation', $baseUrl);

        return [
            'root_path' => $rootPath,
            'base_url' => $baseUrl,
            'visibility' => 'public',
            'max_object_bytes' => $this->maxObjectBytes(
                $input['max_object_bytes']
                    ?? $previous['max_object_bytes']
                    ?? StorageWriteHandle::DEFAULT_MAX_TOTAL_BYTES,
            ),
        ];
    }

    public function secretConfigurationKeys(): array
    {
        return [];
    }

    public function objectNamespaceFingerprint(array $config): string
    {
        $normalized = $this->normalizeConfiguration($config, $config);
        return hash('sha256', implode("\0", [
            $this->providerCode(),
            rtrim(str_replace('\\', '/', $normalized['root_path']), '/'),
            $normalized['visibility'],
        ]));
    }

    public function createDriver(StorageConfigSnapshot $snapshot, StorageRequestResourceRegistryInterface $resources): StorageDriverInterface
    {
        $config = $snapshot->driverConfig();
        return new LocalFilesystemDriver(
            $snapshot->diskCode,
            (string)($config['root_path'] ?? rtrim(PUB, '/\\') . DIRECTORY_SEPARATOR . 'media'),
            $resources,
            (int)($config['max_object_bytes'] ?? StorageWriteHandle::DEFAULT_MAX_TOTAL_BYTES),
        );
    }

    public function createUrlAdapter(
        StorageConfigSnapshot $snapshot,
        StorageRequestResourceRegistryInterface $resources,
    ): StorageUrlAdapterInterface
    {
        $config = $snapshot->driverConfig();
        return new LocalFilesystemUrlAdapter(
            $snapshot->diskCode,
            (string)($config['base_url'] ?? '/pub/media'),
        );
    }

    private function maxObjectBytes(mixed $value): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if ($value === false || $value < 1 || $value > StorageWriteHandle::MAX_TOTAL_BYTES) {
            throw new \InvalidArgumentException((string)__('本地存储单对象字节上限无效。'));
        }
        return (int)$value;
    }
}
