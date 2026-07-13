<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Resource;

use Weline\Acl\Service\WhitelistService;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class WhitelistServiceInterfaceFactory implements FactoryObjectInterface
{
    public function create(): WhitelistServiceInterface
    {
        return ObjectManager::getInstance(WhitelistService::class);
    }
}
