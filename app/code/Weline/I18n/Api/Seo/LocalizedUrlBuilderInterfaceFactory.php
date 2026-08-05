<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Seo;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\Seo\LocalizedUrlBuilder;

final class LocalizedUrlBuilderInterfaceFactory implements FactoryObjectInterface
{
    public function create(): LocalizedUrlBuilderInterface
    {
        return ObjectManager::getInstance(LocalizedUrlBuilder::class);
    }
}
