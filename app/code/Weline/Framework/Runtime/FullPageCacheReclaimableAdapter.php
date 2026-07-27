<?php
declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Last-resort reclaimable adapter for FullPageCache process L1.
 */
final class FullPageCacheReclaimableAdapter implements MemoryReclaimableInterface
{
    public function getName(): string
    {
        return 'full_page_cache_process_l1';
    }

    public function estimateBytes(): int
    {
        return 0;
    }

    public function reclaimPriority(): int
    {
        return 100;
    }

    public function minRetainBytes(): int
    {
        return 0;
    }

    public function compact(): array
    {
        return ['freed_bytes' => 0, 'skipped' => false, 'name' => $this->getName()];
    }

    public function evict(int $targetBytes): array
    {
        if ($targetBytes <= 0 || !WlsConcurrency::canCompactProcessCaches()) {
            return ['freed_bytes' => 0, 'skipped' => true, 'name' => $this->getName()];
        }
        if (!\class_exists(\Weline\Framework\Router\FullPageCacheCoordinator::class, false)
            && !\class_exists(\Weline\Framework\Router\FullPageCacheCoordinator::class, true)
        ) {
            return ['freed_bytes' => 0, 'skipped' => true, 'name' => $this->getName()];
        }
        $before = \memory_get_usage(true);
        \Weline\Framework\Router\FullPageCacheCoordinator::clearProcessCache();
        $after = \memory_get_usage(true);
        $freed = \max(0, $before - $after);

        return ['freed_bytes' => $freed > 0 ? $freed : 1, 'skipped' => false, 'name' => $this->getName()];
    }
}
