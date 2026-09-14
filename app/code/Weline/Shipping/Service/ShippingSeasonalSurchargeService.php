<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\ShippingSeasonalRule;

final class ShippingSeasonalSurchargeService
{
    public function __construct(private readonly ObjectManager $objectManager)
    {
    }

    /**
     * @param array{scope_type?:string,scope_id?:int,website_id?:int}|null $context
     * @return list<array{rule_code:string,rule_name:string,amount_minor:int}>
     */
    public function matchRules(
        int $baseAmountMinor,
        int $currencyPrecision,
        ?array $context = null,
        ?\DateTimeInterface $now = null,
    ): array {
        $scopeType = (string)($context['scope_type'] ?? ShippingSeasonalRule::SCOPE_WEBSITE);
        $scopeId = (int)($context['scope_id'] ?? $context['website_id'] ?? 0);
        $today = ($now ?? new \DateTimeImmutable('now'))->format('Y-m-d');

        /** @var ShippingSeasonalRule $model */
        $model = $this->objectManager->getInstance(ShippingSeasonalRule::class, [], false);
        $items = $model->reset()
            ->where(ShippingSeasonalRule::schema_fields_SCOPE_TYPE, $scopeType)
            ->where(ShippingSeasonalRule::schema_fields_SCOPE_ID, $scopeId)
            ->where(ShippingSeasonalRule::schema_fields_IS_ACTIVE, 1)
            ->order(ShippingSeasonalRule::schema_fields_PRIORITY, 'DESC')
            ->order(ShippingSeasonalRule::schema_fields_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $hits = [];
        foreach (is_array($items) ? $items : [] as $rule) {
            if (!$rule instanceof ShippingSeasonalRule) {
                continue;
            }
            $start = (string)$rule->getData(ShippingSeasonalRule::schema_fields_START_DATE);
            $end = (string)$rule->getData(ShippingSeasonalRule::schema_fields_END_DATE);
            if ($start !== '' && $today < $start) {
                continue;
            }
            if ($end !== '' && $today > $end) {
                continue;
            }
            $amountType = (string)$rule->getData(ShippingSeasonalRule::schema_fields_AMOUNT_TYPE);
            $amountValue = (float)$rule->getData(ShippingSeasonalRule::schema_fields_AMOUNT_VALUE);
            if ($amountType === ShippingSeasonalRule::AMOUNT_PERCENT) {
                $extra = (int)round($baseAmountMinor * max(0, $amountValue) / 100);
            } else {
                $scale = 10 ** max(0, min(6, $currencyPrecision));
                $extra = (int)round(max(0, $amountValue) * $scale);
            }
            if ($extra <= 0) {
                continue;
            }
            $hits[] = [
                'rule_code' => (string)$rule->getData(ShippingSeasonalRule::schema_fields_RULE_CODE),
                'rule_name' => (string)$rule->getData(ShippingSeasonalRule::schema_fields_RULE_NAME),
                'amount_minor' => $extra,
            ];
        }

        return $hits;
    }

    /**
     * @param list<array{amount_minor:int}> $hits
     */
    public function sumMinor(array $hits): int
    {
        $sum = 0;
        foreach ($hits as $hit) {
            $sum += max(0, (int)($hit['amount_minor'] ?? 0));
        }

        return $sum;
    }
}
