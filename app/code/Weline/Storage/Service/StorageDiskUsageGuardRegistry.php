<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Storage\Api\StorageDiskUsageGuardInterface;

final class StorageDiskUsageGuardRegistry
{
    public const CAPABILITY_PREFIX = 'storage.disk_usage_guard.';
    private const MAX_GUARDS = 64;

    /** @var list<class-string>|null */
    private ?array $implementations = null;

    public function __construct(private readonly ?ServiceProviderRegistry $providers = null)
    {
    }

    public function assertCanDelete(string $diskCode): void
    {
        foreach ($this->implementations() as $implementation) {
            $guard = ObjectManager::getInstance($implementation);
            if (!$guard instanceof StorageDiskUsageGuardInterface) {
                throw new \RuntimeException($implementation . ' must implement ' . StorageDiskUsageGuardInterface::class);
            }
            $guard->assertCanDelete($diskCode);
        }
    }

    /** @return list<class-string> */
    private function implementations(): array
    {
        if ($this->implementations !== null) {
            return $this->implementations;
        }
        $registry = $this->providers ?? ObjectManager::getInstance(ServiceProviderRegistry::class);
        $items = $registry->implementationsWithPrefix(self::CAPABILITY_PREFIX);
        if (count($items) > self::MAX_GUARDS) {
            throw new \RuntimeException((string)__('存储磁盘占用保护器数量超过上限。'));
        }
        sort($items, SORT_STRING);

        return $this->implementations = array_values(array_unique($items));
    }
}
