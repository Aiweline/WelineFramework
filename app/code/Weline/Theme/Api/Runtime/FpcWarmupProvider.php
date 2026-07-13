<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Runtime;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\FpcWarmupProviderInterface;
use Weline\Theme\Observer\WorkerBootstrapWarmup;

final class FpcWarmupProvider implements FpcWarmupProviderInterface
{
    public function warmProcessFastPathPayloads(): void
    {
        ObjectManager::getInstance(WorkerBootstrapWarmup::class)->warmFpcFastPathPayloadsForReady();
    }

    public function warmupPaths(): array
    {
        return ObjectManager::getInstance(WorkerBootstrapWarmup::class)->getFpcWarmupPaths();
    }
}
