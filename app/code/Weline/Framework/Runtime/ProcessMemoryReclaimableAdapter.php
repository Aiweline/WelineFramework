<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Cache\Contract\ProcessMemoryStoreInterface;
use Weline\Framework\Cache\Policy\ProcessMemoryEvictionPolicy;

/**
 * Bridges ProcessMemoryStore into host pressure reclaim registry.
 */
final class ProcessMemoryReclaimableAdapter implements MemoryReclaimableInterface
{
    public function __construct(
        private readonly ProcessMemoryStoreInterface $store,
        private readonly string $name = 'process_memory_store',
        private readonly int $priority = 40,
        private readonly int $minRetainBytes = 0,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function estimateBytes(): int
    {
        return $this->store->estimateBytes();
    }

    public function reclaimPriority(): int
    {
        return $this->priority;
    }

    public function minRetainBytes(): int
    {
        return $this->minRetainBytes;
    }

    public function compact(): array
    {
        if (!WlsConcurrency::canCompactProcessCaches()) {
            return ['freed_bytes' => 0, 'skipped' => true, 'name' => $this->getName()];
        }
        $before = $this->store->estimateBytes();
        $this->store->evictByPolicy(ProcessMemoryEvictionPolicy::TIER_SOFT);
        $after = $this->store->estimateBytes();

        return [
            'freed_bytes' => \max(0, $before - $after),
            'skipped' => false,
            'name' => $this->getName(),
        ];
    }

    public function evict(int $targetBytes): array
    {
        if ($targetBytes <= 0 || !WlsConcurrency::canCompactProcessCaches()) {
            return ['freed_bytes' => 0, 'skipped' => true, 'name' => $this->getName()];
        }
        $before = $this->store->estimateBytes();
        $this->store->evictByPolicy(ProcessMemoryEvictionPolicy::TIER_HARD, $targetBytes, null);
        $after = $this->store->estimateBytes();
        $freed = \max(0, $before - $after);

        return [
            'freed_bytes' => $freed > 0 ? $freed : 0,
            'skipped' => false,
            'name' => $this->getName(),
        ];
    }
}
