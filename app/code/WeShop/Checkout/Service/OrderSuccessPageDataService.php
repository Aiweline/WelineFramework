<?php

declare(strict_types=1);

namespace WeShop\Checkout\Service;

use WeShop\Order\Model\Order;
use WeShop\Order\Model\OrderItem;
use WeShop\Order\Service\OrderService;
use WeShop\Product\Service\ProductRecommendationService;

class OrderSuccessPageDataService
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly ProductRecommendationService $productRecommendationService
    ) {
    }

    /**
     * @param array<string, mixed> $lastOrderContext
     * @return array<string, mixed>
     */
    public function build(Order $order, array $lastOrderContext = []): array
    {
        $summary = $this->normalizeOrderSummary($order, $lastOrderContext);
        $shippingAddress = $this->normalizeShippingAddress(
            is_array($lastOrderContext['shipping_address'] ?? null) ? $lastOrderContext['shipping_address'] : []
        );
        $paymentMethod = $this->normalizePaymentMethod(
            is_array($lastOrderContext['payment_method'] ?? null) ? $lastOrderContext['payment_method'] : []
        );
        $orderItems = $this->mapOrderItems($this->orderService->getOrderItems((int) $order->getId()));

        return [
            'order' => [
                'order_id' => (int) $order->getId(),
                'increment_id' => (string) ($order->getData(Order::schema_fields_increment_id) ?? ''),
                'status' => (string) ($order->getData(Order::schema_fields_status) ?? ''),
                'created_at' => (string) ($order->getData(Order::schema_fields_created_at) ?? ''),
                'grand_total' => $summary['grand_total'],
                'subtotal' => $summary['subtotal'],
                'shipping_amount' => $summary['shipping'],
                'tax_amount' => $summary['tax'],
                'discount_amount' => $summary['discount'],
                'shipping_address' => $shippingAddress,
                'payment_method_title' => (string) ($paymentMethod['title'] ?? ''),
                'payment_last4' => (string) ($paymentMethod['card_last4'] ?? ''),
            ],
            'order_items' => $orderItems,
            'shipping_address' => $shippingAddress,
            'payment_method' => $paymentMethod,
            'recommendations' => $this->productRecommendationService->getRecommendations(array_column($orderItems, 'product_id'), 4),
            'subtotal' => $summary['subtotal'],
            'shipping' => $summary['shipping'],
            'tax' => $summary['tax'],
            'grand_total' => $summary['grand_total'],
        ];
    }

    /**
     * @param array<string, mixed> $lastOrderContext
     * @return array<string, float>
     */
    protected function normalizeOrderSummary(Order $order, array $lastOrderContext): array
    {
        $contextSummary = is_array($lastOrderContext['cart_summary'] ?? null)
            ? $lastOrderContext['cart_summary']
            : (is_array($lastOrderContext['order_summary'] ?? null) ? $lastOrderContext['order_summary'] : []);

        $subtotal = (float) ($contextSummary['subtotal'] ?? $order->getData(Order::schema_fields_subtotal) ?? 0);
        $shipping = (float) ($contextSummary['shipping'] ?? $order->getData(Order::schema_fields_shipping_amount) ?? 0);
        $discount = (float) ($contextSummary['discount'] ?? $order->getData(Order::schema_fields_discount_amount) ?? 0);
        $tax = (float) ($contextSummary['tax'] ?? $order->getData(Order::schema_fields_tax_amount) ?? 0);
        $grandTotal = (float) ($contextSummary['grand_total'] ?? $contextSummary['total'] ?? $order->getData(Order::schema_fields_total) ?? 0);

        if ($subtotal <= 0 && $grandTotal > 0) {
            $subtotal = max(0.0, round($grandTotal - $shipping - $tax + $discount, 2));
        }

        if ($grandTotal <= 0) {
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
     * @param array<int, mixed> $orderItems
     * @return array<int, array<string, mixed>>
     */
    protected function mapOrderItems(array $orderItems): array
    {
        $mapped = [];
        foreach ($orderItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $qty = (int) ($item[OrderItem::schema_fields_QUANTITY] ?? $item['qty'] ?? 1);
            $price = (float) ($item[OrderItem::schema_fields_PRICE] ?? $item['price'] ?? 0);
            $mapped[] = [
                'item_id' => (int) ($item[OrderItem::schema_fields_ID] ?? 0),
                'product_id' => (int) ($item[OrderItem::schema_fields_PRODUCT_ID] ?? 0),
                'name' => (string) ($item[OrderItem::schema_fields_PRODUCT_NAME] ?? $item['name'] ?? ''),
                'sku' => (string) ($item[OrderItem::schema_fields_PRODUCT_SKU] ?? $item['sku'] ?? ''),
                'qty' => $qty,
                'price' => $price,
                'row_total' => (float) ($item[OrderItem::schema_fields_TOTAL] ?? $item['total'] ?? ($price * $qty)),
                'image' => (string) ($item['image'] ?? ''),
                'options' => $this->normalizeOptions($item['options'] ?? $item['product_options'] ?? null),
            ];
        }

        return $mapped;
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function normalizeOptions(mixed $rawOptions): array
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
                    $options[] = [
                        'label' => \trim((string) $label) !== '' ? \trim((string) $label) : (string) __('规格'),
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

            $normalized = [
                'label' => \trim((string) ($option['label'] ?? '')) !== ''
                    ? \trim((string) ($option['label'] ?? ''))
                    : (string) __('规格'),
                'value' => $value,
            ];

            foreach (['attribute_id', 'option_id'] as $idKey) {
                $id = (int) ($option[$idKey] ?? 0);
                if ($id > 0) {
                    $normalized[$idKey] = $id;
                }
            }

            $code = \trim((string) ($option['code'] ?? ''));
            if ($code !== '') {
                $normalized['code'] = $code;
            }

            $swatchType = \trim((string) ($option['swatch_type'] ?? ''));
            $swatchValue = \trim((string) ($option['swatch_value'] ?? ''));
            if ($swatchType !== '' && $swatchValue !== '') {
                $normalized['swatch_type'] = $swatchType;
                $normalized['swatch_value'] = $swatchValue;
            }

            $options[] = $normalized;
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $shippingAddress
     * @return array<string, string>
     */
    protected function normalizeShippingAddress(array $shippingAddress): array
    {
        $firstName = (string) ($shippingAddress['firstname'] ?? '');
        $lastName = (string) ($shippingAddress['lastname'] ?? '');
        $name = trim((string) ($shippingAddress['name'] ?? trim($firstName . ' ' . $lastName)));

        return [
            'name' => $name,
            'firstname' => $firstName,
            'lastname' => $lastName,
            'street' => (string) ($shippingAddress['street'] ?? ''),
            'city' => (string) ($shippingAddress['city'] ?? ''),
            'region' => (string) ($shippingAddress['region'] ?? $shippingAddress['state'] ?? ''),
            'postcode' => (string) ($shippingAddress['postcode'] ?? ''),
            'country' => (string) ($shippingAddress['country'] ?? $shippingAddress['country_id'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $paymentMethod
     * @return array<string, string>
     */
    protected function normalizePaymentMethod(array $paymentMethod): array
    {
        $title = (string) ($paymentMethod['title'] ?? $paymentMethod['name'] ?? '');

        return [
            'code' => (string) ($paymentMethod['code'] ?? ''),
            'name' => $title,
            'title' => $title,
            'description' => (string) ($paymentMethod['description'] ?? ''),
            'card_last4' => (string) ($paymentMethod['card_last4'] ?? $paymentMethod['last4'] ?? ''),
        ];
    }
}
