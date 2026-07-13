<?php

declare(strict_types=1);

namespace Weline\ModuleManager\Api;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\ModuleManager\Service\ModuleCatalog;

final class ModuleCatalogInterfaceFactory implements FactoryObjectInterface
{
    public function create(): ModuleCatalogInterface
    {
        return ObjectManager::getInstance(ModuleCatalog::class);
    }
}
