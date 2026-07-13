<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Role;

use Weline\Acl\Service\RoleCatalog;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class RoleCatalogInterfaceFactory implements FactoryObjectInterface
{
    public function create(): RoleCatalogInterface
    {
        return ObjectManager::getInstance(RoleCatalog::class);
    }
}
