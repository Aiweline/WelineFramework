<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Resource;

use Weline\Acl\Service\MenuResourceService;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class MenuResourceServiceInterfaceFactory implements FactoryObjectInterface
{
    public function create(): MenuResourceServiceInterface
    {
        return ObjectManager::getInstance(MenuResourceService::class);
    }
}
