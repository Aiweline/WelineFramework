<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Preload\Provider;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\Preload\WorkerPreloadProviderInterface;
use Weline\Framework\Runtime\Preload\WorkerPreloadResult;

final class ObjectMetadataPreloadProvider implements WorkerPreloadProviderInterface
{
    public function code(): string
    {
        return 'object_metadata';
    }

    public function phase(): string
    {
        return WorkerPreloadContext::PHASE_READY_GATE;
    }

    public function priority(): int
    {
        return 10;
    }

    public function isEnabled(WorkerPreloadContext $context): bool
    {
        return true;
    }

    public function preload(WorkerPreloadContext $context): WorkerPreloadResult
    {
        $start = \microtime(true);
        $memoryStart = \memory_get_usage(true);
        $stats = ObjectManager::preloadRuntimeMetadata();
        $items = 0;
        foreach ($stats as $value) {
            if (\is_int($value)) {
                $items += $value;
            }
        }

        return WorkerPreloadResult::warmed(
            $this->code(),
            $context->phase(),
            $items,
            \round((\microtime(true) - $start) * 1000, 2),
            \memory_get_usage(true) - $memoryStart,
            $stats
        );
    }

    public function invalidationKeys(): array
    {
        return ['generated/reflection_metadata.php', 'generated/compiled_factories.php', 'generated/plugins.php'];
    }
}
