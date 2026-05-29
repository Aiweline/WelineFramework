<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Service;

use WeShop\Affiliate\Model\Affiliate;
use WeShop\Affiliate\Model\AffiliateAttribution;
use WeShop\Affiliate\Model\AffiliateCommission;
use WeShop\Affiliate\Model\AffiliateShare;
use WeShop\Affiliate\Model\AffiliateTouch;
use WeShop\Affiliate\Model\AffiliateWithdrawal;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;

class AffiliateService
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    public const SHARE_STATUS_ACTIVE = 'active';
    public const SHARE_STATUS_DISABLED = 'disabled';

    public const ATTRIBUTION_STATUS_ACTIVE = 'active';
    public const ATTRIBUTION_STATUS_EXPIRED = 'expired';

    public const COMMISSION_STATUS_PENDING = 'pending';
    public const COMMISSION_STATUS_APPROVED = 'approved';
    public const COMMISSION_STATUS_PAID = 'paid';
    public const COMMISSION_STATUS_CANCELLED = 'cancelled';
    public const COMMISSION_STATUS_REVERSED = 'reversed';

    public const WITHDRAWAL_STATUS_REQUESTED = 'requested';
    public const WITHDRAWAL_STATUS_PROCESSING = 'processing';
    public const WITHDRAWAL_STATUS_PAID = 'paid';
    public const WITHDRAWAL_STATUS_REJECTED = 'rejected';
    public const WITHDRAWAL_STATUS_CANCELLED = 'cancelled';

    public const EVENT_SHARE_OUTBOUND = 'share_outbound';
    public const EVENT_SHARE_CLICKED = 'share_clicked';
    public const EVENT_PRODUCT_VIEWED = 'product_viewed';
    public const EVENT_WISHLIST_ADDED = 'wishlist_added';
    public const EVENT_ADD_TO_CART = 'add_to_cart';
    public const EVENT_REVIEW_CREATED = 'review_created';
    public const EVENT_CUSTOMER_REGISTERED = 'customer_registered';
    public const EVENT_ORDER_CREATED = 'order_created';
    public const EVENT_PAYMENT_PAID = 'payment_paid';
    public const EVENT_ORDER_CANCELLED = 'order_cancelled';
    public const EVENT_ORDER_REFUNDED = 'order_refunded';

    private const ATTRIBUTION_TTL_SECONDS = 2592000;
    private const VISITOR_COOKIE = 'weshop_affiliate_visitor';
    private const ATTRIBUTION_COOKIE = 'weshop_affiliate_share';
    private const ATTRIBUTION_SESSION_KEY = 'weshop_affiliate_attribution';
    private const CLICK_DEDUPE_SECONDS = 1800;
    private const PRODUCT_VIEW_DEDUPE_SECONDS = 60;
    private const PRODUCT_VIEW_ROUTE = 'product/frontend/product/view';

    public function __construct(
        private readonly ?Affiliate $affiliateModel = null,
        private readonly ?AffiliateShare $shareModel = null,
        private readonly ?AffiliateTouch $touchModel = null,
        private readonly ?AffiliateAttribution $attributionModel = null,
        private readonly ?AffiliateCommission $commissionModel = null,
        private readonly ?EventsManager $eventsManager = null,
        private readonly ?Url $url = null,
        private readonly ?CustomerSession $customerSession = null,
        private readonly ?AffiliateWithdrawal $withdrawalModel = null
    ) {
    }

    public function createAffiliate(int $customerId): Affiliate
    {
        if ($customerId <= 0) {
            throw new \InvalidArgumentException((string) __('Customer ID is required.'));
        }

        $existing = $this->getAffiliateAccount($customerId);
        if (is_array($existing) && (int) ($existing[Affiliate::schema_fields_ID] ?? 0) > 0) {
            $affiliate = $this->newAffiliateModel();
            $affiliate->load((int) $existing[Affiliate::schema_fields_ID]);
            return $affiliate;
        }

        $now = date('Y-m-d H:i:s');

        $affiliate = $this->newAffiliateModel();
        $affiliate->clearData()->save([
            Affiliate::schema_fields_CUSTOMER_ID => $customerId,
            Affiliate::schema_fields_REFERRAL_CODE => $this->generateReferralCode($customerId),
            Affiliate::schema_fields_COMMISSION_RATE => 0.10,
            Affiliate::schema_fields_TOTAL_COMMISSION => 0.0,
            Affiliate::schema_fields_PAID_COMMISSION => 0.0,
            Affiliate::schema_fields_STATUS => self::STATUS_ACTIVE,
            Affiliate::schema_fields_CREATED_AT => $now,
            Affiliate::schema_fields_UPDATED_AT => $now,
        ]);

        return $affiliate;
    }

    public function calculateCommission(string $referralCode, float $orderTotal): float
    {
        if ($referralCode === '' || $orderTotal <= 0) {
            return 0.0;
        }

        $affiliate = $this->newAffiliateModel();
        $affiliate->load(Affiliate::schema_fields_REFERRAL_CODE, $referralCode);
        if (
            !$affiliate->getId()
            || (string) ($affiliate->getData(Affiliate::schema_fields_STATUS) ?? '') !== self::STATUS_ACTIVE
        ) {
            return 0.0;
        }

        $rate = (float) ($affiliate->getData(Affiliate::schema_fields_COMMISSION_RATE) ?? 0);
        if ($rate <= 0) {
            return 0.0;
        }

        return round($orderTotal * $rate, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function getProductShareLinks(int $customerId, int $productId, string $channel = ''): array
    {
        if ($customerId <= 0) {
            throw new \InvalidArgumentException((string) __('请先登录后再生成分享链接。'));
        }
        if ($productId <= 0) {
            throw new \InvalidArgumentException((string) __('缺少商品 ID。'));
        }

        $account = $this->getAffiliateAccountOrCreate($customerId);
        if ((string) ($account[Affiliate::schema_fields_STATUS] ?? self::STATUS_DISABLED) !== self::STATUS_ACTIVE) {
            throw new \RuntimeException((string) __('当前分销账户不可用。'));
        }

        $targetPath = $this->resolveProductShareTargetPath($productId);
        $share = $this->ensureProductShare(
            (int) ($account[Affiliate::schema_fields_ID] ?? 0),
            $customerId,
            $productId,
            $this->normalizeChannel($channel),
            $targetPath
        );

        $shareCode = (string) ($share->getData(AffiliateShare::schema_fields_SHARE_CODE) ?? '');
        $trackingUrl = $this->url()->getOriginUrl('affiliate/redirect', ['code' => $shareCode]);
        $productUrl = $this->resolveShareTargetUrl($share);

        return [
            'share_id' => (int) ($share->getId() ?? 0),
            'share_code' => $shareCode,
            'affiliate_id' => (int) ($share->getData(AffiliateShare::schema_fields_AFFILIATE_ID) ?? 0),
            'product_id' => $productId,
            'channel' => (string) ($share->getData(AffiliateShare::schema_fields_CHANNEL) ?? ''),
            'tracking_url' => $trackingUrl,
            'product_url' => $productUrl,
            'platform_urls' => $this->buildPlatformShareUrls($trackingUrl),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getShareLink(int $customerId, string $targetUrl = '', string $channel = ''): array
    {
        if ($customerId <= 0) {
            throw new \InvalidArgumentException((string) __('请先登录后再生成推广链接。'));
        }

        $account = $this->getAffiliateAccountOrCreate($customerId);
        if ((string) ($account[Affiliate::schema_fields_STATUS] ?? self::STATUS_DISABLED) !== self::STATUS_ACTIVE) {
            throw new \RuntimeException((string) __('当前分销账户不可用。'));
        }

        $target = $this->normalizeShareTarget($targetUrl);
        $channel = $this->normalizeChannel($channel);
        if ($channel === '') {
            $channel = $target['channel'];
        }

        $share = $this->ensureProductShare(
            (int) ($account[Affiliate::schema_fields_ID] ?? 0),
            $customerId,
            0,
            $channel,
            $target['target_path']
        );

        $shareCode = (string) ($share->getData(AffiliateShare::schema_fields_SHARE_CODE) ?? '');
        $trackingUrl = $this->url()->getOriginUrl('affiliate/redirect', ['code' => $shareCode]);

        return [
            'share_id' => (int) ($share->getId() ?? 0),
            'share_code' => $shareCode,
            'affiliate_id' => (int) ($share->getData(AffiliateShare::schema_fields_AFFILIATE_ID) ?? 0),
            'product_id' => 0,
            'channel' => (string) ($share->getData(AffiliateShare::schema_fields_CHANNEL) ?? ''),
            'target_path' => $target['target_path'],
            'target_label' => $target['label'],
            'tracking_url' => $trackingUrl,
            'target_url' => $this->url()->getOriginUrl($target['target_path']),
            'platform_urls' => $this->buildPlatformShareUrls($trackingUrl),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recordOutboundShare(string $shareCode, string $platform, int $customerId = 0, array $metadata = []): array
    {
        $share = $this->requireShareByCode($shareCode);
        $platform = $this->normalizeChannel($platform);
        if ($platform === '') {
            throw new \InvalidArgumentException((string) __('缺少分享平台。'));
        }

        $touch = $this->recordTouch(self::EVENT_SHARE_OUTBOUND, [
            'share_id' => (int) ($share->getId() ?? 0),
            'affiliate_id' => (int) ($share->getData(AffiliateShare::schema_fields_AFFILIATE_ID) ?? 0),
            'product_id' => (int) ($share->getData(AffiliateShare::schema_fields_PRODUCT_ID) ?? 0),
            'customer_id' => max(0, $customerId),
            'visitor_key' => $this->getVisitorKey(false),
            'channel' => $platform,
            'metadata' => $metadata,
        ]);

        $this->incrementShareCounter($share, AffiliateShare::schema_fields_OUTBOUND_COUNT);

        $payload = [
            'share' => $share,
            'touch' => $touch,
            'share_code' => $shareCode,
            'platform' => $platform,
            'customer_id' => $customerId,
            'product_id' => (int) ($share->getData(AffiliateShare::schema_fields_PRODUCT_ID) ?? 0),
        ];
        $this->dispatchAffiliateEvent('share_outbound', $payload);

        return [
            'share_code' => $shareCode,
            'platform' => $platform,
            'touch_id' => (int) ($touch->getId() ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recordShareClick(string $shareCode, int $customerId = 0): array
    {
        $share = $this->requireShareByCode($shareCode);
        $visitorKey = $this->getVisitorKey(true);
        $shareId = (int) ($share->getId() ?? 0);
        $affiliateId = (int) ($share->getData(AffiliateShare::schema_fields_AFFILIATE_ID) ?? 0);
        $affiliateCustomerId = (int) ($share->getData(AffiliateShare::schema_fields_CUSTOMER_ID) ?? 0);
        $productId = (int) ($share->getData(AffiliateShare::schema_fields_PRODUCT_ID) ?? 0);
        $isSelfClick = $customerId > 0 && $customerId === $affiliateCustomerId;
        $duplicateClick = $this->hasRecentTouch($shareId, self::EVENT_SHARE_CLICKED, $visitorKey, self::CLICK_DEDUPE_SECONDS);

        $touch = null;
        if (!$duplicateClick) {
            $touch = $this->recordTouch(self::EVENT_SHARE_CLICKED, [
                'share_id' => $shareId,
                'affiliate_id' => $affiliateId,
                'product_id' => $productId,
                'customer_id' => max(0, $customerId),
                'visitor_key' => $visitorKey,
                'channel' => (string) ($share->getData(AffiliateShare::schema_fields_CHANNEL) ?? ''),
                'metadata' => ['self_click' => $isSelfClick],
            ]);
            $this->incrementShareCounter($share, AffiliateShare::schema_fields_CLICK_COUNT);
        }

        $attribution = null;
        if (!$isSelfClick) {
            $attribution = $this->startAttribution($share, max(0, $customerId), $visitorKey);
        }

        $targetUrl = $this->resolveShareTargetUrl($share);
        $payload = [
            'share' => $share,
            'touch' => $touch,
            'attribution' => $attribution,
            'share_code' => $shareCode,
            'customer_id' => $customerId,
            'visitor_key' => $visitorKey,
            'product_id' => $productId,
            'is_self_click' => $isSelfClick,
            'is_duplicate_click' => $duplicateClick,
            'target_url' => $targetUrl,
        ];
        $this->dispatchAffiliateEvent('share_clicked', $payload);

        if (!$isSelfClick && !$duplicateClick && $productId > 0) {
            $this->recordEngagement(self::EVENT_PRODUCT_VIEWED, [
                'product_id' => $productId,
                'customer_id' => max(0, $customerId),
                'metadata' => ['source' => 'product_share_redirect'],
                'idempotency_key' => 'product_share_redirect:' . $shareId . ':' . $visitorKey,
            ]);
        }

        return [
            'share_code' => $shareCode,
            'target_url' => $targetUrl,
            'attributed' => !$isSelfClick,
            'duplicate' => $duplicateClick,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function recordEngagement(string $eventType, array $payload = []): ?array
    {
        $eventType = $this->normalizeEventType($eventType);
        if ($eventType === '') {
            return null;
        }

        $customerId = max(0, (int) ($payload['customer_id'] ?? $this->getCurrentCustomerId()));
        $visitorKey = $this->getVisitorKey(false);
        $attribution = $this->resolveActiveAttribution($customerId, $visitorKey);
        if (!is_array($attribution)) {
            return null;
        }

        $productId = max(0, (int) ($payload['product_id'] ?? 0));
        $attributionProductId = (int) ($attribution[AffiliateAttribution::schema_fields_PRODUCT_ID] ?? 0);
        if ($productId > 0 && $attributionProductId > 0 && $productId !== $attributionProductId) {
            return null;
        }

        $shareId = (int) ($attribution[AffiliateAttribution::schema_fields_SHARE_ID] ?? 0);
        if (
            $eventType === self::EVENT_PRODUCT_VIEWED
            && $visitorKey !== ''
            && $this->hasRecentTouch($shareId, $eventType, $visitorKey, self::PRODUCT_VIEW_DEDUPE_SECONDS)
        ) {
            return null;
        }

        $touch = $this->recordTouch($eventType, [
            'share_id' => $shareId,
            'affiliate_id' => (int) ($attribution[AffiliateAttribution::schema_fields_AFFILIATE_ID] ?? 0),
            'product_id' => $productId > 0 ? $productId : $attributionProductId,
            'customer_id' => $customerId,
            'visitor_key' => $visitorKey,
            'order_id' => max(0, (int) ($payload['order_id'] ?? 0)),
            'value' => (float) ($payload['value'] ?? 0),
            'channel' => (string) ($payload['channel'] ?? ''),
            'metadata' => $payload['metadata'] ?? $payload,
            'idempotency_key' => (string) ($payload['idempotency_key'] ?? ''),
        ]);

        $eventPayload = [
            'event_type' => $eventType,
            'touch' => $touch,
            'attribution' => $attribution,
            'customer_id' => $customerId,
            'product_id' => (int) ($touch->getData(AffiliateTouch::schema_fields_PRODUCT_ID) ?? 0),
        ];
        $this->dispatchAffiliateEvent('engagement_recorded', $eventPayload);

        return [
            'touch_id' => (int) ($touch->getId() ?? 0),
            'event_type' => $eventType,
            'attribution_id' => (int) ($attribution[AffiliateAttribution::schema_fields_ID] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handleCheckoutOrderCreated(array $payload): void
    {
        $orderId = $this->extractOrderId($payload);
        if ($orderId <= 0) {
            return;
        }

        $customerId = max(0, (int) ($payload['customer_id'] ?? $this->extractOrderCustomerId($payload)));
        $visitorKey = $this->getVisitorKey(false);
        $attribution = $this->resolveActiveAttribution($customerId, $visitorKey);
        if (!is_array($attribution)) {
            return;
        }

        $affiliate = $this->getAffiliateRowById((int) ($attribution[AffiliateAttribution::schema_fields_AFFILIATE_ID] ?? 0));
        if (!is_array($affiliate)) {
            return;
        }

        $affiliateCustomerId = (int) ($affiliate[Affiliate::schema_fields_CUSTOMER_ID] ?? 0);
        if ($customerId > 0 && $affiliateCustomerId === $customerId) {
            $this->recordEngagement(self::EVENT_ORDER_CREATED, [
                'customer_id' => $customerId,
                'order_id' => $orderId,
                'product_id' => (int) ($attribution[AffiliateAttribution::schema_fields_PRODUCT_ID] ?? 0),
                'metadata' => ['self_purchase' => true],
                'idempotency_key' => 'affiliate:self_order:' . $orderId,
            ]);
            return;
        }

        $orderItems = is_array($payload['order_items'] ?? null) ? $payload['order_items'] : [];
        $summary = is_array($payload['order_summary'] ?? null) ? $payload['order_summary'] : [];
        if ($orderItems === []) {
            return;
        }
        $currencyCode = $this->extractOrderCurrencyCode($payload, $summary);

        $rate = max(0.0, (float) ($affiliate[Affiliate::schema_fields_COMMISSION_RATE] ?? 0));
        if ($rate <= 0) {
            return;
        }

        $matchedProductId = (int) ($attribution[AffiliateAttribution::schema_fields_PRODUCT_ID] ?? 0);
        $createdCommissions = [];
        foreach ($orderItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = (int) ($item['product_id'] ?? 0);
            if ($matchedProductId > 0 && $productId !== $matchedProductId) {
                continue;
            }

            $baseAmount = $this->calculateCommissionBase($item, $summary, $orderItems);
            if ($baseAmount <= 0) {
                continue;
            }

            $commissionAmount = round($baseAmount * $rate, 2);
            if ($commissionAmount <= 0) {
                continue;
            }

            $commission = $this->createPendingCommission([
                'affiliate_id' => (int) ($affiliate[Affiliate::schema_fields_ID] ?? 0),
                'share_id' => (int) ($attribution[AffiliateAttribution::schema_fields_SHARE_ID] ?? 0),
                'attribution_id' => (int) ($attribution[AffiliateAttribution::schema_fields_ID] ?? 0),
                'order_id' => $orderId,
                'order_item_id' => (int) ($item['item_id'] ?? $item['order_item_id'] ?? 0),
                'product_id' => $productId,
                'customer_id' => $customerId,
                'base_amount' => $baseAmount,
                'commission_rate' => $rate,
                'commission_amount' => $commissionAmount,
                'currency_code' => $currencyCode,
            ]);
            if ($commission instanceof AffiliateCommission) {
                $createdCommissions[] = $commission;
            }
        }

        if ($createdCommissions === []) {
            return;
        }

        $this->recordTouch(self::EVENT_ORDER_CREATED, [
            'share_id' => (int) ($attribution[AffiliateAttribution::schema_fields_SHARE_ID] ?? 0),
            'affiliate_id' => (int) ($attribution[AffiliateAttribution::schema_fields_AFFILIATE_ID] ?? 0),
            'product_id' => $matchedProductId,
            'customer_id' => $customerId,
            'visitor_key' => $visitorKey,
            'order_id' => $orderId,
            'value' => array_sum(array_map(
                static fn (AffiliateCommission $commission): float => (float) ($commission->getData(AffiliateCommission::schema_fields_BASE_AMOUNT) ?? 0),
                $createdCommissions
            )),
            'idempotency_key' => 'affiliate:order_created:' . $orderId . ':' . (int) ($attribution[AffiliateAttribution::schema_fields_ID] ?? 0),
        ]);

        $share = $this->newShareModel();
        $share->load((int) ($attribution[AffiliateAttribution::schema_fields_SHARE_ID] ?? 0));
        if ($share->getId()) {
            $this->incrementShareCounter($share, AffiliateShare::schema_fields_ORDER_COUNT);
        }

        $this->dispatchAffiliateEvent('conversion_pending', [
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'attribution' => $attribution,
            'commissions' => $createdCommissions,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handlePaymentStatusChanged(array $payload): void
    {
        $orderId = $this->extractOrderId($payload);
        if ($orderId <= 0) {
            return;
        }

        $newStatus = strtolower((string) ($payload['new_payment_status'] ?? $payload['payment_status'] ?? ''));
        if ($newStatus === 'paid') {
            $this->recordOrderTouchByCommission($orderId, self::EVENT_PAYMENT_PAID);
            $this->transitionOrderCommissions($orderId, self::COMMISSION_STATUS_APPROVED, 'payment_paid');
            return;
        }

        if ($newStatus === 'refunded') {
            $this->recordOrderTouchByCommission($orderId, self::EVENT_ORDER_REFUNDED);
            $this->transitionOrderCommissions($orderId, self::COMMISSION_STATUS_REVERSED, 'payment_refunded');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handleOrderStatusChanged(array $payload): void
    {
        $orderId = $this->extractOrderId($payload);
        if ($orderId <= 0) {
            return;
        }

        $newStatus = strtolower((string) ($payload['new_status'] ?? $payload['status'] ?? ''));
        if ($newStatus === 'cancelled') {
            $this->recordOrderTouchByCommission($orderId, self::EVENT_ORDER_CANCELLED);
            $this->transitionOrderCommissions($orderId, self::COMMISSION_STATUS_CANCELLED, 'order_cancelled');
            return;
        }

        if ($newStatus === 'refunded') {
            $this->recordOrderTouchByCommission($orderId, self::EVENT_ORDER_REFUNDED);
            $this->transitionOrderCommissions($orderId, self::COMMISSION_STATUS_REVERSED, 'order_refunded');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handleCustomerRegistered(array $payload): void
    {
        $customerId = max(0, (int) ($payload['customer_id'] ?? 0));
        if ($customerId <= 0) {
            return;
        }

        $visitorKey = $this->getVisitorKey(false);
        $referralCode = trim((string) ($payload['referral_code'] ?? ''));
        $attribution = $this->resolveActiveAttribution($customerId, $visitorKey);
        $share = null;
        $affiliateId = 0;
        $productId = 0;

        if (!is_array($attribution) && $referralCode !== '') {
            $affiliate = $this->loadAffiliateByReferralCode($referralCode);
            if (is_array($affiliate)) {
                $affiliateId = (int) ($affiliate[Affiliate::schema_fields_ID] ?? 0);
                $affiliateCustomerId = (int) ($affiliate[Affiliate::schema_fields_CUSTOMER_ID] ?? 0);
                if ($affiliateId > 0 && $affiliateCustomerId !== $customerId) {
                    $share = $this->ensureProductShare(
                        $affiliateId,
                        $affiliateCustomerId,
                        0,
                        'registration',
                        $this->getReferralBasePath() . '?ref=' . rawurlencode($referralCode)
                    );
                    $attribution = $this->startAttribution($share, $customerId, $visitorKey);
                }
            }
        }

        if (is_array($attribution)) {
            $affiliateId = (int) ($attribution[AffiliateAttribution::schema_fields_AFFILIATE_ID] ?? 0);
            $productId = (int) ($attribution[AffiliateAttribution::schema_fields_PRODUCT_ID] ?? 0);
            $shareId = (int) ($attribution[AffiliateAttribution::schema_fields_SHARE_ID] ?? 0);
            if ($shareId > 0) {
                $share = $this->newShareModel();
                $share->clear()->load($shareId);
            }
            $this->bindAttributionCustomer((int) ($attribution[AffiliateAttribution::schema_fields_ID] ?? 0), $customerId);
        }

        if ($affiliateId <= 0) {
            return;
        }

        $touch = $this->recordTouch(self::EVENT_CUSTOMER_REGISTERED, [
            'share_id' => $share instanceof AffiliateShare ? (int) ($share->getId() ?? 0) : 0,
            'affiliate_id' => $affiliateId,
            'product_id' => $productId,
            'customer_id' => $customerId,
            'visitor_key' => $visitorKey,
            'channel' => $share instanceof AffiliateShare ? (string) ($share->getData(AffiliateShare::schema_fields_CHANNEL) ?? '') : 'registration',
            'metadata' => [
                'source' => 'customer_register_after',
                'has_referral_code' => $referralCode !== '',
            ],
            'idempotency_key' => 'affiliate:customer_registered:' . $affiliateId . ':' . $customerId,
        ]);

        $this->dispatchAffiliateEvent('engagement_recorded', [
            'event_type' => self::EVENT_CUSTOMER_REGISTERED,
            'touch' => $touch,
            'attribution' => $attribution,
            'customer_id' => $customerId,
            'product_id' => $productId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAffiliateSummary(int $customerId): array
    {
        $account = $this->getAffiliateAccountOrCreate($customerId);
        $affiliateId = (int) ($account[Affiliate::schema_fields_ID] ?? 0);
        $referralCode = (string) ($account[Affiliate::schema_fields_REFERRAL_CODE] ?? '');
        $defaultShareLink = $this->getDefaultShareLinkData($customerId);
        $metrics = $this->getAffiliateMetrics($affiliateId);

        $totalCommission = (float) ($account[Affiliate::schema_fields_TOTAL_COMMISSION] ?? 0);
        $paidCommission = (float) ($account[Affiliate::schema_fields_PAID_COMMISSION] ?? 0);
        $ledgerPending = (float) ($metrics['pending_commission'] ?? 0);
        $legacyPending = max(0.0, round($totalCommission - $paidCommission, 2));
        $pendingCommission = $ledgerPending > 0 ? $ledgerPending : $legacyPending;
        $withdrawalSummary = $this->getWithdrawalSummary($affiliateId);
        $workspace = $this->getAffiliateWorkspaceData($affiliateId);

        return [
            'affiliate_id' => $affiliateId,
            'customer_id' => (int) ($account[Affiliate::schema_fields_CUSTOMER_ID] ?? $customerId),
            'referral_code' => $referralCode,
            'referral_link' => $this->getReferralLink($referralCode),
            'default_share_link' => $defaultShareLink,
            'commission_rate' => (float) ($account[Affiliate::schema_fields_COMMISSION_RATE] ?? 0),
            'currency_code' => (string) ($metrics['commission_currency_code'] ?? 'USD'),
            'total_commission' => $totalCommission,
            'paid_commission' => $paidCommission,
            'pending_commission' => $pendingCommission,
            'approved_commission' => (float) ($metrics['approved_commission'] ?? 0),
            'cancelled_commission' => (float) ($metrics['cancelled_commission'] ?? 0),
            'available_commission' => (float) ($withdrawalSummary['available_amount'] ?? 0),
            'withdrawal_requested' => (float) ($withdrawalSummary['requested_amount'] ?? 0),
            'withdrawal_processing' => (float) ($withdrawalSummary['processing_amount'] ?? 0),
            'withdrawn_commission' => (float) ($withdrawalSummary['paid_amount'] ?? 0),
            'withdrawal_summary' => $withdrawalSummary,
            'status' => (string) ($account[Affiliate::schema_fields_STATUS] ?? self::STATUS_DISABLED),
        ] + $metrics + $workspace;
    }

    /**
     * @return array<string, mixed>
     */
    public function requestWithdrawal(int $customerId, float $amount, string $method = '', string $accountLabel = '', array $metadata = []): array
    {
        if ($customerId <= 0) {
            throw new \InvalidArgumentException((string) __('请先登录后再申请提现。'));
        }

        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException((string) __('提现金额必须大于 0。'));
        }

        $account = $this->getAffiliateAccountOrCreate($customerId);
        $affiliateId = (int) ($account[Affiliate::schema_fields_ID] ?? 0);
        if ($affiliateId <= 0 || (string) ($account[Affiliate::schema_fields_STATUS] ?? self::STATUS_DISABLED) !== self::STATUS_ACTIVE) {
            throw new \RuntimeException((string) __('当前分销账户不可用。'));
        }

        $summary = $this->getWithdrawalSummary($affiliateId);
        $available = (float) ($summary['available_amount'] ?? 0);
        if ($amount > $available) {
            throw new \InvalidArgumentException((string) __('提现金额超过可提现余额。'));
        }

        $now = date('Y-m-d H:i:s');
        $currencyCode = $this->normalizeReportCurrencyCode((string) ($metadata['currency_code'] ?? $summary['currency_code'] ?? ''));
        $method = $this->normalizeWithdrawalMethod($method);
        $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        $withdrawal = $this->newWithdrawalModel();
        $withdrawal->clearData()->save([
            AffiliateWithdrawal::schema_fields_AFFILIATE_ID => $affiliateId,
            AffiliateWithdrawal::schema_fields_CUSTOMER_ID => $customerId,
            AffiliateWithdrawal::schema_fields_AMOUNT => $amount,
            AffiliateWithdrawal::schema_fields_CURRENCY_CODE => $currencyCode,
            AffiliateWithdrawal::schema_fields_STATUS => self::WITHDRAWAL_STATUS_REQUESTED,
            AffiliateWithdrawal::schema_fields_METHOD => $method,
            AffiliateWithdrawal::schema_fields_ACCOUNT_LABEL => $this->maskPayoutAccount($accountLabel),
            AffiliateWithdrawal::schema_fields_METADATA_JSON => $metadataJson,
            AffiliateWithdrawal::schema_fields_NOTE => '',
            AffiliateWithdrawal::schema_fields_REQUESTED_AT => $now,
            AffiliateWithdrawal::schema_fields_CREATED_AT => $now,
            AffiliateWithdrawal::schema_fields_UPDATED_AT => $now,
        ]);

        $row = $this->formatWithdrawalRow($withdrawal->getData());
        $row['withdrawal_id'] = (int) ($withdrawal->getId() ?? ($row['withdrawal_id'] ?? 0));

        $this->dispatchAffiliateEvent('reward_requested', [
            'withdrawal' => $withdrawal,
            'affiliate_id' => $affiliateId,
            'customer_id' => $customerId,
            'amount' => $amount,
            'currency_code' => $currencyCode,
            'method' => $method,
            'status' => self::WITHDRAWAL_STATUS_REQUESTED,
        ]);

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAffiliateAccount(int $customerId): ?array
    {
        $affiliate = $this->newAffiliateModel();
        $rows = $affiliate->clear()
            ->where(Affiliate::schema_fields_CUSTOMER_ID, $customerId)
            ->limit(1)
            ->select()
            ->fetchArray();

        foreach ($rows as $row) {
            if (is_array($row)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getAffiliateAccountOrCreate(int $customerId): array
    {
        $existing = $this->getAffiliateAccount($customerId);
        if (is_array($existing)) {
            return $existing;
        }

        $affiliate = $this->createAffiliate($customerId);

        return [
            Affiliate::schema_fields_ID => (int) ($affiliate->getId() ?? 0),
            Affiliate::schema_fields_CUSTOMER_ID => (int) ($affiliate->getData(Affiliate::schema_fields_CUSTOMER_ID) ?? $customerId),
            Affiliate::schema_fields_REFERRAL_CODE => (string) ($affiliate->getData(Affiliate::schema_fields_REFERRAL_CODE) ?? ''),
            Affiliate::schema_fields_COMMISSION_RATE => (float) ($affiliate->getData(Affiliate::schema_fields_COMMISSION_RATE) ?? 0),
            Affiliate::schema_fields_TOTAL_COMMISSION => (float) ($affiliate->getData(Affiliate::schema_fields_TOTAL_COMMISSION) ?? 0),
            Affiliate::schema_fields_PAID_COMMISSION => (float) ($affiliate->getData(Affiliate::schema_fields_PAID_COMMISSION) ?? 0),
            Affiliate::schema_fields_STATUS => (string) ($affiliate->getData(Affiliate::schema_fields_STATUS) ?? self::STATUS_DISABLED),
        ];
    }

    public function getReferralBasePath(): string
    {
        return '/customer/account/register';
    }

    public function getReferralLink(string $referralCode): string
    {
        $referralCode = trim($referralCode);
        if ($referralCode === '') {
            return '';
        }

        return $this->url()->getOriginUrl($this->getReferralBasePath(), ['ref' => $referralCode]);
    }

    protected function generateReferralCode(int $customerId): string
    {
        return 'REF' . str_pad((string) $customerId, 8, '0', STR_PAD_LEFT) . strtoupper(bin2hex(random_bytes(2)));
    }

    public function getStatusOptions(): array
    {
        return [
            self::STATUS_ACTIVE => (string) __('Active'),
            self::STATUS_DISABLED => (string) __('Disabled'),
        ];
    }

    public function isValidStatus(string $status): bool
    {
        return isset($this->getStatusOptions()[$status]);
    }

    public function getAffiliateRecord(int $affiliateId): ?Affiliate
    {
        $affiliate = $this->newAffiliateModel();
        $affiliate->clear()->load($affiliateId);

        return $affiliate->getId() ? $affiliate : null;
    }

    public function getAffiliateList(int $page = 1, int $pageSize = 20, array $filters = []): array
    {
        $affiliate = $this->newAffiliateModel();
        $affiliate->clear();

        if (!empty($filters['customer_id'])) {
            $affiliate->where(Affiliate::schema_fields_CUSTOMER_ID, (int) $filters['customer_id']);
        }

        if (!empty($filters['referral_code'])) {
            $affiliate->where(Affiliate::schema_fields_REFERRAL_CODE, '%' . $filters['referral_code'] . '%', 'LIKE');
        }

        if (!empty($filters['status']) && $this->isValidStatus((string) $filters['status'])) {
            $affiliate->where(Affiliate::schema_fields_STATUS, (string) $filters['status']);
        }

        $affiliate->order(Affiliate::schema_fields_CREATED_AT, 'DESC')
            ->pagination($page, $pageSize);

        return [
            'items' => $affiliate->select()->fetchArray(),
            'total' => $affiliate->getTotalCount(),
            'pagination' => $affiliate->getPagination(),
        ];
    }

    public function saveAffiliate(array $data): Affiliate
    {
        $affiliateId = (int) ($data['affiliate_id'] ?? 0);
        $customerId = (int) ($data['customer_id'] ?? 0);
        $commissionRate = (float) ($data['commission_rate'] ?? 0);
        $status = trim((string) ($data['status'] ?? self::STATUS_ACTIVE));

        if (!$this->isValidStatus($status)) {
            throw new \InvalidArgumentException((string) __('Unsupported affiliate status.'));
        }

        if ($commissionRate < 0 || $commissionRate > 1) {
            throw new \InvalidArgumentException((string) __('Commission rate must be between 0 and 1.'));
        }

        $affiliate = $this->newAffiliateModel();

        if ($affiliateId) {
            $affiliate->clear()->load($affiliateId);
            if (!$affiliate->getId()) {
                throw new \InvalidArgumentException((string) __('Affiliate record not found.'));
            }
        } elseif ($customerId) {
            $existing = $this->getAffiliateAccount($customerId);
            if (is_array($existing) && (int) ($existing[Affiliate::schema_fields_ID] ?? 0) > 0) {
                $affiliate->clear()->load((int) $existing[Affiliate::schema_fields_ID]);
            } else {
                $affiliate = $this->createAffiliate($customerId);
            }
        } else {
            throw new \InvalidArgumentException((string) __('Customer ID or affiliate ID is required.'));
        }

        $affiliate->setData([
            Affiliate::schema_fields_COMMISSION_RATE => round($commissionRate, 2),
            Affiliate::schema_fields_STATUS => $status,
            Affiliate::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ]);

        if (!$affiliate->getData(Affiliate::schema_fields_CREATED_AT)) {
            $affiliate->setData(Affiliate::schema_fields_CREATED_AT, date('Y-m-d H:i:s'));
        }

        $affiliate->save();

        return $affiliate;
    }

    protected function calculateCommissionBase(array $item, array $summary, array $allItems = []): float
    {
        $quantity = max(1, (int) ($item['quantity'] ?? $item['qty'] ?? 1));
        $price = max(0.0, (float) ($item['price'] ?? 0));
        $rowTotal = (float) ($item['row_total'] ?? $item['total'] ?? 0);
        if ($rowTotal <= 0 && $price > 0) {
            $rowTotal = $price * $quantity;
        }
        if ($rowTotal <= 0) {
            return 0.0;
        }

        $subtotal = max(0.0, (float) ($summary['subtotal'] ?? 0));
        if ($subtotal <= 0) {
            foreach ($allItems as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $candidateQty = max(1, (int) ($candidate['quantity'] ?? $candidate['qty'] ?? 1));
                $candidatePrice = max(0.0, (float) ($candidate['price'] ?? 0));
                $candidateTotal = (float) ($candidate['row_total'] ?? $candidate['total'] ?? 0);
                $subtotal += $candidateTotal > 0 ? $candidateTotal : ($candidatePrice * $candidateQty);
            }
        }

        $discount = max(0.0, (float) ($summary['discount'] ?? $summary['discount_amount'] ?? 0));
        if ($subtotal <= 0 || $discount <= 0) {
            return round($rowTotal, 2);
        }

        $itemDiscount = min($rowTotal, round($discount * ($rowTotal / $subtotal), 2));
        return round(max(0.0, $rowTotal - $itemDiscount), 2);
    }

    private function ensureProductShare(int $affiliateId, int $customerId, int $productId, string $channel, string $targetPath): AffiliateShare
    {
        $existing = $this->newShareModel()->clear()
            ->where(AffiliateShare::schema_fields_AFFILIATE_ID, $affiliateId)
            ->where(AffiliateShare::schema_fields_PRODUCT_ID, $productId)
            ->where(AffiliateShare::schema_fields_CHANNEL, $channel)
            ->where(AffiliateShare::schema_fields_STATUS, self::SHARE_STATUS_ACTIVE)
            ->limit(1)
            ->find()
            ->fetch();

        if ($existing instanceof AffiliateShare && $existing->getId()) {
            if ($targetPath !== '' && (string) ($existing->getData(AffiliateShare::schema_fields_TARGET_PATH) ?? '') !== $targetPath) {
                $existing->setData(AffiliateShare::schema_fields_TARGET_PATH, $targetPath)
                    ->setData(AffiliateShare::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                    ->save();
            }
            return $existing;
        }

        $now = date('Y-m-d H:i:s');
        $share = $this->newShareModel();
        $share->clearData()->save([
            AffiliateShare::schema_fields_AFFILIATE_ID => $affiliateId,
            AffiliateShare::schema_fields_CUSTOMER_ID => $customerId,
            AffiliateShare::schema_fields_PRODUCT_ID => $productId,
            AffiliateShare::schema_fields_CHANNEL => $channel,
            AffiliateShare::schema_fields_SHARE_CODE => $this->generateShareCode($affiliateId, $productId),
            AffiliateShare::schema_fields_TARGET_PATH => $targetPath !== '' ? $targetPath : self::PRODUCT_VIEW_ROUTE,
            AffiliateShare::schema_fields_STATUS => self::SHARE_STATUS_ACTIVE,
            AffiliateShare::schema_fields_OUTBOUND_COUNT => 0,
            AffiliateShare::schema_fields_CLICK_COUNT => 0,
            AffiliateShare::schema_fields_ORDER_COUNT => 0,
            AffiliateShare::schema_fields_CREATED_AT => $now,
            AffiliateShare::schema_fields_UPDATED_AT => $now,
        ]);

        $this->dispatchAffiliateEvent('share_created', [
            'share' => $share,
            'affiliate_id' => $affiliateId,
            'customer_id' => $customerId,
            'product_id' => $productId,
            'share_code' => (string) ($share->getData(AffiliateShare::schema_fields_SHARE_CODE) ?? ''),
        ]);

        return $share;
    }

    private function resolveProductShareTargetPath(int $productId): string
    {
        $product = $this->loadShareableProduct($productId);
        if (!is_array($product)) {
            throw new \InvalidArgumentException((string) __('商品不存在或已下架，无法生成分销分享链接。'));
        }

        $handle = trim((string) ($product['handle'] ?? ''));
        if ($handle !== '') {
            return 'product/' . ltrim($handle, '/');
        }

        return self::PRODUCT_VIEW_ROUTE;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadShareableProduct(int $productId): ?array
    {
        try {
            $products = w_query('product', 'getProductByIds', ['product_ids' => [$productId]], 'frontend');
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($products)) {
            return null;
        }

        foreach ($products as $product) {
            if (!is_array($product) || (int) ($product['product_id'] ?? 0) !== $productId) {
                continue;
            }
            $status = $product['status'] ?? 0;
            if ($status === 1 || $status === '1' || $status === 'enabled') {
                return $product;
            }
        }

        return null;
    }

    private function requireShareByCode(string $shareCode): AffiliateShare
    {
        $shareCode = trim($shareCode);
        if ($shareCode === '') {
            throw new \InvalidArgumentException((string) __('缺少分享码。'));
        }

        $share = $this->newShareModel();
        $share->load(AffiliateShare::schema_fields_SHARE_CODE, $shareCode);
        if (!$share->getId() || (string) ($share->getData(AffiliateShare::schema_fields_STATUS) ?? '') !== self::SHARE_STATUS_ACTIVE) {
            throw new \InvalidArgumentException((string) __('分享链接无效或已过期。'));
        }

        return $share;
    }

    private function startAttribution(AffiliateShare $share, int $customerId, string $visitorKey): AffiliateAttribution
    {
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + self::ATTRIBUTION_TTL_SECONDS);
        $existing = $this->loadActiveAttributionRow($customerId, $visitorKey);
        $attribution = $this->newAttributionModel();

        if (is_array($existing) && (int) ($existing[AffiliateAttribution::schema_fields_ID] ?? 0) > 0) {
            $attribution->load((int) $existing[AffiliateAttribution::schema_fields_ID]);
        } else {
            $attribution->clearData();
        }

        $attributionData = [
            AffiliateAttribution::schema_fields_SHARE_ID => (int) ($share->getId() ?? 0),
            AffiliateAttribution::schema_fields_AFFILIATE_ID => (int) ($share->getData(AffiliateShare::schema_fields_AFFILIATE_ID) ?? 0),
            AffiliateAttribution::schema_fields_CUSTOMER_ID => $customerId,
            AffiliateAttribution::schema_fields_VISITOR_KEY => $visitorKey,
            AffiliateAttribution::schema_fields_PRODUCT_ID => (int) ($share->getData(AffiliateShare::schema_fields_PRODUCT_ID) ?? 0),
            AffiliateAttribution::schema_fields_STATUS => self::ATTRIBUTION_STATUS_ACTIVE,
            AffiliateAttribution::schema_fields_LAST_TOUCH_AT => $now,
            AffiliateAttribution::schema_fields_EXPIRES_AT => $expiresAt,
            AffiliateAttribution::schema_fields_UPDATED_AT => $now,
        ];
        if ($attribution->getId()) {
            $attributionData[AffiliateAttribution::schema_fields_ID] = (int) $attribution->getId();
        } else {
            $attributionData[AffiliateAttribution::schema_fields_FIRST_TOUCH_AT] = $now;
            $attributionData[AffiliateAttribution::schema_fields_CREATED_AT] = $now;
        }

        $attribution->save($attributionData);

        $this->persistAttributionState((string) ($share->getData(AffiliateShare::schema_fields_SHARE_CODE) ?? ''), [
            'share_id' => (int) ($share->getId() ?? 0),
            'affiliate_id' => (int) ($share->getData(AffiliateShare::schema_fields_AFFILIATE_ID) ?? 0),
            'product_id' => (int) ($share->getData(AffiliateShare::schema_fields_PRODUCT_ID) ?? 0),
            'expires_at' => $expiresAt,
        ]);

        $this->dispatchAffiliateEvent('attribution_started', [
            'share' => $share,
            'attribution' => $attribution,
            'customer_id' => $customerId,
            'visitor_key' => $visitorKey,
            'expires_at' => $expiresAt,
        ]);

        return $attribution;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveActiveAttribution(int $customerId = 0, string $visitorKey = ''): ?array
    {
        $visitorKey = $visitorKey !== '' ? $visitorKey : $this->getVisitorKey(false);
        $row = $this->loadActiveAttributionRow($customerId, $visitorKey);
        if (!is_array($row)) {
            return null;
        }

        if ($this->isExpiredAt($row[AffiliateAttribution::schema_fields_EXPIRES_AT] ?? null)) {
            $this->expireAttribution((int) ($row[AffiliateAttribution::schema_fields_ID] ?? 0));
            return null;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadActiveAttributionRow(int $customerId = 0, string $visitorKey = ''): ?array
    {
        $candidates = [];
        if ($customerId > 0) {
            $candidates[] = [
                AffiliateAttribution::schema_fields_CUSTOMER_ID => $customerId,
            ];
        }
        if ($visitorKey !== '') {
            $candidates[] = [
                AffiliateAttribution::schema_fields_VISITOR_KEY => $visitorKey,
            ];
        }

        foreach ($candidates as $conditions) {
            $attribution = $this->newAttributionModel()->clear()
                ->where(AffiliateAttribution::schema_fields_STATUS, self::ATTRIBUTION_STATUS_ACTIVE);
            foreach ($conditions as $field => $value) {
                $attribution->where($field, $value);
            }
            $rows = $attribution->order(AffiliateAttribution::schema_fields_LAST_TOUCH_AT, 'DESC')
                ->limit(1)
                ->select()
                ->fetchArray();
            foreach ($rows as $row) {
                if (is_array($row)) {
                    return $row;
                }
            }
        }

        return null;
    }

    private function createPendingCommission(array $data): ?AffiliateCommission
    {
        $affiliateId = (int) ($data['affiliate_id'] ?? 0);
        $orderId = (int) ($data['order_id'] ?? 0);
        $orderItemId = (int) ($data['order_item_id'] ?? 0);
        if ($affiliateId <= 0 || $orderId <= 0) {
            return null;
        }

        $existing = $this->findCommission($orderId, $orderItemId, $affiliateId);
        if ($existing instanceof AffiliateCommission) {
            return null;
        }

        $now = date('Y-m-d H:i:s');
        $commission = $this->newCommissionModel();
        $commission->clearData()->save([
            AffiliateCommission::schema_fields_AFFILIATE_ID => $affiliateId,
            AffiliateCommission::schema_fields_SHARE_ID => (int) ($data['share_id'] ?? 0),
            AffiliateCommission::schema_fields_ATTRIBUTION_ID => (int) ($data['attribution_id'] ?? 0),
            AffiliateCommission::schema_fields_ORDER_ID => $orderId,
            AffiliateCommission::schema_fields_ORDER_ITEM_ID => $orderItemId,
            AffiliateCommission::schema_fields_PRODUCT_ID => (int) ($data['product_id'] ?? 0),
            AffiliateCommission::schema_fields_CUSTOMER_ID => (int) ($data['customer_id'] ?? 0),
            AffiliateCommission::schema_fields_BASE_AMOUNT => round((float) ($data['base_amount'] ?? 0), 2),
            AffiliateCommission::schema_fields_COMMISSION_RATE => round((float) ($data['commission_rate'] ?? 0), 2),
            AffiliateCommission::schema_fields_COMMISSION_AMOUNT => round((float) ($data['commission_amount'] ?? 0), 2),
            AffiliateCommission::schema_fields_CURRENCY_CODE => $this->normalizeCurrencyCode((string) ($data['currency_code'] ?? '')),
            AffiliateCommission::schema_fields_STATUS => self::COMMISSION_STATUS_PENDING,
            AffiliateCommission::schema_fields_REASON => 'order_created',
            AffiliateCommission::schema_fields_CREATED_AT => $now,
            AffiliateCommission::schema_fields_UPDATED_AT => $now,
        ]);

        $this->dispatchAffiliateEvent('commission_created', [
            'commission' => $commission,
            'order_id' => $orderId,
            'affiliate_id' => $affiliateId,
            'status' => self::COMMISSION_STATUS_PENDING,
        ]);

        return $commission;
    }

    private function transitionOrderCommissions(int $orderId, string $targetStatus, string $reason): void
    {
        $rows = $this->newCommissionModel()->clear()
            ->where(AffiliateCommission::schema_fields_ORDER_ID, $orderId)
            ->select()
            ->fetchArray();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $commission = $this->newCommissionModel();
            $commission->load((int) ($row[AffiliateCommission::schema_fields_ID] ?? 0));
            if (!$commission->getId()) {
                continue;
            }

            $currentStatus = (string) ($commission->getData(AffiliateCommission::schema_fields_STATUS) ?? '');
            if ($currentStatus === $targetStatus) {
                continue;
            }

            if ($targetStatus === self::COMMISSION_STATUS_APPROVED && $currentStatus !== self::COMMISSION_STATUS_PENDING) {
                continue;
            }

            if (in_array($targetStatus, [self::COMMISSION_STATUS_CANCELLED, self::COMMISSION_STATUS_REVERSED], true)
                && in_array($currentStatus, [self::COMMISSION_STATUS_CANCELLED, self::COMMISSION_STATUS_REVERSED], true)
            ) {
                continue;
            }

            $amount = (float) ($commission->getData(AffiliateCommission::schema_fields_COMMISSION_AMOUNT) ?? 0);
            if ($targetStatus === self::COMMISSION_STATUS_APPROVED) {
                $this->adjustAffiliateTotalCommission((int) ($commission->getData(AffiliateCommission::schema_fields_AFFILIATE_ID) ?? 0), $amount);
            } elseif (in_array($currentStatus, [self::COMMISSION_STATUS_APPROVED, self::COMMISSION_STATUS_PAID], true)) {
                $this->adjustAffiliateTotalCommission((int) ($commission->getData(AffiliateCommission::schema_fields_AFFILIATE_ID) ?? 0), -$amount);
            }

            $commission->setData(AffiliateCommission::schema_fields_STATUS, $targetStatus)
                ->setData(AffiliateCommission::schema_fields_REASON, $reason)
                ->setData(AffiliateCommission::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                ->save();

            $this->dispatchAffiliateEvent('commission_status_changed', [
                'commission' => $commission,
                'order_id' => $orderId,
                'affiliate_id' => (int) ($commission->getData(AffiliateCommission::schema_fields_AFFILIATE_ID) ?? 0),
                'old_status' => $currentStatus,
                'new_status' => $targetStatus,
                'reason' => $reason,
            ]);
        }
    }

    private function recordOrderTouchByCommission(int $orderId, string $eventType): void
    {
        $rows = $this->newCommissionModel()->clear()
            ->where(AffiliateCommission::schema_fields_ORDER_ID, $orderId)
            ->limit(1)
            ->select()
            ->fetchArray();

        $row = null;
        foreach ($rows as $candidate) {
            if (is_array($candidate)) {
                $row = $candidate;
                break;
            }
        }
        if (!is_array($row)) {
            return;
        }

        $this->recordTouch($eventType, [
            'share_id' => (int) ($row[AffiliateCommission::schema_fields_SHARE_ID] ?? 0),
            'affiliate_id' => (int) ($row[AffiliateCommission::schema_fields_AFFILIATE_ID] ?? 0),
            'product_id' => (int) ($row[AffiliateCommission::schema_fields_PRODUCT_ID] ?? 0),
            'customer_id' => (int) ($row[AffiliateCommission::schema_fields_CUSTOMER_ID] ?? 0),
            'visitor_key' => $this->getVisitorKey(false),
            'order_id' => $orderId,
            'idempotency_key' => 'affiliate:' . $eventType . ':' . $orderId,
        ]);
    }

    private function recordTouch(string $eventType, array $data): AffiliateTouch
    {
        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            $idempotencyKey = sha1(json_encode([
                $eventType,
                (int) ($data['share_id'] ?? 0),
                (int) ($data['affiliate_id'] ?? 0),
                (int) ($data['product_id'] ?? 0),
                (int) ($data['customer_id'] ?? 0),
                (int) ($data['order_id'] ?? 0),
                (string) ($data['visitor_key'] ?? ''),
                microtime(true),
                random_int(1, PHP_INT_MAX),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $existing = $this->findTouchByIdempotency($idempotencyKey);
        if ($existing instanceof AffiliateTouch) {
            return $existing;
        }

        $metadata = $data['metadata'] ?? [];
        $metadataJson = is_array($metadata)
            ? (json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '')
            : (string) $metadata;

        $touch = $this->newTouchModel();
        $touch->clearData()->save([
            AffiliateTouch::schema_fields_SHARE_ID => (int) ($data['share_id'] ?? 0),
            AffiliateTouch::schema_fields_AFFILIATE_ID => (int) ($data['affiliate_id'] ?? 0),
            AffiliateTouch::schema_fields_EVENT_TYPE => $eventType,
            AffiliateTouch::schema_fields_PRODUCT_ID => (int) ($data['product_id'] ?? 0),
            AffiliateTouch::schema_fields_CUSTOMER_ID => (int) ($data['customer_id'] ?? 0),
            AffiliateTouch::schema_fields_VISITOR_KEY => (string) ($data['visitor_key'] ?? ''),
            AffiliateTouch::schema_fields_ORDER_ID => (int) ($data['order_id'] ?? 0),
            AffiliateTouch::schema_fields_VALUE => round((float) ($data['value'] ?? 0), 2),
            AffiliateTouch::schema_fields_CHANNEL => $this->normalizeChannel((string) ($data['channel'] ?? '')),
            AffiliateTouch::schema_fields_METADATA_JSON => $metadataJson,
            AffiliateTouch::schema_fields_IDEMPOTENCY_KEY => $idempotencyKey,
            AffiliateTouch::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        ]);

        return $touch;
    }

    private function findTouchByIdempotency(string $idempotencyKey): ?AffiliateTouch
    {
        if ($idempotencyKey === '') {
            return null;
        }

        $touch = $this->newTouchModel()->clear()
            ->where(AffiliateTouch::schema_fields_IDEMPOTENCY_KEY, $idempotencyKey)
            ->limit(1)
            ->find()
            ->fetch();

        return $touch instanceof AffiliateTouch && $touch->getId() ? $touch : null;
    }

    private function hasRecentTouch(int $shareId, string $eventType, string $visitorKey, int $seconds): bool
    {
        if ($shareId <= 0 || $visitorKey === '') {
            return false;
        }

        $rows = $this->newTouchModel()->clear()
            ->where(AffiliateTouch::schema_fields_SHARE_ID, $shareId)
            ->where(AffiliateTouch::schema_fields_EVENT_TYPE, $eventType)
            ->where(AffiliateTouch::schema_fields_VISITOR_KEY, $visitorKey)
            ->order(AffiliateTouch::schema_fields_CREATED_AT, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $createdAt = strtotime((string) ($row[AffiliateTouch::schema_fields_CREATED_AT] ?? ''));
            return $createdAt !== false && $createdAt >= time() - $seconds;
        }

        return false;
    }

    private function findCommission(int $orderId, int $orderItemId, int $affiliateId): ?AffiliateCommission
    {
        $commission = $this->newCommissionModel()->clear()
            ->where(AffiliateCommission::schema_fields_ORDER_ID, $orderId)
            ->where(AffiliateCommission::schema_fields_ORDER_ITEM_ID, $orderItemId)
            ->where(AffiliateCommission::schema_fields_AFFILIATE_ID, $affiliateId)
            ->limit(1)
            ->find()
            ->fetch();

        return $commission instanceof AffiliateCommission && $commission->getId() ? $commission : null;
    }

    private function adjustAffiliateTotalCommission(int $affiliateId, float $delta): void
    {
        if ($affiliateId <= 0 || abs($delta) < 0.00001) {
            return;
        }

        $affiliate = $this->newAffiliateModel();
        $affiliate->clear()->load($affiliateId);
        if (!$affiliate->getId()) {
            return;
        }

        $current = (float) ($affiliate->getData(Affiliate::schema_fields_TOTAL_COMMISSION) ?? 0);
        $affiliate->setData(Affiliate::schema_fields_TOTAL_COMMISSION, max(0.0, round($current + $delta, 2)))
            ->setData(Affiliate::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();
    }

    private function incrementShareCounter(AffiliateShare $share, string $field): void
    {
        if (!$share->getId()) {
            return;
        }

        $share->setData($field, max(0, (int) ($share->getData($field) ?? 0)) + 1)
            ->setData(AffiliateShare::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();
    }

    private function expireAttribution(int $attributionId): void
    {
        if ($attributionId <= 0) {
            return;
        }

        $attribution = $this->newAttributionModel();
        $attribution->load($attributionId);
        if (!$attribution->getId()) {
            return;
        }

        $attribution->setData(AffiliateAttribution::schema_fields_STATUS, self::ATTRIBUTION_STATUS_EXPIRED)
            ->setData(AffiliateAttribution::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();
    }

    private function isExpiredAt(mixed $expiresAt): bool
    {
        $timestamp = strtotime((string) $expiresAt);
        return $timestamp === false || $timestamp < time();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getAffiliateMetrics(int $affiliateId): array
    {
        $metrics = [
            'share_count' => 0,
            'outbound_share_count' => 0,
            'click_count' => 0,
            'view_count' => 0,
            'wishlist_count' => 0,
            'add_to_cart_count' => 0,
            'review_count' => 0,
            'order_count' => 0,
            'paid_count' => 0,
            'cancelled_count' => 0,
            'refunded_count' => 0,
            'pending_commission' => 0.0,
            'approved_commission' => 0.0,
            'paid_ledger_commission' => 0.0,
            'cancelled_commission' => 0.0,
            'commission_currency_code' => 'USD',
        ];

        if ($affiliateId <= 0) {
            return $metrics;
        }

        try {
            $shares = $this->newShareModel()->clear()
                ->where(AffiliateShare::schema_fields_AFFILIATE_ID, $affiliateId)
                ->select()
                ->fetchArray();
            $metrics['share_count'] = count($shares);
            foreach ($shares as $share) {
                if (!is_array($share)) {
                    continue;
                }
                $metrics['outbound_share_count'] += (int) ($share[AffiliateShare::schema_fields_OUTBOUND_COUNT] ?? 0);
                $metrics['click_count'] += (int) ($share[AffiliateShare::schema_fields_CLICK_COUNT] ?? 0);
                $metrics['order_count'] += (int) ($share[AffiliateShare::schema_fields_ORDER_COUNT] ?? 0);
            }

            $touches = $this->newTouchModel()->clear()
                ->where(AffiliateTouch::schema_fields_AFFILIATE_ID, $affiliateId)
                ->select()
                ->fetchArray();
            foreach ($touches as $touch) {
                if (!is_array($touch)) {
                    continue;
                }
                match ((string) ($touch[AffiliateTouch::schema_fields_EVENT_TYPE] ?? '')) {
                    self::EVENT_PRODUCT_VIEWED => ++$metrics['view_count'],
                    self::EVENT_WISHLIST_ADDED => ++$metrics['wishlist_count'],
                    self::EVENT_ADD_TO_CART => ++$metrics['add_to_cart_count'],
                    self::EVENT_REVIEW_CREATED => ++$metrics['review_count'],
                    self::EVENT_PAYMENT_PAID => ++$metrics['paid_count'],
                    self::EVENT_ORDER_CANCELLED => ++$metrics['cancelled_count'],
                    self::EVENT_ORDER_REFUNDED => ++$metrics['refunded_count'],
                    default => null,
                };
            }

            $commissions = $this->newCommissionModel()->clear()
                ->where(AffiliateCommission::schema_fields_AFFILIATE_ID, $affiliateId)
                ->select()
                ->fetchArray();
            foreach ($commissions as $commission) {
                if (!is_array($commission)) {
                    continue;
                }
                $amount = (float) ($commission[AffiliateCommission::schema_fields_COMMISSION_AMOUNT] ?? 0);
                $metrics['_commission_currencies'][$this->commissionCurrencyCode($commission)] = true;
                match ((string) ($commission[AffiliateCommission::schema_fields_STATUS] ?? '')) {
                    self::COMMISSION_STATUS_PENDING => $metrics['pending_commission'] += $amount,
                    self::COMMISSION_STATUS_APPROVED => $metrics['approved_commission'] += $amount,
                    self::COMMISSION_STATUS_PAID => $metrics['paid_ledger_commission'] += $amount,
                    self::COMMISSION_STATUS_CANCELLED, self::COMMISSION_STATUS_REVERSED => $metrics['cancelled_commission'] += $amount,
                    default => null,
                };
            }
        } catch (\Throwable) {
            return $metrics;
        }

        foreach (['pending_commission', 'approved_commission', 'paid_ledger_commission', 'cancelled_commission'] as $amountKey) {
            $metrics[$amountKey] = round((float) $metrics[$amountKey], 2);
        }
        $metrics['commission_currency_code'] = $this->summarizeCurrencyCodes((array) ($metrics['_commission_currencies'] ?? []));
        unset($metrics['_commission_currencies']);

        return $metrics;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getWithdrawalSummary(int $affiliateId): array
    {
        $summary = [
            'available_amount' => 0.0,
            'requested_amount' => 0.0,
            'processing_amount' => 0.0,
            'paid_amount' => 0.0,
            'rejected_amount' => 0.0,
            'cancelled_amount' => 0.0,
            'currency_code' => 'USD',
        ];
        if ($affiliateId <= 0) {
            return $summary;
        }

        $metrics = $this->getAffiliateMetrics($affiliateId);
        $approved = (float) ($metrics['approved_commission'] ?? 0);
        $summary['currency_code'] = $this->normalizeReportCurrencyCode((string) ($metrics['commission_currency_code'] ?? ''));

        try {
            $rows = $this->newWithdrawalModel()->clear()
                ->where(AffiliateWithdrawal::schema_fields_AFFILIATE_ID, $affiliateId)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            $summary['available_amount'] = round($approved, 2);
            return $summary;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $amount = (float) ($row[AffiliateWithdrawal::schema_fields_AMOUNT] ?? 0);
            $currency = $this->normalizeReportCurrencyCode((string) ($row[AffiliateWithdrawal::schema_fields_CURRENCY_CODE] ?? $summary['currency_code'] ?? ''));
            $summary['_withdrawal_currencies'][$currency] = true;
            match ((string) ($row[AffiliateWithdrawal::schema_fields_STATUS] ?? '')) {
                self::WITHDRAWAL_STATUS_REQUESTED => $summary['requested_amount'] += $amount,
                self::WITHDRAWAL_STATUS_PROCESSING => $summary['processing_amount'] += $amount,
                self::WITHDRAWAL_STATUS_PAID => $summary['paid_amount'] += $amount,
                self::WITHDRAWAL_STATUS_REJECTED => $summary['rejected_amount'] += $amount,
                self::WITHDRAWAL_STATUS_CANCELLED => $summary['cancelled_amount'] += $amount,
                default => null,
            };
        }

        $reserved = $summary['requested_amount'] + $summary['processing_amount'] + $summary['paid_amount'];
        $summary['available_amount'] = max(0.0, round($approved - $reserved, 2));
        foreach ($summary as $key => $value) {
            if (!is_numeric($value)) {
                continue;
            }
            $summary[$key] = round((float) $value, 2);
        }
        $withdrawalCurrencies = (array) ($summary['_withdrawal_currencies'] ?? []);
        unset($summary['_withdrawal_currencies']);
        if ($withdrawalCurrencies !== []) {
            $withdrawalCurrencies[$summary['currency_code']] = true;
            $summary['currency_code'] = $this->summarizeCurrencyCodes($withdrawalCurrencies);
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getDefaultShareLinkData(int $customerId): array
    {
        try {
            return $this->getShareLink($customerId, '/', 'homepage');
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getAffiliateWorkspaceData(int $affiliateId): array
    {
        $empty = [
            'share_links' => [],
            'referred_customers' => [],
            'promoted_products' => [],
            'affiliate_orders' => [],
            'commission_ledger' => [],
            'withdrawal_records' => [],
        ];
        if ($affiliateId <= 0) {
            return $empty;
        }

        try {
            $shares = $this->newShareModel()->clear()
                ->where(AffiliateShare::schema_fields_AFFILIATE_ID, $affiliateId)
                ->order(AffiliateShare::schema_fields_UPDATED_AT, 'DESC')
                ->select()
                ->fetchArray();
            $touches = $this->newTouchModel()->clear()
                ->where(AffiliateTouch::schema_fields_AFFILIATE_ID, $affiliateId)
                ->order(AffiliateTouch::schema_fields_CREATED_AT, 'DESC')
                ->select()
                ->fetchArray();
            $commissions = $this->newCommissionModel()->clear()
                ->where(AffiliateCommission::schema_fields_AFFILIATE_ID, $affiliateId)
                ->order(AffiliateCommission::schema_fields_CREATED_AT, 'DESC')
                ->select()
                ->fetchArray();
            $withdrawals = $this->newWithdrawalModel()->clear()
                ->where(AffiliateWithdrawal::schema_fields_AFFILIATE_ID, $affiliateId)
                ->order(AffiliateWithdrawal::schema_fields_REQUESTED_AT, 'DESC')
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return $empty;
        }

        $shares = array_values(array_filter($shares, 'is_array'));
        $touches = array_values(array_filter($touches, 'is_array'));
        $commissions = array_values(array_filter($commissions, 'is_array'));
        $withdrawals = array_values(array_filter($withdrawals ?? [], 'is_array'));

        $customerIds = [];
        $orderIds = [];
        $productIds = [];
        foreach ($touches as $touch) {
            $customerIds[] = (int) ($touch[AffiliateTouch::schema_fields_CUSTOMER_ID] ?? 0);
            $orderIds[] = (int) ($touch[AffiliateTouch::schema_fields_ORDER_ID] ?? 0);
            $productIds[] = (int) ($touch[AffiliateTouch::schema_fields_PRODUCT_ID] ?? 0);
        }
        foreach ($commissions as $commission) {
            $customerIds[] = (int) ($commission[AffiliateCommission::schema_fields_CUSTOMER_ID] ?? 0);
            $orderIds[] = (int) ($commission[AffiliateCommission::schema_fields_ORDER_ID] ?? 0);
            $productIds[] = (int) ($commission[AffiliateCommission::schema_fields_PRODUCT_ID] ?? 0);
        }
        foreach ($shares as $share) {
            $productIds[] = (int) ($share[AffiliateShare::schema_fields_PRODUCT_ID] ?? 0);
        }

        $customers = $this->indexByIntKey($this->queryProviderList('customer', 'getCustomersInfo', [
            'customer_ids' => $this->cleanIds($customerIds),
        ]), 'customer_id');
        $customerOrderStats = $this->indexByIntKey($this->queryProviderList('order', 'getCustomersOrderStats', [
            'customer_ids' => $this->cleanIds($customerIds),
        ]), 'customer_id');
        $orders = $this->indexByIntKey($this->queryProviderList('order', 'getOrdersInfo', [
            'order_ids' => $this->cleanIds($orderIds),
        ]), 'order_id');
        $products = $this->indexByIntKey($this->queryProviderList('product', 'getProductByIds', [
            'product_ids' => $this->cleanIds($productIds),
        ], 'frontend'), 'product_id');

        return [
            'share_links' => $this->buildShareLinkRows($shares, $touches, $commissions, $products),
            'referred_customers' => $this->buildReferredCustomerRows($touches, $commissions, $customers, $customerOrderStats),
            'promoted_products' => $this->buildPromotedProductRows($shares, $touches, $commissions, $products),
            'affiliate_orders' => $this->buildAffiliateOrderRows($commissions, $orders, $customers, $products),
            'commission_ledger' => $this->buildCommissionLedgerRows($commissions, $orders, $customers, $products),
            'withdrawal_records' => $this->buildWithdrawalRows($withdrawals),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $shares
     * @param array<int, array<string, mixed>> $touches
     * @param array<int, array<string, mixed>> $commissions
     * @param array<int, array<string, mixed>> $products
     * @return array<int, array<string, mixed>>
     */
    private function buildShareLinkRows(array $shares, array $touches, array $commissions, array $products): array
    {
        $touchMetrics = [];
        foreach ($touches as $touch) {
            $shareId = (int) ($touch[AffiliateTouch::schema_fields_SHARE_ID] ?? 0);
            if ($shareId <= 0) {
                continue;
            }
            $eventType = (string) ($touch[AffiliateTouch::schema_fields_EVENT_TYPE] ?? '');
            $touchMetrics[$shareId][$eventType] = (int) ($touchMetrics[$shareId][$eventType] ?? 0) + 1;
        }

        $commissionMetrics = [];
        foreach ($commissions as $commission) {
            $shareId = (int) ($commission[AffiliateCommission::schema_fields_SHARE_ID] ?? 0);
            if ($shareId <= 0) {
                continue;
            }
            $commissionMetrics[$shareId]['amount'] = round((float) ($commissionMetrics[$shareId]['amount'] ?? 0) + (float) ($commission[AffiliateCommission::schema_fields_COMMISSION_AMOUNT] ?? 0), 2);
            $commissionMetrics[$shareId]['orders'][(int) ($commission[AffiliateCommission::schema_fields_ORDER_ID] ?? 0)] = true;
            $commissionMetrics[$shareId]['currencies'][$this->commissionCurrencyCode($commission)] = true;
        }

        $rows = [];
        foreach ($shares as $share) {
            $shareId = (int) ($share[AffiliateShare::schema_fields_ID] ?? 0);
            $productId = (int) ($share[AffiliateShare::schema_fields_PRODUCT_ID] ?? 0);
            $targetPath = (string) ($share[AffiliateShare::schema_fields_TARGET_PATH] ?? '');
            $shareCode = (string) ($share[AffiliateShare::schema_fields_SHARE_CODE] ?? '');
            $product = $products[$productId] ?? [];
            $targetLabel = $this->formatShareTargetLabel($targetPath, $product);

            $rows[] = [
                'share_id' => $shareId,
                'share_code' => $shareCode,
                'target_label' => $targetLabel,
                'target_path' => $targetPath,
                'target_url' => $this->url()->getOriginUrl($targetPath === '' ? '/' : $targetPath),
                'tracking_url' => $this->url()->getOriginUrl('affiliate/redirect', ['code' => $shareCode]),
                'product_id' => $productId,
                'product_name' => (string) ($product['name'] ?? ''),
                'channel' => (string) ($share[AffiliateShare::schema_fields_CHANNEL] ?? ''),
                'outbound_count' => (int) ($share[AffiliateShare::schema_fields_OUTBOUND_COUNT] ?? 0),
                'click_count' => (int) ($share[AffiliateShare::schema_fields_CLICK_COUNT] ?? 0),
                'view_count' => (int) ($touchMetrics[$shareId][self::EVENT_PRODUCT_VIEWED] ?? 0),
                'registered_count' => (int) ($touchMetrics[$shareId][self::EVENT_CUSTOMER_REGISTERED] ?? 0),
                'wishlist_count' => (int) ($touchMetrics[$shareId][self::EVENT_WISHLIST_ADDED] ?? 0),
                'add_to_cart_count' => (int) ($touchMetrics[$shareId][self::EVENT_ADD_TO_CART] ?? 0),
                'order_count' => count(array_filter(array_keys($commissionMetrics[$shareId]['orders'] ?? []))),
                'commission_amount' => round((float) ($commissionMetrics[$shareId]['amount'] ?? 0), 2),
                'currency_code' => $this->summarizeCurrencyCodes((array) ($commissionMetrics[$shareId]['currencies'] ?? [])),
                'created_at' => (string) ($share[AffiliateShare::schema_fields_CREATED_AT] ?? ''),
                'updated_at' => (string) ($share[AffiliateShare::schema_fields_UPDATED_AT] ?? ''),
            ];
        }

        return array_slice($rows, 0, 20);
    }

    /**
     * @param array<int, array<string, mixed>> $touches
     * @param array<int, array<string, mixed>> $commissions
     * @param array<int, array<string, mixed>> $customers
     * @param array<int, array<string, mixed>> $customerOrderStats
     * @return array<int, array<string, mixed>>
     */
    private function buildReferredCustomerRows(array $touches, array $commissions, array $customers, array $customerOrderStats): array
    {
        $rows = [];
        foreach ($touches as $touch) {
            if ((string) ($touch[AffiliateTouch::schema_fields_EVENT_TYPE] ?? '') !== self::EVENT_CUSTOMER_REGISTERED) {
                continue;
            }
            $customerId = (int) ($touch[AffiliateTouch::schema_fields_CUSTOMER_ID] ?? 0);
            if ($customerId <= 0 || isset($rows[$customerId])) {
                continue;
            }
            $customer = $customers[$customerId] ?? [];
            $stats = $customerOrderStats[$customerId] ?? [];
            $customerEmail = (string) ($customer['email'] ?? '');
            $rows[$customerId] = [
                'customer_id' => $customerId,
                'email_masked' => $this->maskEmail($customerEmail),
                'name' => $this->safeCustomerDisplayName((string) ($customer['name'] ?? ''), $customerEmail),
                'registered_at' => (string) ($customer['created_at'] ?? $touch[AffiliateTouch::schema_fields_CREATED_AT] ?? ''),
                'first_attributed_at' => (string) ($touch[AffiliateTouch::schema_fields_CREATED_AT] ?? ''),
                'order_count' => (int) ($stats['order_count'] ?? 0),
                'paid_order_count' => (int) ($stats['paid_order_count'] ?? 0),
                'currency_code' => (string) ($stats['currency_code'] ?? 'USD'),
                'currency_codes' => is_array($stats['currency_codes'] ?? null) ? $stats['currency_codes'] : [],
                'total_amount' => round((float) ($stats['total_amount'] ?? 0), 2),
                'paid_amount' => round((float) ($stats['paid_amount'] ?? 0), 2),
                'last_order_at' => (string) ($stats['last_order_at'] ?? ''),
                'source' => '注册归因',
            ];
        }

        foreach ($commissions as $commission) {
            $customerId = (int) ($commission[AffiliateCommission::schema_fields_CUSTOMER_ID] ?? 0);
            if ($customerId <= 0 || isset($rows[$customerId])) {
                continue;
            }
            $customer = $customers[$customerId] ?? [];
            $stats = $customerOrderStats[$customerId] ?? [];
            $customerEmail = (string) ($customer['email'] ?? '');
            $rows[$customerId] = [
                'customer_id' => $customerId,
                'email_masked' => $this->maskEmail($customerEmail),
                'name' => $this->safeCustomerDisplayName((string) ($customer['name'] ?? ''), $customerEmail),
                'registered_at' => (string) ($customer['created_at'] ?? ''),
                'first_attributed_at' => (string) ($commission[AffiliateCommission::schema_fields_CREATED_AT] ?? ''),
                'order_count' => (int) ($stats['order_count'] ?? 0),
                'paid_order_count' => (int) ($stats['paid_order_count'] ?? 0),
                'currency_code' => (string) ($stats['currency_code'] ?? 'USD'),
                'currency_codes' => is_array($stats['currency_codes'] ?? null) ? $stats['currency_codes'] : [],
                'total_amount' => round((float) ($stats['total_amount'] ?? 0), 2),
                'paid_amount' => round((float) ($stats['paid_amount'] ?? 0), 2),
                'last_order_at' => (string) ($stats['last_order_at'] ?? ''),
                'source' => '订单归因',
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['first_attributed_at'] ?? ''), (string) ($a['first_attributed_at'] ?? '')));

        return array_slice(array_values($rows), 0, 20);
    }

    /**
     * @param array<int, array<string, mixed>> $shares
     * @param array<int, array<string, mixed>> $touches
     * @param array<int, array<string, mixed>> $commissions
     * @param array<int, array<string, mixed>> $products
     * @return array<int, array<string, mixed>>
     */
    private function buildPromotedProductRows(array $shares, array $touches, array $commissions, array $products): array
    {
        $rows = [];
        foreach ($shares as $share) {
            $productId = (int) ($share[AffiliateShare::schema_fields_PRODUCT_ID] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $rows[$productId] ??= $this->blankProductRow($productId, $products[$productId] ?? []);
            ++$rows[$productId]['share_count'];
            $rows[$productId]['click_count'] += (int) ($share[AffiliateShare::schema_fields_CLICK_COUNT] ?? 0);
        }

        foreach ($touches as $touch) {
            $productId = (int) ($touch[AffiliateTouch::schema_fields_PRODUCT_ID] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $rows[$productId] ??= $this->blankProductRow($productId, $products[$productId] ?? []);
            match ((string) ($touch[AffiliateTouch::schema_fields_EVENT_TYPE] ?? '')) {
                self::EVENT_PRODUCT_VIEWED => ++$rows[$productId]['view_count'],
                self::EVENT_WISHLIST_ADDED => ++$rows[$productId]['wishlist_count'],
                self::EVENT_ADD_TO_CART => ++$rows[$productId]['add_to_cart_count'],
                default => null,
            };
        }

        foreach ($commissions as $commission) {
            $productId = (int) ($commission[AffiliateCommission::schema_fields_PRODUCT_ID] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $rows[$productId] ??= $this->blankProductRow($productId, $products[$productId] ?? []);
            $rows[$productId]['orders'][(int) ($commission[AffiliateCommission::schema_fields_ORDER_ID] ?? 0)] = true;
            $rows[$productId]['currencies'][$this->commissionCurrencyCode($commission)] = true;
            $rows[$productId]['base_amount'] = round((float) $rows[$productId]['base_amount'] + (float) ($commission[AffiliateCommission::schema_fields_BASE_AMOUNT] ?? 0), 2);
            $rows[$productId]['commission_amount'] = round((float) $rows[$productId]['commission_amount'] + (float) ($commission[AffiliateCommission::schema_fields_COMMISSION_AMOUNT] ?? 0), 2);
        }

        foreach ($rows as &$row) {
            $row['order_count'] = count(array_filter(array_keys($row['orders'] ?? [])));
            $row['currency_code'] = $this->summarizeCurrencyCodes((array) ($row['currencies'] ?? []));
            unset($row['orders'], $row['currencies']);
        }
        unset($row);

        usort($rows, static fn (array $a, array $b): int => ($b['commission_amount'] <=> $a['commission_amount']) ?: ($b['click_count'] <=> $a['click_count']));

        return array_slice(array_values($rows), 0, 20);
    }

    /**
     * @param array<int, array<string, mixed>> $commissions
     * @param array<int, array<string, mixed>> $orders
     * @param array<int, array<string, mixed>> $customers
     * @param array<int, array<string, mixed>> $products
     * @return array<int, array<string, mixed>>
     */
    private function buildAffiliateOrderRows(array $commissions, array $orders, array $customers, array $products): array
    {
        $rows = [];
        foreach ($commissions as $commission) {
            $orderId = (int) ($commission[AffiliateCommission::schema_fields_ORDER_ID] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $order = $orders[$orderId] ?? [];
            $customerId = (int) ($commission[AffiliateCommission::schema_fields_CUSTOMER_ID] ?? ($order['customer_id'] ?? 0));
            $productId = (int) ($commission[AffiliateCommission::schema_fields_PRODUCT_ID] ?? 0);
            $currencyCode = $this->commissionCurrencyCode($commission, $order);
            $rows[$orderId] ??= [
                'order_id' => $orderId,
                'increment_id' => (string) ($order['increment_id'] ?? ('#' . $orderId)),
                'customer_id' => $customerId,
                'customer_email_masked' => $this->maskEmail((string) ($customers[$customerId]['email'] ?? '')),
                'order_total' => round((float) ($order['total'] ?? 0), 2),
                'currency_code' => $currencyCode,
                'order_status' => (string) ($order['status'] ?? ''),
                'payment_status' => (string) ($order['payment_status'] ?? ''),
                'base_amount' => 0.0,
                'commission_amount' => 0.0,
                'commission_statuses' => [],
                'products' => [],
                'created_at' => (string) ($order['created_at'] ?? $commission[AffiliateCommission::schema_fields_CREATED_AT] ?? ''),
            ];

            $rows[$orderId]['base_amount'] = round((float) $rows[$orderId]['base_amount'] + (float) ($commission[AffiliateCommission::schema_fields_BASE_AMOUNT] ?? 0), 2);
            $rows[$orderId]['commission_amount'] = round((float) $rows[$orderId]['commission_amount'] + (float) ($commission[AffiliateCommission::schema_fields_COMMISSION_AMOUNT] ?? 0), 2);
            if (($rows[$orderId]['currency_code'] ?? $currencyCode) !== $currencyCode) {
                $rows[$orderId]['currency_code'] = 'MIXED';
            }
            $rows[$orderId]['commission_statuses'][(string) ($commission[AffiliateCommission::schema_fields_STATUS] ?? '')] = true;
            if ($productId > 0) {
                $rows[$orderId]['products'][$productId] = (string) ($products[$productId]['name'] ?? ('#' . $productId));
            }
        }

        foreach ($rows as &$row) {
            $row['commission_status'] = implode(' / ', array_filter(array_keys($row['commission_statuses'])));
            $row['product_names'] = implode('、', array_filter(array_values($row['products'])));
            unset($row['commission_statuses'], $row['products']);
        }
        unset($row);

        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return array_slice(array_values($rows), 0, 20);
    }

    /**
     * @param array<int, array<string, mixed>> $commissions
     * @param array<int, array<string, mixed>> $orders
     * @param array<int, array<string, mixed>> $customers
     * @param array<int, array<string, mixed>> $products
     * @return array<int, array<string, mixed>>
     */
    private function buildCommissionLedgerRows(array $commissions, array $orders, array $customers, array $products): array
    {
        $rows = [];
        foreach ($commissions as $commission) {
            $orderId = (int) ($commission[AffiliateCommission::schema_fields_ORDER_ID] ?? 0);
            $order = $orders[$orderId] ?? [];
            $customerId = (int) ($commission[AffiliateCommission::schema_fields_CUSTOMER_ID] ?? ($order['customer_id'] ?? 0));
            $productId = (int) ($commission[AffiliateCommission::schema_fields_PRODUCT_ID] ?? 0);
            $rows[] = [
                'commission_id' => (int) ($commission[AffiliateCommission::schema_fields_ID] ?? 0),
                'order_id' => $orderId,
                'increment_id' => (string) ($order['increment_id'] ?? ($orderId > 0 ? '#' . $orderId : '')),
                'customer_email_masked' => $this->maskEmail((string) ($customers[$customerId]['email'] ?? '')),
                'product_id' => $productId,
                'product_name' => (string) ($products[$productId]['name'] ?? ($productId > 0 ? '#' . $productId : '')),
                'base_amount' => round((float) ($commission[AffiliateCommission::schema_fields_BASE_AMOUNT] ?? 0), 2),
                'commission_rate' => (float) ($commission[AffiliateCommission::schema_fields_COMMISSION_RATE] ?? 0),
                'commission_amount' => round((float) ($commission[AffiliateCommission::schema_fields_COMMISSION_AMOUNT] ?? 0), 2),
                'currency_code' => $this->commissionCurrencyCode($commission, $order),
                'status' => (string) ($commission[AffiliateCommission::schema_fields_STATUS] ?? ''),
                'reason' => (string) ($commission[AffiliateCommission::schema_fields_REASON] ?? ''),
                'created_at' => (string) ($commission[AffiliateCommission::schema_fields_CREATED_AT] ?? ''),
                'updated_at' => (string) ($commission[AffiliateCommission::schema_fields_UPDATED_AT] ?? ''),
            ];
        }

        return array_slice($rows, 0, 30);
    }

    /**
     * @param array<int, array<string, mixed>> $withdrawals
     * @return array<int, array<string, mixed>>
     */
    private function buildWithdrawalRows(array $withdrawals): array
    {
        $rows = [];
        foreach ($withdrawals as $withdrawal) {
            $rows[] = $this->formatWithdrawalRow($withdrawal);
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['requested_at'] ?? ''), (string) ($a['requested_at'] ?? '')));

        return array_slice($rows, 0, 30);
    }

    /**
     * @param array<string, mixed> $withdrawal
     * @return array<string, mixed>
     */
    private function formatWithdrawalRow(array $withdrawal): array
    {
        return [
            'withdrawal_id' => (int) ($withdrawal[AffiliateWithdrawal::schema_fields_ID] ?? $withdrawal['withdrawal_id'] ?? 0),
            'affiliate_id' => (int) ($withdrawal[AffiliateWithdrawal::schema_fields_AFFILIATE_ID] ?? 0),
            'customer_id' => (int) ($withdrawal[AffiliateWithdrawal::schema_fields_CUSTOMER_ID] ?? 0),
            'amount' => round((float) ($withdrawal[AffiliateWithdrawal::schema_fields_AMOUNT] ?? 0), 2),
            'currency_code' => $this->normalizeReportCurrencyCode((string) ($withdrawal[AffiliateWithdrawal::schema_fields_CURRENCY_CODE] ?? '')),
            'status' => (string) ($withdrawal[AffiliateWithdrawal::schema_fields_STATUS] ?? self::WITHDRAWAL_STATUS_REQUESTED),
            'method' => (string) ($withdrawal[AffiliateWithdrawal::schema_fields_METHOD] ?? 'manual'),
            'account_label' => (string) ($withdrawal[AffiliateWithdrawal::schema_fields_ACCOUNT_LABEL] ?? ''),
            'note' => (string) ($withdrawal[AffiliateWithdrawal::schema_fields_NOTE] ?? ''),
            'requested_at' => (string) ($withdrawal[AffiliateWithdrawal::schema_fields_REQUESTED_AT] ?? ''),
            'processed_at' => (string) ($withdrawal[AffiliateWithdrawal::schema_fields_PROCESSED_AT] ?? ''),
            'paid_at' => (string) ($withdrawal[AffiliateWithdrawal::schema_fields_PAID_AT] ?? ''),
            'created_at' => (string) ($withdrawal[AffiliateWithdrawal::schema_fields_CREATED_AT] ?? ''),
            'updated_at' => (string) ($withdrawal[AffiliateWithdrawal::schema_fields_UPDATED_AT] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function blankProductRow(int $productId, array $product): array
    {
        return [
            'product_id' => $productId,
            'product_name' => (string) ($product['name'] ?? ('#' . $productId)),
            'sku' => (string) ($product['sku'] ?? ''),
            'share_count' => 0,
            'click_count' => 0,
            'view_count' => 0,
            'wishlist_count' => 0,
            'add_to_cart_count' => 0,
            'order_count' => 0,
            'base_amount' => 0.0,
            'commission_amount' => 0.0,
            'currency_code' => 'USD',
            'currencies' => [],
            'orders' => [],
        ];
    }

    /**
     * @param array<int, mixed> $ids
     * @return array<int, int>
     */
    private function cleanIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function queryProviderList(string $provider, string $operation, array $params, string $area = 'frontend'): array
    {
        try {
            $result = $area !== ''
                ? w_query($provider, $operation, $params, $area)
                : w_query($provider, $operation, $params);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($result)) {
            return [];
        }

        return array_values(array_filter($result, 'is_array'));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function indexByIntKey(array $rows, string $key): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $id = (int) ($row[$key] ?? 0);
            if ($id > 0) {
                $indexed[$id] = $row;
            }
        }

        return $indexed;
    }

    private function maskEmail(string $email): string
    {
        $email = trim($email);
        if ($email === '' || !str_contains($email, '@')) {
            return '***';
        }

        [$local, $domain] = explode('@', $email, 2);
        $localLength = strlen($local);
        if ($localLength <= 2) {
            $maskedLocal = substr($local, 0, 1) . '***';
        } else {
            $maskedLocal = substr($local, 0, 2) . '***' . substr($local, -1);
        }

        return $maskedLocal . '@' . $domain;
    }

    private function normalizeWithdrawalMethod(string $method): string
    {
        $method = strtolower(trim($method));
        $method = preg_replace('/[^a-z0-9_]+/', '_', $method) ?? '';

        return $method !== '' ? substr($method, 0, 40) : 'manual';
    }

    private function maskPayoutAccount(string $accountLabel): string
    {
        $value = trim($accountLabel);
        if ($value === '') {
            return '';
        }

        if (str_contains($value, '@')) {
            return $this->maskEmail($value);
        }

        $compact = preg_replace('/\s+/', '', $value) ?? $value;
        $length = strlen($compact);
        if ($length <= 6) {
            return str_repeat('*', max(3, $length));
        }

        return substr($compact, 0, 2) . str_repeat('*', min(8, max(3, $length - 6))) . substr($compact, -4);
    }

    private function safeCustomerDisplayName(string $name, string $email): string
    {
        $name = trim($name);
        if ($name === '' || str_contains($name, '@')) {
            return '';
        }

        if ($email !== '' && strcasecmp($name, $email) === 0) {
            return '';
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $product
     */
    private function formatShareTargetLabel(string $targetPath, array $product): string
    {
        $productName = trim((string) ($product['name'] ?? ''));
        if ($productName !== '') {
            return $productName;
        }

        $targetPath = trim($targetPath);
        if ($targetPath === '' || $targetPath === '/') {
            return (string) __('商城首页');
        }

        if (str_starts_with($targetPath, $this->getReferralBasePath())) {
            return (string) __('邀请注册页');
        }

        return '/' . ltrim($targetPath, '/');
    }

    /**
     * @return array{target_path:string,label:string,channel:string}
     */
    private function normalizeShareTarget(string $targetUrl): array
    {
        $targetUrl = trim($targetUrl);
        if ($targetUrl === '') {
            $targetUrl = '/';
        }

        if (preg_match('/^https?:\/\//i', $targetUrl)) {
            $origin = $this->url()->getOriginUrl('');
            $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
            $targetHost = strtolower((string) parse_url($targetUrl, PHP_URL_HOST));
            if ($originHost !== '' && $targetHost !== '' && $originHost !== $targetHost) {
                throw new \InvalidArgumentException((string) __('推广链接只能选择本站地址。'));
            }
            $path = (string) (parse_url($targetUrl, PHP_URL_PATH) ?: '/');
            $query = (string) (parse_url($targetUrl, PHP_URL_QUERY) ?: '');
            $targetUrl = $path . ($query !== '' ? '?' . $query : '');
        }

        if ($targetUrl[0] !== '/') {
            $targetUrl = '/' . $targetUrl;
        }

        $path = '/' . ltrim($targetUrl, '/');
        if (preg_match('#^/affiliate/redirect(?:\?|/|$)#i', $path)) {
            throw new \InvalidArgumentException((string) __('不能把分销跳转链接再次作为推广目标。'));
        }

        $targetPath = ltrim($path, '/');
        if ($path === '/') {
            $targetPath = '/';
        }

        return [
            'target_path' => $targetPath,
            'label' => $path === '/' ? (string) __('商城首页') : $path,
            'channel' => $path === '/' ? 'homepage' : ('custom_' . substr(sha1($path), 0, 12)),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadAffiliateByReferralCode(string $referralCode): ?array
    {
        if ($referralCode === '') {
            return null;
        }

        $rows = $this->newAffiliateModel()->clear()
            ->where(Affiliate::schema_fields_REFERRAL_CODE, $referralCode)
            ->where(Affiliate::schema_fields_STATUS, self::STATUS_ACTIVE)
            ->limit(1)
            ->select()
            ->fetchArray();

        foreach ($rows as $row) {
            if (is_array($row)) {
                return $row;
            }
        }

        return null;
    }

    private function bindAttributionCustomer(int $attributionId, int $customerId): void
    {
        if ($attributionId <= 0 || $customerId <= 0) {
            return;
        }

        $attribution = $this->newAttributionModel();
        $attribution->clear()->load($attributionId);
        if (!$attribution->getId()) {
            return;
        }

        if ((int) ($attribution->getData(AffiliateAttribution::schema_fields_CUSTOMER_ID) ?? 0) === $customerId) {
            return;
        }

        $attribution->setData(AffiliateAttribution::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(AffiliateAttribution::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getAffiliateRowById(int $affiliateId): ?array
    {
        if ($affiliateId <= 0) {
            return null;
        }

        $rows = $this->newAffiliateModel()->clear()
            ->where(Affiliate::schema_fields_ID, $affiliateId)
            ->limit(1)
            ->select()
            ->fetchArray();

        foreach ($rows as $row) {
            if (is_array($row)) {
                return $row;
            }
        }

        return null;
    }

    private function extractOrderId(array $payload): int
    {
        $orderId = (int) ($payload['order_id'] ?? 0);
        if ($orderId > 0) {
            return $orderId;
        }

        $order = $payload['order'] ?? null;
        if (is_object($order) && method_exists($order, 'getId')) {
            return (int) $order->getId();
        }

        return 0;
    }

    private function extractOrderCustomerId(array $payload): int
    {
        $order = $payload['order'] ?? null;
        if (is_object($order) && method_exists($order, 'getData')) {
            return (int) ($order->getData('customer_id') ?? 0);
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $summary
     */
    private function extractOrderCurrencyCode(array $payload, array $summary = []): string
    {
        $currency = (string) ($summary['currency'] ?? $summary['currency_code'] ?? $payload['currency'] ?? $payload['currency_code'] ?? '');
        if ($currency === '') {
            $order = $payload['order'] ?? null;
            if (is_object($order) && method_exists($order, 'getData')) {
                $currency = (string) ($order->getData('currency_code') ?? $order->getData('currency') ?? '');
            }
        }

        return $this->normalizeCurrencyCode($currency);
    }

    private function normalizeCurrencyCode(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'USD';
    }

    private function normalizeReportCurrencyCode(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if ($currency === 'MIXED') {
            return $currency;
        }

        return $this->normalizeCurrencyCode($currency);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function commissionCurrencyCode(array $row, array $order = []): string
    {
        return $this->normalizeCurrencyCode((string) (
            $row[AffiliateCommission::schema_fields_CURRENCY_CODE]
            ?? $row['currency_code']
            ?? $order['currency_code']
            ?? $order['currency']
            ?? ''
        ));
    }

    /**
     * @param array<string, bool> $currencies
     */
    private function summarizeCurrencyCodes(array $currencies): string
    {
        $codes = array_keys(array_filter($currencies));
        sort($codes);
        return count($codes) === 1 ? $codes[0] : ($codes === [] ? 'USD' : 'MIXED');
    }

    private function resolveShareTargetUrl(AffiliateShare $share): string
    {
        $productId = (int) ($share->getData(AffiliateShare::schema_fields_PRODUCT_ID) ?? 0);
        $targetPath = trim((string) ($share->getData(AffiliateShare::schema_fields_TARGET_PATH) ?? self::PRODUCT_VIEW_ROUTE));
        if ($targetPath !== '' && preg_match('/^https?:\/\//i', $targetPath)) {
            return $targetPath;
        }
        if ($targetPath === '' || $targetPath === 'product/view') {
            $targetPath = self::PRODUCT_VIEW_ROUTE;
        }

        $params = $targetPath === self::PRODUCT_VIEW_ROUTE ? ['id' => $productId] : [];

        return $this->buildFrontendPath($targetPath, $params);
    }

    /**
     * @param array<string, scalar|null> $params
     */
    private function buildFrontendPath(string $path, array $params = []): string
    {
        $url = '/' . ltrim($path, '/');
        $query = http_build_query(array_filter(
            $params,
            static fn ($value): bool => $value !== null && $value !== ''
        ));

        if ($query === '') {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . $query;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildPlatformShareUrls(string $targetUrl): array
    {
        $encodedUrl = rawurlencode($targetUrl);

        return [
            ['platform' => 'copy', 'label' => (string) __('复制链接'), 'url' => $targetUrl],
            ['platform' => 'facebook', 'label' => 'Facebook', 'url' => 'https://www.facebook.com/sharer/sharer.php?u=' . $encodedUrl],
            ['platform' => 'x', 'label' => 'X', 'url' => 'https://twitter.com/intent/tweet?url=' . $encodedUrl],
            ['platform' => 'linkedin', 'label' => 'LinkedIn', 'url' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $encodedUrl],
            ['platform' => 'whatsapp', 'label' => 'WhatsApp', 'url' => 'https://api.whatsapp.com/send?text=' . $encodedUrl],
        ];
    }

    private function generateShareCode(int $affiliateId, int $productId): string
    {
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $code = 'AFF' . strtoupper(base_convert((string) max(1, $affiliateId), 10, 36))
                . 'P' . strtoupper(base_convert((string) max(1, $productId), 10, 36))
                . strtoupper(bin2hex(random_bytes(4)));

            $share = $this->newShareModel();
            $share->load(AffiliateShare::schema_fields_SHARE_CODE, $code);
            if (!$share->getId()) {
                return $code;
            }
        }

        return 'AFF' . strtoupper(bin2hex(random_bytes(12)));
    }

    private function getCurrentCustomerId(): int
    {
        try {
            return (int) ($this->customerSession()->getUserId() ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function getVisitorKey(bool $create): string
    {
        $token = '';
        try {
            $token = trim((string) Cookie::get(self::VISITOR_COOKIE, ''));
        } catch (\Throwable) {
            $token = '';
        }

        if ($token === '') {
            try {
                $sessionValue = $this->customerSession()->get(self::VISITOR_COOKIE);
                $token = is_scalar($sessionValue) ? trim((string) $sessionValue) : '';
            } catch (\Throwable) {
                $token = '';
            }
        }

        if ($token === '' && $create) {
            $token = 'afv_' . bin2hex(random_bytes(16));
            try {
                Cookie::set(self::VISITOR_COOKIE, $token, self::ATTRIBUTION_TTL_SECONDS, ['httponly' => true, 'samesite' => 'Lax']);
            } catch (\Throwable) {
            }
            try {
                $this->customerSession()->set(self::VISITOR_COOKIE, $token);
            } catch (\Throwable) {
            }
        }

        if ($token === '') {
            return '';
        }

        return hash('sha256', $token);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function persistAttributionState(string $shareCode, array $state): void
    {
        try {
            Cookie::set(self::ATTRIBUTION_COOKIE, $shareCode, self::ATTRIBUTION_TTL_SECONDS, ['httponly' => true, 'samesite' => 'Lax']);
        } catch (\Throwable) {
        }

        try {
            $this->customerSession()->set(self::ATTRIBUTION_SESSION_KEY, $state + ['share_code' => $shareCode]);
        } catch (\Throwable) {
        }
    }

    private function normalizeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        return preg_replace('/[^a-z0-9_]+/', '_', $channel) ?? '';
    }

    private function normalizeEventType(string $eventType): string
    {
        $eventType = strtolower(trim($eventType));
        return preg_replace('/[^a-z0-9_]+/', '_', $eventType) ?? '';
    }

    private function dispatchAffiliateEvent(string $eventName, array $payload): void
    {
        $this->eventsManager()->dispatch('WeShop_Affiliate::' . $eventName, $payload);
    }

    private function newAffiliateModel(): Affiliate
    {
        return $this->affiliateModel ? clone $this->affiliateModel : ObjectManager::getInstance(Affiliate::class);
    }

    private function newShareModel(): AffiliateShare
    {
        return $this->shareModel ? clone $this->shareModel : ObjectManager::getInstance(AffiliateShare::class);
    }

    private function newTouchModel(): AffiliateTouch
    {
        return $this->touchModel ? clone $this->touchModel : ObjectManager::getInstance(AffiliateTouch::class);
    }

    private function newAttributionModel(): AffiliateAttribution
    {
        return $this->attributionModel ? clone $this->attributionModel : ObjectManager::getInstance(AffiliateAttribution::class);
    }

    private function newCommissionModel(): AffiliateCommission
    {
        return $this->commissionModel ? clone $this->commissionModel : ObjectManager::getInstance(AffiliateCommission::class);
    }

    private function newWithdrawalModel(): AffiliateWithdrawal
    {
        return $this->withdrawalModel ? clone $this->withdrawalModel : ObjectManager::getInstance(AffiliateWithdrawal::class);
    }

    private function eventsManager(): EventsManager
    {
        return $this->eventsManager ?? ObjectManager::getInstance(EventsManager::class);
    }

    private function url(): Url
    {
        return $this->url ?? ObjectManager::getInstance(Url::class);
    }

    private function customerSession(): CustomerSession
    {
        return $this->customerSession ?? ObjectManager::getInstance(CustomerSession::class);
    }
}
