<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Asset;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class StaticAssetPublisherInterfaceFactory implements FactoryObjectInterface
{
    public function create(): StaticAssetPublisherInterface
    {
        return ObjectManager::getInstance(StaticAssetPublisher::class);
    }
}
