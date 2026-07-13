<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Auth;

use Weline\Backend\Service\BackendUserContextProvider;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class BackendUserContextProviderInterfaceFactory implements FactoryObjectInterface
{
    public function create(): BackendUserContextProviderInterface
    {
        return ObjectManager::getInstance(BackendUserContextProvider::class);
    }
}
