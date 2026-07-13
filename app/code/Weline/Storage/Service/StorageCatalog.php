<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

use Weline\Storage\Api\StorageCatalogInterface;

final class StorageCatalog implements StorageCatalogInterface
{
    public function __construct(private readonly StorageManager $storageManager)
    {
    }

    public function all(): array
    {
        return $this->storageManager->getStorageList();
    }
}
