<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Resource;

use Weline\Acl\Service\MenuRegistry;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class MenuRegistryInterfaceFactory implements FactoryObjectInterface
{
    public function create(): MenuRegistryInterface
    {
        return ObjectManager::getInstance(MenuRegistry::class);
    }
}
