<?php

declare(strict_types=1);

namespace Weline\Seo\Api\Protocol;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class WebsiteProtocolResolverInterfaceFactory implements FactoryObjectInterface
{
    public function create(): WebsiteProtocolResolverInterface
    {
        return ObjectManager::getInstance(WebsiteProtocolResolver::class);
    }
}
