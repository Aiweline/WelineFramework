<?php

declare(strict_types=1);

namespace Weline\Framework\View\Cache;

use Weline\Framework\Runtime\MemoryReclaimableInterface;
use Weline\Framework\Runtime\WlsConcurrency;

/**
 * Budgeted reclaim for the anonymous fragment SWR bag. Widget/hook fragments
 * cost a full widget render each when lost (~10ms apiece on storefront chrome),
 * so pressure handling must trim oldest entries instead of wiping the bag.
 */
final class TemplateFragmentOutputReclaimable implements MemoryReclaimableInterface
{
    /** Soft reclaim floor: keeps ~48 hot fragments (chrome widgets ≈ 66KB total). */
    private const SOFT_RETAIN_BYTES = 262144;

    /** Absolute floor even under hard evict — one chrome set survives. */
    private const MIN_RETAIN_BYTES = 65536;

    public function getName(): string
    {
        return 'template_fragment_output';
    }

    public function estimateBytes(): int
    {
        return TemplateFragmentOutputCache::estimateBytes();
    }

    public function reclaimPriority(): int
    {
        return 60;
    }

    public function minRetainBytes(): int
    {
        return self::MIN_RETAIN_BYTES;
    }

    public function compact(): array
    {
        if (!WlsConcurrency::canCompactProcessCaches()) {
            return ['freed_bytes' => 0, 'skipped' => true, 'name' => $this->getName()];
        }

        return [
            'freed_bytes' => TemplateFragmentOutputCache::trimToBudget(self::SOFT_RETAIN_BYTES),
            'skipped' => false,
            'name' => $this->getName(),
        ];
    }

    public function evict(int $targetBytes): array
    {
        if ($targetBytes <= 0 || !WlsConcurrency::canCompactProcessCaches()) {
            return ['freed_bytes' => 0, 'skipped' => true, 'name' => $this->getName()];
        }
        $before = TemplateFragmentOutputCache::estimateBytes();
        $floor = \max(0, $before - $targetBytes);
        if ($floor < self::MIN_RETAIN_BYTES) {
            $floor = self::MIN_RETAIN_BYTES;
        }

        return [
            'freed_bytes' => TemplateFragmentOutputCache::trimToBudget($floor),
            'skipped' => false,
            'name' => $this->getName(),
        ];
    }
}
