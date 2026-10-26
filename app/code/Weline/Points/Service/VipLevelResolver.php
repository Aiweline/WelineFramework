<?php

declare(strict_types=1);

namespace Weline\Points\Service;

/**
 * Pure VIP level resolution. Highest active threshold at or below growth wins.
 */
final class VipLevelResolver
{
    /**
     * @return array<string, mixed>
     */
    public function standardLevel(): array
    {
        return [
            'level_id' => 0,
            'code' => 'standard',
            'name' => '普通会员',
            'min_growth' => 0,
            'discount_rate' => 0.0,
            'points_multiplier' => 1.0,
            'free_shipping' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * @param list<array<string, mixed>> $levels
     * @return array<string, mixed>
     */
    public function resolve(array $levels, int $growth): array
    {
        $growth = max(0, $growth);
        $candidates = [];
        foreach ($levels as $level) {
            if (!\is_array($level)) {
                continue;
            }
            if (($level['is_active'] ?? true) === false) {
                continue;
            }
            $minGrowth = (int) ($level['min_growth'] ?? 0);
            if ($minGrowth < 0 || $minGrowth > $growth) {
                continue;
            }
            $candidates[] = $this->normalize($level);
        }

        if ($candidates === []) {
            return $this->standardLevel();
        }

        usort(
            $candidates,
            static fn (array $left, array $right): int =>
                [$left['min_growth'], $left['sort_order'], $left['level_id']]
                <=> [$right['min_growth'], $right['sort_order'], $right['level_id']],
        );

        return $candidates[array_key_last($candidates)];
    }

    /**
     * @param array<string, mixed> $level
     * @return array<string, mixed>
     */
    private function normalize(array $level): array
    {
        $standard = $this->standardLevel();

        return [
            'level_id' => (int) ($level['level_id'] ?? 0),
            'code' => trim((string) ($level['code'] ?? $standard['code'])) ?: $standard['code'],
            'name' => trim((string) ($level['name'] ?? $standard['name'])) ?: $standard['name'],
            'min_growth' => max(0, (int) ($level['min_growth'] ?? 0)),
            'discount_rate' => max(0.0, min(1.0, (float) ($level['discount_rate'] ?? 0.0))),
            'points_multiplier' => max(0.0, (float) ($level['points_multiplier'] ?? 1.0)),
            'free_shipping' => (bool) ($level['free_shipping'] ?? false),
            'is_active' => (bool) ($level['is_active'] ?? true),
            'sort_order' => (int) ($level['sort_order'] ?? 0),
        ];
    }
}
