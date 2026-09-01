<?php

declare(strict_types=1);

namespace Weline\Maintenance\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Marketing\Api\Coupon\RandomCouponCampaignProviderInterface;
use Weline\Marketing\Api\Coupon\RandomCouponCampaignRequest;

/**
 * Syncs maintenance wait-gift settings into Marketing random-coupon campaign.
 */
final class WaitGiftCampaignSyncService
{
    public const SOURCE_MODULE = 'Weline_Maintenance';
    public const SOURCE_TYPE = 'maintenance_wait_gift';
    public const SOURCE_ID = 'default';

    /**
     * @param array<string, mixed> $config
     * @return array{rule_id:int,enabled:bool}
     */
    public function sync(array $config): array
    {
        $enabled = (bool)($config['enabled'] ?? false);
        $type = \strtolower(\trim((string)($config['discount_type'] ?? 'fixed_amount')));
        $value = \round(\max(0, (float)($config['discount_value'] ?? 0)), 2);
        $existing = (int)($config['marketing_rule_id'] ?? 0);

        $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(RandomCouponCampaignProviderInterface::class);
        if (!$provider instanceof RandomCouponCampaignProviderInterface) {
            return ['rule_id' => $existing, 'enabled' => false];
        }

        $result = $provider->upsert(new RandomCouponCampaignRequest(
            sourceModule: self::SOURCE_MODULE,
            sourceType: self::SOURCE_TYPE,
            sourceId: self::SOURCE_ID,
            sourceKey: 'wait_gift',
            displayName: (string)\__('维护等待补偿礼金'),
            discountType: $type === 'percentage' || $type === 'percent'
                ? RandomCouponCampaignRequest::DISCOUNT_PERCENTAGE
                : RandomCouponCampaignRequest::DISCOUNT_FIXED,
            discountValue: $value,
            active: $enabled && $value > 0,
            existingRuleId: $existing,
            priority: 95,
            metadata: [
                'min_wait_sec' => (int)($config['min_wait_sec'] ?? UpgradeWaveService::MIN_WAIT_SECONDS_DEFAULT),
            ],
        ));

        $ruleId = \max(0, $result->ruleId);
        $waves = new UpgradeWaveService();
        $waves->writeGiftConfig([
            'enabled' => $enabled && $result->enabled,
            'min_wait_sec' => (int)($config['min_wait_sec'] ?? UpgradeWaveService::MIN_WAIT_SECONDS_DEFAULT),
            'marketing_rule_id' => $ruleId,
            'discount_type' => $type === 'percentage' || $type === 'percent' ? 'percentage' : 'fixed_amount',
            'discount_value' => $value,
        ]);

        return [
            'rule_id' => $ruleId,
            'enabled' => $result->enabled,
        ];
    }
}
