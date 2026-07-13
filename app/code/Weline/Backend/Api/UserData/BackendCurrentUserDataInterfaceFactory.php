<?php

declare(strict_types=1);

namespace Weline\Backend\Api\UserData;

use Weline\Backend\Service\BackendCurrentUserData;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class BackendCurrentUserDataInterfaceFactory implements FactoryObjectInterface
{
    public function create(): BackendCurrentUserDataInterface
    {
        return ObjectManager::getInstance(BackendCurrentUserData::class);
    }
}
