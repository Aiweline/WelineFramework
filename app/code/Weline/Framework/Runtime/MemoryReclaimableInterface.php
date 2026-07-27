<?php
declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Process-local reclaimable memory facade for host pressure coordination.
 */
interface MemoryReclaimableInterface
{
    public function estimateBytes(): int;

    /** Lower number = reclaim earlier. */
    public function reclaimPriority(): int;

    public function minRetainBytes(): int;

    /**
     * @return array{freed_bytes:int, skipped:bool, name:string}
     */
    public function compact(): array;

    /**
     * @return array{freed_bytes:int, skipped:bool, name:string}
     */
    public function evict(int $targetBytes): array;

    public function getName(): string;
}
