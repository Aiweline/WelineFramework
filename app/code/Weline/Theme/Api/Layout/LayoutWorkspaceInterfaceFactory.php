<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Layout;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\LayoutWorkspace;

final class LayoutWorkspaceInterfaceFactory implements FactoryObjectInterface
{
    public function create(): LayoutWorkspaceInterface
    {
        return ObjectManager::getInstance(LayoutWorkspace::class);
    }
}
