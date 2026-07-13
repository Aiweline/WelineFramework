<?php

declare(strict_types=1);

namespace Weline\Widget\Api;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Widget\Service\WidgetRegistry;

final class WidgetRegistryInterfaceFactory implements FactoryObjectInterface
{
    public function create(): WidgetRegistryInterface
    {
        return ObjectManager::getInstance(WidgetRegistry::class);
    }
}
