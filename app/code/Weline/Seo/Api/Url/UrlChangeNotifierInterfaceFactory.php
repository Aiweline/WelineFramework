<?php

declare(strict_types=1);

namespace Weline\Seo\Api\Url;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Service\SeoUrlChangeService;

final class UrlChangeNotifierInterfaceFactory implements FactoryObjectInterface
{
    public function create(): UrlChangeNotifierInterface
    {
        return ObjectManager::getInstance(SeoUrlChangeService::class);
    }
}
