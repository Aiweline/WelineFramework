<?php

declare(strict_types=1);

namespace Weline\Affiliate\Service;

use Weline\Affiliate\Model\AffiliateCommission;
use Weline\Affiliate\Model\AffiliateShare;
use Weline\Affiliate\Model\AffiliateTouch;
use Weline\Framework\Manager\ObjectManager;

/** 分销漏斗与平台维度统计（前台账户中心 + 后台详情复用）。 */
final class AffiliateAnalyticsService
{
    public function __construct(
        private readonly AffiliateScopeFormDataService $scopeFormDataService,
        private readonly ?AffiliateShare $shareModel = null,
        private readonly ?AffiliateTouch $touchModel = null,
        private readonly ?AffiliateCommission $commissionModel = null,
    ) {
    }

    /**
     * @return array{
     *     conversion_funnel:array<string,int|float>,
     *     platform_funnel:list<array<string,mixed>>,
     *     scope_label:string,
     *     website_id:int,
     *     store_code:string,
     *     channel_code:string
     * }
     */
    public function buildAffiliateAnalytics(int $affiliateId, array $affiliateRow = []): array
    {
        $websiteId = (int) ($affiliateRow['website_id'] ?? 0);
        $storeCode = (string) ($affiliateRow['store_code'] ?? '');
        $channelCode = (string) ($affiliateRow['channel_code'] ?? '');

        if ($affiliateId <= 0) {
            return $this->emptyAnalytics($websiteId, $storeCode, $channelCode);
        }

        try {
            $shares = $this->newShareModel()->clear()
                ->where(AffiliateShare::schema_fields_AFFILIATE_ID, $affiliateId)
                ->select()
                ->fetchArray();
            $touches = $this->newTouchModel()->clear()
                ->where(AffiliateTouch::schema_fields_AFFILIATE_ID, $affiliateId)
                ->select()
                ->fetchArray();
            $commissions = $this->newCommissionModel()->clear()
                ->where(AffiliateCommission::schema_fields_AFFILIATE_ID, $affiliateId)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return $this->emptyAnalytics($websiteId, $storeCode, $channelCode);
        }

        $shares = array_values(array_filter(is_array($shares) ? $shares : [], 'is_array'));
        $touches = array_values(array_filter(is_array($touches) ? $touches : [], 'is_array'));
        $commissions = array_values(array_filter(is_array($commissions) ? $commissions : [], 'is_array'));

        $shareChannelById = [];
        foreach ($shares as $share) {
            $shareId = (int) ($share[AffiliateShare::schema_fields_ID] ?? 0);
            if ($shareId <= 0) {
                continue;
            }
            $shareChannelById[$shareId] = trim((string) ($share[AffiliateShare::schema_fields_CHANNEL] ?? ''));
        }

        /** @var array<string, array<string, int|float>> $platformBuckets */
        $platformBuckets = [];
        $ensureBucket = function (string $platform) use (&$platformBuckets): array {
            if (!isset($platformBuckets[$platform])) {
                $platformBuckets[$platform] = [
                    'outbound_count' => 0,
                    'click_count' => 0,
                    'view_count' => 0,
                    'registered_count' => 0,
                    'wishlist_count' => 0,
                    'add_to_cart_count' => 0,
                    'order_count' => 0,
                    'paid_count' => 0,
                    'commission_amount' => 0.0,
                ];
            }

            return $platformBuckets[$platform];
        };

        foreach ($shares as $share) {
            $platform = $this->normalizePlatformKey((string) ($share[AffiliateShare::schema_fields_CHANNEL] ?? ''));
            $bucket = $ensureBucket($platform);
            $platformBuckets[$platform] = [
                'outbound_count' => (int) $bucket['outbound_count'] + (int) ($share[AffiliateShare::schema_fields_OUTBOUND_COUNT] ?? 0),
                'click_count' => (int) $bucket['click_count'] + (int) ($share[AffiliateShare::schema_fields_CLICK_COUNT] ?? 0),
                'view_count' => (int) ($bucket['view_count'] ?? 0),
                'registered_count' => (int) ($bucket['registered_count'] ?? 0),
                'wishlist_count' => (int) ($bucket['wishlist_count'] ?? 0),
                'add_to_cart_count' => (int) ($bucket['add_to_cart_count'] ?? 0),
                'order_count' => (int) $bucket['order_count'] + (int) ($share[AffiliateShare::schema_fields_ORDER_COUNT] ?? 0),
                'paid_count' => (int) ($bucket['paid_count'] ?? 0),
                'commission_amount' => (float) ($bucket['commission_amount'] ?? 0),
            ];
        }

        $conversionFunnel = [
            'outbound_share_count' => 0,
            'click_count' => 0,
            'view_count' => 0,
            'registered_count' => 0,
            'wishlist_count' => 0,
            'add_to_cart_count' => 0,
            'order_count' => 0,
            'paid_count' => 0,
            'commission_amount' => 0.0,
        ];

        foreach ($shares as $share) {
            $conversionFunnel['outbound_share_count'] += (int) ($share[AffiliateShare::schema_fields_OUTBOUND_COUNT] ?? 0);
            $conversionFunnel['click_count'] += (int) ($share[AffiliateShare::schema_fields_CLICK_COUNT] ?? 0);
            $conversionFunnel['order_count'] += (int) ($share[AffiliateShare::schema_fields_ORDER_COUNT] ?? 0);
        }

        foreach ($touches as $touch) {
            $eventType = (string) ($touch[AffiliateTouch::schema_fields_EVENT_TYPE] ?? '');
            $shareId = (int) ($touch[AffiliateTouch::schema_fields_SHARE_ID] ?? 0);
            $platform = $shareId > 0
                ? $this->normalizePlatformKey((string) ($shareChannelById[$shareId] ?? ''))
                : $this->normalizePlatformKey((string) ($touch[AffiliateTouch::schema_fields_CHANNEL] ?? ''));
            $bucket = $ensureBucket($platform);

            if ($eventType === AffiliateService::EVENT_PRODUCT_VIEWED) {
                ++$conversionFunnel['view_count'];
                $bucket['view_count'] = (int) ($bucket['view_count'] ?? 0) + 1;
            } elseif ($eventType === AffiliateService::EVENT_CUSTOMER_REGISTERED) {
                ++$conversionFunnel['registered_count'];
                $bucket['registered_count'] = (int) ($bucket['registered_count'] ?? 0) + 1;
            } elseif ($eventType === AffiliateService::EVENT_WISHLIST_ADDED) {
                ++$conversionFunnel['wishlist_count'];
                $bucket['wishlist_count'] = (int) ($bucket['wishlist_count'] ?? 0) + 1;
            } elseif ($eventType === AffiliateService::EVENT_ADD_TO_CART) {
                ++$conversionFunnel['add_to_cart_count'];
                $bucket['add_to_cart_count'] = (int) ($bucket['add_to_cart_count'] ?? 0) + 1;
            } elseif ($eventType === AffiliateService::EVENT_PAYMENT_PAID) {
                ++$conversionFunnel['paid_count'];
                $bucket['paid_count'] = (int) ($bucket['paid_count'] ?? 0) + 1;
            }

            $platformBuckets[$platform] = $bucket;
        }

        $orderIds = [];
        foreach ($commissions as $commission) {
            $amount = (float) ($commission[AffiliateCommission::schema_fields_COMMISSION_AMOUNT] ?? 0);
            $conversionFunnel['commission_amount'] = round((float) $conversionFunnel['commission_amount'] + $amount, 2);
            $shareId = (int) ($commission[AffiliateCommission::schema_fields_SHARE_ID] ?? 0);
            $platform = $shareId > 0
                ? $this->normalizePlatformKey((string) ($shareChannelById[$shareId] ?? ''))
                : 'direct';
            $bucket = $ensureBucket($platform);
            $bucket['commission_amount'] = round((float) ($bucket['commission_amount'] ?? 0) + $amount, 2);
            $platformBuckets[$platform] = $bucket;
            $orderId = (int) ($commission[AffiliateCommission::schema_fields_ORDER_ID] ?? 0);
            if ($orderId > 0) {
                $orderIds[$orderId] = true;
            }
        }
        if ($conversionFunnel['order_count'] <= 0) {
            $conversionFunnel['order_count'] = count($orderIds);
        }

        $platformFunnel = [];
        foreach ($platformBuckets as $platform => $bucket) {
            $platformFunnel[] = [
                'platform' => $platform,
                'platform_label' => $this->platformLabel($platform),
                'outbound_count' => (int) ($bucket['outbound_count'] ?? 0),
                'click_count' => (int) ($bucket['click_count'] ?? 0),
                'view_count' => (int) ($bucket['view_count'] ?? 0),
                'registered_count' => (int) ($bucket['registered_count'] ?? 0),
                'wishlist_count' => (int) ($bucket['wishlist_count'] ?? 0),
                'add_to_cart_count' => (int) ($bucket['add_to_cart_count'] ?? 0),
                'order_count' => (int) ($bucket['order_count'] ?? 0),
                'paid_count' => (int) ($bucket['paid_count'] ?? 0),
                'commission_amount' => round((float) ($bucket['commission_amount'] ?? 0), 2),
            ];
        }

        usort($platformFunnel, static function (array $left, array $right): int {
            return ($right['click_count'] <=> $left['click_count'])
                ?: ($right['commission_amount'] <=> $left['commission_amount']);
        });

        return [
            'conversion_funnel' => $conversionFunnel,
            'platform_funnel' => $platformFunnel,
            'scope_label' => $this->scopeFormDataService->buildScopeLabel($websiteId, $storeCode, $channelCode),
            'website_id' => $websiteId,
            'store_code' => $storeCode,
            'channel_code' => $channelCode,
        ];
    }

