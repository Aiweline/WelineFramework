<?php

declare(strict_types=1);

namespace Weline\Theme\Api\View;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\ComponentMetaReader;

final class ComponentMetaReaderInterfaceFactory implements FactoryObjectInterface
{
    public function create(): ComponentMetaReaderInterface
    {
        return ObjectManager::getInstance(ComponentMetaReader::class);
    }
}
