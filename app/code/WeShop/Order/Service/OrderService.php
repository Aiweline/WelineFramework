<?php

declare(strict_types=1);

namespace WeShop\Order\Service;

use WeShop\Order\Model\Order;
use WeShop\Order\Model\OrderItem;
use WeShop\Product\Model\Product;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class OrderService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PAID = 'paid';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    public const PAYMENT_STATUS_PENDING = 'pending';
    public const PAYMENT_STATUS_PAID = 'paid';
    public const PAYMENT_STATUS_FAILED = 'failed';
    public const PAYMENT_STATUS_PARTIAL = 'partial';
    public const PAYMENT_STATUS_REFUNDED = 'refunded';

    public const FULFILLMENT_STATUS_PENDING = 'pending';
    public const FULFILLMENT_STATUS_SHIPPED = 'shipped';
    public const FULFILLMENT_STATUS_DELIVERED = 'delivered';

    public function __construct(
        private readonly ?Order $orderModel = null,
        private readonly ?OrderItem $orderItemModel = null
    ) {
    }

    public function getAvailableStatuses(): array
    {
        return [
            self::STATUS_PENDING => (string) __('待处理'),
            self::STATUS_PROCESSING => (string) __('处理中'),
            self::STATUS_PAID => (string) __('已付款'),
            self::STATUS_FULFILLED => (string) __('已发货'),
            self::STATUS_COMPLETED => (string) __('已完成'),
            self::STATUS_CANCELLED => (string) __('已取消'),
            self::STATUS_REFUNDED => (string) __('已退款'),
        ];
    }

    public function isValidStatus(string $status): bool
    {
        return isset($this->getAvailableStatuses()[$status]);
    }

    public function createOrder(array $orderData): Order
    {
        $order = $this->newOrderModel();
        $incrementId = $this->generateOrderNumber();

        $order->clearData()
            ->setData(Order::schema_fields_increment_id, $incrementId)
            ->setData(Order::schema_fields_customer_id, (int) ($orderData['customer_id'] ?? 0))
            ->setData(Order::schema_fields_status, (string) ($orderData['status'] ?? self::STATUS_PENDING))
            ->setData(Order::schema_fields_subtotal, (float) ($orderData['subtotal'] ?? 0))
            ->setData(Order::schema_fields_shipping_amount, (float) ($orderData['shipping_amount'] ?? 0))
            ->setData(Order::schema_fields_discount_amount, (float) ($orderData['discount_amount'] ?? 0))
            ->setData(Order::schema_fields_tax_amount, (float) ($orderData['tax_amount'] ?? 0))
            ->setData(Order::schema_fields_total, (float) ($orderData['total'] ?? 0))
            ->setData(Order::schema_fields_payment_status, (string) ($orderData['payment_status'] ?? self::PAYMENT_STATUS_PENDING))
            ->setData(Order::schema_fields_fulfillment_status, (string) ($orderData['fulfillment_status'] ?? self::FULFILLMENT_STATUS_PENDING))
            ->setData(Order::schema_fields_shipping_method, (string) ($orderData['shipping_method'] ?? ''))
            ->setData(Order::schema_fields_payment_method, (string) ($orderData['payment_method'] ?? ''))
            ->setData(Order::schema_fields_shipping_address, $this->encodeAddress($orderData['shipping_address'] ?? []))
            ->setData(Order::schema_fields_created_at, date('Y-m-d H:i:s'))
            ->setData(Order::schema_fields_updated_at, date('Y-m-d H:i:s'))
            ->save();

        $items = $orderData['items'] ?? $orderData['order_items'] ?? [];
        if (\is_array($items) && $items !== [] && (int)$order->getId() > 0) {
            $this->addOrderItems((int)$order->getId(), $items);
        }

        return $order;
    }

    /**
     * Persist immutable product snapshots for an order.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public function addOrderItems(int $orderId, array $items): array
    {
        if ($orderId <= 0 || $items === []) {
            return [];
        }

        $savedItems = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $item = $this->hydrateOrderItemSnapshot($item);
            $quantity = max(1, (int)($item['quantity'] ?? $item['qty'] ?? 1));
            $price = (float)($item['price'] ?? 0);
            $total = array_key_exists('total', $item)
                ? (float)$item['total']
                : round($price * $quantity, 2);
            $productName = trim((string)($item['product_name'] ?? $item['name'] ?? ''));
            $productSku = (string)($item['product_sku'] ?? $item['sku'] ?? '');
            $productImage = trim((string)($item['product_image'] ?? $item['image'] ?? ''));

            $orderItem = $this->newOrderItemModel();
            $orderItem->clearData()
                ->setData(OrderItem::schema_fields_ORDER_ID, $orderId)
                ->setData(OrderItem::schema_fields_PRODUCT_ID, (int)($item['product_id'] ?? 0))
                ->setData(OrderItem::schema_fields_PRODUCT_NAME, $productName)
                ->setData(OrderItem::schema_fields_PRODUCT_SKU, $productSku)
                ->setData(OrderItem::schema_fields_PRODUCT_IMAGE, $productImage)
                ->setData(OrderItem::schema_fields_QUANTITY, $quantity)
                ->setData(OrderItem::schema_fields_PRICE, $price)
                ->setData(OrderItem::schema_fields_TOTAL, $total)
                ->setData(OrderItem::schema_fields_CREATED_AT, date('Y-m-d H:i:s'))
                ->save();

            if ($orderItem->getId()) {
                $savedItems[] = [
                    'item_id' => (int)$orderItem->getId(),
                    'order_id' => $orderId,
                    'product_id' => (int)($item['product_id'] ?? 0),
                    'product_name' => $productName,
                    'product_sku' => $productSku,
                    'product_image' => $productImage,
                    'quantity' => $quantity,
                    'price' => $price,
                    'total' => $total,
                ];
            }
        }

        return $savedItems;
    }

    private function resolveProductImageBySku(string $sku): string
    {
        return (string)($this->loadProductSnapshot(0, $sku)['image'] ?? '');
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    protected function hydrateOrderItemSnapshot(array $item): array
    {
        $productId = (int)($item['product_id'] ?? 0);
        $productSku = trim((string)($item['product_sku'] ?? $item['sku'] ?? ''));
        $productSnapshot = $this->loadProductSnapshot($productId, $productSku);

        $productName = trim((string)($item['product_name'] ?? $item['name'] ?? ''));
        if ($productName === '') {
            $productName = (string)($productSnapshot['name'] ?? '');
        }
        if ($productName === '') {
            $productName = $productSku !== ''
                ? $productSku
                : (string)__('商品 #%{1}', [$productId > 0 ? $productId : 0]);
        }

        $productImage = trim((string)($item['product_image'] ?? $item['image'] ?? ''));
        if ($productImage === '') {
            $productImage = (string)($productSnapshot['image'] ?? '');
        }

        if ($productSku === '') {
            $productSku = (string)($productSnapshot['sku'] ?? '');
        }

        if (!array_key_exists('price', $item) || (float)$item['price'] <= 0) {
            $item['price'] = (float)($productSnapshot['price'] ?? $item['price'] ?? 0);
        }

        $item['product_name'] = $productName;
        $item['name'] = $productName;
        $item['product_sku'] = $productSku;
        $item['sku'] = $productSku;
        $item['product_image'] = $productImage;
        $item['image'] = $productImage;

        return $item;
    }

    /**
     * @return array{name?: string, sku?: string, image?: string, price?: float}
     */
    protected function loadProductSnapshot(int $productId, string $sku = ''): array
    {
        $product = ObjectManager::getInstance(Product::class);
        $matched = null;

        if ($productId > 0) {
            $product->load($productId);
            if ($product->getId()) {
                $matched = $product;
            }
        }

        if (!$matched && $sku !== '') {
            $result = $product->clear()
                ->where(Product::schema_fields_sku, $sku)
                ->select()
                ->fetch();
            $matched = $result->getItems()[0] ?? null;
        }

        if (!$matched) {
            return [];
        }

        return [
            'name' => trim((string)$matched->getData(Product::schema_fields_name)),
            'sku' => trim((string)$matched->getData(Product::schema_fields_sku)),
            'image' => trim((string)$matched->getData(Product::schema_fields_image)),
            'price' => (float)($matched->getData(Product::schema_fields_price) ?? 0),
        ];
    }

    public function createShipment(int $orderId, string $carrier, string $trackingNumber): Order
    {
        $order = $this->requireOrder($orderId);
        $status = (string) ($order->getData(Order::schema_fields_status) ?? self::STATUS_PENDING);
        $paymentStatus = $order->hasField(Order::schema_fields_payment_status)
            ? (string) $order->getData(Order::schema_fields_payment_status)
            : self::PAYMENT_STATUS_PENDING;

        if ($status === self::STATUS_CANCELLED || $status === self::STATUS_REFUNDED) {
            throw new \InvalidArgumentException((string) __('已取消或已退款的订单无法发货。'));
        }

        if ($paymentStatus !== self::PAYMENT_STATUS_PAID && $status !== self::STATUS_PAID) {
            throw new \InvalidArgumentException((string) __('仅已付款订单可发货。'));
        }

        $now = date('Y-m-d H:i:s');
        $order->setData(Order::schema_fields_status, self::STATUS_FULFILLED)
            ->setData(Order::schema_fields_fulfillment_status, self::FULFILLMENT_STATUS_SHIPPED)
            ->setData(Order::schema_fields_fulfillment_carrier, trim($carrier))
            ->setData(Order::schema_fields_fulfillment_tracking_number, trim($trackingNumber))
            ->setData(Order::schema_fields_shipped_at, $now)
            ->setData(Order::schema_fields_updated_at, $now)
            ->save();

        $eventData = [
            'order' => $order,
            'order_id' => $orderId,
            'carrier' => trim($carrier),
            'tracking_number' => trim($trackingNumber),
        ];
        ObjectManager::getInstance(EventsManager::class)->dispatch('WeShop_Order::order_shipped', $eventData);

        return $order;
    }

    public function markDelivered(int $orderId): Order
    {
        $order = $this->requireOrder($orderId);
        $fulfillmentStatus = (string) ($order->getData(Order::schema_fields_fulfillment_status) ?? self::FULFILLMENT_STATUS_PENDING);
        if ($fulfillmentStatus !== self::FULFILLMENT_STATUS_SHIPPED) {
            throw new \InvalidArgumentException((string) __('仅已发货订单可标记为已送达。'));
        }

        $now = date('Y-m-d H:i:s');
        $order->setData(Order::schema_fields_status, self::STATUS_COMPLETED)
            ->setData(Order::schema_fields_fulfillment_status, self::FULFILLMENT_STATUS_DELIVERED)
            ->setData(Order::schema_fields_delivered_at, $now)
            ->setData(Order::schema_fields_updated_at, $now)
            ->save();

        $eventData = [
            'order' => $order,
            'order_id' => $orderId,
        ];
        ObjectManager::getInstance(EventsManager::class)->dispatch('WeShop_Order::order_completed', $eventData);

        return $order;
    }

    public function getOrder(int $orderId): ?Order
    {
        $order = $this->newOrderModel();
        $order->load($orderId);

        return $order->getId() ? $order : null;
    }

    public function getOrderByIncrementId(string $incrementId): ?Order
    {
        $order = $this->newOrderModel();
        $result = $order->clear()
            ->where(Order::schema_fields_increment_id, $incrementId)
            ->select()
            ->fetch();

        $matched = $result->getItems()[0] ?? null;

        return $matched instanceof Order && $matched->getId() ? $matched : null;
    }

    public function getCustomerOrders(int $customerId, int $page = 1, int $pageSize = 20, array $filters = []): array
    {
        $order = $this->newOrderModel();
        $order->clear()
            ->where(Order::schema_fields_customer_id, $customerId);

        if (!empty($filters['status'])) {
            $order->where(Order::schema_fields_status, (string) $filters['status']);
        }

        if (!empty($filters['payment_status']) && $order->hasField('payment_status')) {
            $order->where('payment_status', (string) $filters['payment_status']);
        }

        $total = $this->countCustomerOrders($customerId, $filters);

        $order->order(Order::schema_fields_created_at, 'DESC')
            ->pagination($page, $pageSize);

        $items = $order->select()->fetchArray();
        $totalPages = max(1, $pageSize > 0 ? (int)ceil($total / $pageSize) : 1);
        $pagination = [
            'page' => max(1, $page),
            'page_size' => max(1, $pageSize),
            'total' => $total,
            'total_pages' => $totalPages,
            'has_previous' => $page > 1,
            'has_next' => $page < $totalPages,
        ];

        return [
            'items' => $items,
            'total' => $total,
            'pagination' => $pagination,
        ];
    }

    protected function countCustomerOrders(int $customerId, array $filters = []): int
    {
        $order = $this->newOrderModel();
        $order->clear()
            ->where(Order::schema_fields_customer_id, $customerId);

        if (!empty($filters['status'])) {
            $order->where(Order::schema_fields_status, (string) $filters['status']);
        }

        if (!empty($filters['payment_status']) && $order->hasField('payment_status')) {
            $order->where('payment_status', (string) $filters['payment_status']);
        }

        return (int)$order->count();
    }

    public function getOrders(int $page = 1, int $pageSize = 20, array $filters = []): array
    {
        $order = $this->newOrderModel();
        $order->clear();

        if (!empty($filters['status'])) {
            $order->where(Order::schema_fields_status, (string) $filters['status']);
        }

        if (!empty($filters['increment_id'])) {
            $order->where(Order::schema_fields_increment_id, '%' . $filters['increment_id'] . '%', 'LIKE');
        }

        if (!empty($filters['customer_id'])) {
            $order->where(Order::schema_fields_customer_id, (int) $filters['customer_id']);
        }

        $order->order(Order::schema_fields_created_at, 'DESC')
            ->pagination($page, $pageSize);

        $items = $order->select()->fetchArray();

        return [
            'items' => $items,
            'total' => $order->getTotalCount(),
            'pagination' => $order->getPagination(),
        ];
    }

    public function getOrderSummary(): array
    {
        $order = $this->newOrderModel();

        $summary = [
            'total' => $order->clear()->count(),
            self::STATUS_PENDING => 0,
            self::STATUS_PROCESSING => 0,
            self::STATUS_COMPLETED => 0,
            self::STATUS_CANCELLED => 0,
        ];

        foreach ([self::STATUS_PENDING, self::STATUS_PROCESSING, self::STATUS_COMPLETED, self::STATUS_CANCELLED] as $status) {
            $summary[$status] = $order->clear()
                ->where(Order::schema_fields_status, $status)
                ->count();
        }

        return $summary;
    }

    public function getUnpaidOrderCount(int $customerId): int
    {
        $order = $this->newOrderModel();
        $order->clear()
            ->where(Order::schema_fields_customer_id, $customerId)
            ->where(Order::schema_fields_status, [self::STATUS_PENDING, self::STATUS_PROCESSING], 'IN');

        if ($order->hasField('payment_status')) {
            $order->where('payment_status', [self::PAYMENT_STATUS_PENDING, self::PAYMENT_STATUS_FAILED], 'IN');
        }

        return max(0, (int) ($order->select()->getTotalCount() ?? 0));
    }

    public function getUnpaidOrders(int $customerId): array
    {
        $order = $this->newOrderModel();
        $order->clear()
            ->where(Order::schema_fields_customer_id, $customerId)
            ->where(Order::schema_fields_status, [self::STATUS_PENDING, self::STATUS_PROCESSING], 'IN')
            ->order(Order::schema_fields_created_at, 'DESC');

        if ($order->hasField('payment_status')) {
            $order->where('payment_status', [self::PAYMENT_STATUS_PENDING, self::PAYMENT_STATUS_FAILED], 'IN');
        }

        return $order->select()->fetchArray();
    }

    public function updateOrderStatus(int $orderId, string $status): Order
    {
        if (!$this->isValidStatus($status)) {
            throw new \InvalidArgumentException((string) __('不支持的订单状态。'));
        }

        $order = $this->requireOrder($orderId);
        $currentStatus = (string) ($order->getData(Order::schema_fields_status) ?? self::STATUS_PENDING);
        if (!$this->canTransitionOrderStatus($currentStatus, $status)) {
            throw new \InvalidArgumentException((string) __('不允许的订单状态变更：%{1} → %{2}', [$currentStatus, $status]));
        }

        $order->setData(Order::schema_fields_status, $status)
            ->setData(Order::schema_fields_updated_at, date('Y-m-d H:i:s'))
            ->save();

        return $order;
    }

    public function updatePaymentStatus(int $orderId, string $paymentStatus): Order
    {
        if (!$this->isValidPaymentStatus($paymentStatus)) {
            throw new \InvalidArgumentException((string) __('不支持的支付状态。'));
        }

        $order = $this->requireOrder($orderId);
        $currentStatus = (string) ($order->getData(Order::schema_fields_status) ?? self::STATUS_PENDING);

        if ($order->hasField('payment_status')) {
            $order->setData('payment_status', $paymentStatus);
        }

        $targetStatus = $this->deriveOrderStatusFromPaymentStatus($currentStatus, $paymentStatus);
        if ($targetStatus !== null && $this->canTransitionOrderStatus($currentStatus, $targetStatus)) {
            $order->setData(Order::schema_fields_status, $targetStatus);
        }

        $order->setData(Order::schema_fields_updated_at, date('Y-m-d H:i:s'))
            ->save();

        return $order;
    }

    public function canCancelOrder(int $orderId, int $customerId): array
    {
        $order = $this->newOrderModel();
        $order->load($orderId);

        if (!$order->getId()) {
            return ['can_cancel' => false, 'reason' => __('订单不存在。'), 'require_return' => false];
        }

        if ((int) ($order->getData(Order::schema_fields_customer_id) ?? 0) !== $customerId) {
            return ['can_cancel' => false, 'reason' => __('您无法取消该订单。'), 'require_return' => false];
        }

        $status = (string) ($order->getData(Order::schema_fields_status) ?? self::STATUS_PENDING);
        $paymentStatus = $order->hasField('payment_status') ? (string) $order->getData('payment_status') : '';
        $fulfillmentStatus = $order->hasField('fulfillment_status') ? (string) $order->getData('fulfillment_status') : '';

        if ($status === self::STATUS_CANCELLED) {
            return ['can_cancel' => false, 'reason' => __('订单已取消。'), 'require_return' => false];
        }

        if ($status === self::STATUS_COMPLETED) {
            return ['can_cancel' => false, 'reason' => __('已完成订单无法取消，请申请退换货。'), 'require_return' => false];
        }

        if ($status === self::STATUS_REFUNDED) {
            return ['can_cancel' => false, 'reason' => __('订单已退款。'), 'require_return' => false];
        }

        if ($status === self::STATUS_FULFILLED || in_array($fulfillmentStatus, ['shipped', 'delivered'], true)) {
            if (!$this->checkOrderReturn($orderId)) {
                return [
                    'can_cancel' => false,
                    'reason' => __('订单已发货，取消前请先提交退换货申请。'),
                    'require_return' => true,
                ];
            }

            if (!$this->checkReturnConfirmed($orderId)) {
                return [
                    'can_cancel' => false,
                    'reason' => __('退换货待确认，收到退货后方可取消订单。'),
                    'require_return' => true,
                ];
            }
        }

        if ($status === self::STATUS_PAID || $paymentStatus === self::PAYMENT_STATUS_PAID) {
            if ($status !== self::STATUS_FULFILLED && !in_array($fulfillmentStatus, ['shipped', 'delivered'], true)) {
                return [
                    'can_cancel' => true,
                    'reason' => null,
                    'require_return' => false,
                    'require_refund' => true,
                ];
            }
        }

        if (in_array($status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true)) {
            return [
                'can_cancel' => true,
                'reason' => null,
                'require_return' => false,
                'require_refund' => $paymentStatus === self::PAYMENT_STATUS_PAID,
            ];
        }

        return ['can_cancel' => false, 'reason' => __('当前订单状态不允许取消。'), 'require_return' => false];
    }

    public function cancelOrder(int $orderId, int $customerId): bool
    {
        $checkResult = $this->canCancelOrder($orderId, $customerId);
        if (!$checkResult['can_cancel']) {
            throw new \Exception((string) ($checkResult['reason'] ?? __('该订单无法取消。')));
        }

        $order = $this->requireOrder($orderId);
        $paymentStatus = $order->hasField('payment_status') ? (string) $order->getData('payment_status') : '';

        $order->setData(Order::schema_fields_status, self::STATUS_CANCELLED)
            ->setData(Order::schema_fields_updated_at, date('Y-m-d H:i:s'));

        if ($order->hasField('payment_status') && $paymentStatus === self::PAYMENT_STATUS_PAID) {
            $order->setData('payment_status', self::PAYMENT_STATUS_REFUNDED);
        }

        $order->save();

        $eventData = [
            'order' => $order,
            'order_id' => $orderId,
            'customer_id' => $customerId,
        ];
        ObjectManager::getInstance(EventsManager::class)->dispatch('WeShop_Order::cancelled', $eventData);

        return true;
    }

    public function canRetryPayment(int $orderId, int $customerId): bool
    {
        $order = $this->newOrderModel();
        $order->load($orderId);

        if (!$order->getId()) {
            return false;
        }

        if ((int) ($order->getData(Order::schema_fields_customer_id) ?? 0) !== $customerId) {
            return false;
        }

        $status = (string) ($order->getData(Order::schema_fields_status) ?? self::STATUS_PENDING);
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true)) {
            return false;
        }

        if ($order->hasField('payment_status')) {
            $paymentStatus = (string) $order->getData('payment_status');
            if (!in_array($paymentStatus, [self::PAYMENT_STATUS_PENDING, self::PAYMENT_STATUS_FAILED], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRetryPaymentContext(int $orderId, int $customerId): ?array
    {
        if (!$this->canRetryPayment($orderId, $customerId)) {
            return null;
        }

        $order = $this->getOrder($orderId);
        if (!$order || !$order->getId()) {
            return null;
        }

        $items = $this->getOrderItems($orderId);
        if ($items === []) {
            return null;
        }

        return [
            'order' => $order,
            'order_id' => (int) $order->getId(),
            'increment_id' => (string) ($order->getData(Order::schema_fields_increment_id) ?? ''),
            'items' => $this->mapOrderItemsForCheckout($items),
            'summary' => $this->buildOrderSummary($order, $items),
        ];
    }

    public function getOrderItems(int $orderId): array
    {
        $orderItem = $this->newOrderItemModel();
        $orderItem->clear()
            ->where('order_id', $orderId)
            ->order('item_id', 'ASC');

        $items = $orderItem->select()->fetchArray();
        if ($items === []) {
            return [];
        }

        foreach ($items as $index => $item) {
            if (!\is_array($item)) {
                continue;
            }

            $items[$index] = $this->hydratePersistedOrderItem($item);
        }

        return $items;
    }

    protected function checkOrderReturn(int $orderId): bool
    {
        $order = $this->newOrderModel();
        $order->load($orderId);

        if ($order->hasField('return_status')) {
            $returnStatus = (string) $order->getData('return_status');
            return $returnStatus !== '' && $returnStatus !== 'none';
        }

        return false;
    }

    protected function checkReturnConfirmed(int $orderId): bool
    {
        $order = $this->newOrderModel();
        $order->load($orderId);

        if ($order->hasField('return_status')) {
            $returnStatus = (string) $order->getData('return_status');
            return in_array($returnStatus, ['confirmed', 'received'], true);
        }

        return false;
    }

    protected function requireOrder(int $orderId): Order
    {
        $order = $this->newOrderModel();
        $order->load($orderId);
        if (!$order->getId()) {
            throw new \Exception((string) __('订单不存在。'));
        }

        return $order;
    }

    protected function newOrderModel(): Order
    {
        return $this->orderModel ? clone $this->orderModel : ObjectManager::getInstance(Order::class);
    }

    protected function newOrderItemModel(): OrderItem
    {
        return $this->orderItemModel ? clone $this->orderItemModel : ObjectManager::getInstance(OrderItem::class);
    }

    protected function isValidPaymentStatus(string $paymentStatus): bool
    {
        return in_array($paymentStatus, [
            self::PAYMENT_STATUS_PENDING,
            self::PAYMENT_STATUS_PAID,
            self::PAYMENT_STATUS_FAILED,
            self::PAYMENT_STATUS_PARTIAL,
            self::PAYMENT_STATUS_REFUNDED,
        ], true);
    }

    protected function canTransitionOrderStatus(string $currentStatus, string $targetStatus): bool
    {
        if ($currentStatus === $targetStatus) {
            return true;
        }

        $allowedTransitions = [
            self::STATUS_PENDING => [self::STATUS_PROCESSING, self::STATUS_PAID, self::STATUS_CANCELLED],
            self::STATUS_PROCESSING => [self::STATUS_PAID, self::STATUS_FULFILLED, self::STATUS_CANCELLED],
            self::STATUS_PAID => [self::STATUS_FULFILLED, self::STATUS_COMPLETED, self::STATUS_REFUNDED, self::STATUS_CANCELLED],
            self::STATUS_FULFILLED => [self::STATUS_COMPLETED, self::STATUS_REFUNDED],
            self::STATUS_COMPLETED => [self::STATUS_REFUNDED],
            self::STATUS_CANCELLED => [self::STATUS_REFUNDED],
            self::STATUS_REFUNDED => [],
        ];

        return in_array($targetStatus, $allowedTransitions[$currentStatus] ?? [], true);
    }

    protected function deriveOrderStatusFromPaymentStatus(string $currentStatus, string $paymentStatus): ?string
    {
        return match ($paymentStatus) {
            self::PAYMENT_STATUS_PAID => self::STATUS_PAID,
            self::PAYMENT_STATUS_REFUNDED => self::STATUS_REFUNDED,
            self::PAYMENT_STATUS_FAILED => in_array($currentStatus, [self::STATUS_PENDING, self::STATUS_PROCESSING], true)
                ? self::STATUS_PENDING
                : null,
            default => null,
        };
    }

    protected function encodeAddress(mixed $address): string
    {
        if (!is_array($address) || $address === []) {
            return '';
        }

        $encoded = json_encode($address, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, float>
     */
    protected function buildOrderSummary(Order $order, array $items): array
    {
        $subtotal = (float) ($order->getData(Order::schema_fields_subtotal) ?? 0);
        $shipping = (float) ($order->getData(Order::schema_fields_shipping_amount) ?? 0);
        $discount = (float) ($order->getData(Order::schema_fields_discount_amount) ?? 0);
        $tax = (float) ($order->getData(Order::schema_fields_tax_amount) ?? 0);

        $fallbackSubtotal = 0.0;
        foreach ($items as $item) {
            $fallbackSubtotal += (float) ($item['total'] ?? 0);
        }

        if ($subtotal <= 0 && $fallbackSubtotal > 0) {
            $subtotal = $fallbackSubtotal;
        }

        $grandTotal = (float) ($order->getData(Order::schema_fields_total) ?? 0);
        if ($grandTotal <= 0 && $subtotal > 0) {
            $grandTotal = max(0.0, round($subtotal + $shipping + $tax - $discount, 2));
        }

        return [
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'discount' => $discount,
            'tax' => $tax,
            'grand_total' => $grandTotal,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    protected function mapOrderItemsForCheckout(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $item = \is_array($item) ? $this->hydratePersistedOrderItem($item) : [];
            $qty = (int) ($item['quantity'] ?? 0);
            $price = (float) ($item['price'] ?? 0);
            $rowTotal = (float) ($item['total'] ?? ($price * $qty));
            $result[] = [
                'item_id' => (int) ($item['item_id'] ?? 0),
                'product_id' => (int) ($item['product_id'] ?? 0),
                'name' => (string) ($item['product_name'] ?? ''),
                'image' => (string) ($item['product_image'] ?? $item['image'] ?? ''),
                'price' => $price,
                'qty' => $qty,
                'row_total' => $rowTotal,
                'product_name' => (string) ($item['product_name'] ?? ''),
                'product_sku' => (string) ($item['product_sku'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    protected function hydratePersistedOrderItem(array $item): array
    {
        $hydrated = $this->hydrateOrderItemSnapshot($item);
        $quantity = max(1, (int)($hydrated[OrderItem::schema_fields_QUANTITY] ?? $hydrated['quantity'] ?? 1));
        $price = (float)($hydrated[OrderItem::schema_fields_PRICE] ?? $hydrated['price'] ?? 0);
        $total = array_key_exists(OrderItem::schema_fields_TOTAL, $hydrated)
            ? (float)$hydrated[OrderItem::schema_fields_TOTAL]
            : (float)($hydrated['total'] ?? round($price * $quantity, 2));

        $hydrated[OrderItem::schema_fields_PRODUCT_NAME] = $hydrated['product_name'] ?? $hydrated['name'] ?? '';
        $hydrated[OrderItem::schema_fields_PRODUCT_SKU] = $hydrated['product_sku'] ?? $hydrated['sku'] ?? '';
        $hydrated[OrderItem::schema_fields_PRODUCT_IMAGE] = $hydrated['product_image'] ?? $hydrated['image'] ?? '';
        $hydrated[OrderItem::schema_fields_QUANTITY] = $quantity;
        $hydrated[OrderItem::schema_fields_PRICE] = $price;
        $hydrated[OrderItem::schema_fields_TOTAL] = $total;
        $hydrated['quantity'] = $quantity;
        $hydrated['price'] = $price;
        $hydrated['total'] = $total;

        return $hydrated;
    }

    protected function generateOrderNumber(): string
    {
        return date('YmdHis') . str_pad((string) rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }
}
