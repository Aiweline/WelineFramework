<?php

declare(strict_types=1);

namespace Weline\B2B\Model;

/**
 * System VIP ladder seed table (vip0…vip12). Amounts are minor units defaults
 * in the website default (benchmark) currency.
 */
final class SystemVipLadder
{
    public const ASSET_CODE_B2B_CREDIT = 'b2b_credit';
    public const TIER_MIN = 0;
    public const TIER_MAX = 12;

    public const LEGACY_GROUP_ID = 'g-system-vip';
    public const LEGACY_CODE = 'vip';

    /**
     * Spend thresholds (major units of website default currency).
     * vip12 = 1_000_000 (至少 100 万)；曲线前缓后快，避免等差过陡。
     *
     * @var array<int, int>
     */
    private const SPEND_MAJOR = [
        0 => 0,
        1 => 5_000,
        2 => 15_000,
        3 => 35_000,
        4 => 70_000,
        5 => 120_000,
        6 => 200_000,
        7 => 320_000,
        8 => 450_000,
        9 => 600_000,
        10 => 750_000,
        11 => 900_000,
        12 => 1_000_000,
    ];

    /**
     * Target credit grant (major units). Scales with tier, stays below spend threshold.
     *
     * @var array<int, int>
     */
    private const CREDIT_MAJOR = [
        0 => 0,
        1 => 1_000,
        2 => 2_500,
        3 => 5_000,
        4 => 10_000,
        5 => 18_000,
        6 => 30_000,
        7 => 45_000,
        8 => 60_000,
        9 => 80_000,
        10 => 100_000,
        11 => 120_000,
        12 => 150_000,
    ];

    /**
     * @return list<array{
     *   tier_rank:int,
     *   group_id:string,
     *   code:string,
     *   name:string,
     *   description:string,
     *   credit_limit_minor:int,
     *   spend_threshold_minor:int
     * }>
     */
    public static function seedDefinitions(): array
    {
        $rows = [];
        for ($tier = self::TIER_MIN; $tier <= self::TIER_MAX; $tier++) {
            $spendMajor = self::SPEND_MAJOR[$tier] ?? 0;
            $creditMajor = self::CREDIT_MAJOR[$tier] ?? 0;
            $rows[] = [
                'tier_rank' => $tier,
                'group_id' => self::groupId($tier),
                'code' => self::code($tier),
                'name' => $tier === 0 ? 'VIP0 初级' : ('VIP' . $tier),
                'description' => $tier === 0
                    ? '批发入门等级；开启批发信用后按达标折扣额度授信。'
                    : ('批发等级 VIP' . $tier . '；消费达标后可自动升入。'),
                'credit_limit_minor' => $creditMajor * 100,
                'spend_threshold_minor' => $spendMajor * 100,
            ];
        }

        return $rows;
    }

    /** Previous linear schedule (major×100 minor): spend = tier*5000, credit = tier*1000. */
    public static function isLegacyLinearAmounts(int $tier, int $creditMinor, int $spendMinor): bool
    {
        if ($tier < self::TIER_MIN || $tier > self::TIER_MAX) {
            return false;
        }
        $legacyCredit = $tier * 100_000;
        $legacySpend = $tier === 0 ? 0 : ($tier * 500_000);

        return $creditMinor === $legacyCredit && $spendMinor === $legacySpend;
    }

    public static function groupId(int $tier): string
    {
        self::assertTier($tier);

        return 'g-system-vip' . $tier;
    }

    public static function code(int $tier): string
    {
        self::assertTier($tier);

        return 'vip' . $tier;
    }

    public static function isSystemVipGroupId(string $groupId): bool
    {
        $groupId = trim($groupId);
        if ($groupId === self::LEGACY_GROUP_ID) {
            return true;
        }
        if (!preg_match('/^g-system-vip(\d{1,2})$/', $groupId, $m)) {
            return false;
        }
        $tier = (int)$m[1];

        return $tier >= self::TIER_MIN && $tier <= self::TIER_MAX;
    }

    public static function isSystemVipCode(string $code): bool
    {
        $code = trim($code);
        if ($code === self::LEGACY_CODE) {
            return true;
        }
        if (!preg_match('/^vip(\d{1,2})$/', $code, $m)) {
            return false;
        }
        $tier = (int)$m[1];

        return $tier >= self::TIER_MIN && $tier <= self::TIER_MAX;
    }

    public static function tierFromGroupId(string $groupId): ?int
    {
        $groupId = trim($groupId);
        if (!preg_match('/^g-system-vip(\d{1,2})$/', $groupId, $m)) {
            return null;
        }
        $tier = (int)$m[1];
        if ($tier < self::TIER_MIN || $tier > self::TIER_MAX) {
            return null;
        }

        return $tier;
    }

    private static function assertTier(int $tier): void
    {
        if ($tier < self::TIER_MIN || $tier > self::TIER_MAX) {
            throw new \InvalidArgumentException('b2b_vip_tier_out_of_range:' . $tier);
        }
    }
}
