<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Localization;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\CurrentWebsiteCurrencyCatalog;

final class WebsiteCurrencyCatalogInterfaceFactory implements FactoryObjectInterface
{
    public function create(): WebsiteCurrencyCatalogInterface
    {
        return ObjectManager::getInstance(CurrentWebsiteCurrencyCatalog::class);
    }
}
