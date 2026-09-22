<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Checkout\Api\CheckoutSessionStoreInterface;
use Weline\Checkout\Api\ContinuePayBindingInterface;
use Weline\Checkout\Model\CheckoutSession;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order as OrderModel;

/**
 * 续付绑定：按订单原 Type 建桶；同会话最多一个 continue_pay 桶。
 * 桶列表存 Cookie（权威 quote 仍在 CheckoutSessionStore）。
 */
final class ContinuePayBindingService implements ContinuePayBindingInterface
{
    public const COOKIE_BUCKETS = 'weline_checkout_session_buckets';

    public function __construct(
        private readonly CheckoutSessionStoreInterface $sessions,
        private readonly CheckoutSessionAccessService $access,
        private readonly CheckoutTypeProviderRegistry $types,
    ) {
    }

    public function adopt(array $params, ?int $customerId): array
    {
        $quoteToken = trim((string)($params['quote_token'] ?? ''));
        $orderUuid = trim((string)($params['order_uuid'] ?? ''));
        $idempotencyKey = trim((string)($params['idempotency_key'] ?? ''));
        $paymentMethod = trim((string)($params['payment_method'] ?? ''));
        if ($quoteToken === '' || $orderUuid === '') {
            return [
                'success' => false,
                'message' => (string)__('续付凭据不完整'),
                'error_code' => 'continue_pay_params_incomplete',
            ];
        }
        if (!$this->access->canAccess($quoteToken, $orderUuid, $customerId)) {
            return [
                'success' => false,
                'message' => (string)__('无权继续支付该订单'),
                'error_code' => 'continue_pay_access_denied',
            ];
        }

        $session = $this->sessions->get($quoteToken);
        if (!is_array($session) || (string)($session['state'] ?? '') !== CheckoutSession::STATE_SUBMITTED) {
            return [
                'success' => false,
                'message' => (string)__('结账会话不可用于继续支付'),
                'error_code' => 'continue_pay_session_invalid',
            ];
        }

        $cartType = strtolower(trim((string)($session['cart_type'] ?? $session['order_type'] ?? 'toc'))) ?: 'toc';
        $typeCode = $this->types->typeCodeFromCartType($cartType);
        $type = $this->types->get($typeCode);
        $label = $type !== null ? $type->getLabel() : (string)__('零售');
        $capabilities = $type !== null ? $type->getCapabilities() : [
            'needs_shipping_address' => true,
            'needs_shipping_method' => true,
        ];

        $orderSnapshot = $this->loadOrderSnapshot($orderUuid);
        if ($orderSnapshot === null) {
            return [
                'success' => false,
                'message' => (string)__('订单不存在或不可用'),
                'error_code' => 'continue_pay_order_missing',
            ];
        }
        // Prefer order address; if incomplete, fall back to submitted checkout session address.
        $orderSnapshot = $this->enrichSnapshotAddressFromSession($orderSnapshot, $session);
        $status = strtolower(trim((string)($orderSnapshot['status'] ?? '')));
        if (in_array($status, ['paid', 'fulfilled', 'completed', 'refunded', 'cancelled', 'canceled'], true)) {
            return [
                'success' => false,
                'message' => (string)__('订单已不可继续支付'),
                'error_code' => 'continue_pay_order_not_pending',
            ];
        }

        $bucketId = 'cp_' . substr(hash('sha256', $quoteToken . '|' . $orderUuid), 0, 16);
        $continueBucket = [
            'bucket_id' => $bucketId,
            'type_code' => $typeCode,
            'cart_type' => $cartType,
            'payment_mode' => self::MODE_CONTINUE_PAY,
            'quote_token' => $quoteToken,
            'order_uuid' => $orderUuid,
            'idempotency_key' => $idempotencyKey,
            'payment_method' => $paymentMethod,
            'label' => (string)__('继续支付'),
            'type_label' => $label,
            'capabilities' => $capabilities,
        ];

        $buckets = $this->readBuckets();
        // Drop previous continue_pay bucket (at most one).
        $buckets = array_values(array_filter(
            $buckets,
            static fn(array $b): bool => ($b['payment_mode'] ?? '') !== self::MODE_CONTINUE_PAY
        ));
        // Ensure a normal bucket for the prior active quote if different and present.
        $priorToken = trim((string)($params['prior_quote_token'] ?? ''));
        if ($priorToken !== '' && $priorToken !== $quoteToken) {
            $priorSession = $this->sessions->get($priorToken);
            if (is_array($priorSession)
                && (string)($priorSession['state'] ?? '') === CheckoutSession::STATE_QUOTED
            ) {
                $priorCart = strtolower(trim((string)($priorSession['cart_type'] ?? $priorSession['order_type'] ?? 'toc'))) ?: 'toc';
                $priorType = $this->types->typeCodeFromCartType($priorCart);
                $priorProvider = $this->types->get($priorType);
                $priorId = 'nm_' . substr(hash('sha256', $priorToken), 0, 16);
                $exists = false;
                foreach ($buckets as $b) {
                    if (($b['bucket_id'] ?? '') === $priorId) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $buckets[] = [
                        'bucket_id' => $priorId,
                        'type_code' => $priorType,
                        'cart_type' => $priorCart,
                        'payment_mode' => self::MODE_NORMAL,
                        'quote_token' => $priorToken,
                        'label' => $priorProvider !== null ? $priorProvider->getLabel() : (string)__('零售'),
                        'type_label' => $priorProvider !== null ? $priorProvider->getLabel() : (string)__('零售'),
                        'capabilities' => $priorProvider !== null ? $priorProvider->getCapabilities() : [],
                    ];
                }
            }
        }

        $buckets[] = $continueBucket;
        $this->writeBuckets($buckets, $bucketId);

        $addressComplete = !empty($orderSnapshot['address_complete']);
        $needsShipping = !empty($capabilities['needs_shipping_address']);
        $paymentChrome = $this->resolvePaymentMethodChrome($paymentMethod);
        $shippingChrome = $this->resolveShippingMethodChrome(
            (string)($orderSnapshot['shipping_method'] ?? ''),
            (string)($orderSnapshot['currency'] ?? 'CNY'),
            (float)($orderSnapshot['shipping_amount'] ?? 0),
        );
        $itemsChrome = $this->resolveOrderItemsChrome($orderSnapshot);

        return [
            'success' => true,
            'active_bucket_id' => $bucketId,
            'bucket' => $continueBucket,
            'buckets' => $buckets,
            'switcher_visible' => count($buckets) >= 2,
            'order' => $orderSnapshot,
            'address_complete' => $addressComplete,
            'items' => $itemsChrome['items'],
            'items_html' => $itemsChrome['html'],
            'payment_methods' => $paymentChrome['methods'],
            'payment_methods_html' => $paymentChrome['html'],
            'shipping_methods' => $shippingChrome['methods'],
            'shipping_methods_html' => $shippingChrome['html'],
            'chrome' => [
                'title' => (string)__('继续支付'),
                // 齐全：只说明续付；不全：才提示补全。禁止默认催「去改地址」。
                'detail' => ($needsShipping && !$addressComplete)
                    ? (string)__('请补全收货信息后再支付。')
                    : (string)__('正在继续支付未完成订单。'),
                'cta_label' => (string)__('继续支付'),
                'submit_mode' => 'resumePaymentV2',
                'address_complete' => $addressComplete,
                'expand_address' => $needsShipping && !$addressComplete,
            ],
        ];
    }

