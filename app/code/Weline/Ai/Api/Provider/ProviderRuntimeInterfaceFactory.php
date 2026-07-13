<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Provider;

use Weline\Ai\Service\Provider\ProviderRuntime;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class ProviderRuntimeInterfaceFactory implements FactoryObjectInterface
{
    public function create(): ProviderRuntimeInterface
    {
        return ObjectManager::getInstance(ProviderRuntime::class);
    }
}
