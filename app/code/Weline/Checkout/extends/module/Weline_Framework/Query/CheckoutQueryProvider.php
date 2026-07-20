<?php

declare(strict_types=1);

namespace Weline\Checkout\Extends\Module\Weline_Framework\Query;

use Weline\Checkout\Service\CheckoutService;
use Weline\Checkout\Service\PaymentService;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;

/**
 * 前台结账 Facade：聚合购物车、配送、支付，供 Theme 结账页通过 Weline.Api.resource('checkout') 调用。
 */
class CheckoutQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly PaymentService $paymentService,
        private readonly SessionFactory $sessionFactory,
    ) {
    }

    public function getProviderName(): string
    {
        return 'checkout';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getData' => $this->getData($params),
            'placeOrder', 'createOrder' => $this->placeOrder($params),
            default => throw new \InvalidArgumentException((string)__('结账接口不支持该操作：%{1}', $operation)),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function getData(array $params): array
    {
        $this->assertLoggedIn();

        $shippingAddress = \is_array($params['shipping_address'] ?? null)
            ? $params['shipping_address']
            : [];

        $cart = $this->loadCartSummary();
        $items = \is_array($cart['items'] ?? null) ? $cart['items'] : [];
        $currency = (string)($cart['currency'] ?? 'CNY');

        return $this->ok((string)__('结账信息已加载'), [
            'currency' => $currency,
            'cart' => [
                'subtotal' => (float)($cart['subtotal'] ?? 0),
                'grand_total' => (float)($cart['grand_total'] ?? $cart['subtotal'] ?? 0),
                'currency' => $currency,
                'is_empty' => (bool)($cart['is_empty'] ?? $items === []),
                'item_count' => (int)($cart['item_count'] ?? \count($items)),
            ],
            'items' => $items,
            'shipping_methods' => $this->loadShippingMethods($shippingAddress),
            'payment_methods' => $this->loadPaymentMethods($params + [
                'currency' => $currency,
                'amount' => (float)($cart['grand_total'] ?? $cart['subtotal'] ?? 0),
            ]),
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function placeOrder(array $params): array
    {
        $customerId = $this->assertLoggedIn();
        $shippingAddress = \is_array($params['shipping_address'] ?? null) ? $params['shipping_address'] : [];
        $shippingMethod = trim((string)($params['shipping_method'] ?? ''));
        $paymentMethod = trim((string)($params['payment_method'] ?? ''));

        if ($shippingMethod === '' || $paymentMethod === '') {
            throw new \InvalidArgumentException((string)__('请补全收货信息并选择配送和支付方式。'));
        }

        $cart = $this->loadCartSummary();
        $items = \is_array($cart['items'] ?? null) ? $cart['items'] : [];
        if ($items === []) {
            throw new \RuntimeException((string)__('购物车为空，请先加入商品。'));
        }

        $shippingAmount = $this->resolveShippingAmount($shippingMethod, $shippingAddress);
        $orderItems = [];
        foreach ($items as $item) {
            $qty = (float)($item['qty'] ?? $item['quantity'] ?? 1);
            $price = (float)($item['price'] ?? 0);
            $orderItems[] = [
                'product_id' => (int)($item['product_id'] ?? $item['id'] ?? 0),
                'product_name' => (string)($item['name'] ?? $item['product_name'] ?? ''),
                'sku' => (string)($item['sku'] ?? ''),
                'quantity' => $qty,
                'price' => $price,
                'row_total' => (float)($item['row_total'] ?? ($qty * $price)),
            ];
        }

        $order = $this->checkoutService->createOrder([
            'customer_id' => $customerId,
            'items' => $orderItems,
            'shipping_address' => $shippingAddress,
            'billing_address' => $shippingAddress,
            'shipping_method' => $shippingMethod,
            'shipping_amount' => $shippingAmount,
            'tax_amount' => 0.0,
            'discount_amount' => 0.0,
            'payment_method' => $paymentMethod,
            'currency' => (string)($cart['currency'] ?? 'CNY'),
        ]);

        $orderId = (int)$order->getId();
        $redirect = '/checkout/success-page?order_id=' . $orderId;
        $requiresAction = false;

        try {
            $paymentResult = $this->paymentService->processPayment($orderId, $paymentMethod, [
                'shipping_address' => $shippingAddress,
            ]);
            $gateway = \is_array($paymentResult['gateway_response'] ?? null)
                ? $paymentResult['gateway_response']
                : [];
            $paymentRedirect = trim((string)(
                $paymentResult['redirect']
                ?? $paymentResult['redirect_url']
                ?? $gateway['redirect_url']
                ?? $gateway['response']['redirect_url']
                ?? ''
            ));
            if ($paymentRedirect !== '') {
                $redirect = $paymentRedirect;
                $requiresAction = true;
            }
        } catch (\Throwable) {
            // 订单已创建；支付跳转失败时仍落到成功页，由用户继续支付。
        }

        try {
            w_query('cart', 'clear');
        } catch (\Throwable) {
        }

        return $this->ok((string)__('订单创建成功'), [
            'order_id' => $orderId,
            'order_number' => (string)$order->getOrderNumber(),
            'redirect' => $redirect,
            'redirect_url' => $redirect,
            'requires_action' => $requiresAction,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCartSummary(): array
    {
        try {
            $result = w_query('cart', 'summary');
        } catch (\Throwable) {
            return [
                'items' => [],
                'subtotal' => 0.0,
                'grand_total' => 0.0,
                'currency' => 'CNY',
                'is_empty' => true,
                'item_count' => 0,
            ];
        }

        if (!\is_array($result)) {
            return [
                'items' => [],
                'subtotal' => 0.0,
                'is_empty' => true,
            ];
        }

        $nested = \is_array($result['data'] ?? null) ? $result['data'] : [];

        return $nested + $result;
    }

    /**
     * @param array<string, mixed> $shippingAddress
     * @return list<array<string, mixed>>
     */
    private function loadShippingMethods(array $shippingAddress): array
    {
        $countryCode = strtoupper(trim((string)($shippingAddress['country_code'] ?? 'CN'))) ?: 'CN';
        try {
            $result = w_query('shippingInfo', 'getByLocation', [
                'country_code' => $countryCode,
                'province' => (string)($shippingAddress['province'] ?? ''),
                'city' => (string)($shippingAddress['city'] ?? ''),
                'district' => (string)($shippingAddress['district'] ?? ''),
            ]);
        } catch (\Throwable) {
            return [];
        }

        if (!\is_array($result)) {
            return [];
        }

        $payload = \is_array($result['data'] ?? null) ? $result['data'] : $result;
        $services = \is_array($payload['services'] ?? null) ? $payload['services'] : [];
        $methods = [];
        foreach ($services as $service) {
            if (!\is_array($service)) {
                continue;
            }
            $code = trim((string)($service['service_code'] ?? $service['service_id'] ?? ''));
            if ($code === '') {
                continue;
            }
            $amount = $this->firstPriceAmount(\is_array($service['price_rules'] ?? null) ? $service['price_rules'] : []);
            $etaMin = $service['estimated_days_min'] ?? null;
            $etaMax = $service['estimated_days_max'] ?? null;
            $eta = '';
            if ($etaMin !== null || $etaMax !== null) {
                $eta = (string)__('预计 %{1}-%{2} 天', [(string)$etaMin, (string)$etaMax]);
            }
            $methods[] = [
                'code' => $code,
                'label' => (string)($service['service_name'] ?? $code),
                'title' => (string)($service['service_name'] ?? $code),
                'description' => $eta,
                'eta_label' => $eta,
                'amount' => $amount,
                'fee' => $amount,
                'source' => 'Weline_Shipping',
            ];
        }

        return $methods;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function loadPaymentMethods(array $params): array
    {
        try {
            $result = w_query('payment', 'getCheckoutPaymentMethods', $params);
        } catch (\Throwable) {
            return [];
        }

        $methods = [];
        if (\is_array($result)) {
            $list = \is_array($result['data'] ?? null) ? $result['data'] : $result;
            if (\array_is_list($list) || (isset($list[0]) && \is_array($list[0]))) {
                $methods = $list;
            }
        }

        $normalized = [];
        foreach ($methods as $method) {
            if (!\is_array($method)) {
                continue;
            }
            $code = trim((string)($method['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            if (\array_key_exists('enabled', $method) && !$method['enabled']) {
                continue;
            }
            $normalized[] = [
                'code' => $code,
                'label' => (string)($method['label'] ?? $method['title'] ?? $method['name'] ?? $code),
                'title' => (string)($method['title'] ?? $method['label'] ?? $code),
                'description' => (string)($method['description'] ?? ''),
                'source' => (string)($method['source'] ?? 'Weline_Payment'),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $shippingAddress
     */
    private function resolveShippingAmount(string $shippingMethod, array $shippingAddress): float
    {
        foreach ($this->loadShippingMethods($shippingAddress) as $method) {
            if ((string)($method['code'] ?? '') === $shippingMethod) {
                return (float)($method['amount'] ?? $method['fee'] ?? 0);
            }
        }

        return 0.0;
    }

    /**
     * @param list<mixed> $priceRules
     */
    private function firstPriceAmount(array $priceRules): float
    {
        foreach ($priceRules as $rule) {
            if (!\is_array($rule)) {
                continue;
            }
            foreach (['price', 'amount', 'fee', 'shipping_fee'] as $key) {
                if (isset($rule[$key]) && is_numeric($rule[$key])) {
                    return round((float)$rule[$key], 2);
                }
            }
        }

        return 0.0;
    }

    private function assertLoggedIn(): int
    {
        $session = $this->sessionFactory->createFrontendSession();
        $userId = (int)($session->getUserId() ?? 0);
        if ($userId <= 0 || !$session->isLoggedIn()) {
            throw new \RuntimeException((string)__('请先登录'));
        }

        return $userId;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function ok(string $message, array $data): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ] + $data;
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'checkout',
            'name' => (string)__('前台结账'),
            'description' => (string)__('聚合购物车、配送与支付，供结账页 Weline.Api.resource(\'checkout\') 使用。'),
            'module' => 'Weline_Checkout',
            'operations' => [
                [
                    'name' => 'getData',
                    'frontend' => true,
                    'auth' => 'customer',
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 2,
                    'params' => [
                        'shipping_address' => ['type' => 'array', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Load checkout cart, shipping and payment options',
                ],
                [
                    'name' => 'placeOrder',
                    'frontend' => true,
                    'auth' => 'customer',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'shipping_address' => ['type' => 'array', 'required' => true],
                        'shipping_method' => ['type' => 'string', 'required' => true],
                        'payment_method' => ['type' => 'string', 'required' => true],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Create order from cart and start payment',
                ],
                [
                    'name' => 'createOrder',
                    'frontend' => true,
                    'auth' => 'customer',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'shipping_address' => ['type' => 'array', 'required' => true],
                        'shipping_method' => ['type' => 'string', 'required' => true],
                        'payment_method' => ['type' => 'string', 'required' => true],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Alias of placeOrder',
                ],
            ],
        ];
    }
}
