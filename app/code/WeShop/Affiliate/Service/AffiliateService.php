<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Service;

use WeShop\Affiliate\Model\Affiliate;
use WeShop\Affiliate\Model\AffiliateAttribution;
use WeShop\Affiliate\Model\AffiliateCommission;
use WeShop\Affiliate\Model\AffiliateShare;
use WeShop\Affiliate\Model\AffiliateTouch;
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

    public const EVENT_SHARE_OUTBOUND = 'share_outbound';
    public const EVENT_SHARE_CLICKED = 'share_clicked';
    public const EVENT_PRODUCT_VIEWED = 'product_viewed';
    public const EVENT_WISHLIST_ADDED = 'wishlist_added';
    public const EVENT_ADD_TO_CART = 'add_to_cart';
    public const EVENT_REVIEW_CREATED = 'review_created';
    public const EVENT_ORDER_CREATED = 'order_created';
    public const EVENT_PAYMENT_PAID = 'payment_paid';
    public const EVENT_ORDER_CANCELLED = 'order_cancelled';
    public const EVENT_ORDER_REFUNDED = 'order_refunded';

    private const ATTRIBUTION_TTL_SECONDS = 2592000;
    private const VISITOR_COOKIE = 'weshop_affiliate_visitor';
    private const ATTRIBUTION_COOKIE = 'weshop_affiliate_share';
    private const ATTRIBUTION_SESSION_KEY = 'weshop_affiliate_attribution';
    private const CLICK_DEDUPE_SECONDS = 1800;
    private const PRODUCT_VIEW_ROUTE = 'product/frontend/product/view';

    public function __construct(
        private readonly ?Affiliate $affiliateModel = null,
        private readonly ?AffiliateShare $shareModel = null,
        private readonly ?AffiliateTouch $touchModel = null,
        private readonly ?AffiliateAttribution $attributionModel = null,
        private readonly ?AffiliateCommission $commissionModel = null,
        private readonly ?EventsManager $eventsManager = null,
        private readonly ?Url $url = null,
        private readonly ?CustomerSession $customerSession = null
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

        $share = $this->ensureProductShare(
            (int) ($account[Affiliate::schema_fields_ID] ?? 0),
            $customerId,
            $productId,
            $this->normalizeChannel($channel)
        );

        $shareCode = (string) ($share->getData(AffiliateShare::schema_fields_SHARE_CODE) ?? '');
        $trackingUrl = $this->url()->getUrl('affiliate/redirect', ['code' => $shareCode]);
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

        $touch = $this->recordTouch($eventType, [
            'share_id' => (int) ($attribution[AffiliateAttribution::schema_fields_SHARE_ID] ?? 0),
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
     * @return array<string, mixed>
     */
    public function getAffiliateSummary(int $customerId): array
    {
        $account = $this->getAffiliateAccountOrCreate($customerId);
        $affiliateId = (int) ($account[Affiliate::schema_fields_ID] ?? 0);
        $metrics = $this->getAffiliateMetrics($affiliateId);

        $totalCommission = (float) ($account[Affiliate::schema_fields_TOTAL_COMMISSION] ?? 0);
        $paidCommission = (float) ($account[Affiliate::schema_fields_PAID_COMMISSION] ?? 0);
        $ledgerPending = (float) ($metrics['pending_commission'] ?? 0);
        $legacyPending = max(0.0, round($totalCommission - $paidCommission, 2));
        $pendingCommission = $ledgerPending > 0 ? $ledgerPending : $legacyPending;
        $referralCode = (string) ($account[Affiliate::schema_fields_REFERRAL_CODE] ?? '');

        return [
            'affiliate_id' => $affiliateId,
            'customer_id' => (int) ($account[Affiliate::schema_fields_CUSTOMER_ID] ?? $customerId),
            'referral_code' => $referralCode,
            'referral_link' => $referralCode === '' ? '' : $this->getReferralBasePath() . '?ref=' . rawurlencode($referralCode),
            'commission_rate' => (float) ($account[Affiliate::schema_fields_COMMISSION_RATE] ?? 0),
            'total_commission' => $totalCommission,
            'paid_commission' => $paidCommission,
            'pending_commission' => $pendingCommission,
            'approved_commission' => (float) ($metrics['approved_commission'] ?? 0),
            'cancelled_commission' => (float) ($metrics['cancelled_commission'] ?? 0),
            'status' => (string) ($account[Affiliate::schema_fields_STATUS] ?? self::STATUS_DISABLED),
        ] + $metrics;
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
        return '/register';
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
        $affiliate->load($affiliateId);

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
            $affiliate->load($affiliateId);
            if (!$affiliate->getId()) {
                throw new \InvalidArgumentException((string) __('Affiliate record not found.'));
            }
        } elseif ($customerId) {
            $existing = $this->getAffiliateAccount($customerId);
            if (is_array($existing) && (int) ($existing[Affiliate::schema_fields_ID] ?? 0) > 0) {
                $affiliate->load((int) $existing[Affiliate::schema_fields_ID]);
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

    private function ensureProductShare(int $affiliateId, int $customerId, int $productId, string $channel): AffiliateShare
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
            AffiliateShare::schema_fields_TARGET_PATH => self::PRODUCT_VIEW_ROUTE,
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
        $affiliate->load($affiliateId);
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

        return $metrics;
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

        return $this->buildFrontendPath($targetPath, ['id' => $productId]);
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
