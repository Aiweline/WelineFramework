<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Localization;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class LocaleCatalogInterfaceFactory implements FactoryObjectInterface
{
    public function create(): LocaleCatalogInterface
    {
        return ObjectManager::getInstance(InstalledLocaleCatalog::class);
    }
}
