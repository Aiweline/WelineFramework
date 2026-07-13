<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

use Weline\Ai\Service\SecretStoreService;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class SecretStoreInterfaceFactory implements FactoryObjectInterface
{
    public function create(): SecretStoreInterface
    {
        return ObjectManager::getInstance(SecretStoreService::class);
    }
}
