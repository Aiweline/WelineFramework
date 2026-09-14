<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\SystemVipLadder;
use Weline\Framework\Database\ConnectionFactory;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Api\ConfigStore;

/**
 * Website-scoped default wholesale qty/discount ladder (runtime inherit, no PriceList rewrite).
 */
final class DefaultWholesalePolicy
{
    public const CONFIG_MODULE = 'Weline_B2B';
    public const CONFIG_AREA = ConfigReader::area_FRONTEND;

    public const KEY_MAX_DISCOUNT_BPS = 'b2b_max_discount_bps';
    public const KEY_MIN_MARGIN_BPS = 'b2b_min_margin_bps';
    public const KEY_TIER_POLICY_JSON = 'b2b_default_tier_policy_json';

    public const DEFAULT_MAX_DISCOUNT_BPS = 2000;
    public const DEFAULT_MIN_MARGIN_BPS = 0;
    public const BASE_MIN_QTY = 5;
    public const STEP_MIN_QTY = 5;
    public const BASE_DISCOUNT_BPS = 500;
    public const STEP_DISCOUNT_BPS = 500;

    public const SYNTHETIC_LIST_PREFIX = 'pl-default:';

    private ?array $testingMaxDiscount = null;
    private ?array $testingMinMargin = null;
    /** @var array<int, list<array{tier_rank:int,min_qty:int,discount_bps:int}>>|null */
    private ?array $testingTiersByWebsite = null;

    public function __construct(private ?ConfigStore $config = null)
    {
    }

    public static function forConnection(ConnectionFactory $connection): self
    {
        return new self(ConfigStore::forConnection($connection));
    }

    /**
     * @param list<array{tier_rank?:int,min_qty?:int,discount_bps?:int}>|null $tiers
     */
    public static function forTesting(
        int $maxDiscountBps = self::DEFAULT_MAX_DISCOUNT_BPS,
        int $minMarginBps = self::DEFAULT_MIN_MARGIN_BPS,
        ?array $tiers = null,
        int $websiteId = 0,
    ): self {
        $policy = new self();
        $policy->testingMaxDiscount = [$websiteId => $maxDiscountBps];
        $policy->testingMinMargin = [$websiteId => $minMarginBps];
        if ($tiers !== null) {
            $policy->testingTiersByWebsite = [
                $websiteId => self::normalizeTierRows($tiers, $maxDiscountBps),
            ];
        }

        return $policy;
    }

    public function maxDiscountBps(int $websiteId): int
    {
        if ($this->testingMaxDiscount !== null) {
            return max(0, min(10000, (int) ($this->testingMaxDiscount[$websiteId]
                ?? $this->testingMaxDiscount[0]
                ?? self::DEFAULT_MAX_DISCOUNT_BPS)));
        }
        $raw = $this->resolvedValue(self::KEY_MAX_DISCOUNT_BPS, $this->websiteScope($websiteId), self::DEFAULT_MAX_DISCOUNT_BPS);

        return max(0, min(10000, (int) $raw));
    }

    public function minMarginBps(int $websiteId): int
    {
        if ($this->testingMinMargin !== null) {
            return max(0, min(10000, (int) ($this->testingMinMargin[$websiteId]
                ?? $this->testingMinMargin[0]
                ?? self::DEFAULT_MIN_MARGIN_BPS)));
        }
        $raw = $this->resolvedValue(self::KEY_MIN_MARGIN_BPS, $this->websiteScope($websiteId), self::DEFAULT_MIN_MARGIN_BPS);

        return max(0, min(10000, (int) $raw));
    }

    /**
     * @return list<array{tier_rank:int,min_qty:int,discount_bps:int}>
     */
    public function allTiers(int $websiteId): array
    {
        $max = $this->maxDiscountBps($websiteId);
        if ($this->testingTiersByWebsite !== null) {
            $rows = $this->testingTiersByWebsite[$websiteId] ?? $this->testingTiersByWebsite[0] ?? null;
            if (is_array($rows)) {
                return self::normalizeTierRows($rows, $max);
            }
        }
        // Pure testing harness without ConfigStore: seed from max discount.
        if ($this->testingMaxDiscount !== null && $this->config === null) {
            return self::seedTierRows($max);
        }
        $raw = $this->resolvedValue(self::KEY_TIER_POLICY_JSON, $this->websiteScope($websiteId), null);
        $decoded = $this->decodeTiers($raw);
        if ($decoded === []) {
            return self::seedTierRows($max);
        }

        return self::normalizeTierRows($decoded, $max);
    }

    /**
     * @return list<array{min_qty:int,discount_bps:int,amount_minor?:int}>
     */
    public function tiersForGroup(string $groupId, int $websiteId, ?int $tierRank = null): array
    {
        $tier = $tierRank;
        if ($tier === null) {
            if (!SystemVipLadder::isSystemVipGroupId($groupId) && $groupId !== SystemVipLadder::LEGACY_GROUP_ID) {
                return [];
            }
            $tier = SystemVipLadder::tierFromGroupId($groupId);
            if ($tier === null && $groupId === SystemVipLadder::LEGACY_GROUP_ID) {
                $tier = 0;
            }
        }
        if ($tier === null || $tier < SystemVipLadder::TIER_MIN || $tier > SystemVipLadder::TIER_MAX) {
            return [];
        }
        foreach ($this->allTiers($websiteId) as $row) {
            if ((int) $row['tier_rank'] === $tier) {
                return [[
                    'min_qty' => (int) $row['min_qty'],
                    'discount_bps' => (int) $row['discount_bps'],
                ]];
            }
        }

        return [];
    }

