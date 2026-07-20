<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Runtime\ResumableSystemTaskStarter;

/** ObjectManager bridge for trusted system-owned task starts. */
final class ResumableSystemTaskStarterInterfaceFactory implements FactoryObjectInterface
{
    public function create(): ResumableSystemTaskStarterInterface
    {
        $starter = ObjectManager::getInstance(ResumableSystemTaskStarter::class);
        if (!$starter instanceof ResumableSystemTaskStarterInterface) {
            throw new \RuntimeException('Configured resumable system task starter violates its contract.');
        }

        return $starter;
    }
}
