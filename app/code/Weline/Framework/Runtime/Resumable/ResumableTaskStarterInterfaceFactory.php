<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Runtime\ResumableTaskStarter;

/** ObjectManager bridge for the public task-start boundary. */
final class ResumableTaskStarterInterfaceFactory implements FactoryObjectInterface
{
    public function create(): ResumableTaskStarterInterface
    {
        $starter = ObjectManager::getInstance(ResumableTaskStarter::class);
        if (!$starter instanceof ResumableTaskStarterInterface) {
            throw new \RuntimeException('Configured resumable task starter violates its contract.');
        }
        return $starter;
    }
}
