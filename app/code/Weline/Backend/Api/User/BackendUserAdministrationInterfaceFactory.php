<?php

declare(strict_types=1);

namespace Weline\Backend\Api\User;

use Weline\Backend\Service\BackendUserAdministration;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class BackendUserAdministrationInterfaceFactory implements FactoryObjectInterface
{
    public function create(): BackendUserAdministrationInterface
    {
        return ObjectManager::getInstance(BackendUserAdministration::class);
    }
}
