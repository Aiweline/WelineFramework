<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Api\Scope;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Service\ScopedConfigRepository;

final class ScopedConfigRepositoryInterfaceFactory implements FactoryObjectInterface
{
    public function create(): ScopedConfigRepositoryInterface
    {
        return ObjectManager::getInstance(ScopedConfigRepository::class);
    }
}
