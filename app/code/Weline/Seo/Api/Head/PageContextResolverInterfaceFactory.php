<?php

declare(strict_types=1);

namespace Weline\Seo\Api\Head;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class PageContextResolverInterfaceFactory implements FactoryObjectInterface
{
    public function create(): PageContextResolverInterface
    {
        return ObjectManager::getInstance(PageContextResolver::class);
    }
}
