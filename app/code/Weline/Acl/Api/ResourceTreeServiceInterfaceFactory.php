<?php

declare(strict_types=1);

namespace Weline\Acl\Api;

use Weline\Acl\Service\ResourceTreeService;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class ResourceTreeServiceInterfaceFactory implements FactoryObjectInterface
{
    public function create(): ResourceTreeServiceInterface
    {
        return ObjectManager::getInstance(ResourceTreeService::class);
    }
}
