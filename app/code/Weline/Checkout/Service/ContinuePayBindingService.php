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

        return [
            'success' => true,
            'active_bucket_id' => $bucketId,
            'bucket' => $continueBucket,
            'buckets' => $buckets,
            'switcher_visible' => count($buckets) >= 2,
            'order' => $orderSnapshot,
            'address_complete' => $addressComplete,
            'payment_methods' => $paymentChrome['methods'],
            'payment_methods_html' => $paymentChrome['html'],
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

            return [
                'order_uuid' => $orderUuid,
                'status' => (string)$order->getData(OrderModel::schema_fields_STATUS),
                'currency' => (string)$order->getData(OrderModel::schema_fields_CURRENCY),
                'grand_total' => (float)$order->getData(OrderModel::schema_fields_GRAND_TOTAL),
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
