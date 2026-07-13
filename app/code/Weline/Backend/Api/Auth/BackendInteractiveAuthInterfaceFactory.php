<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Auth;

use Weline\Backend\Service\BackendInteractiveAuth;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class BackendInteractiveAuthInterfaceFactory implements FactoryObjectInterface
{
    public function create(): BackendInteractiveAuthInterface
    {
        return ObjectManager::getInstance(BackendInteractiveAuth::class);
    }
}
