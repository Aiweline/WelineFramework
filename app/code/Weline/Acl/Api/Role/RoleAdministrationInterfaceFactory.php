<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Role;

use Weline\Acl\Service\RoleAdministration;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class RoleAdministrationInterfaceFactory implements FactoryObjectInterface
{
    public function create(): RoleAdministrationInterface
    {
        return ObjectManager::getInstance(RoleAdministration::class);
    }
}
