<?php

declare(strict_types=1);

namespace Weline\Framework\View\Taglib\Ast;

use Weline\Framework\Runtime\MemoryReclaimableInterface;
use Weline\Framework\Runtime\WlsConcurrency;

/**
 * Soft pressure: half-trim NodePool + StringInterner.
 * Hard full reset stays on Framework ProcessCacheResetter.
 */
final class AstNodePoolReclaimable implements MemoryReclaimableInterface
{
    public function getName(): string
    {
        return 'ast_node_pool';
    }

    public function estimateBytes(): int
    {
        $stats = NodePool::stats();
        $strings = (int)($stats['strings']['memory'] ?? 0);

        return \max(0, (($stats['textNodes'] ?? 0) + ($stats['phpNodes'] ?? 0)) * 128 + $strings);
    }

    public function reclaimPriority(): int
    {
        return 50;
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
        $before = $this->estimateBytes();
        NodePool::trimForMemoryPressure();
        $after = $this->estimateBytes();

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
