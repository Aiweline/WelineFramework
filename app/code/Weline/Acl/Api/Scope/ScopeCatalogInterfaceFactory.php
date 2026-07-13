<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Scope;

use Weline\Acl\Service\ScopeCatalog;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class ScopeCatalogInterfaceFactory implements FactoryObjectInterface
{
    public function create(): ScopeCatalogInterface
    {
        return ObjectManager::getInstance(ScopeCatalog::class);
    }
}