    public function release(?string $bucketId = null): array
    {
        $buckets = $this->readBuckets();
        $active = $this->readActiveBucketId();
        $target = $bucketId !== null && $bucketId !== '' ? $bucketId : $active;
        $next = [];
        foreach ($buckets as $b) {
            if (($b['payment_mode'] ?? '') === self::MODE_CONTINUE_PAY
                && ($target === '' || ($b['bucket_id'] ?? '') === $target)
            ) {
                continue;
            }
            $next[] = $b;
        }
        $newActive = '';
        if ($next !== []) {
            $newActive = (string)($next[0]['bucket_id'] ?? '');
            foreach ($next as $b) {
                if (($b['bucket_id'] ?? '') === $active && ($b['payment_mode'] ?? '') !== self::MODE_CONTINUE_PAY) {
                    $newActive = (string)$b['bucket_id'];
                    break;
                }
            }
        }
        $this->writeBuckets($next, $newActive);

        return [
            'ok' => true,
            'buckets' => $next,
            'active_bucket_id' => $newActive,
            'switcher_visible' => count($next) >= 2,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listBuckets(): array
    {
        return $this->readBuckets();
    }

    public function getActiveBucketId(): string
    {
        return $this->readActiveBucketId();
    }

    /**
     * @param array<string, mixed> $bucket
     */
    public function upsertNormalBucket(array $bucket): void
    {
        $id = trim((string)($bucket['bucket_id'] ?? ''));
        if ($id === '') {
            return;
        }
        $buckets = $this->readBuckets();
        $found = false;
        foreach ($buckets as $i => $b) {
            if (($b['bucket_id'] ?? '') === $id) {
                $buckets[$i] = array_merge($b, $bucket, ['payment_mode' => self::MODE_NORMAL]);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $bucket['payment_mode'] = self::MODE_NORMAL;
            $buckets[] = $bucket;
        }
        $active = $this->readActiveBucketId() ?: $id;
        $this->writeBuckets($buckets, $active);
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function enrichSnapshotAddressFromSession(array $snapshot, array $session): array
    {
        $shipping = is_array($snapshot['shipping_address'] ?? null) ? $snapshot['shipping_address'] : [];
        if ($this->isShippingAddressComplete($shipping)) {
            return $snapshot;
        }
        $fromSession = $session['shipping_address'] ?? null;
        if (!is_array($fromSession) || $fromSession === []) {
            $fromSession = $session['address'] ?? null;
        }
        if (!is_array($fromSession) || $fromSession === []) {
            $submitted = is_array($session['submitted_result'] ?? null) ? $session['submitted_result'] : [];
            $fromSession = $submitted['shipping_address'] ?? null;
        }
        if (!is_array($fromSession) || $fromSession === []) {
            return $snapshot;
        }
        $merged = array_merge($shipping, array_filter(
            $fromSession,
            static fn(mixed $v): bool => $v !== null && $v !== ''
        ));
        $snapshot['shipping_address'] = $merged;
        $snapshot['address_complete'] = $this->isShippingAddressComplete($merged);

        return $snapshot;
    }

    private function loadOrderSnapshot(string $orderUuid): ?array
    {
        try {
            /** @var OrderModel $order */
            $order = ObjectManager::getInstance(OrderModel::class);
            $order->clear()->where(OrderModel::schema_fields_ORDER_UUID, $orderUuid)->find()->fetch();
            if (!(int)$order->getData(OrderModel::schema_fields_ID)) {
                return null;
            }
            $shippingRaw = $order->getData(OrderModel::schema_fields_SHIPPING_ADDRESS);
            $shipping = [];
            if (is_string($shippingRaw) && $shippingRaw !== '') {
                $decoded = json_decode($shippingRaw, true);
                if (is_array($decoded)) {
                    $shipping = $decoded;
                }
            } elseif (is_array($shippingRaw)) {
                $shipping = $shippingRaw;
            }
            $catalogRaw = $order->getData(OrderModel::schema_fields_CATALOG_SNAPSHOT_JSON);
            $lines = [];
            if (is_string($catalogRaw) && $catalogRaw !== '') {
                $catalog = json_decode($catalogRaw, true);
                if (is_array($catalog)) {
                    $lines = is_array($catalog['lines'] ?? null) ? $catalog['lines'] : (array_is_list($catalog) ? $catalog : []);
                }
            } elseif (is_array($catalogRaw)) {
                $lines = is_array($catalogRaw['lines'] ?? null) ? $catalogRaw['lines'] : [];
            }
            // 真单 / 旧种子：catalog 空时回落 OrderItem，避免续付摘要「无商品」。
            if ($lines === []) {
                $lines = $this->loadOrderItemLines($orderUuid);
            }
            $couponCode = '';
            $moneyRaw = $order->getData(OrderModel::schema_fields_MONEY_SNAPSHOT_JSON);
            if (is_string($moneyRaw) && $moneyRaw !== '') {
                $moneyDecoded = json_decode($moneyRaw, true);
                if (is_array($moneyDecoded)) {
                    $couponCode = strtoupper(trim((string)($moneyDecoded['coupon_code'] ?? '')));
                }
            } elseif (is_array($moneyRaw)) {
                $couponCode = strtoupper(trim((string)($moneyRaw['coupon_code'] ?? '')));
            }

            return [
                'order_uuid' => $orderUuid,
                'order_number' => (string)$order->getData(OrderModel::schema_fields_ORDER_NUMBER),
                'status' => (string)$order->getData(OrderModel::schema_fields_STATUS),
                'currency' => (string)$order->getData(OrderModel::schema_fields_CURRENCY),
                'grand_total' => (float)$order->getData(OrderModel::schema_fields_GRAND_TOTAL),
                'subtotal' => (float)$order->getData(OrderModel::schema_fields_SUBTOTAL),
                'shipping_amount' => (float)$order->getData(OrderModel::schema_fields_SHIPPING_AMOUNT),
                'tax_amount' => (float)$order->getData(OrderModel::schema_fields_TAX_AMOUNT),
                'discount_amount' => (float)$order->getData(OrderModel::schema_fields_DISCOUNT_AMOUNT),
                'coupon_code' => $couponCode,
                'shipping_address' => $shipping,
                'address_complete' => $this->isShippingAddressComplete($shipping),
                'shipping_method' => (string)$order->getData(OrderModel::schema_fields_SHIPPING_METHOD),
                'lines' => $lines,
                'customer_id' => (int)$order->getData(OrderModel::schema_fields_CUSTOMER_ID),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadOrderItemLines(string $orderUuid): array
    {
        try {
            /** @var \Weline\Order\Model\OrderItem $item */
            $item = ObjectManager::getInstance(\Weline\Order\Model\OrderItem::class);
            $rows = $item->clear()
                ->where(\Weline\Order\Model\OrderItem::schema_fields_ORDER_UUID, $orderUuid)
                ->select()
                ->fetch()
                ->getItems();
            $lines = [];
            foreach ($rows as $row) {
                if (!is_object($row) || !method_exists($row, 'getData')) {
                    continue;
                }
                $name = trim((string)$row->getData(\Weline\Order\Model\OrderItem::schema_fields_PRODUCT_NAME));
                $sku = trim((string)$row->getData(\Weline\Order\Model\OrderItem::schema_fields_PRODUCT_SKU));
                if ($name === '' && $sku === '') {
                    continue;
                }
                $lines[] = [
                    'name' => $name !== '' ? $name : $sku,
                    'product_name' => $name,
                    'sku' => $sku,
                    'product_id' => (int)$row->getData(\Weline\Order\Model\OrderItem::schema_fields_PRODUCT_ID),
                    'qty' => (float)$row->getData(\Weline\Order\Model\OrderItem::schema_fields_QTY_ORDERED),
                    'quantity' => (float)$row->getData(\Weline\Order\Model\OrderItem::schema_fields_QTY_ORDERED),
                    'unit_price' => (float)$row->getData(\Weline\Order\Model\OrderItem::schema_fields_PRICE),
                    'row_total' => (float)$row->getData(\Weline\Order\Model\OrderItem::schema_fields_ROW_TOTAL),
                ];
            }

            return $lines;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 续付是否可收起地址编辑器：姓名/电话/街道/国家齐全即视为完整（与店面校验对齐）。
     *
     * @param array<string, mixed> $shipping
     */
    private function isShippingAddressComplete(array $shipping): bool
    {
        $name = trim((string)($shipping['name'] ?? $shipping['contact_name'] ?? ''));
        $phone = trim((string)($shipping['phone'] ?? $shipping['contact_phone'] ?? ''));
        $line = trim((string)($shipping['address1'] ?? $shipping['street'] ?? ''));
        $country = trim((string)($shipping['country_code'] ?? $shipping['country'] ?? ''));

        return $name !== '' && $phone !== '' && $line !== '' && $country !== '';
    }

    /**
     * 续付摘要：复用结账 renderItems（缩略图 + 标题 + 规格/色块 + 数量/价），禁止前端手拼。
     *
     * @param array<string, mixed> $orderSnapshot
     * @return array{items:list<array<string,mixed>>,html:string}
     */
    private function resolveOrderItemsChrome(array $orderSnapshot): array
    {
        $currency = trim((string)($orderSnapshot['currency'] ?? 'CNY')) ?: 'CNY';
        $rawLines = is_array($orderSnapshot['lines'] ?? null) ? $orderSnapshot['lines'] : [];
        $items = [];
        foreach ($rawLines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $normalized = $this->normalizeOrderLineForItemsHtml($line);
            if ($normalized === null) {
                continue;
            }
            $items[] = $normalized;
        }
        try {
            /** @var CheckoutHtmlRenderer $html */
            $html = ObjectManager::getInstance(CheckoutHtmlRenderer::class);

            return [
                'items' => $items,
                'html' => $html->renderItems(
                    $items,
                    $currency,
                    (string)__('订单商品信息暂不可用。'),
                ),
            ];
        } catch (\Throwable) {
            return ['items' => $items, 'html' => ''];
        }
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>|null
     */
    private function normalizeOrderLineForItemsHtml(array $line): ?array
    {
        $name = trim((string)($line['name'] ?? $line['product_name'] ?? $line['sku'] ?? ''));
        if ($name === '') {
            return null;
        }
        $qty = (float)($line['qty'] ?? $line['quantity'] ?? 0);
        if ($qty <= 0 && isset($line['qty_minor'])) {
            $minor = (float)$line['qty_minor'];
            $qty = $minor >= 100.0 ? ($minor / 100.0) : $minor;
        }
        if ($qty <= 0) {
            $qty = 1.0;
        }
        $unitMinor = max(0, (int)($line['unit_price_minor'] ?? 0));
        if ($unitMinor <= 0 && isset($line['unit_price'])) {
            $unitMinor = (int)round(((float)$line['unit_price']) * 100);
        }
        if ($unitMinor <= 0 && isset($line['price'])) {
            $unitMinor = (int)round(((float)$line['price']) * 100);
        }
        $rowMinor = max(0, (int)($line['row_total_minor'] ?? 0));
        if ($rowMinor <= 0 && isset($line['row_total'])) {
            $rowMinor = (int)round(((float)$line['row_total']) * 100);
        }
        if ($rowMinor <= 0) {
            $rowMinor = (int)round($unitMinor * $qty);
        }
        $options = is_array($line['options'] ?? null) ? $line['options'] : [];
        $image = trim((string)($line['image_src'] ?? $line['image'] ?? $line['thumbnail'] ?? ''));
        if ($image === '') {
            foreach ($options as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $swatch = trim((string)($option['swatch_image'] ?? ''));
                if ($swatch !== '') {
                    $image = $swatch;
                    break;
                }
            }
        }

        return [
            'name' => $name,
            'product_name' => $name,
            'sku' => trim((string)($line['sku'] ?? '')),
            'product_id' => (int)($line['product_id'] ?? 0),
            'qty' => $qty,
            'quantity' => $qty,
            'price' => $unitMinor / 100,
            'unit_price_minor' => $unitMinor,
            'row_total' => $rowMinor / 100,
            'row_total_minor' => $rowMinor,
            'options' => $options,
            'selection' => is_array($line['selection'] ?? null) ? $line['selection'] : [],
            'image' => $image,
            'image_src' => $image,
            'found' => true,
            'sellable' => true,
        ];
    }

    /**
     * @return array{methods:list<array<string,mixed>>,html:string}
     */
    private function resolvePaymentMethodChrome(string $paymentMethod): array
    {
        $code = strtolower(trim($paymentMethod));
        if ($code === '') {
            return ['methods' => [], 'html' => ''];
        }
        try {
            /** @var CheckoutPaymentMethodsProvider $provider */
            $provider = ObjectManager::getInstance(CheckoutPaymentMethodsProvider::class);
            $method = $provider->findMethod($code);
            $methods = $method !== null ? [$method] : [];
            /** @var CheckoutHtmlRenderer $html */
            $html = ObjectManager::getInstance(CheckoutHtmlRenderer::class);

            return [
                'methods' => $methods,
                'html' => $html->renderPaymentMethodOptions(
                    $methods,
                    'payment_method',
                    '',
                    ['selected_code' => $code],
                ),
            ];
        } catch (\Throwable) {
            return ['methods' => [], 'html' => ''];
        }
    }

    /**
     * @return array{methods:list<array<string,mixed>>,html:string}
     */
    /**
     * 续付配送行必须带订单冻结运费；禁止 amount=0 导致摘要「免运」而 PayPal 仍收订单运费。
     *
     * @return array{methods:list<array<string,mixed>>,html:string}
     */
    private function resolveShippingMethodChrome(
        string $shippingMethod,
        string $currency = 'CNY',
        float $shippingAmount = 0.0,
    ): array {
        $code = trim($shippingMethod);
        if ($code === '') {
            return ['methods' => [], 'html' => ''];
        }
        $label = $code;
        $fee = max(0.0, round($shippingAmount, 2));
        try {
            /** @var \Weline\Shipping\Model\ShippingService $service */
            $service = ObjectManager::getInstance(\Weline\Shipping\Model\ShippingService::class);
            $service->clear()
                ->where(\Weline\Shipping\Model\ShippingService::schema_fields_SERVICE_CODE, $code)
                ->find()
                ->fetch();
            if ((int)$service->getId() > 0) {
                $raw = trim((string)$service->getData(
                    \Weline\Shipping\Model\ShippingService::schema_fields_SERVICE_NAME
                ));
                if ($raw !== '') {
                    $label = (string)__($raw);
                }
            }
        } catch (\Throwable) {
            // keep code
        }
        $methods = [[
            'code' => $code,
            'label' => $label,
            'title' => $label,
            'description' => (string)__((new \Weline\Shipping\Service\ShippingIncotermService())
                ->labelForDutyNoticeCode(\Weline\Shipping\Service\ShippingIncotermService::NOTICE_DDU)),
            'amount' => $fee,
            'fee' => $fee,
            'source' => 'continue_pay_order',
        ]];
        try {
            /** @var CheckoutHtmlRenderer $html */
            $html = ObjectManager::getInstance(CheckoutHtmlRenderer::class);

            return [
                'methods' => $methods,
                'html' => $html->renderMethodOptions(
                    $methods,
                    'shipping_method',
                    $currency !== '' ? $currency : 'CNY',
                    '',
                    true,
                ),
            ];
        } catch (\Throwable) {
            return ['methods' => $methods, 'html' => ''];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readBuckets(): array
    {
        $raw = (string)Cookie::get(self::COOKIE_BUCKETS);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $buckets = is_array($decoded['buckets'] ?? null) ? $decoded['buckets'] : [];
        $out = [];
        foreach ($buckets as $b) {
            if (is_array($b) && trim((string)($b['bucket_id'] ?? '')) !== '') {
                $out[] = $b;
            }
        }

        return $out;
    }

    private function readActiveBucketId(): string
    {
        $raw = (string)Cookie::get(self::COOKIE_BUCKETS);
        if ($raw === '') {
            return '';
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? trim((string)($decoded['active_bucket_id'] ?? '')) : '';
    }

    /**
     * @param list<array<string, mixed>> $buckets
     */
    private function writeBuckets(array $buckets, string $activeBucketId): void
    {
        $payload = json_encode([
            'buckets' => array_values($buckets),
            'active_bucket_id' => $activeBucketId,
        ], JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            return;
        }
        Cookie::set(self::COOKIE_BUCKETS, $payload, 86400 * 7);
    }
}
