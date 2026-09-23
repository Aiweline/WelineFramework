<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\FreeShippingRule;

/**
 * 国际通用满额免邮种子模板：幂等 upsert；origin=seed 不可物理删除。
 */
final class FreeShippingRuleSeedService
{
    /**
     * Default only SEED_FREE_49 active (homepage Wave-1/2 ops lock: free shipping at $49).
     * Avoid enabling multiple amount tiers so a lower threshold always wins.
     *
     * @var list<array{
     *   rule_code:string,
     *   rule_name:string,
     *   condition_type:string,
     *   min_order_amount:float,
     *   priority:int,
     *   is_active:int
     * }>
     */
    public const DEFAULT_SEEDS = [
        [
            'rule_code' => 'SEED_FREE_49',
            'rule_name' => '满49免邮（入门）',
            'condition_type' => FreeShippingRule::CONDITION_ORDER_AMOUNT,
            'min_order_amount' => 49.00,
            'priority' => 10,
            'is_active' => 1,
        ],
        [
            'rule_code' => 'SEED_FREE_99',
            'rule_name' => '满99免邮（标准）',
            'condition_type' => FreeShippingRule::CONDITION_ORDER_AMOUNT,
            'min_order_amount' => 99.00,
            'priority' => 20,
            'is_active' => 0,
        ],
        [
            'rule_code' => 'SEED_FREE_149',
            'rule_name' => '满149免邮（常用）',
            'condition_type' => FreeShippingRule::CONDITION_ORDER_AMOUNT,
            'min_order_amount' => 149.00,
            'priority' => 30,
            'is_active' => 0,
        ],
        [
            'rule_code' => 'SEED_FREE_199',
            'rule_name' => '满199免邮（跨境）',
            'condition_type' => FreeShippingRule::CONDITION_ORDER_AMOUNT,
            'min_order_amount' => 199.00,
            'priority' => 40,
            'is_active' => 0,
        ],
        [
            'rule_code' => 'SEED_FREE_299',
            'rule_name' => '满299免邮（国际）',
            'condition_type' => FreeShippingRule::CONDITION_ORDER_AMOUNT,
            'min_order_amount' => 299.00,
            'priority' => 50,
            'is_active' => 0,
        ],
        [
            'rule_code' => 'SEED_FREE_499',
            'rule_name' => '满499免邮（全球）',
            'condition_type' => FreeShippingRule::CONDITION_ORDER_AMOUNT,
            'min_order_amount' => 499.00,
            'priority' => 60,
            'is_active' => 0,
        ],
    ];

    public function __construct(private readonly ObjectManager $objectManager)
    {
    }

    /**
     * @return list<string>
     */
    public static function canonicalCodes(): array
    {
        $codes = [];
        foreach (self::DEFAULT_SEEDS as $seed) {
            $codes[] = (string)$seed['rule_code'];
        }

        return $codes;
    }

