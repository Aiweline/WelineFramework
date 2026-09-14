<?php

declare(strict_types=1);

namespace Weline\Marketing\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Api\Coupon\RandomCouponCampaignProviderInterface;
use Weline\Marketing\Api\Coupon\RandomCouponCampaignRequest;
use Weline\Marketing\Api\Coupon\RandomCouponCampaignResult;
use Weline\Marketing\Model\Coupon\Coupon;
use Weline\Marketing\Model\Rule\Rule;

/**
 * Upserts coupon-type rules and mints one-time random codes for external modules.
 */
final class RandomCouponCampaignProvider implements RandomCouponCampaignProviderInterface
{
    public function upsert(RandomCouponCampaignRequest $request): RandomCouponCampaignResult
    {
        $sourceModule = \trim($request->sourceModule);
        $sourceType = \trim($request->sourceType);
        $sourceId = \trim($request->sourceId);
        if ($sourceModule === '' || $sourceType === '' || $sourceId === '') {
            return new RandomCouponCampaignResult(ruleId: 0, enabled: false);
        }

        $discountType = $this->normalizeDiscountType($request->discountType);
        $discountValue = \round(\max(0, $request->discountValue), 2);
        $enabled = $request->active
            && $discountType !== ''
            && $discountValue > 0;

        /** @var Rule $rule */
        $rule = ObjectManager::getInstance(Rule::class);
        $ruleId = \max(0, $request->existingRuleId);
        if ($ruleId > 0) {
            $rule->load($ruleId);
        }
        if (!$rule->getId()) {
            $rule = ObjectManager::getInstance(Rule::class);
            $rule->clearData();
        }

        $now = \date('Y-m-d H:i:s');
        $sourceKey = \trim($request->sourceKey);
        $name = \trim($request->displayName);
        if ($name === '') {
            $name = \sprintf('随机礼金·%s', $sourceKey !== '' ? $sourceKey : ($sourceType . ':' . $sourceId));
        }

        $rule->setData(Rule::schema_fields_NAME, $name);
        $rule->setData(Rule::schema_fields_DESCRIPTION, $this->buildDescription($request));
        $rule->setData(Rule::schema_fields_RULE_TYPE, Rule::RULE_TYPE_COUPON);
        $rule->setData(
            Rule::schema_fields_STATUS,
            $enabled ? Rule::STATUS_ACTIVE : Rule::STATUS_INACTIVE,
        );
        $rule->setData(Rule::schema_fields_PRIORITY, \max(0, $request->priority));
        $rule->setData(Rule::schema_fields_IS_STOP_PROCESSING, 0);
        $rule->setData(Rule::schema_fields_UPDATED_AT, $now);
        if (!$rule->getId()) {
            $rule->setData(Rule::schema_fields_CREATED_AT, $now);
        }

        if ($enabled) {
            $rule->setConditions([
                'type' => 'and',
                'conditions' => [],
            ]);
            $actionType = $discountType === RandomCouponCampaignRequest::DISCOUNT_FIXED
                ? 'discount_fixed_amount'
                : 'discount_percentage';
            $rule->setActions([[
                'type' => $actionType,
                'discount_value' => $discountValue,
                'apply_to' => 'subtotal',
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

        return new RandomCouponCampaignResult(
            ruleId: (int)$rule->getId(),
            enabled: $enabled,
        );
    }

    public function issueRandomCoupon(int $ruleId, array $context = []): array
    {
        if ($ruleId <= 0) {
            return ['coupon_code' => '', 'coupon_id' => 0];
        }

        /** @var Rule $rule */
        $rule = ObjectManager::getInstance(Rule::class);
        $rule->load($ruleId);
        if (!$rule->getId() || $rule->getData(Rule::schema_fields_STATUS) !== Rule::STATUS_ACTIVE) {
            return ['coupon_code' => '', 'coupon_id' => 0];
        }

        $discountType = RandomCouponCampaignRequest::DISCOUNT_FIXED;
        $discountValue = 0.0;
        $actions = $rule->getActions() ?? [];
        if ($actions !== [] && \is_array($actions[0] ?? null)) {
            $action = $actions[0];
            $type = (string)($action['type'] ?? '');
            if ($type === 'discount_percentage') {
                $discountType = Coupon::TYPE_PERCENTAGE;
            } elseif ($type === 'discount_fixed_amount') {
                $discountType = Coupon::TYPE_FIXED_AMOUNT;
            }
            $discountValue = (float)($action['discount_value'] ?? 0);
        }
        if (!empty($context['discount_type'])) {
            $ctxType = \strtolower(\trim((string)$context['discount_type']));
            if (\in_array($ctxType, [Coupon::TYPE_PERCENTAGE, 'percent', 'percentage'], true)) {
                $discountType = Coupon::TYPE_PERCENTAGE;
            } elseif (\in_array($ctxType, [Coupon::TYPE_FIXED_AMOUNT, 'fixed', 'fixed_amount'], true)) {
                $discountType = Coupon::TYPE_FIXED_AMOUNT;
            }
        }
        if (isset($context['discount_value']) && (float)$context['discount_value'] > 0) {
            $discountValue = (float)$context['discount_value'];
        }

        /** @var CouponService $coupons */
        $coupons = ObjectManager::getInstance(CouponService::class);
        /** @var CouponSourceAttribution $attribution */
        $attribution = ObjectManager::getInstance(CouponSourceAttribution::class);
        $source = $attribution->fromIssueContext($rule, $context);

        $prefix = 'MW';
        $code = $prefix . \strtoupper(\substr(\bin2hex(\random_bytes(4)), 0, 8));
        $coupon = $coupons->createCoupon([
            Coupon::schema_fields_RULE_ID => $ruleId,
            Coupon::schema_fields_CODE => $code,
            Coupon::schema_fields_TYPE => $discountType,
            Coupon::schema_fields_DISCOUNT_VALUE => $discountValue,
            Coupon::schema_fields_USAGE_LIMIT => 1,
            Coupon::schema_fields_CUSTOMER_LIMIT => 1,
            Coupon::schema_fields_STATUS => Coupon::STATUS_ACTIVE,
            Coupon::schema_fields_START_DATE => \date('Y-m-d H:i:s'),
            Coupon::schema_fields_END_DATE => \date('Y-m-d H:i:s', \time() + 86400 * 30),
            Coupon::schema_fields_SOURCE_MODULE => $source['source_module'],
            Coupon::schema_fields_SOURCE_TYPE => $source['source_type'],
            Coupon::schema_fields_SOURCE_ID => $source['source_id'],
            Coupon::schema_fields_SOURCE_KEY => $source['source_key'],
        ]);

        return [
            'coupon_code' => (string)$coupon->getData(Coupon::schema_fields_CODE),
            'coupon_id' => (int)$coupon->getId(),
        ];
    }

    private function normalizeDiscountType(string $type): string
    {
        $type = \strtolower(\trim($type));

        return match ($type) {
            RandomCouponCampaignRequest::DISCOUNT_PERCENTAGE, 'percent', 'pct' => RandomCouponCampaignRequest::DISCOUNT_PERCENTAGE,
            RandomCouponCampaignRequest::DISCOUNT_FIXED, 'fixed', 'amount' => RandomCouponCampaignRequest::DISCOUNT_FIXED,
            default => '',
        };
    }

    private function buildDescription(RandomCouponCampaignRequest $request): string
    {
        $parts = [
            'weline:external_managed=1',
            'source=' . \trim($request->sourceType),
            'module=' . \trim($request->sourceModule),
            'id=' . \trim($request->sourceId),
            'key=' . \trim($request->sourceKey),
            'kind=random_coupon',
        ];

        return '[' . \implode(';', $parts) . ']';
    }
}
