<?php

declare(strict_types=1);

namespace Weline\Framework\View;

use Weline\Framework\Runtime\MemoryReclaimableInterface;
use Weline\Framework\Runtime\WlsConcurrency;

/** Soft/evict: drop TemplateCacheManager process memory bag. */
final class TemplateCacheProcessReclaimable implements MemoryReclaimableInterface
{
    public function getName(): string
    {
        return 'template_cache_process';
    }

    public function estimateBytes(): int
    {
        return 0;
    }

    public function reclaimPriority(): int
    {
        return 45;
    }

    public function minRetainBytes(): int
    {
        return 0;
    }

    public function compact(): array
    {
        if (!WlsConcurrency::canCompactProcessCaches()) {
            return ['freed_bytes' => 0, 'skipped' => true, 'name' => $this->getName()];
        }
        if (!\class_exists(TemplateCacheManager::class, false)
            && !\class_exists(TemplateCacheManager::class, true)
        ) {
            return ['freed_bytes' => 0, 'skipped' => true, 'name' => $this->getName()];
        }
        $before = \memory_get_usage(true);
        TemplateCacheManager::clearProcessMemoryCache();
        $after = \memory_get_usage(true);

        return [
            'freed_bytes' => \max(0, $before - $after),
            'skipped' => false,
            'name' => $this->getName(),
        ];
    }

    public function evict(int $targetBytes): array
    {
        if ($targetBytes <= 0) {
            return ['freed_bytes' => 0, 'skipped' => true, 'name' => $this->getName()];
        }

        return $this->compact();
    }
}