    /**
     * Upsert seeds for one scope. Never deletes. Preserves merchant name/active on update.
     */
    public function seedDefaults(string $scopeType = FreeShippingRule::SCOPE_WEBSITE, int $scopeId = 0): int
    {
        $scopeType = strtolower(trim($scopeType));
        if (!in_array($scopeType, [
            FreeShippingRule::SCOPE_WEBSITE,
            FreeShippingRule::SCOPE_STORE,
            FreeShippingRule::SCOPE_CHANNEL,
        ], true)) {
            $scopeType = FreeShippingRule::SCOPE_WEBSITE;
        }
        $scopeId = max(0, $scopeId);
        $n = 0;
        $now = date('Y-m-d H:i:s');

        foreach (self::DEFAULT_SEEDS as $seed) {
            $code = strtoupper(trim((string)$seed['rule_code']));
            $existing = $this->findByScopeCode($scopeType, $scopeId, $code);
            if ($existing instanceof FreeShippingRule && (int)$existing->getId() > 0) {
                $existing->setData(FreeShippingRule::schema_fields_ORIGIN, FreeShippingRule::ORIGIN_SEED);
                if (trim((string)$existing->getData(FreeShippingRule::schema_fields_RULE_NAME)) === '') {
                    $existing->setData(FreeShippingRule::schema_fields_RULE_NAME, (string)$seed['rule_name']);
                }
                if (trim((string)$existing->getData(FreeShippingRule::schema_fields_CONDITION_TYPE)) === '') {
                    $existing->setData(
                        FreeShippingRule::schema_fields_CONDITION_TYPE,
                        (string)$seed['condition_type'],
                    );
                }
                $existing->setData(FreeShippingRule::schema_fields_UPDATED_AT, $now);
                $existing->save();
            } else {
                /** @var FreeShippingRule $row */
                $row = $this->objectManager->getInstance(FreeShippingRule::class, [], false);
                $row->clearData()->setData([
                    FreeShippingRule::schema_fields_SCOPE_TYPE => $scopeType,
                    FreeShippingRule::schema_fields_SCOPE_ID => $scopeId,
                    FreeShippingRule::schema_fields_RULE_NAME => (string)$seed['rule_name'],
                    FreeShippingRule::schema_fields_RULE_CODE => $code,
                    FreeShippingRule::schema_fields_CONDITION_TYPE => (string)$seed['condition_type'],
                    FreeShippingRule::schema_fields_MIN_ORDER_AMOUNT => (float)$seed['min_order_amount'],
                    FreeShippingRule::schema_fields_ORIGIN => FreeShippingRule::ORIGIN_SEED,
                    FreeShippingRule::schema_fields_IS_ACTIVE => !empty($seed['is_active']) ? 1 : 0,
                    FreeShippingRule::schema_fields_PRIORITY => (int)$seed['priority'],
                    FreeShippingRule::schema_fields_CREATED_AT => $now,
                    FreeShippingRule::schema_fields_UPDATED_AT => $now,
                ])->save();
            }
            ++$n;
        }

        return $n;
    }

    /**
     * Homepage Wave-1/2 ops: lock active amount-tier seed to SEED_FREE_49 ($49).
     * Deactivates other SEED_FREE_* amount seeds on the same scope; never deletes.
     */
    public function alignHomepageWave2Threshold49(
        string $scopeType = FreeShippingRule::SCOPE_WEBSITE,
        int $scopeId = 0,
    ): int {
        $this->seedDefaults($scopeType, $scopeId);
        $scopeType = strtolower(trim($scopeType)) ?: FreeShippingRule::SCOPE_WEBSITE;
        $scopeId = max(0, $scopeId);
        $now = date('Y-m-d H:i:s');
        $changed = 0;

        foreach (self::DEFAULT_SEEDS as $seed) {
            $code = strtoupper(trim((string)$seed['rule_code']));
            $row = $this->findByScopeCode($scopeType, $scopeId, $code);
            if (!$row instanceof FreeShippingRule || (int)$row->getId() <= 0) {
                continue;
            }
            $wantActive = $code === 'SEED_FREE_49' ? 1 : 0;
            $current = (int)$row->getData(FreeShippingRule::schema_fields_IS_ACTIVE) ? 1 : 0;
            if ($current === $wantActive) {
                continue;
            }
            $row->setData(FreeShippingRule::schema_fields_IS_ACTIVE, $wantActive);
            $row->setData(FreeShippingRule::schema_fields_UPDATED_AT, $now);
            $row->save();
            ++$changed;
        }

        return $changed;
    }

    public function findSeedId(string $ruleCode, string $scopeType = FreeShippingRule::SCOPE_WEBSITE, int $scopeId = 0): int
    {
        $row = $this->findByScopeCode($scopeType, $scopeId, strtoupper(trim($ruleCode)));
        if ($row instanceof FreeShippingRule) {
            return (int)$row->getId();
        }

        return 0;
    }

    private function findByScopeCode(string $scopeType, int $scopeId, string $code): ?FreeShippingRule
    {
        if ($code === '') {
            return null;
        }
        /** @var FreeShippingRule $model */
        $model = $this->objectManager->getInstance(FreeShippingRule::class, [], false);
        $items = $model->reset()
            ->where(FreeShippingRule::schema_fields_SCOPE_TYPE, $scopeType)
            ->where(FreeShippingRule::schema_fields_SCOPE_ID, $scopeId)
            ->where(FreeShippingRule::schema_fields_RULE_CODE, $code)
            ->select()
            ->fetch()
            ->getItems();
        $existing = is_array($items) ? ($items[0] ?? null) : null;

        return $existing instanceof FreeShippingRule && (int)$existing->getId() > 0 ? $existing : null;
    }
}
