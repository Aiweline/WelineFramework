<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable\Runner;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Runtime\ResumableTaskRunnerStore;

final class RuntimeRunnerStoreInterfaceFactory implements FactoryObjectInterface
{
    public function create(): RuntimeRunnerStoreInterface
    {
        $store = ObjectManager::getInstance(ResumableTaskRunnerStore::class);
        if (!$store instanceof RuntimeRunnerStoreInterface) {
            throw new \RuntimeException('Configured Runtime Runner store violates its contract.');
        }
        return $store;
    }
}