    public function groupCanInheritTemplate(string $groupId, ?int $tierRank = null): bool
    {
        if (SystemVipLadder::isSystemVipGroupId($groupId) || $groupId === SystemVipLadder::LEGACY_GROUP_ID) {
            return true;
        }
        if ($tierRank === null) {
            return false;
        }

        return $tierRank >= SystemVipLadder::TIER_MIN && $tierRank <= SystemVipLadder::TIER_MAX;
    }

    public function materializeAmount(int $retailAmountMinor, int $discountBps): int
    {
        $discountBps = max(0, min(10000, $discountBps));
        if ($retailAmountMinor <= 0) {
            return 0;
        }

        return (int) floor($retailAmountMinor * (10000 - $discountBps) / 10000);
    }

    /**
     * Pick absolute amount for qty from template tiers (single discount tier per VIP by default).
     *
     * @param list<array{min_qty:int,discount_bps:int}> $tiers
     */
    public function amountForQty(int $retailAmountMinor, array $tiers, int $qty): ?int
    {
        $qty = max(1, $qty);
        $bestMin = -1;
        $bestBps = null;
        foreach ($tiers as $tier) {
            $minQty = max(1, (int) ($tier['min_qty'] ?? 1));
            $bps = max(0, min(10000, (int) ($tier['discount_bps'] ?? 0)));
            if ($qty >= $minQty && $minQty > $bestMin) {
                $bestMin = $minQty;
                $bestBps = $bps;
            }
        }
        if ($bestBps === null) {
            return null;
        }

        return $this->materializeAmount($retailAmountMinor, $bestBps);
    }

    public function lowestMinQty(array $tiers): int
    {
        $min = null;
        foreach ($tiers as $tier) {
            $q = max(1, (int) ($tier['min_qty'] ?? 1));
            $min = $min === null ? $q : min($min, $q);
        }

        return $min ?? self::BASE_MIN_QTY;
    }

    public static function syntheticListId(int $websiteId, string $groupId): string
    {
        return self::SYNTHETIC_LIST_PREFIX . max(0, $websiteId) . ':' . trim($groupId);
    }

    public static function isSyntheticListId(string $listId): bool
    {
        return str_starts_with(trim($listId), self::SYNTHETIC_LIST_PREFIX);
    }

    /**
     * @return list<array{tier_rank:int,min_qty:int,discount_bps:int}>
     */
    public static function seedTierRows(int $maxDiscountBps = self::DEFAULT_MAX_DISCOUNT_BPS): array
    {
        $maxDiscountBps = max(0, min(10000, $maxDiscountBps));
        $rows = [];
        for ($tier = SystemVipLadder::TIER_MIN; $tier <= SystemVipLadder::TIER_MAX; $tier++) {
            $rows[] = [
                'tier_rank' => $tier,
                'min_qty' => self::BASE_MIN_QTY + ($tier * self::STEP_MIN_QTY),
                'discount_bps' => min(self::BASE_DISCOUNT_BPS + ($tier * self::STEP_DISCOUNT_BPS), $maxDiscountBps),
            ];
        }

        return $rows;
    }

    /**
     * @param list<array{tier_rank?:int,min_qty?:int,discount_bps?:int}> $rows
     * @return list<array{tier_rank:int,min_qty:int,discount_bps:int}>
     */
    public static function normalizeTierRows(array $rows, int $maxDiscountBps): array
    {
        $maxDiscountBps = max(0, min(10000, $maxDiscountBps));
        $byTier = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $tier = (int) ($row['tier_rank'] ?? -1);
            if ($tier < SystemVipLadder::TIER_MIN || $tier > SystemVipLadder::TIER_MAX) {
                continue;
            }
            $byTier[$tier] = [
                'tier_rank' => $tier,
                'min_qty' => max(1, (int) ($row['min_qty'] ?? self::BASE_MIN_QTY)),
                'discount_bps' => max(0, min($maxDiscountBps, (int) ($row['discount_bps'] ?? 0))),
            ];
        }
        $out = [];
        foreach (self::seedTierRows($maxDiscountBps) as $seed) {
            $tier = $seed['tier_rank'];
            $out[] = $byTier[$tier] ?? $seed;
        }

        return $out;
    }

    /**
     * Validate rows for config save; throws when any discount exceeds max.
     *
     * @param list<array{tier_rank?:int,min_qty?:int,discount_bps?:int}> $rows
     * @return list<array{tier_rank:int,min_qty:int,discount_bps:int}>
     */
    public static function assertAndNormalizeForSave(array $rows, int $maxDiscountBps): array
    {
        $maxDiscountBps = max(0, min(10000, $maxDiscountBps));
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $bps = (int) ($row['discount_bps'] ?? 0);
            if ($bps > $maxDiscountBps) {
                throw new \InvalidArgumentException(
                    (string) __(
                        '默认批发模板折扣超过最大折扣护栏：档 %{1} 为 %{2} bps，上限 %{3} bps',
                        [(int) ($row['tier_rank'] ?? -1), $bps, $maxDiscountBps]
                    )
                );
            }
        }

        return self::normalizeTierRows($rows, $maxDiscountBps);
    }

    private function decodeTiers(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function websiteScope(int $websiteId): string
    {
        return 'website:' . max(0, $websiteId);
    }

    private function resolvedValue(string $key, string $scope, mixed $default): mixed
    {
        $resolved = $this->configStore()->resolveConfig(
            $key,
            self::CONFIG_MODULE,
            self::CONFIG_AREA,
            $scope,
            ConfigReader::LOCALE_DEFAULT,
            $default,
        );
        if (!is_array($resolved) || !array_key_exists('value', $resolved)) {
            return $default;
        }

        return $resolved['value'];
    }

    private function configStore(): ConfigStore
    {
        return $this->config ??= new ConfigStore();
    }
}
