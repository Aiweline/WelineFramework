<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Authorization;

use Weline\Acl\Service\AclService;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class AuthorizationServiceInterfaceFactory implements FactoryObjectInterface
{
    public function create(): AuthorizationServiceInterface
    {
        return ObjectManager::getInstance(AclService::class);
    }
}
