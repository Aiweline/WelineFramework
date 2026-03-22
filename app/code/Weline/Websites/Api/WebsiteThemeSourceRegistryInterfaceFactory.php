<?php

declare(strict_types=1);

namespace Weline\Websites\Api;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\AiWorkbench\ThemeSourceRegistry;

class WebsiteThemeSourceRegistryInterfaceFactory implements FactoryObjectInterface
{
    public function create(): WebsiteThemeSourceRegistryInterface
    {
        return ObjectManager::getInstance(ThemeSourceRegistry::class);
    }
}
