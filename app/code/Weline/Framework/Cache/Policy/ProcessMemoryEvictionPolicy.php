<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Policy;

/**
 * Shared eviction ordering for process L1 bags and Adapter soft/hard relief.
 *
 * Order: pin ascending → heat ascending → bytes descending.
 */
final class ProcessMemoryEvictionPolicy
{
    public const TIER_SOFT = 'soft';
    public const TIER_HARD = 'hard';

    /**
     * @param array<string, array{pin:int, heat:int, bytes:int}> $metas
     * @return list<string>
     */
    public static function selectVictims(
        array $metas,
        string $tier,
        ?int $targetBytes = null,
        ?int $targetCount = null
    ): array {
        if ($metas === []) {
            return [];
        }

        $tier = $tier === self::TIER_HARD ? self::TIER_HARD : self::TIER_SOFT;
        $candidates = [];
        foreach ($metas as $key => $meta) {
            $pin = (int)($meta['pin'] ?? 0);
            if ($pin > 0) {
                continue;
            }
            $candidates[$key] = [
                'pin' => $pin,
                'heat' => (int)($meta['heat'] ?? 0),
                'bytes' => \max(0, (int)($meta['bytes'] ?? 0)),
            ];
        }

        if ($candidates === []) {
            return [];
        }

        \uasort(
            $candidates,
            static function (array $a, array $b): int {
                if ($a['pin'] !== $b['pin']) {
                    return $a['pin'] <=> $b['pin'];
                }
                if ($a['heat'] !== $b['heat']) {
                    return $a['heat'] <=> $b['heat'];
                }

                return $b['bytes'] <=> $a['bytes'];
            }
        );

        $ordered = \array_keys($candidates);
        if ($tier === self::TIER_HARD && $targetBytes === null && $targetCount === null) {
            return $ordered;
        }

        if ($targetCount === null && $targetBytes === null) {
            // Soft default: half of unpinned entries.
            $targetCount = \max(1, (int)\ceil(\count($ordered) / 2));
        }

        $selected = [];
        $freedBytes = 0;
        foreach ($ordered as $key) {
            if ($targetCount !== null && \count($selected) >= $targetCount) {
                break;
            }
            if ($targetBytes !== null && $freedBytes >= $targetBytes && $selected !== []) {
                break;
            }
            $selected[] = $key;
            $freedBytes += $candidates[$key]['bytes'];
        }

        return $selected;
    }
}
