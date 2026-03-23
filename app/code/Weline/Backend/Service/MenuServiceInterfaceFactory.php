<?php
declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 将 MenuServiceInterface 映射到 MenuService 实现。
 */
class MenuServiceInterfaceFactory implements FactoryObjectInterface
{
    public function create(): MenuServiceInterface
    {
        return ObjectManager::getInstance(MenuService::class);
    }
}
