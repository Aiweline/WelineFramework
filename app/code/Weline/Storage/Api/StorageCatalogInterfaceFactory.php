<?php

declare(strict_types=1);

namespace Weline\Storage\Api;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Storage\Service\StorageCatalog;

final class StorageCatalogInterfaceFactory implements FactoryObjectInterface
{
    public function create(): StorageCatalogInterface
    {
        return ObjectManager::getInstance(StorageCatalog::class);
    }
}
