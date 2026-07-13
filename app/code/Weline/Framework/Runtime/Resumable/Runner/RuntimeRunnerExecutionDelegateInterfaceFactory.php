<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable\Runner;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Runtime\ResumableTaskRunnerDelegate;

final class RuntimeRunnerExecutionDelegateInterfaceFactory implements FactoryObjectInterface
{
    public function create(): RuntimeRunnerExecutionDelegateInterface
    {
        $delegate = ObjectManager::getInstance(ResumableTaskRunnerDelegate::class);
        if (!$delegate instanceof RuntimeRunnerExecutionDelegateInterface) {
            throw new \RuntimeException('Configured Runtime Runner delegate violates its contract.');
        }
        return $delegate;
    }
}
