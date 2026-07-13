<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Catalog;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\WebsiteCatalog;

final class WebsiteCatalogInterfaceFactory implements FactoryObjectInterface
{
    public function create(): WebsiteCatalogInterface
    {
        return ObjectManager::getInstance(WebsiteCatalog::class);
    }
}
