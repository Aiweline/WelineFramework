<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Runtime\ResumableTaskRuntime;

/** ObjectManager bridge for the trusted system Runtime boundary. */
final class ResumableSystemTaskRuntimeInterfaceFactory implements FactoryObjectInterface
{
    public function create(): ResumableSystemTaskRuntimeInterface
    {
        $runtime = ObjectManager::getInstance(ResumableTaskRuntime::class);
        if (!$runtime instanceof ResumableSystemTaskRuntimeInterface) {
            throw new \RuntimeException('Configured resumable system task runtime violates its contract.');
        }

        return $runtime;
    }
}
