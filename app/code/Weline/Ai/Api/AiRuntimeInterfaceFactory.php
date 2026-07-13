<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class AiRuntimeInterfaceFactory implements FactoryObjectInterface
{
    public function create(): AiRuntimeInterface
    {
        return ObjectManager::getInstance(AiRuntime::class);
    }
}
