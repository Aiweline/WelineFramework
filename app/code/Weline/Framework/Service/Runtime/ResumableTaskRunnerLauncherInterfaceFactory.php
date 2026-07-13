<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Runtime;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;

final class ResumableTaskRunnerLauncherInterfaceFactory implements FactoryObjectInterface
{
    public function create(): ResumableTaskRunnerLauncherInterface
    {
        $launcher = ObjectManager::getInstance(ResumableTaskRunnerLauncher::class);
        if (!$launcher instanceof ResumableTaskRunnerLauncherInterface) {
            throw new \RuntimeException('Configured resumable task Runner launcher violates its contract.');
        }
        return $launcher;
    }
}
