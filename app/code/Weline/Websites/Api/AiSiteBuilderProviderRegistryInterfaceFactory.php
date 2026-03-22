<?php

declare(strict_types=1);

namespace Weline\Websites\Api;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\AiWorkbench\ProviderRegistry;

class AiSiteBuilderProviderRegistryInterfaceFactory implements FactoryObjectInterface
{
    public function create(): AiSiteBuilderProviderRegistryInterface
    {
        return ObjectManager::getInstance(ProviderRegistry::class);
    }
}
