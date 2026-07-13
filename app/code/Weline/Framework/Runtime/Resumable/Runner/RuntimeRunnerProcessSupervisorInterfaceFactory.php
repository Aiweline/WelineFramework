<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable\Runner;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class RuntimeRunnerProcessSupervisorInterfaceFactory implements FactoryObjectInterface
{
    public function create(): RuntimeRunnerProcessSupervisorInterface
    {
        $supervisor = ObjectManager::getInstance(RuntimeRunnerProcessSupervisor::class);
        if (!$supervisor instanceof RuntimeRunnerProcessSupervisorInterface) {
            throw new \RuntimeException('Configured Runtime Runner process supervisor violates its contract.');
        }
        return $supervisor;
    }
}
