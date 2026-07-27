<?php
declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Registry of MemoryReclaimableInterface adapters ordered by reclaim priority.
 */
final class MemoryReclaimableRegistry
{
    /** @var list<MemoryReclaimableInterface> */
    private array $items = [];

    public function register(MemoryReclaimableInterface $item): void
    {
        $this->items[] = $item;
        \usort(
            $this->items,
            static fn(MemoryReclaimableInterface $a, MemoryReclaimableInterface $b): int
                => $a->reclaimPriority() <=> $b->reclaimPriority()
        );
    }

    /**
     * @return list<MemoryReclaimableInterface>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * @return array{freed_bytes:int, skipped:int, details:list<array<string, mixed>>}
     */
    public function compactAll(): array
    {
        if (!WlsConcurrency::canCompactProcessCaches()) {
            return ['freed_bytes' => 0, 'skipped' => \count($this->items), 'details' => []];
        }
        $freed = 0;
        $skipped = 0;
        $details = [];
        foreach ($this->items as $item) {
            $result = $item->compact();
            $freed += (int)($result['freed_bytes'] ?? 0);
            if (!empty($result['skipped'])) {
                $skipped++;
            }
            $details[] = $result;
        }

        return ['freed_bytes' => $freed, 'skipped' => $skipped, 'details' => $details];
    }

    /**
     * @return array{freed_bytes:int, skipped:int, details:list<array<string, mixed>>}
     */
    public function evictBytes(int $targetBytes, bool $includeLastResort = false): array
    {
        if ($targetBytes <= 0) {
            return ['freed_bytes' => 0, 'skipped' => 0, 'details' => []];
        }
        if (!WlsConcurrency::canCompactProcessCaches()) {
            return ['freed_bytes' => 0, 'skipped' => \count($this->items), 'details' => []];
        }

        $remaining = $targetBytes;
        $freed = 0;
        $skipped = 0;
        $details = [];
        foreach ($this->items as $item) {
            // Priority >= 100 reserved for last-resort (FPC).
            if (!$includeLastResort && $item->reclaimPriority() >= 100) {
                continue;
            }
            $result = $item->evict($remaining);
            $chunk = (int)($result['freed_bytes'] ?? 0);
            $freed += $chunk;
            $remaining = \max(0, $remaining - $chunk);
            if (!empty($result['skipped'])) {
                $skipped++;
            }
            $details[] = $result;
            if ($remaining <= 0) {
                break;
            }
        }

        return ['freed_bytes' => $freed, 'skipped' => $skipped, 'details' => $details];
    }
}
