<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\ShippingSurchargeRule;

/**
 * Match remote/special surcharges for Local quotes.
 */
final class ShippingSurchargeService
{
    public function __construct(private readonly ObjectManager $objectManager)
    {
    }

    /**
     * @param array<string,mixed> $destAddress
     * @param array{scope_type?:string,scope_id?:int,website_id?:int}|null $context
     * @return list<array{rule_code:string,rule_name:string,amount_minor:int}>
     */
    public function matchRules(array $destAddress, int $baseAmountMinor, int $currencyPrecision, ?array $context = null): array
    {
        $scopeType = (string)($context['scope_type'] ?? ShippingSurchargeRule::SCOPE_WEBSITE);
        $scopeId = (int)($context['scope_id'] ?? $context['website_id'] ?? 0);
        $country = strtoupper(trim((string)($destAddress['country_code'] ?? '')));
        $province = trim((string)($destAddress['province'] ?? ''));
        $postcode = trim((string)($destAddress['postcode'] ?? $destAddress['postal_code'] ?? ''));

        /** @var ShippingSurchargeRule $model */
        $model = $this->objectManager->getInstance(ShippingSurchargeRule::class, [], false);
        $items = $model->reset()
            ->where(ShippingSurchargeRule::schema_fields_SCOPE_TYPE, $scopeType)
            ->where(ShippingSurchargeRule::schema_fields_SCOPE_ID, $scopeId)
            ->where(ShippingSurchargeRule::schema_fields_IS_ACTIVE, 1)
            ->order(ShippingSurchargeRule::schema_fields_PRIORITY, 'DESC')
            ->order(ShippingSurchargeRule::schema_fields_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $hits = [];
        foreach (is_array($items) ? $items : [] as $rule) {
            if (!$rule instanceof ShippingSurchargeRule) {
                continue;
            }
            $ruleCountry = strtoupper(trim((string)$rule->getData(ShippingSurchargeRule::schema_fields_COUNTRY_CODE)));
            if ($ruleCountry !== '' && $ruleCountry !== $country) {
                continue;
            }
            $matchType = (string)$rule->getData(ShippingSurchargeRule::schema_fields_MATCH_TYPE);
            $matchValue = trim((string)$rule->getData(ShippingSurchargeRule::schema_fields_MATCH_VALUE));
            $ok = match ($matchType) {
                ShippingSurchargeRule::MATCH_COUNTRY => $ruleCountry !== '' && $ruleCountry === $country,
                ShippingSurchargeRule::MATCH_PROVINCE => $matchValue !== '' && (
                    $province === $matchValue
                    || mb_strpos($province, $matchValue) !== false
                    || mb_strpos($matchValue, $province) !== false
                ),
                ShippingSurchargeRule::MATCH_POSTAL_PREFIX => $matchValue !== ''
                    && $postcode !== ''
                    && str_starts_with($postcode, $matchValue),
                default => false,
            };
            if (!$ok) {
                continue;
            }
            $amountType = (string)$rule->getData(ShippingSurchargeRule::schema_fields_AMOUNT_TYPE);
            $amountValue = (float)$rule->getData(ShippingSurchargeRule::schema_fields_AMOUNT_VALUE);
            if ($amountType === ShippingSurchargeRule::AMOUNT_PERCENT) {
                $extra = (int)round($baseAmountMinor * max(0, $amountValue) / 100);
            } else {
                $scale = 10 ** max(0, min(6, $currencyPrecision));
                $extra = (int)round(max(0, $amountValue) * $scale);
            }
            if ($extra <= 0) {
                continue;
            }
            $hits[] = [
                'rule_id' => (int)$rule->getId(),
                'rule_code' => (string)$rule->getData(ShippingSurchargeRule::schema_fields_RULE_CODE),
                'rule_name' => (string)$rule->getData(ShippingSurchargeRule::schema_fields_RULE_NAME),
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
