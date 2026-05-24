<?php

declare(strict_types=1);

namespace WeShop\Checkout\Service;

use WeShop\Address\Service\AddressService;
use WeShop\B2B\Service\B2BCheckoutValidator;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Order\Model\Order;
use WeShop\Order\Service\OrderService;
use Weline\Checkout\Service\CheckoutIdentityService;

class CheckoutService
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly ?AddressService $addressService = null,
        private readonly ?B2BCheckoutValidator $b2bCheckoutValidator = null
    ) {
    }

    public function createOrderFromCart(int $customerId, array $checkoutData): Order
    {
        $cartCustomerId = max(0, (int) ($checkoutData['cart_customer_id'] ?? $customerId));
        if ($cartCustomerId <= 0) {
            $cartCustomerId = $customerId;
        }

        $cartItems = $this->query('cart', 'getCartItems', [
            'customer_id' => $cartCustomerId,
        ]);
        $cartItems = \is_array($cartItems) ? $cartItems : [];

        $totals = $this->query('cart', 'calculateTotals', [
            'customer_id' => $cartCustomerId,
        ]);
        $totals = \is_array($totals) ? $totals : [];

        if ($cartItems === []) {
            throw new \Exception((string) __('购物车为空，无法结账。'));
        }

        $shippingAddress = $this->normalizeShippingAddress($checkoutData);
        $billingAddress = $this->normalizeBillingAddress($checkoutData, $shippingAddress);
        $summary = $this->buildCheckoutSummary($cartItems, $totals, $checkoutData);

        $orderSummary = $this->query('order', 'createOrder', [
            'order_data' => [
                'customer_id' => $customerId,
                'status' => OrderService::STATUS_PENDING,
                'subtotal' => $summary['subtotal'],
                'shipping_amount' => $summary['shipping'],
                'discount_amount' => $summary['discount'],
                'tax_amount' => $summary['tax'],
                'total' => $summary['grand_total'],
                'shipping_method' => (string) ($checkoutData['shipping_method'] ?? ''),
                'payment_method' => (string) ($checkoutData['payment_method'] ?? ''),
                'shipping_address' => $shippingAddress,
                'billing_address' => $billingAddress,
            ],
        ]);
        if (!\is_array($orderSummary) || (int) ($orderSummary['order_id'] ?? 0) <= 0) {
            throw new \Exception((string) __('订单创建失败。'));
        }

        $orderId = (int) ($orderSummary['order_id'] ?? 0);
        $orderItems = [];
        foreach ($cartItems as $cartItem) {
            if (!\is_array($cartItem)) {
                continue;
            }

            $product = \is_array($cartItem['product'] ?? null) ? $cartItem['product'] : [];
            $quantity = (int) ($cartItem['quantity'] ?? $cartItem['qty'] ?? 1);
            $price = (float) ($cartItem['price'] ?? 0);
            $options = $this->normalizeOptions($cartItem['options'] ?? $cartItem['product_options'] ?? null);
            $orderItems[] = [
                'product_id' => (int) ($cartItem['product_id'] ?? 0),
                'product_name' => (string) ($product['name'] ?? $cartItem['product_name'] ?? ''),
                'product_sku' => (string) ($product['sku'] ?? $cartItem['product_sku'] ?? ''),
                'product_image' => (string) ($product['image'] ?? $cartItem['product_image'] ?? $cartItem['image'] ?? ''),
                'product_options' => $this->encodeOptions($options),
                'options' => $options,
                'quantity' => $quantity,
                'price' => $price,
                'total' => $price * $quantity,
            ];
        }

        if ($orderItems !== []) {
            $this->query('order', 'addOrderItems', [
                'order_id' => $orderId,
                'items' => $orderItems,
            ]);
        }

        $this->query('cart', 'clearCart', [
            'customer_id' => $cartCustomerId,
        ]);

        $order = $this->orderService->getOrder($orderId);
        if (!$order) {
            throw new \Exception((string) __('订单创建失败。'));
        }

        $order->setData('weshop_checkout_summary', $summary);

        $eventData = [
            'order' => $order,
            'customer_id' => $customerId,
            'cart_customer_id' => $cartCustomerId,
            'is_guest_checkout' => !empty($checkoutData['is_guest_checkout']),
            'checkout_mode' => (string) ($checkoutData['checkout_mode'] ?? ''),
            'guest_email' => (string) ($checkoutData['guest_email'] ?? ''),
            'notification_channels' => is_array($checkoutData['notification_channels'] ?? null)
                ? $checkoutData['notification_channels']
                : [],
        ];
        $this->getEventsManager()->dispatch('WeShop_Checkout::order_created', $eventData);

        return $order;
    }

    /**
     * @param array<string, mixed> $checkoutData
     * @return array<string, float>
     */
    public function previewCheckoutSummary(int $customerId, array $checkoutData): array
    {
        $checkoutData = $this->normalizeCheckoutData($checkoutData);
        $checkoutData['customer_id'] = $customerId;
        $cartCustomerId = max(0, (int) ($checkoutData['cart_customer_id'] ?? $customerId));
        if ($cartCustomerId <= 0) {
            $cartCustomerId = $customerId;
        }

        $retryOrderId = (int) ($checkoutData['order_id'] ?? $checkoutData['retry_order_id'] ?? 0);
        if ($retryOrderId > 0) {
            $retryContext = $this->orderService->getRetryPaymentContext($retryOrderId, $customerId);
            if (\is_array($retryContext) && \is_array($retryContext['summary'] ?? null)) {
                return $this->normalizeSummary((array) $retryContext['summary']);
            }
        }

        $cartItems = $this->query('cart', 'getCartItems', [
            'customer_id' => $cartCustomerId,
        ]);
        $cartItems = \is_array($cartItems) ? $cartItems : [];

        $totals = $this->query('cart', 'calculateTotals', [
            'customer_id' => $cartCustomerId,
        ]);
        $totals = \is_array($totals) ? $totals : [];

        if ($cartItems === []) {
            return $this->normalizeSummary($totals);
        }

        return $this->buildCheckoutSummary($cartItems, $totals, $checkoutData);
    }

    /**
     * @return array<string, mixed>
     */
    public function placeOrder(array $checkoutData): array
    {
        $checkoutData = $this->normalizeCheckoutData($checkoutData);
        $this->validateCheckoutData($checkoutData);

        $customerId = (int) ($checkoutData['customer_id'] ?? 0);
        if ($customerId <= 0) {
            throw new \InvalidArgumentException((string) __('提交订单需要结账身份。'));
        }

        $retryOrderId = (int) ($checkoutData['order_id'] ?? $checkoutData['retry_order_id'] ?? 0);
        $isRetryPayment = $retryOrderId > 0;

        $order = $isRetryPayment
            ? $this->reuseRetryPaymentOrder($customerId, $retryOrderId)
            : $this->createOrderFromCart($customerId, $checkoutData);

        $payment = $this->query('payment', 'processPayment', [
            'order' => $order,
            'payment_method' => (string) ($checkoutData['payment_method'] ?? ''),
            'payment_data' => $checkoutData,
        ]);
        $payment = \is_array($payment) ? $payment : [];

        $paymentStatus = (string) ($payment['status'] ?? '');
        if ($paymentStatus !== '') {
            $this->orderService->updatePaymentStatus((int) $order->getId(), $this->normalizePaymentStatus($paymentStatus));
            $order = $this->orderService->getOrder((int) $order->getId()) ?? $order;
        }

        return [
            'order' => $order,
            'order_id' => (int) $order->getId(),
            'order_increment_id' => $this->readOrderIncrementId($order),
            'order_summary' => $this->readOrderSummary($order),
            'payment' => $payment,
            'payment_method' => [
                'code' => (string) ($payment['payment_method'] ?? $checkoutData['payment_method'] ?? ''),
                'title' => (string) ($payment['payment_method_title'] ?? $payment['title'] ?? $checkoutData['payment_method'] ?? ''),
                'description' => (string) ($payment['description'] ?? ''),
            ],
            'is_retry_payment' => $isRetryPayment,
        ];
    }

    public function getCheckoutPaymentMethods(int $customerId, array $context = []): array
    {
        $context['customer_id'] = $customerId;
        $methods = $this->query('payment', 'getCheckoutPaymentMethods', $context);

        return \is_array($methods) ? $methods : [];
    }

    public function getCheckoutShippingMethods(int $customerId, array $context = []): array
    {
        $context['customer_id'] = $customerId;
        $methods = $this->query('shipping', 'getCheckoutShippingMethods', $context);

        return \is_array($methods) ? $methods : [];
    }

    public function validateCheckoutData(array $checkoutData): bool
    {
        $this->getCheckoutIdentityService()->validateGuestCheckout([
            'checkout_mode' => (string) ($checkoutData['checkout_mode'] ?? ''),
            'is_guest_checkout' => !empty($checkoutData['is_guest_checkout']),
            'guest_email' => (string) ($checkoutData['guest_email'] ?? $checkoutData['email'] ?? ''),
            'customer_id' => (int) ($checkoutData['customer_id'] ?? 0),
            'cart_customer_id' => (int) ($checkoutData['cart_customer_id'] ?? $checkoutData['customer_id'] ?? 0),
            'authenticated_customer_id' => (int) ($checkoutData['authenticated_customer_id'] ?? 0),
            'requires_guest_email' => true,
        ], $checkoutData);

        $shippingAddress = $this->normalizeShippingAddress($checkoutData);
        if ($shippingAddress === []) {
            throw new \InvalidArgumentException((string) __('请填写收货地址信息。'));
        }

        $billingAddress = $this->normalizeBillingAddress($checkoutData, $shippingAddress);
        if ($billingAddress === []) {
            throw new \InvalidArgumentException((string) __('请填写账单地址信息。'));
        }

        if (empty($checkoutData['shipping_method'])) {
            throw new \InvalidArgumentException((string) __('请选择配送方式。'));
        }

        if (empty($checkoutData['payment_method'])) {
            throw new \InvalidArgumentException((string) __('请选择支付方式。'));
        }

        $this->b2bCheckoutValidator?->validate($checkoutData);

        return true;
    }

    protected function query(string $provider, string $operation, array $params = []): mixed
    {
        return w_query($provider, $operation, $params);
    }

    /**
     * @param array<int, mixed> $cartItems
     * @param array<string, mixed> $totals
     * @param array<string, mixed> $checkoutData
     * @return array<string, float>
     */
    protected function buildCheckoutSummary(array $cartItems, array $totals, array $checkoutData): array
    {
        $subtotal = (float) ($totals['subtotal'] ?? $this->calculateSubtotalFromCartItems($cartItems));
        $discount = max(0.0, (float) ($totals['discount'] ?? 0.0));
        $shipping = $this->calculateCheckoutShipping($cartItems, $subtotal, $checkoutData);
        $taxDetails = $this->calculateCheckoutTaxDetails($cartItems, $totals, $subtotal, $discount, $shipping, $checkoutData);
        $tax = $taxDetails['tax_amount'];
        $grandTotal = max(0.0, round($subtotal + $shipping + $taxDetails['chargeable_tax'] - $discount, 2));

        return [
            'subtotal' => round($subtotal, 2),
            'shipping' => $shipping,
            'discount' => round($discount, 2),
            'tax' => $tax,
            'grand_total' => $grandTotal,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeCheckoutData(array $checkoutData): array
    {
        $shippingAddress = $checkoutData['shipping_address'] ?? $checkoutData['shipping'] ?? [];
        if (!\is_array($shippingAddress)) {
            $shippingAddress = [];
        }

        $checkoutData['shipping_address'] = $shippingAddress;
        $billingAddress = $checkoutData['billing_address'] ?? $checkoutData['billing'] ?? [];
        if (!\is_array($billingAddress)) {
            $billingAddress = [];
        }
        $checkoutData['billing_address'] = $billingAddress;
        $checkoutData['billing_address_id'] = max(0, (int) ($checkoutData['billing_address_id'] ?? 0));
        $checkoutData['billing_same_as_shipping'] = $this->normalizeBoolean($checkoutData['billing_same_as_shipping'] ?? true);
        $checkoutData['shipping_method'] = (string) ($checkoutData['shipping_method'] ?? '');
        $checkoutData['payment_method'] = (string) ($checkoutData['payment_method'] ?? '');
        $checkoutData['order_id'] = (int) ($checkoutData['order_id'] ?? $checkoutData['retry_order_id'] ?? 0);
        $checkoutData['cart_customer_id'] = max(0, (int) ($checkoutData['cart_customer_id'] ?? $checkoutData['customer_id'] ?? 0));
        $checkoutData['checkout_mode'] = (string) ($checkoutData['checkout_mode'] ?? (!empty($checkoutData['is_guest_checkout']) ? CheckoutIdentityService::MODE_GUEST : CheckoutIdentityService::MODE_CUSTOMER));
        $checkoutData['is_guest_checkout'] = $this->getCheckoutIdentityService()->normalizeMode($checkoutData['checkout_mode']) === CheckoutIdentityService::MODE_GUEST
            || !empty($checkoutData['is_guest_checkout']);
        $checkoutData['notification_channels'] = $this->normalizeNotificationChannels($checkoutData['notification_channels'] ?? []);

        return $checkoutData;
    }

    /**
     * @param mixed $channels
     * @return array<int, string>
     */
    protected function normalizeNotificationChannels(mixed $channels): array
    {
        if (!\is_array($channels)) {
            $channels = $channels === null || $channels === '' ? [] : [$channels];
        }

        $normalized = [];
        foreach ($channels as $channel) {
            $channel = strtolower(trim((string) $channel));
            if ($channel !== '') {
                $normalized[] = $channel;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<int, mixed> $cartItems
     */
    protected function calculateSubtotalFromCartItems(array $cartItems): float
    {
        $subtotal = 0.0;
        foreach ($cartItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $price = (float) ($item['price'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? $item['qty'] ?? 1);
            $subtotal += $price * $quantity;
        }

        return $subtotal;
    }

    /**
     * @param array<int, mixed> $cartItems
     * @param array<string, mixed> $checkoutData
     */
    protected function calculateCheckoutShipping(array $cartItems, float $subtotal, array $checkoutData): float
    {
        $shippingMethod = (string) ($checkoutData['shipping_method'] ?? '');
        if ($shippingMethod === '') {
            return 0.0;
        }

        $shippingAmount = $this->query('shipping', 'calculateShipping', [
            'shipping_method' => $shippingMethod,
            'shipping_data' => [
                'customer_id' => (int) ($checkoutData['customer_id'] ?? 0),
                'subtotal' => $subtotal,
                'currency' => (string) ($checkoutData['currency'] ?? ''),
                'address' => $this->normalizeShippingAddress($checkoutData),
                'items' => $this->mapCartItemsForShipping($cartItems),
            ],
        ]);

        return round(max(0.0, is_numeric($shippingAmount) ? (float) $shippingAmount : 0.0), 2);
    }

    /**
     * @param array<int, mixed> $cartItems
     * @param array<string, mixed> $totals
     * @param array<string, mixed> $checkoutData
     * @return array{tax_amount: float, chargeable_tax: float, included_tax: float}
     */
    protected function calculateCheckoutTaxDetails(
        array $cartItems,
        array $totals,
        float $subtotal,
        float $discount,
        float $shipping,
        array $checkoutData
    ): array {
        $address = $this->normalizeShippingAddress($checkoutData);
        $country = (string) ($address['country_id'] ?? $address['country'] ?? '');
        $region = (string) ($address['region'] ?? '');
        $context = $this->buildCheckoutTaxContext($cartItems, $totals, $discount, $shipping, $checkoutData);
        $params = [
            'subtotal' => $subtotal,
            'country' => $country,
            'region' => $region,
            'shipping_amount' => $shipping,
            'discount' => $discount,
            'context' => $context,
        ];

        try {
            $taxResult = $this->query('tax', 'calculateTaxBreakdown', $params);
        } catch (\Throwable) {
            $taxResult = null;
        }

        if (is_array($taxResult)) {
            $taxAmount = round(max(0.0, (float) ($taxResult['tax_amount'] ?? $taxResult['tax'] ?? 0.0)), 2);
            $chargeableTax = round(
                max(0.0, (float) ($taxResult['chargeable_tax'] ?? $taxResult['tax_amount'] ?? $taxResult['tax'] ?? 0.0)),
                2
            );
            $includedTax = round(max(0.0, (float) ($taxResult['included_tax'] ?? 0.0)), 2);

            return [
                'tax_amount' => $taxAmount,
                'chargeable_tax' => $chargeableTax,
                'included_tax' => $includedTax,
            ];
        }

        $taxAmount = is_numeric($taxResult) ? (float) $taxResult : 0.0;
        if (!is_numeric($taxResult)) {
            $legacyResult = $this->query('tax', 'calculateTax', $params);
            $taxAmount = is_numeric($legacyResult) ? (float) $legacyResult : 0.0;
        }

        $taxAmount = round(max(0.0, $taxAmount), 2);

        return [
            'tax_amount' => $taxAmount,
            'chargeable_tax' => $taxAmount,
            'included_tax' => 0.0,
        ];
    }

    /**
     * @param array<string, mixed> $checkoutData
     * @return array<string, mixed>
     */
    protected function normalizeShippingAddress(array $checkoutData): array
    {
        $address = is_array($checkoutData['shipping_address'] ?? null) ? $checkoutData['shipping_address'] : [];
        $guestEmail = trim((string) ($checkoutData['guest_email'] ?? $checkoutData['email'] ?? ''));
        if ($guestEmail !== '' && empty($address['email'])) {
            $address['email'] = $guestEmail;
        }

        $addressId = (int) ($checkoutData['shipping_address_id'] ?? 0);
        if ($addressId <= 0) {
            return $address;
        }

        $resolved = $this->resolveSavedShippingAddress($addressId, (int) ($checkoutData['customer_id'] ?? 0));
        if ($resolved === []) {
            return $address;
        }

        if ($address === []) {
            return $resolved;
        }

        return array_merge($resolved, array_filter(
            $address,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []
        ));
    }

    /**
     * @param array<string, mixed> $checkoutData
     * @param array<string, mixed> $shippingAddress
     * @return array<string, mixed>
     */
    protected function normalizeBillingAddress(array $checkoutData, array $shippingAddress = []): array
    {
        if ($this->normalizeBoolean($checkoutData['billing_same_as_shipping'] ?? true)) {
            return $shippingAddress !== [] ? $shippingAddress : $this->normalizeShippingAddress($checkoutData);
        }

        $address = is_array($checkoutData['billing_address'] ?? null) ? $checkoutData['billing_address'] : [];
        $guestEmail = trim((string) ($checkoutData['guest_email'] ?? $checkoutData['email'] ?? ''));
        if ($guestEmail !== '' && empty($address['email'])) {
            $address['email'] = $guestEmail;
        }

        $addressId = (int) ($checkoutData['billing_address_id'] ?? 0);
        if ($addressId <= 0 || !empty($checkoutData['is_guest_checkout'])) {
            return $address;
        }

        $addressCustomerId = (int) ($checkoutData['authenticated_customer_id'] ?? $checkoutData['customer_id'] ?? 0);
        $resolved = $this->resolveSavedBillingAddress($addressId, $addressCustomerId);
        if ($resolved === []) {
            return $address;
        }

        if ($address === []) {
            return $resolved;
        }

        return array_merge($resolved, array_filter(
            $address,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []
        ));
    }

    protected function normalizeBoolean(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return false;
        }

        return !\in_array($value, ['0', 'false', 'off', 'no'], true);
    }

    /**
     * @param array<int, mixed> $cartItems
     * @return array<int, array<string, mixed>>
     */
    protected function mapCartItemsForShipping(array $cartItems): array
    {
        $result = [];
        foreach ($cartItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $product = is_array($item['product'] ?? null) ? $item['product'] : [];
            $result[] = [
                'product_id' => (int) ($item['product_id'] ?? 0),
                'qty' => (int) ($item['quantity'] ?? $item['qty'] ?? 1),
                'price' => (float) ($item['price'] ?? 0),
                'weight' => (float) ($item['weight'] ?? $product['weight'] ?? 0),
                'sku' => (string) ($product['sku'] ?? $item['product_sku'] ?? ''),
                'name' => (string) ($product['name'] ?? $item['product_name'] ?? ''),
                'options' => $this->normalizeOptions($item['options'] ?? $item['product_options'] ?? null),
            ];
        }

        return $result;
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
     * @param array<int, array<string, string>> $options
     */
    protected function encodeOptions(array $options): string
    {
        $options = $this->normalizeOptions($options);
        if ($options === []) {
            return '';
        }

        $encoded = \json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return \is_string($encoded) ? $encoded : '';
    }

    /**
     * @param array<int, mixed> $cartItems
     * @param array<string, mixed> $totals
     * @param array<string, mixed> $checkoutData
     * @return array<string, mixed>
     */
    protected function buildCheckoutTaxContext(
        array $cartItems,
        array $totals,
        float $discount,
        float $shipping,
        array $checkoutData
    ): array {
        $context = [];
        if (is_array($totals['tax_context'] ?? null)) {
            $context = $totals['tax_context'];
        }

        if (is_array($checkoutData['tax_context'] ?? null)) {
            $context = array_merge($context, $checkoutData['tax_context']);
        }

        $passthroughKeys = [
            'apply_to_shipping',
            'default_rate',
            'country_rates',
            'region_rates',
            'prices_include_tax',
            'price_includes_tax',
            'shipping_includes_tax',
        ];
        foreach ($passthroughKeys as $key) {
            if (array_key_exists($key, $totals) && !array_key_exists($key, $context)) {
                $context[$key] = $totals[$key];
            }
            if (array_key_exists($key, $checkoutData)) {
                $context[$key] = $checkoutData[$key];
            }
        }

        $context['shipping_amount'] = $shipping;
        $context['discount'] = $discount;
        $context['currency'] = (string) ($checkoutData['currency'] ?? $totals['currency'] ?? '');
        $context['customer_id'] = (int) ($checkoutData['customer_id'] ?? $totals['customer_id'] ?? 0);
        $context['items'] = $this->mapCartItemsForShipping($cartItems);

        return $context;
    }

    protected function getEventsManager(): EventsManager
    {
        return ObjectManager::getInstance(EventsManager::class);
    }

    protected function getCheckoutIdentityService(): CheckoutIdentityService
    {
        return ObjectManager::getInstance(CheckoutIdentityService::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveSavedShippingAddress(int $addressId, int $customerId): array
    {
        if ($addressId <= 0 || $this->addressService === null) {
            return [];
        }

        $address = $this->addressService->getAddress($addressId, $customerId > 0 ? $customerId : null);

        return is_array($address) ? $address : [];
    }

    protected function resolveSavedBillingAddress(int $addressId, int $customerId): array
    {
        return $this->resolveSavedShippingAddress($addressId, $customerId);
    }

    protected function reuseRetryPaymentOrder(int $customerId, int $orderId): Order
    {
        $context = $this->orderService->getRetryPaymentContext($orderId, $customerId);
        if (!\is_array($context) || !($context['order'] ?? null) instanceof Order) {
            throw new \InvalidArgumentException((string) __('该订单已无法重新支付。'));
        }

        /** @var Order $order */
        $order = $context['order'];
        $order->setData('weshop_checkout_summary', $context['summary'] ?? []);

        return $order;
    }

    protected function normalizePaymentStatus(string $paymentStatus): string
    {
        return match (strtolower($paymentStatus)) {
            'paid', 'success', 'completed' => OrderService::PAYMENT_STATUS_PAID,
            'refunded' => OrderService::PAYMENT_STATUS_REFUNDED,
            'failed', 'error' => OrderService::PAYMENT_STATUS_FAILED,
            'partial' => OrderService::PAYMENT_STATUS_PARTIAL,
            default => OrderService::PAYMENT_STATUS_PENDING,
        };
    }

    protected function readOrderIncrementId(Order $order): string
    {
        if (method_exists($order, 'getIncrementId')) {
            return (string) $order->getIncrementId();
        }

        return (string) ($order->getData(Order::schema_fields_increment_id) ?? '');
    }

    /**
     * @return array<string, float>
     */
    protected function readOrderSummary(Order $order): array
    {
        $summary = $order->getData('weshop_checkout_summary');
        if (\is_array($summary)) {
            return $this->normalizeSummary($summary);
        }

        $grandTotal = (float) ($order->getData(Order::schema_fields_total) ?? 0);

        return [
            'subtotal' => $grandTotal,
            'shipping' => 0.0,
            'discount' => 0.0,
            'tax' => 0.0,
            'grand_total' => $grandTotal,
        ];
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, float>
     */
    protected function normalizeSummary(array $summary): array
    {
        return [
            'subtotal' => (float) ($summary['subtotal'] ?? 0),
            'shipping' => (float) ($summary['shipping'] ?? 0),
            'discount' => (float) ($summary['discount'] ?? 0),
            'tax' => (float) ($summary['tax'] ?? 0),
            'grand_total' => (float) ($summary['grand_total'] ?? $summary['total'] ?? 0),
        ];
    }
}
