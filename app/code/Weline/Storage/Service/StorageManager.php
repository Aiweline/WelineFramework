<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Storage\Adapter\StorageInterfaceBridge;
use Weline\Storage\Api\Data\StorageDiskCode;
use Weline\Storage\Api\StorageInterface;
use Weline\Storage\Api\StorageManagerInterface;

/**
 * @deprecated Legacy facade. New code must depend on StorageManagerInterface.
 *
 * The facade owns no driver registry, SDK client, disk cache, or local
 * fallback. Compatibility calls therefore retain the request/Fiber resource
 * lifecycle, immutable config snapshot, and URL-adapter behavior of v2.
 */
class StorageManager
{
    public function __construct(
        private readonly ?StorageManagerInterface $manager = null,
        private readonly ?StorageConfigTester $tester = null,
        private readonly ?StorageDriverProviderRegistry $providers = null,
    ) {
    }

    public function registerDisk(string $name, string $driver, array $config = []): self
    {
        throw new \LogicException((string)__('运行时注册存储磁盘已禁用，请保存磁盘配置并推进 config_revision。'));
    }

    public function registerDriver(string $driver, string $adapterClass): self
    {
        throw new \LogicException((string)__('运行时注册存储驱动已禁用，请通过编译 Provider 注册。'));
    }

    public function disk(?string $name = null): StorageInterface
    {
        $disk = $name === null || trim($name) === ''
            ? $this->getManager()->defaultDisk()
            : $this->getManager()->disk($name);

        return new StorageInterfaceBridge($disk);
    }

    public function getDefault(): StorageInterface
    {
        return $this->disk();
    }

    public function getLocalDisk(): StorageInterface
    {
        return $this->disk(StorageDiskCode::BUILTIN_LOCAL_MEDIA);
    }

    /** @return array<string,StorageInterface> */
    public function getDisks(): array
    {
        $result = [];
        foreach ($this->getManager()->catalog() as $item) {
            $code = trim((string)($item['disk_code'] ?? ''));
            if ($code !== '') {
                $result[$code] = $this->disk($code);
            }
        }
        return $result;
    }

    /** @return array<string,class-string> */
    public function getDrivers(): array
    {
        $result = [];
        foreach ($this->getProviders()->all() as $code => $provider) {
            $result[$code] = $provider::class;
        }
        return $result;
    }

    public function getDefaultDiskName(): ?string
    {
        return $this->getManager()->defaultDisk()->diskCode();
    }

    public function hasDisk(string $name): bool
    {
        try {
            $this->getManager()->disk($name);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function testConfig(string $driver, array $config): bool
    {
        return $this->getTester()->test($driver, 'connection_test', $config);
    }

    /** @return list<array<string,mixed>> */
    public function getStorageList(): array
    {
        return array_map(
            static fn(array $item): array => [
                'name' => (string)($item['disk_code'] ?? ''),
                'driver' => (string)($item['provider_code'] ?? ''),
                'is_default' => (bool)($item['is_default'] ?? false),
                'info' => $item,
            ],
            $this->getManager()->catalog(),
        );
    }

    public function reload(): void
    {
        $manager = $this->getManager();
        if ($manager instanceof StorageManagerV2) {
            $manager->resetRequestState();
        }
    }

    private function getManager(): StorageManagerInterface
    {
        return $this->manager ?? ObjectManager::getInstance(StorageManagerInterface::class);
    }

    private function getTester(): StorageConfigTester
    {
        return $this->tester ?? ObjectManager::getInstance(StorageConfigTester::class);
    }

    private function getProviders(): StorageDriverProviderRegistry
    {
        return $this->providers ?? ObjectManager::getInstance(StorageDriverProviderRegistry::class);
    }
}
