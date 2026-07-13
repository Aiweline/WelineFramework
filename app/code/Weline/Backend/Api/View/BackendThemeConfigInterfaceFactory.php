<?php

declare(strict_types=1);

namespace Weline\Backend\Api\View;

use Weline\Backend\Block\ThemeConfig;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class BackendThemeConfigInterfaceFactory implements FactoryObjectInterface
{
    public function create(): BackendThemeConfigInterface
    {
        return ObjectManager::getInstance(ThemeConfig::class);
    }
}
