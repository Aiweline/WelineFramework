<?php

declare(strict_types=1);

namespace Weline\Newsletter\Service;

use Weline\Framework\DateTime\Timezone;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Marketing\Api\Coupon\RandomCouponCampaignProviderInterface;
use Weline\Newsletter\Model\Subscriber;

/**
 * Lifetime-once welcome gift issuer for newsletter subscribers.
 */
final class SubscribeGiftIssuer
{
    public function __construct(
        private readonly SubscribeGiftConfig $config = new SubscribeGiftConfig(),
    ) {
    }

    /**
     * @return array{issued:bool,coupon_code:string,coupon_id:int,skipped:bool,reason:string}
     */
    public function issueIfEligible(Subscriber $subscriber): array
    {
        $giftStatus = (string)$subscriber->getData(Subscriber::schema_fields_GIFT_STATUS);
        if (\in_array($giftStatus, [Subscriber::GIFT_ISSUED, Subscriber::GIFT_REDEEMED], true)) {
            return $this->skip('already_issued', (string)$subscriber->getData(Subscriber::schema_fields_COUPON_CODE), (int)$subscriber->getData(Subscriber::schema_fields_COUPON_ID));
        }

        $cfg = $this->config->read();
        if (!(bool)($cfg['enabled'] ?? false)) {
            return $this->skip('gift_disabled');
        }

        $ruleId = \max(0, (int)($cfg['marketing_rule_id'] ?? 0));
        if ($ruleId <= 0) {
            $synced = (new SubscribeGiftCampaignSyncService($this->config))->sync();
            $ruleId = \max(0, (int)($synced['rule_id'] ?? 0));
            if ($ruleId <= 0 || !(bool)($synced['enabled'] ?? false)) {
                return $this->skip('campaign_inactive');
            }
        }

        $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(RandomCouponCampaignProviderInterface::class);
        if (!$provider instanceof RandomCouponCampaignProviderInterface) {
            return $this->skip('provider_missing');
        }

        $subscriberId = (int)$subscriber->getId();
        $email = (string)$subscriber->getData(Subscriber::schema_fields_EMAIL);
        $validDays = \max(1, (int)($cfg['valid_days'] ?? SubscribeGiftConfig::DEFAULT_VALID_DAYS));

        $issued = $provider->issueRandomCoupon($ruleId, [
            'source' => SubscribeGiftCampaignSyncService::SOURCE_TYPE,
            'source_module' => SubscribeGiftCampaignSyncService::SOURCE_MODULE,
            'source_type' => SubscribeGiftCampaignSyncService::SOURCE_TYPE,
            'source_id' => SubscribeGiftCampaignSyncService::SOURCE_ID,
            'source_key' => SubscribeGiftCampaignSyncService::SOURCE_KEY,
            'subscriber_id' => $subscriberId,
            'email_hash' => $email !== '' ? \hash('sha256', $email) : '',
            'valid_days' => $validDays,
            'discount_type' => (string)($cfg['discount_type'] ?? 'percentage'),
            'discount_value' => (float)($cfg['discount_value'] ?? 10),
        ]);

        $code = \strtoupper(\trim((string)($issued['coupon_code'] ?? '')));
        $couponId = \max(0, (int)($issued['coupon_id'] ?? 0));
        if ($code === '') {
            return $this->skip('coupon_issue_failed');
        }

        $now = Timezone::utcNowSql();
        $subscriber
            ->setData(Subscriber::schema_fields_COUPON_ID, $couponId > 0 ? $couponId : null)
            ->setData(Subscriber::schema_fields_COUPON_CODE, $code)
            ->setData(Subscriber::schema_fields_GIFT_STATUS, Subscriber::GIFT_ISSUED)
            ->setData(Subscriber::schema_fields_GIFT_ISSUED_AT, $now)
            ->setData(Subscriber::schema_fields_UPDATED_AT, $now)
            ->save();

        return [
            'issued' => true,
            'coupon_code' => $code,
            'coupon_id' => $couponId,
            'skipped' => false,
            'reason' => '',
        ];
    }

    /**
     * @return array{issued:bool,coupon_code:string,coupon_id:int,skipped:bool,reason:string}
     */
    private function skip(string $reason, string $code = '', int $couponId = 0): array
    {
        return [
            'issued' => false,
            'coupon_code' => $code,
            'coupon_id' => $couponId,
            'skipped' => true,
            'reason' => $reason,
        ];
    }
}
