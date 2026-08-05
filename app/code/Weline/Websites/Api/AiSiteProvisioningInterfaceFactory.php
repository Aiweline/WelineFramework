<?php

declare(strict_types=1);

namespace Weline\Websites\Api;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\AiSiteProvisioningService;

class AiSiteProvisioningInterfaceFactory implements FactoryObjectInterface
{
    public function create(): AiSiteProvisioningInterface
    {
        return ObjectManager::getInstance(AiSiteProvisioningService::class);
    }
}
