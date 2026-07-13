<?php

declare(strict_types=1);

namespace Weline\ModuleRouter\Api;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\ModuleRouter\Config\ModuleRouterReader;

final class RouterRulesReaderInterfaceFactory implements FactoryObjectInterface
{
    public function create(): RouterRulesReaderInterface
    {
        return ObjectManager::getInstance(ModuleRouterReader::class);
    }
}
