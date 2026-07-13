<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Menu;

use Weline\Backend\Service\MenuService;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class MenuReaderInterfaceFactory implements FactoryObjectInterface
{
    public function create(): MenuReaderInterface
    {
        return ObjectManager::getInstance(MenuService::class);
    }
}
