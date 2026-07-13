<?php

declare(strict_types=1);

namespace Weline\Database\Api;

use Weline\Database\Service\ModuleRollbackManager;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class ModuleRollbackManagerInterfaceFactory implements FactoryObjectInterface
{
    public function create(): ModuleRollbackManagerInterface
    {
        return ObjectManager::getInstance(ModuleRollbackManager::class);
    }
}
