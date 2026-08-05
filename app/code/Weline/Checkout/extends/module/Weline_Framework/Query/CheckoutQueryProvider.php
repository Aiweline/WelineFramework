<?php

declare(strict_types=1);

namespace Weline\Checkout\Extends\Module\Weline_Framework\Query;

use Weline\Cart\Api\CheckoutCartSnapshotInterface;
use Weline\Cart\Service\CartV2ConflictException;
use Weline\Cart\Service\CartV2Service;
use Weline\Checkout\Service\CheckoutGroupSubmitService;
use Weline\Checkout\Service\CheckoutIdentityService;
use Weline\Checkout\Service\CheckoutService;
use Weline\Checkout\Service\CheckoutV2ConflictException;
use Weline\Checkout\Service\PaymentService;
use Weline\Customer\Api\Auth\CustomerAccountFacadeInterface;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;

/**
 * 前台结账 Facade：聚合购物车、配送、支付，供 Theme 结账页通过 Weline.Api.resource('checkout') 调用。
 */
class CheckoutQueryProvider implements QueryProviderInterface
{
    private ?CheckoutCartSnapshotInterface $checkoutCartSnapshots;

    private ?CustomerAccountFacadeInterface $customerAccounts;

    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly PaymentService $paymentService,
        private readonly SessionFactory $sessionFactory,
        private readonly CheckoutIdentityService $checkoutIdentityService,
        private readonly CheckoutGroupSubmitService $checkoutGroupSubmitService,
        ?CheckoutCartSnapshotInterface $checkoutCartSnapshots = null,
        ?CustomerAccountFacadeInterface $customerAccounts = null,
    ) {
        $this->checkoutCartSnapshots = $checkoutCartSnapshots;
        $this->customerAccounts = $customerAccounts;
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
            'freezeQuote' => $this->freezeQuote($params),
            'submitV2' => $this->submitV2($params),
            default => throw new \InvalidArgumentException((string)__('结账接口不支持该操作：%{1}', $operation)),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function freezeQuote(array $params): array
    {
        try {
            $address = \is_array($params['address'] ?? null) ? $params['address'] : [];
            $clientHints = \is_array($params['client_hints'] ?? null) ? $params['client_hints'] : [];
            // Allow top-level money fields as client hints for rejection tests.
            foreach (['shipping_amount', 'shipping_amount_minor', 'tax_amount', 'tax_amount_minor', 'grand_total', 'grand_total_minor'] as $moneyKey) {
                if (\array_key_exists($moneyKey, $params) && !\array_key_exists($moneyKey, $clientHints)) {
                    $clientHints[$moneyKey] = $params[$moneyKey];
                }
            }
            foreach (['lines', 'scope', 'website_id', 'store_id', 'currency', 'config_version', 'customer_id'] as $forgedFact) {
                if (\array_key_exists($forgedFact, $params)) {
                    $clientHints[$forgedFact] = $params[$forgedFact];
                }
            }

            $scopeIdentity = $this->currentScope();
            $customerId = $this->currentCustomerId();
            $guestToken = $customerId === null
                ? trim((string)Cookie::get(CartV2Service::GUEST_TOKEN_COOKIE))
                : null;
            $cart = $this->cartSnapshots()->freeze($scopeIdentity, $guestToken, $customerId);
            $currency = strtoupper(trim((string)($cart['currency'] ?? '')));
            $runtimeCurrency = strtoupper(trim(RequestContext::getWelineUserCurrency()));
            if ($runtimeCurrency !== '' && $currency !== $runtimeCurrency) {
                throw new CheckoutV2ConflictException(
                    'checkout_cart_currency_conflict',
                    __('购物车币种与当前请求币种不一致'),
                    ['cart_currency' => $currency, 'runtime_currency' => $runtimeCurrency],
                );
            }
            $scope = $scopeIdentity->toArray() + [
                'store_id' => RequestContext::getWelineStoreId(),
                'channel_id' => RequestContext::getWelineChannelId(),
            ];
            $payload = $this->checkoutGroupSubmitService->freezeAndQuote(
                lines: $cart['lines'],
                address: $address,
                scope: $scope,
                serviceCode: (string)($params['service_code'] ?? ''),
                currency: $currency,
                configVersion: '',
                clientHints: $clientHints,
                customerId: $customerId,
                cartHash: (string)$cart['cart_hash'],
            );

            return [
                'success' => true,
                'quote_token' => (string)($payload['quote_token'] ?? ''),
                'config_version' => (string)($payload['config_version'] ?? ''),
                'currency' => (string)($payload['currency'] ?? ''),
                'data' => $payload,
            ];
        } catch (CartV2ConflictException|CheckoutV2ConflictException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode(),
                'context' => $e->context(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'checkout_freeze_failed',
            ];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function submitV2(array $params): array
    {
        try {
            $clientHints = \is_array($params['client_hints'] ?? null) ? $params['client_hints'] : [];
            foreach (['shipping_amount', 'shipping_amount_minor', 'tax_amount', 'tax_amount_minor', 'grand_total', 'grand_total_minor'] as $moneyKey) {
                if (\array_key_exists($moneyKey, $params) && !\array_key_exists($moneyKey, $clientHints)) {
                    $clientHints[$moneyKey] = $params[$moneyKey];
                }
            }
            $expectedConfig = \array_key_exists('expected_config_version', $params)
                ? (string)$params['expected_config_version']
                : null;
            $expectedTaxHash = \array_key_exists('expected_tax_rule_set_hash', $params)
                ? (string)$params['expected_tax_rule_set_hash']
                : null;
            $result = $this->checkoutGroupSubmitService->submit(
                quoteToken: (string)($params['quote_token'] ?? ''),
                idempotencyKey: (string)($params['idempotency_key'] ?? ('idem_' . bin2hex(random_bytes(8)))),
                clientHints: $clientHints,
                customerId: $this->currentCustomerId(),
                expectedConfigVersion: $expectedConfig,
                expectedTaxRuleSetHash: $expectedTaxHash,
            );

            // The Order/CheckoutSession transaction is already committed at
            // this point. Cart cleanup is a best-effort, identity-bound
            // follow-up and must never turn a successfully created order into
            // a client-visible submit failure.
            try {
                w_query('cart', 'clearV2');
            } catch (\Throwable) {
            }

            return [
                'success' => true,
                'checkout_group_uuid' => $result->checkoutGroupUuid,
                'order_uuids' => $result->orderUuids,
                'replayed' => $result->replayed,
                'data' => $result->toArray(),
            ];
        } catch (CheckoutV2ConflictException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode(),
                'context' => $e->context(),
            ];
        } catch (\Weline\Order\Api\OrderFacadeConflictException $e) {
            // OrderFacade 冲突（idempotency hash / writer gate 等）原样透出错误码
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode(),
                'context' => $e->context(),
            ];
        } catch (\Weline\Inventory\Api\InventoryConflictException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode(),
                'context' => $e->context(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'checkout_submit_v2_failed',
            ];
        }
    }

    private function currentScope(): ScopeIdentity
    {
        $scope = RequestContext::scopeIdentity();
        if (!$scope instanceof ScopeIdentity || $scope->isGlobal()) {
            throw new CheckoutV2ConflictException(
                'checkout_scope_unavailable',
                __('当前请求没有可结账的 Storefront Scope'),
            );
        }

        return $scope;
    }

    private function currentCustomerId(): ?int
    {
        $identity = $this->customerAccounts()->current();
        $customerId = (int)($identity?->getId() ?? 0);

        return $customerId > 0 ? $customerId : null;
    }

    private function cartSnapshots(): CheckoutCartSnapshotInterface
    {
        if ($this->checkoutCartSnapshots instanceof CheckoutCartSnapshotInterface) {
            return $this->checkoutCartSnapshots;
        }
        $resolved = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(CheckoutCartSnapshotInterface::class);
        if (!$resolved instanceof CheckoutCartSnapshotInterface) {
            throw new \RuntimeException('checkout_cart_snapshot_provider_missing');
        }
        return $this->checkoutCartSnapshots = $resolved;
    }

    private function customerAccounts(): CustomerAccountFacadeInterface
    {
        if ($this->customerAccounts instanceof CustomerAccountFacadeInterface) {
            return $this->customerAccounts;
        }
        $resolved = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(CustomerAccountFacadeInterface::class);
        if (!$resolved instanceof CustomerAccountFacadeInterface) {
            throw new \RuntimeException('checkout_customer_account_provider_missing');
        }
        return $this->customerAccounts = $resolved;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function getData(array $params): array
    {
        $identity = $this->resolveCheckoutIdentity($params);
        $shippingAddress = \is_array($params['shipping_address'] ?? null)
            ? $params['shipping_address']
            : [];

        $cart = $this->loadCartSummary();
        $items = \is_array($cart['items'] ?? null) ? $cart['items'] : [];
        $currency = (string)($cart['currency'] ?? 'CNY');
        $shippingMethods = $this->loadShippingMethods($shippingAddress);
        $paymentMethods = $this->loadPaymentMethods($params + [
            'currency' => $currency,
            'amount' => (float)($cart['grand_total'] ?? $cart['subtotal'] ?? 0),
        ]);
        $html = $this->htmlRenderer();

        return $this->ok((string)__('结账信息已加载'), [
            'currency' => $currency,
            'identity' => [
                'checkout_mode' => (string)($identity['checkout_mode'] ?? 'guest'),
                'is_guest_checkout' => !empty($identity['is_guest_checkout']),
                'guest_allowed' => !empty($identity['guest_allowed']),
                'customer_allowed' => !empty($identity['customer_allowed']),
                'requires_guest_email' => !empty($identity['requires_guest_email']),
            ],
            'cart' => [
                'subtotal' => (float)($cart['subtotal'] ?? 0),
                'grand_total' => (float)($cart['grand_total'] ?? $cart['subtotal'] ?? 0),
                'currency' => $currency,
                'is_empty' => (bool)($cart['is_empty'] ?? $items === []),
                'item_count' => (int)($cart['item_count'] ?? \count($items)),
            ],
            'items' => $items,
            'shipping_methods' => $shippingMethods,
            'payment_methods' => $paymentMethods,
            // P2E-003：服务端 HTML；JS 只注入，不 createElement 拼商品/选项 DOM
            'items_html' => $html->renderItems($items, $currency, (string)__('购物车为空，请先加入商品。')),
            'shipping_methods_html' => $html->renderMethodOptions(
                $shippingMethods,
                'shipping_method',
                $currency,
                (string)__('暂无可用配送方式。'),
                showPrice: true,
            ),
            'payment_methods_html' => $html->renderMethodOptions(
                $paymentMethods,
                'payment_method',
                $currency,
                (string)__('暂无可用支付方式。'),
                showPrice: false,
            ),
        ]);
    }

    private function htmlRenderer(): \Weline\Checkout\Service\CheckoutHtmlRenderer
    {
        return \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Checkout\Service\CheckoutHtmlRenderer::class
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function placeOrder(array $params): array
    {
        $identity = $this->resolveCheckoutIdentity($params);
        if (!empty($identity['is_guest_checkout'])) {
            $this->checkoutIdentityService->validateGuestCheckout($identity, $params);
        }

        $shippingAddress = \is_array($params['shipping_address'] ?? null) ? $params['shipping_address'] : [];
        $guestEmail = (string)($identity['guest_email'] ?? '');
        if ($guestEmail !== '' && trim((string)($shippingAddress['email'] ?? '')) === '') {
            $shippingAddress['email'] = $guestEmail;
        }

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

        $currency = (string)($cart['currency'] ?? 'CNY');
        $sellability = $this->assertCartItemsSellable($items, $params + ['currency' => $currency]);
        if (($sellability['ok'] ?? true) === false) {
            $errorCode = (string)($sellability['error_code'] ?? 'price_not_sellable');

            return [
                'success' => false,
                'message' => (string)($sellability['message'] ?? __('该商品暂不可售。')),
                'error_code' => $errorCode,
                'code' => $errorCode,
                'error_detail' => \is_array($sellability['detail'] ?? null) ? $sellability['detail'] : [],
            ];
        }

        $shippingAmount = $this->resolveShippingAmount($shippingMethod, $shippingAddress);
        $orderItems = [];
        foreach ($items as $item) {
            $qty = (float)($item['qty'] ?? $item['quantity'] ?? 1);
            $price = (float)($item['price'] ?? 0);
            $orderItems[] = [
                'product_id' => (int)($item['product_id'] ?? $item['id'] ?? 0),
                'offer_id' => (int)($item['offer_id'] ?? 0),
                'product_name' => (string)($item['name'] ?? $item['product_name'] ?? ''),
                'sku' => (string)($item['sku'] ?? ''),
                'quantity' => $qty,
                'price' => $price,
                'row_total' => (float)($item['row_total'] ?? ($qty * $price)),
            ];
        }

        $order = $this->checkoutService->createOrder([
            'customer_id' => !empty($identity['is_guest_checkout']) ? 0 : max(0, (int)$identity['customer_id']),
            'authenticated_customer_id' => max(0, (int)($identity['authenticated_customer_id'] ?? 0)),
            'checkout_mode' => (string)($identity['checkout_mode'] ?? 'guest'),
            'is_guest_checkout' => !empty($identity['is_guest_checkout']),
            'guest_email' => $guestEmail,
            'guest_allowed' => true,
            'items' => $orderItems,
            'shipping_address' => $shippingAddress,
            'billing_address' => $shippingAddress,
            'shipping_method' => $shippingMethod,
            'shipping_amount' => $shippingAmount,
            'tax_amount' => 0.0,
            'discount_amount' => 0.0,
            'payment_method' => $paymentMethod,
            'currency' => $currency,
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
        return ObjectManager::getInstance(\Weline\Checkout\Service\CheckoutPageViewModel::class)
            ->currentCart();
    }

    /**
     * Price cleared / missing 可售闸门（复用 Cart API；无 Offer 放行）。
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $scopeParams
     * @return array{ok:bool,error_code?:string,message?:string,detail?:array<string,mixed>}
     */
    private function assertCartItemsSellable(array $items, array $scopeParams): array
    {
        /** @var \Weline\Cart\Api\CartPriceSellabilityGate $gate */
        $gate = \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Cart\Api\CartPriceSellabilityGate::class
        );

        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $result = $gate->assertOrAllow($scopeParams + [
                'product_id' => (int)($item['product_id'] ?? $item['id'] ?? 0),
                'offer_id' => (int)($item['offer_id'] ?? 0),
            ]);
            if (($result['ok'] ?? true) === false) {
                return $result;
            }
        }

        return ['ok' => true];
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

    private function resolveCheckoutIdentity(array $params = []): array
    {
        $session = $this->sessionFactory->createFrontendSession();
        $authenticatedCustomerId = 0;
        try {
            if ($session->isLoggedIn()) {
                $authenticatedCustomerId = max(0, (int)($session->getUserId() ?? 0));
            }
        } catch (\Throwable) {
            $authenticatedCustomerId = 0;
        }

        $shippingAddress = \is_array($params['shipping_address'] ?? null) ? $params['shipping_address'] : [];

        return $this->checkoutIdentityService->resolve([
            'authenticated_customer_id' => $authenticatedCustomerId,
            'customer_id' => $authenticatedCustomerId,
            'guest_allowed' => true,
            'customer_allowed' => $authenticatedCustomerId > 0,
            'checkout_mode' => $params['checkout_mode']
                ?? ($authenticatedCustomerId > 0
                    ? CheckoutIdentityService::MODE_CUSTOMER
                    : CheckoutIdentityService::MODE_GUEST),
            'guest_email' => $params['guest_email']
                ?? $params['email']
                ?? ($shippingAddress['email'] ?? ''),
            'requires_guest_email' => $params['requires_guest_email'] ?? true,
        ]);
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
            'description' => (string)__('聚合购物车、配送与支付，供结账页 Weline.Api.resource(\'checkout\') 使用。默认支持匿名结账。'),
            'module' => 'Weline_Checkout',
            'operations' => [
                [
                    'name' => 'getData',
                    'frontend' => true,
                    'auth' => 'any',
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
                    'auth' => 'any',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'shipping_address' => ['type' => 'array', 'required' => true],
                        'shipping_method' => ['type' => 'string', 'required' => true],
                        'payment_method' => ['type' => 'string', 'required' => true],
                        'guest_email' => ['type' => 'string', 'required' => false],
                        'checkout_mode' => ['type' => 'string', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Create order from cart and start payment',
                ],
                [
                    'name' => 'createOrder',
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'shipping_address' => ['type' => 'array', 'required' => true],
                        'shipping_method' => ['type' => 'string', 'required' => true],
                        'payment_method' => ['type' => 'string', 'required' => true],
                        'guest_email' => ['type' => 'string', 'required' => false],
                        'checkout_mode' => ['type' => 'string', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Alias of placeOrder',
                ],
                [
                    'name' => 'freezeQuote',
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        'address' => ['type' => 'array', 'required' => false],
                        'service_code' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'client_hints' => ['type' => 'array', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Freeze the server-owned current Cart and create one Shipping Quote session',
                ],
                [
                    'name' => 'submitV2',
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'quote_token' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'idempotency_key' => ['type' => 'string', 'required' => false, 'max_length' => 128],
                        'expected_config_version' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'expected_tax_rule_set_hash' => ['type' => 'string', 'required' => false, 'max_length' => 128],
                        'client_hints' => ['type' => 'array', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Submit frozen Checkout V2 quote (config version must match)',
                ],
            ],
        ];
    }
}
