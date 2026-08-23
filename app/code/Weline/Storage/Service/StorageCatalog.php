<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Storage\Api\StorageCatalogInterface;
use Weline\Storage\Api\StorageManagerInterface;

final class StorageCatalog implements StorageCatalogInterface
{
    public function __construct(private readonly StorageManagerInterface $storageManager)
    {
    }

    public function all(?ScopeIdentity $scope = null): array
    {
        $list = $this->storageManager->catalog();
        $out = [];
        foreach ($list as $item) {
            $item['driver'] = (string)($item['provider_code'] ?? 'unknown');
            $item['is_default'] = (bool)($item['is_default'] ?? false);
            $item['info'] = [
                'display_name' => (string)($item['display_name'] ?? $item['disk_code'] ?? ''),
                'config_revision' => (int)($item['config_revision'] ?? 1),
            ];
            $out[] = $item;
        }

        return $out;
    }

}
