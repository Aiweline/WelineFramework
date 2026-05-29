<?php

declare(strict_types=1);

namespace WeShop\Order\Service;

use WeShop\Order\Model\Order;
use WeShop\Order\Model\OrderItem;
use Weline\Currency\Helper\CurrencyFormatter;
use Weline\Framework\Http\Url;

class AccountOrdersListApiService
{
    private const SUMMARY_ITEM_LIMIT = 3;
    private const MAX_LABEL_LENGTH = 160;

    public function __construct(
        private readonly AccountOrdersListContextService $accountOrdersListContextService,
        private readonly ?Url $url = null
    ) {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function buildPayload(int $customerId, array $params = []): array
    {
        if ($customerId <= 0) {
            return [
                'success' => false,
                'message' => (string) __('请先登录'),
                'redirect_url' => $this->getUrl()->getUrl('customer/account/login'),
            ];
        }

        $context = $this->accountOrdersListContextService->build($customerId, $params);
        /** @var OrderService $orderService */
        $orderService = $context['orderService'];
        /** @var array<string, string> $statusText */
        $statusText = $context['statusText'];
        /** @var array<int, array<string, mixed>> $orders */
        $orders = $context['orders'];
        $activeOrderStatus = (string) ($context['activeOrderStatus'] ?? '');
        $currentPage = (int) ($context['currentPage'] ?? 1);
        $pageSize = (int) ($context['pageSize'] ?? 10);
        $totalPages = (int) ($context['totalPages'] ?? 1);
        /** @var callable $buildReturnsUrl */
        $buildReturnsUrl = $context['buildReturnsUrl'];

        $cards = [];
        foreach ($orders as $orderIndex => $order) {
            $cards[] = $this->buildOrderCard(
                $order,
                (int) $orderIndex,
                $customerId,
                $orderService,
                $statusText,
                $currentPage,
                $pageSize,
                $activeOrderStatus,
                $buildReturnsUrl
            );
        }

        $unpaidCount = count($orderService->getUnpaidOrders($customerId));

        return [
            'success' => true,
            'message' => (string) __('订单已加载'),
            'active_order_status' => $activeOrderStatus,
            'current_page' => $currentPage,
            'total_pages' => $totalPages,
            'page_size' => $pageSize,
            'is_empty' => $cards === [],
            'is_filtered_empty' => $activeOrderStatus !== '' && $cards === [],
            'unpaid_count' => $unpaidCount,
            'shop_url' => $this->getUrl()->getUrl('weshop'),
            'orders' => $cards,
            'labels' => $this->buildLabels(),
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @param callable $buildReturnsUrl
     *
     * @return array<string, mixed>
     */
    private function buildOrderCard(
        array $order,
        int $orderIndex,
        int $customerId,
        OrderService $orderService,
        array $statusText,
        int $currentPage,
        int $pageSize,
        string $activeOrderStatus,
        callable $buildReturnsUrl
    ): array {
        $orderId = (int) ($order['order_id'] ?? $order[Order::schema_fields_ID] ?? 0);
        $incrementId = (string) ($order['increment_id'] ?? $order[Order::schema_fields_increment_id] ?? $orderId);
        $orderCardId = 'order-card-' . ($orderId > 0 ? $orderId : ($orderIndex + 1));
        $status = (string) ($order['status'] ?? $order[Order::schema_fields_status] ?? OrderService::STATUS_PENDING);
        $paymentStatus = (string) ($order['payment_status'] ?? $order[Order::schema_fields_payment_status] ?? OrderService::PAYMENT_STATUS_PENDING);
        $total = (float) ($order['total'] ?? $order[Order::schema_fields_total] ?? 0);
        $createdAt = (string) ($order['created_at'] ?? $order[Order::schema_fields_created_at] ?? '');
        $paymentMethod = $this->truncateLabel((string) ($order['payment_method'] ?? $order[Order::schema_fields_payment_method] ?? ''));
        $shippingMethod = $this->truncateLabel((string) ($order['shipping_method'] ?? $order[Order::schema_fields_shipping_method] ?? ''));
        $fulfillmentStatus = $this->truncateLabel((string) ($order['fulfillment_status'] ?? $order[Order::schema_fields_fulfillment_status] ?? ''));
        $statusLabel = $statusText[$status] ?? $status;
        $isUnpaid = \in_array($paymentStatus, [OrderService::PAYMENT_STATUS_PENDING, OrderService::PAYMENT_STATUS_FAILED], true);
        $canRetryPayment = $orderId > 0 && $orderService->canRetryPayment($orderId, $customerId);
        $cancelCheck = $orderId > 0 ? $orderService->canCancelOrder($orderId, $customerId) : ['can_cancel' => false];
        $canCancel = !empty($cancelCheck['can_cancel']);
        $cancelReason = $this->truncateLabel((string) ($cancelCheck['reason'] ?? ''));
        $requireReturn = !empty($cancelCheck['require_return']);
        $requireRefund = !empty($cancelCheck['require_refund']);
        $orderItems = $orderId > 0 ? $orderService->getOrderItems($orderId) : [];
        $visibleOrderItems = \array_slice($orderItems, 0, self::SUMMARY_ITEM_LIMIT);
        $remainingItemCount = max(0, \count($orderItems) - \count($visibleOrderItems));

        $returnUrl = '/customer/account/index#orders';
        if ($activeOrderStatus !== '') {
            $returnUrl = '/customer/account/index?' . http_build_query([
                'order_status' => $activeOrderStatus,
                'order_page' => max(1, $currentPage),
                'order_page_size' => $pageSize,
            ]) . '#orders';
        } elseif ($currentPage > 1) {
            $returnUrl = '/customer/account/index?' . http_build_query([
                'order_page' => max(1, $currentPage),
                'order_page_size' => $pageSize,
            ]) . '#orders';
        }

        return [
            'order_id' => $orderId,
            'card_id' => $orderCardId,
            'increment_id' => $incrementId,
            'status' => $status,
            'status_label' => $statusLabel,
            'payment_status' => $paymentStatus,
            'is_unpaid' => $isUnpaid,
            'total_formatted' => CurrencyFormatter::formatConverted($total),
            'created_label' => $createdAt !== '' ? date('Y-m-d H:i', strtotime($createdAt)) : '-',
            'payment_method' => $paymentMethod !== '' ? $paymentMethod : '-',
            'shipping_method' => $shippingMethod !== '' ? $shippingMethod : '-',
            'fulfillment_status' => $fulfillmentStatus !== '' ? $fulfillmentStatus : '-',
            'can_retry_payment' => $canRetryPayment,
            'checkout_url' => $canRetryPayment ? $this->getUrl()->getUrl('checkout', ['order_id' => $orderId]) : '',
            'can_cancel' => $canCancel,
            'cancel_confirm_message' => $requireRefund
                ? (string) __('确定要取消此订单吗？取消后将自动退款。')
                : (string) __('确定要取消此订单吗？'),
            'require_return' => $requireReturn,
            'return_link' => $buildReturnsUrl($orderId, $incrementId, $orderCardId, $currentPage, $pageSize),
            'return_url' => $returnUrl,
            'cancel_reason' => $cancelReason,
            'summary_items' => $this->buildItemRows($visibleOrderItems),
            'remaining_item_count' => $remainingItemCount,
            'detail_items' => $this->buildItemRows($orderItems),
            'has_detail_items' => $orderItems !== [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function buildItemRows(array $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $name = $this->truncateLabel((string) ($item[OrderItem::schema_fields_PRODUCT_NAME] ?? $item['product_name'] ?? ''));
            $sku = $this->truncateLabel((string) ($item[OrderItem::schema_fields_PRODUCT_SKU] ?? $item['product_sku'] ?? ''));
            $image = (string) ($item[OrderItem::schema_fields_PRODUCT_IMAGE] ?? $item['product_image'] ?? $item['image'] ?? '');
            $qty = (int) ($item[OrderItem::schema_fields_QUANTITY] ?? $item['quantity'] ?? 1);
            $price = (float) ($item[OrderItem::schema_fields_PRICE] ?? $item['price'] ?? 0);
            $rowTotal = (float) ($item[OrderItem::schema_fields_TOTAL] ?? $item['total'] ?? 0);
            $optionItems = $this->normalizeOptions($item['options'] ?? $item['product_options'] ?? null);
            $options = $this->formatOptionItems($optionItems);
            $rows[] = [
                'name' => $name !== '' ? $name : '-',
                'sku' => $sku,
                'image' => $image,
                'options' => $options,
                'option_items' => $optionItems,
                'qty' => $qty,
                'price_formatted' => CurrencyFormatter::formatConverted($price),
                'total_formatted' => CurrencyFormatter::formatConverted($rowTotal),
            ];
        }

        return $rows;
    }

    private function formatOptions(mixed $rawOptions): string
    {
        return $this->formatOptionItems($this->normalizeOptions($rawOptions));
    }

    /**
     * @param array<int, array<string, mixed>> $options
     */
    private function formatOptionItems(array $options): string
    {
        $parts = [];
        foreach ($options as $option) {
            $label = \trim((string) ($option['label'] ?? ''));
            $value = \trim((string) ($option['value'] ?? ''));
            if ($value !== '') {
                $parts[] = $label !== '' ? $label . ': ' . $value : $value;
            }
        }

        return \implode(' / ', $parts);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeOptions(mixed $rawOptions): array
    {
        if (\is_string($rawOptions)) {
            $rawOptions = \trim($rawOptions);
            if ($rawOptions === '') {
                return [];
            }

            $decoded = \json_decode($rawOptions, true);
            if (\is_array($decoded)) {
                return $this->normalizeOptions($decoded);
            }

            return [[
                'label' => (string) __('规格'),
                'value' => $rawOptions,
            ]];
        }

        if (!\is_array($rawOptions) || $rawOptions === []) {
            return [];
        }

        $isAssoc = \array_keys($rawOptions) !== \range(0, \count($rawOptions) - 1);
        if ($isAssoc) {
            $options = [];
            foreach ($rawOptions as $label => $value) {
                if (\is_scalar($value) && \trim((string) $value) !== '') {
                    $optionLabel = \trim((string) $label);
                    $options[] = [
                        'label' => $optionLabel !== '' ? $this->localizeOptionLabel($optionLabel) : (string) __('规格'),
                        'value' => \trim((string) $value),
                    ];
                }
            }

            return $options;
        }

        $options = [];
        foreach ($rawOptions as $option) {
            if (!\is_array($option)) {
                continue;
            }

            $value = \trim((string) ($option['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $optionLabel = \trim((string) ($option['label'] ?? ''));

            $normalized = [
                'label' => $optionLabel !== ''
                    ? $this->localizeOptionLabel($optionLabel)
                    : (string) __('规格'),
                'value' => $value,
            ];

            foreach (['attribute_id', 'option_id'] as $idKey) {
                $id = (int) ($option[$idKey] ?? 0);
                if ($id > 0) {
                    $normalized[$idKey] = $id;
                }
            }

            foreach (['code', 'attribute_code', 'option_code', 'swatch_type', 'swatch_value', 'option_image'] as $stringKey) {
                $stringValue = \trim((string) ($option[$stringKey] ?? ''));
                if ($stringValue !== '') {
                    $normalized[$stringKey] = $stringValue;
                }
            }

            if (($normalized['swatch_type'] ?? '') === 'image') {
                if (($normalized['swatch_value'] ?? '') === '' && ($normalized['option_image'] ?? '') !== '') {
                    $normalized['swatch_value'] = $normalized['option_image'];
                }
                if (($normalized['option_image'] ?? '') === '' && ($normalized['swatch_value'] ?? '') !== '') {
                    $normalized['option_image'] = $normalized['swatch_value'];
                }
            }

            $options[] = $normalized;
        }

        return $options;
    }

    private function localizeOptionLabel(string $label): string
    {
        $label = \trim($label);
        if ($label === '' || !\preg_match('/\p{Han}/u', $label)) {
            return $label;
        }

        return (string) __($label);
    }

    /**
     * @return array<string, string>
     */
    private function buildLabels(): array
    {
        return [
            'empty_all' => (string) __('您还没有订单'),
            'empty_filtered' => (string) __('该状态下暂无订单'),
            'view_all_orders' => (string) __('查看全部订单'),
            'go_shopping' => (string) __('去购物'),
            'order_number' => (string) __('订单号：%{1}', ['%{1}']),
            'amount_label' => (string) __('订单金额：'),
            'pending_payment' => (string) __('待支付'),
            'items_missing' => (string) __('订单商品快照缺失，请重新导入订单数据'),
            'more_items' => (string) __('还有 %{1} 件商品，查看详情', ['%{1}']),
            'view_details' => (string) __('查看详情'),
            'continue_payment' => (string) __('继续支付'),
            'cancel_order' => (string) __('取消订单'),
            'request_return' => (string) __('申请退货'),
            'cannot_cancel' => (string) __('不可取消'),
            'order_details' => (string) __('订单详情'),
            'back_to_order' => (string) __('返回订单位置'),
            'order_status' => (string) __('订单状态'),
            'payment_status' => (string) __('支付状态'),
            'fulfillment_status' => (string) __('配送状态'),
            'payment_method' => (string) __('支付方式'),
            'shipping_method' => (string) __('配送方式'),
            'order_amount' => (string) __('订单金额'),
            'order_items' => (string) __('订单商品'),
            'payment_panel_hint' => (string) __('支付流程会嵌入当前个人中心页面，不再跳到独立订单页。'),
            'qty' => (string) __('×%{1}', ['%{1}']),
            'pagination' => (string) __('订单分页'),
            'prev' => 'Prev',
            'next' => 'Next',
        ];
    }

    private function truncateLabel(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (mb_strlen($value) <= self::MAX_LABEL_LENGTH) {
            return $value;
        }

        return mb_substr($value, 0, self::MAX_LABEL_LENGTH) . '…';
    }

    private function getUrl(): Url
    {
        return $this->url ?? \Weline\Framework\Manager\ObjectManager::getInstance(Url::class);
    }
}
