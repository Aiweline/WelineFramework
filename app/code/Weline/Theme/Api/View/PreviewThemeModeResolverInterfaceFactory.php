<?php

declare(strict_types=1);

namespace Weline\Theme\Api\View;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\PreviewThemeModeResolver;

final class PreviewThemeModeResolverInterfaceFactory implements FactoryObjectInterface
{
    public function create(): PreviewThemeModeResolverInterface
    {
        return ObjectManager::getInstance(PreviewThemeModeResolver::class);
    }
}