    /** @return array{conversion_funnel:array<string,int|float>,platform_funnel:list<array<string,mixed>>,scope_label:string,website_id:int,store_code:string,channel_code:string} */
    private function emptyAnalytics(int $websiteId, string $storeCode, string $channelCode): array
    {
        return [
            'conversion_funnel' => [
                'outbound_share_count' => 0,
                'click_count' => 0,
                'view_count' => 0,
                'registered_count' => 0,
                'wishlist_count' => 0,
                'add_to_cart_count' => 0,
                'order_count' => 0,
                'paid_count' => 0,
                'commission_amount' => 0.0,
            ],
            'platform_funnel' => [],
            'scope_label' => $this->scopeFormDataService->buildScopeLabel($websiteId, $storeCode, $channelCode),
            'website_id' => $websiteId,
            'store_code' => $storeCode,
            'channel_code' => $channelCode,
        ];
    }

    private function normalizePlatformKey(string $platform): string
    {
        $platform = strtolower(trim($platform));

        return $platform !== '' ? $platform : 'direct';
    }

    private function platformLabel(string $platform): string
    {
        return match ($platform) {
            'direct', '' => (string) \__('直接访问'),
            'copy' => (string) \__('复制链接'),
            'homepage' => (string) \__('首页推广'),
            'facebook' => 'Facebook',
            'x', 'twitter' => 'X',
            'linkedin' => 'LinkedIn',
            'whatsapp' => 'WhatsApp',
            'wechat' => (string) \__('微信'),
            'weibo' => (string) \__('微博'),
            default => ucfirst(str_replace('_', ' ', $platform)),
        };
    }

    private function newShareModel(): AffiliateShare
    {
        return $this->shareModel ? clone $this->shareModel : ObjectManager::getInstance(AffiliateShare::class);
    }

    private function newTouchModel(): AffiliateTouch
    {
        return $this->touchModel ? clone $this->touchModel : ObjectManager::getInstance(AffiliateTouch::class);
    }

    private function newCommissionModel(): AffiliateCommission
    {
        return $this->commissionModel ? clone $this->commissionModel : ObjectManager::getInstance(AffiliateCommission::class);
    }
}
