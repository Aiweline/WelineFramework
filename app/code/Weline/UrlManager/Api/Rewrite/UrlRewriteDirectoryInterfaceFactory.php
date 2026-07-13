<?php

declare(strict_types=1);

namespace Weline\UrlManager\Api\Rewrite;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class UrlRewriteDirectoryInterfaceFactory implements FactoryObjectInterface
{
    public function create(): UrlRewriteDirectoryInterface
    {
        return ObjectManager::getInstance(UrlRewriteDirectory::class);
    }
}
