<?php

declare(strict_types=1);

namespace Weline\Websites\Api;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class WebsiteTargetLookupInterfaceFactory implements FactoryObjectInterface
{
    public function create(): WebsiteTargetLookupInterface
    {
        return ObjectManager::getInstance(WebsiteTargetLookup::class);
    }
}
