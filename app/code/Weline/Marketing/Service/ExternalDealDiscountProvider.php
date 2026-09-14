<?php

declare(strict_types=1);

namespace Weline\Marketing\Service;

use Weline\Framework\DateTime\Timezone;
use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Api\Deal\ExternalDealDiscountProviderInterface;
use Weline\Marketing\Api\Deal\ExternalDealDiscountRequest;
use Weline\Marketing\Api\Deal\ExternalDealDiscountResult;
use Weline\Marketing\Model\Rule\Rule;

/**
 * Upserts automatic rules for external modules (activity themes, campaigns, etc.).
 */
final class ExternalDealDiscountProvider implements ExternalDealDiscountProviderInterface
{
    public function upsert(ExternalDealDiscountRequest $request): ExternalDealDiscountResult
    {
        $sourceModule = trim($request->sourceModule);
        $sourceType = trim($request->sourceType);
        $sourceId = trim($request->sourceId);
        if ($sourceModule === '' || $sourceType === '' || $sourceId === '') {
            return new ExternalDealDiscountResult(ruleId: 0, skus: [], enabled: false);
        }

        $discountType = $this->normalizeDiscountType($request->discountType);
        $discountValue = round(max(0, $request->discountValue), 2);
        $skus = $this->normalizeSkus($request->skus);
        $enabled = $request->active
            && $discountType !== ExternalDealDiscountRequest::DISCOUNT_NONE
            && $discountValue > 0
            && $skus !== [];

        /** @var Rule $rule */
        $rule = ObjectManager::getInstance(Rule::class);
        $ruleId = max(0, $request->existingRuleId);
        if ($ruleId > 0) {
            $rule->load($ruleId);
        }
        if (!$rule->getId()) {
            $rule = ObjectManager::getInstance(Rule::class);
            $rule->clearData();
        }

        $now = Timezone::utcNowSql();
        $sourceKey = trim($request->sourceKey);
        $name = trim($request->displayName);
        if ($name === '') {
            $name = sprintf('外部折扣·%s', $sourceKey !== '' ? $sourceKey : ($sourceType . ':' . $sourceId));
        }

        $rule->setData(Rule::schema_fields_NAME, $name);
        $rule->setData(Rule::schema_fields_DESCRIPTION, $this->buildDescription($request));
        $rule->setData(Rule::schema_fields_RULE_TYPE, Rule::RULE_TYPE_AUTOMATIC);
        $rule->setData(
            Rule::schema_fields_STATUS,
            $enabled ? Rule::STATUS_ACTIVE : Rule::STATUS_INACTIVE,
        );
        $rule->setData(Rule::schema_fields_PRIORITY, max(0, $request->priority));
        $rule->setData(Rule::schema_fields_IS_STOP_PROCESSING, 0);
        $rule->setData(Rule::schema_fields_START_DATE, $request->startsAtUtc);
        $rule->setData(Rule::schema_fields_END_DATE, $request->endsAtUtc);
        $rule->setData(Rule::schema_fields_UPDATED_AT, $now);
        if (!$rule->getId()) {
            $rule->setData(Rule::schema_fields_CREATED_AT, $now);
        }

        if ($enabled) {
            $rule->setConditions([
                'type' => 'and',
                'conditions' => [[
                    'type' => 'product_sku',
                    'operator' => 'in',
                    'value' => $skus,
                ]],
            ]);
            $actionType = $discountType === ExternalDealDiscountRequest::DISCOUNT_FIXED
                ? 'discount_fixed_amount'
                : 'discount_percentage';
            $rule->setActions([[
                'type' => $actionType,
                'discount_value' => $discountValue,
                'apply_to' => 'matched_products',
                'sku_list' => $skus,
                'external_managed' => 1,
                'source_module' => $sourceModule,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_key' => $sourceKey,
                'metadata' => $request->metadata,
            ]]);
        } else {
            $rule->setConditions(['type' => 'and', 'conditions' => []]);
            $rule->setActions([]);
        }

        $rule->save();

        return new ExternalDealDiscountResult(
            ruleId: (int)$rule->getId(),
            skus: $skus,
            enabled: $enabled,
        );
    }

    public function normalizeDiscountType(string $type): string
    {
        $type = strtolower(trim($type));

        return match ($type) {
            ExternalDealDiscountRequest::DISCOUNT_PERCENTAGE, 'percent', 'pct' => ExternalDealDiscountRequest::DISCOUNT_PERCENTAGE,
            ExternalDealDiscountRequest::DISCOUNT_FIXED, 'fixed', 'amount' => ExternalDealDiscountRequest::DISCOUNT_FIXED,
            default => ExternalDealDiscountRequest::DISCOUNT_NONE,
        };
    }

    /**
     * @param list<string>|array<int, mixed> $skus
     * @return list<string>
     */
    private function normalizeSkus(array $skus): array
    {
        $out = [];
        foreach ($skus as $sku) {
            $sku = trim((string)$sku);
            if ($sku !== '') {
                $out[$sku] = true;
            }
        }

        return array_keys($out);
    }

    private function buildDescription(ExternalDealDiscountRequest $request): string
    {
        return sprintf(
            '[weline:external_managed=1;source=%s;module=%s;id=%s;key=%s] %s',
            trim($request->sourceType),
            trim($request->sourceModule),
            trim($request->sourceId),
            trim($request->sourceKey),
            (string)__('外部模块自动同步的折扣规则，请到来源模块管理活动以禁用；折扣模块不可删除。'),
        );
    }
}
