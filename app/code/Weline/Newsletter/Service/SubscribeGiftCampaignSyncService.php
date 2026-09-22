<?php

declare(strict_types=1);

namespace Weline\Newsletter\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Marketing\Api\Coupon\RandomCouponCampaignProviderInterface;
use Weline\Marketing\Api\Coupon\RandomCouponCampaignRequest;

/**
 * Syncs newsletter subscribe-gift settings into Marketing random-coupon campaign.
 */
final class SubscribeGiftCampaignSyncService
{
    public const SOURCE_MODULE = 'Weline_Newsletter';
    public const SOURCE_TYPE = 'newsletter_subscribe_gift';
    public const SOURCE_ID = 'default';
    public const SOURCE_KEY = 'subscribe_gift';

    public function __construct(
        private readonly SubscribeGiftConfig $config = new SubscribeGiftConfig(),
    ) {
    }

    /**
     * @param array<string, mixed>|null $override
     * @return array{rule_id:int,enabled:bool}
     */
    public function sync(?array $override = null): array
    {
        $cfg = $override !== null ? \array_merge($this->config->read(), $override) : $this->config->read();
        $enabled = (bool)($cfg['enabled'] ?? false);
        $type = $this->config->normalizeDiscountType((string)($cfg['discount_type'] ?? SubscribeGiftConfig::DEFAULT_DISCOUNT_TYPE));
        $value = \round(\max(0, (float)($cfg['discount_value'] ?? 0)), 2);
        $validDays = \max(1, (int)($cfg['valid_days'] ?? SubscribeGiftConfig::DEFAULT_VALID_DAYS));
        $existing = \max(0, (int)($cfg['marketing_rule_id'] ?? 0));

        $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(RandomCouponCampaignProviderInterface::class);
        if (!$provider instanceof RandomCouponCampaignProviderInterface) {
            return ['rule_id' => $existing, 'enabled' => false];
        }

        $result = $provider->upsert(new RandomCouponCampaignRequest(
            sourceModule: self::SOURCE_MODULE,
            sourceType: self::SOURCE_TYPE,
            sourceId: self::SOURCE_ID,
            sourceKey: self::SOURCE_KEY,
            displayName: (string)\__('邮件订阅欢迎礼'),
            discountType: $type === 'percentage'
                ? RandomCouponCampaignRequest::DISCOUNT_PERCENTAGE
                : RandomCouponCampaignRequest::DISCOUNT_FIXED,
            discountValue: $value,
            active: $enabled && $value > 0,
            existingRuleId: $existing,
            priority: 92,
            metadata: [
                'valid_days' => $validDays,
            ],
        ));

        $ruleId = \max(0, $result->ruleId);
        $this->config->write([
            'enabled' => $enabled && $result->enabled,
            'discount_type' => $type,
            'discount_value' => $value,
            'valid_days' => $validDays,
            'marketing_rule_id' => $ruleId,
        ]);

        return [
            'rule_id' => $ruleId,
            'enabled' => $result->enabled,
        ];
    }
}
