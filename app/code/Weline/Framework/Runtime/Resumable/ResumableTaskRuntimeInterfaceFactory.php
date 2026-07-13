<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Runtime\ResumableTaskRuntime;

/** ObjectManager bridge for the public resumable runtime contract. */
final class ResumableTaskRuntimeInterfaceFactory implements FactoryObjectInterface
{
    public function create(): ResumableTaskRuntimeInterface
    {
        $runtime = ObjectManager::getInstance(ResumableTaskRuntime::class);
        if (!$runtime instanceof ResumableTaskRuntimeInterface) {
            throw new \RuntimeException('Configured resumable task runtime violates its contract.');
        }
        return $runtime;
    }
}
