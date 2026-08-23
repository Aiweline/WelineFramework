<?php

declare(strict_types=1);

namespace Weline\Storage\Api;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Storage\Service\StorageDirectoryManager;

final class StorageDirectoryManagerInterfaceFactory implements FactoryObjectInterface
{
    public function create(): StorageDirectoryManagerInterface
    {
        return ObjectManager::getInstance(StorageDirectoryManager::class);
    }
}
