<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable\Runner;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class RuntimeRunnerProcessLauncherInterfaceFactory implements FactoryObjectInterface
{
    public function create(): RuntimeRunnerProcessLauncherInterface
    {
        $launcher = ObjectManager::getInstance(RuntimeRunnerProcessLauncher::class);
        if (!$launcher instanceof RuntimeRunnerProcessLauncherInterface) {
            throw new \RuntimeException('Configured Runtime Runner process launcher violates its contract.');
        }
        return $launcher;
    }
}
