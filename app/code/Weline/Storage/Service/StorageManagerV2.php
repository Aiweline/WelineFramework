<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

use Weline\Storage\Api\Runtime\StorageRequestResourceRegistryInterface;
use Weline\Storage\Api\StorageDiskInterface;
use Weline\Storage\Api\StorageManagerInterface;

final class StorageManagerV2 implements StorageManagerInterface
{
    private const MAX_REQUEST_DISKS = 128;
    /** @var array<string,StorageDiskInterface> */
    private array $requestDisks = [];

    public function __construct(
        private readonly StorageConfigRepository $configs,
        private readonly StorageDriverProviderRegistry $providers,
        private readonly StorageRequestResourceRegistryInterface $resources,
    ) {
    }

    public function disk(string $diskCode): StorageDiskInterface
    {
        $canonical = $this->configs->canonicalize($diskCode);
        if (isset($this->requestDisks[$canonical])) {
            return $this->requestDisks[$canonical];
        }
        if (count($this->requestDisks) >= self::MAX_REQUEST_DISKS) {
            throw new \RuntimeException((string)__('单个请求打开的存储磁盘数超过上限。'));
        }
        $snapshot = $this->configs->snapshot($diskCode);
        $provider = $this->providers->get($snapshot->code()->providerCode());
        $disk = new StorageDisk(
            $snapshot,
            $provider->createDriver($snapshot, $this->resources),
            $provider->createUrlAdapter($snapshot, $this->resources),
        );
        $this->requestDisks[$canonical] = $disk;
        StorageRuntimeDiagnostics::diskCached();
        return $disk;
    }

    public function defaultDisk(): StorageDiskInterface
    {
        return $this->disk($this->configs->defaultDiskCode());
    }

    public function canonicalizeDiskCode(string $diskCode): string
    {
        return $this->configs->canonicalize($diskCode);
    }

    public function catalog(): array
    {
        return $this->configs->catalog();
    }

    public function resetRequestState(): void
    {
        StorageRuntimeDiagnostics::diskCacheReleased(count($this->requestDisks));
        $this->requestDisks = [];
        $this->configs->resetRequestState();
    }
}
