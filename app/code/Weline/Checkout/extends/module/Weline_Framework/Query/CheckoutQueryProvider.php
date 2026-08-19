<?php

declare(strict_types=1);

namespace Weline\Checkout\Extends\Module\Weline_Framework\Query;

use Weline\Cart\Api\CartScopeResolverInterface;
use Weline\Cart\Api\CheckoutCartSnapshotInterface;
use Weline\Cart\Service\CartV2ConflictException;
use Weline\Cart\Service\CartV2Service;
use Weline\Checkout\Service\CheckoutGroupSubmitService;
use Weline\Checkout\Service\CheckoutIdentityService;
use Weline\Checkout\Service\CheckoutOrderPaymentService;
use Weline\Checkout\Service\CheckoutPaymentRecoveryStateService;
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
use Weline\Order\Api\Data\CreateCheckoutGroupResult;
use Weline\Shipping\Model\DeliveryAddress;
use Weline\Shipping\Service\DeliveryAddressService;

/**
 * 前台结账 Facade：聚合购物车、配送、支付，供 Theme 结账页通过 Weline.Api.resource('checkout') 调用。
 */
class CheckoutQueryProvider implements QueryProviderInterface
{
    private ?CheckoutCartSnapshotInterface $checkoutCartSnapshots;

    private ?CustomerAccountFacadeInterface $customerAccounts;

    private ?CartScopeResolverInterface $cartScopeResolver;

    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly PaymentService $paymentService,
        private readonly SessionFactory $sessionFactory,
        private readonly CheckoutIdentityService $checkoutIdentityService,
        private readonly CheckoutGroupSubmitService $checkoutGroupSubmitService,
        private readonly CheckoutOrderPaymentService $checkoutOrderPaymentService,
        private readonly CheckoutPaymentRecoveryStateService $paymentRecoveryState,
        ?CheckoutCartSnapshotInterface $checkoutCartSnapshots = null,
        ?CustomerAccountFacadeInterface $customerAccounts = null,
        ?CartScopeResolverInterface $cartScopeResolver = null,
    ) {
        $this->checkoutCartSnapshots = $checkoutCartSnapshots;
        $this->customerAccounts = $customerAccounts;
        $this->cartScopeResolver = $cartScopeResolver;
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
            'resumePaymentV2' => $this->resumePaymentV2($params),
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
            $guestToken = null;
            if ($customerId === null) {
                $guestToken = trim((string)($params['guest_token'] ?? ''));
                if ($guestToken === '') {
                    $guestToken = trim((string)Cookie::get(CartV2Service::GUEST_TOKEN_COOKIE));
                }
            }
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
            $idempotencyKey = trim((string)($params['idempotency_key'] ?? ''));
            if ($idempotencyKey === '') {
                $idempotencyKey = 'idem_' . bin2hex(random_bytes(8));
            }
            $quoteToken = trim((string)($params['quote_token'] ?? ''));
            $result = $this->checkoutGroupSubmitService->submit(
                quoteToken: $quoteToken,
                idempotencyKey: $idempotencyKey,
                clientHints: $clientHints,
                customerId: $this->currentCustomerId(),
                expectedConfigVersion: $expectedConfig,
                expectedTaxRuleSetHash: $expectedTaxHash,
            );
            $payment = $this->paymentRecoveryState->get($quoteToken, $idempotencyKey);
            if (!is_array($payment)) {
                $payment = $this->payCreatedOrders(
                    $result->orderUuids,
                    (string)($params['payment_method'] ?? ''),
                    $idempotencyKey,
                    [
                        'country_code' => (string)($params['country_code'] ?? ''),
                        'locale' => (string)($params['locale'] ?? ''),
                        'environment' => (string)($params['environment'] ?? 'sandbox'),
                    ],
                );
                $payment = $this->recordPaymentState($quoteToken, $idempotencyKey, $payment);
            }

            // The Order/CheckoutSession transaction is already committed at
            // this point. Cart cleanup is a best-effort, identity-bound
            // follow-up and must never turn a successfully created order into
            // a client-visible submit failure.
            try {
                w_query('cart', 'clearV2', [
                    'guest_token' => trim((string)($params['guest_token'] ?? '')),
                ]);
            } catch (\Throwable) {
            }

            return $this->createdCheckoutResponse($result, $quoteToken, $payment);
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

    /**
     * Resume payment for an already-submitted quote. Replaying the original
     * order idempotency key returns the same group; a fresh payment key only
     * creates a new payment attempt when the order is still unpaid.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function resumePaymentV2(array $params): array
    {
        try {
            $quoteToken = trim((string)($params['quote_token'] ?? ''));
            $idempotencyKey = trim((string)($params['idempotency_key'] ?? ''));
            $paymentIdempotencyKey = trim((string)($params['payment_idempotency_key'] ?? ''));
            if ($quoteToken === '' || $idempotencyKey === '') {
                throw new CheckoutV2ConflictException(
                    CheckoutGroupSubmitService::ERROR_QUOTE_TOKEN,
                    __('支付恢复凭据不完整，请重新进入结账流程。'),
                );
            }
            if ($paymentIdempotencyKey === '' || strlen($paymentIdempotencyKey) > 128) {
                throw new CheckoutV2ConflictException(
                    CheckoutGroupSubmitService::ERROR_QUOTE_TOKEN,
                    __('支付重试幂等键长度须为 1..128。'),
                );
            }

            $result = $this->checkoutGroupSubmitService->submit(
                quoteToken: $quoteToken,
                idempotencyKey: $idempotencyKey,
                customerId: $this->currentCustomerId(),
            );
            $existingPayment = $this->paymentRecoveryState->get($quoteToken, $idempotencyKey);
            if (is_array($existingPayment) && !$this->paymentRecoveryState->canRetry($quoteToken, $idempotencyKey)) {
                return $this->createdCheckoutResponse($result, $quoteToken, $existingPayment);
            }
            if (!is_array($existingPayment)) {
                return [
                    'success' => false,
                    'message' => (string)__('找不到可恢复的支付状态，请从订单列表继续。'),
                    'error_code' => 'checkout_payment_recovery_state_missing',
                ];
            }
            if (!$this->paymentRecoveryState->beginRetry(
                $quoteToken,
                $idempotencyKey,
                $paymentIdempotencyKey,
            )) {
                $claimedPayment = $this->paymentRecoveryState->get($quoteToken, $idempotencyKey);

                return is_array($claimedPayment)
                    ? $this->createdCheckoutResponse($result, $quoteToken, $claimedPayment)
                    : [
                        'success' => false,
                        'message' => (string)__('无法声明该支付重试，请从订单列表重试。'),
                        'error_code' => 'checkout_payment_retry_claim_failed',
                    ];
            }
            $payment = $this->payCreatedOrders(
                $result->orderUuids,
                (string)($params['payment_method'] ?? ''),
                $paymentIdempotencyKey,
                [
                    'country_code' => (string)($params['country_code'] ?? ''),
                    'locale' => (string)($params['locale'] ?? ''),
                    'environment' => (string)($params['environment'] ?? 'sandbox'),
                ],
            );
            $payment = $this->recordPaymentState($quoteToken, $idempotencyKey, $payment);

            return $this->createdCheckoutResponse($result, $quoteToken, $payment);
        } catch (CheckoutV2ConflictException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode(),
                'context' => $e->context(),
            ];
        } catch (\Weline\Order\Api\OrderFacadeConflictException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode(),
                'context' => $e->context(),
            ];
        } catch (\Throwable) {
            return [
                'success' => false,
                'message' => (string)__('无法恢复该支付，请从订单列表重试。'),
                'error_code' => 'checkout_payment_resume_failed',
            ];
        }
    }

    /**
     * @param list<string> $orderUuids
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function payCreatedOrders(
        array $orderUuids,
        string $paymentMethod,
        string $paymentIdempotencyKey,
        array $context,
    ): array {
        try {
            return $this->checkoutOrderPaymentService->pay(
                $orderUuids,
                $paymentMethod,
                $paymentIdempotencyKey,
                $context,
            );
        } catch (\Throwable) {
            return [
                'paid' => false,
                'outcome' => 'failed',
                'status' => 'failed',
                'requires_action' => false,
                'recoverable' => true,
                'redirect_url' => null,
                'error_code' => 'checkout_payment_failed',
                'message' => (string)__('订单已创建，但支付未完成。请重试支付。'),
                'transactions' => [],
            ];
        }
    }

    /**
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    private function createdCheckoutResponse(
        CreateCheckoutGroupResult $result,
        string $quoteToken,
        array $payment,
    ): array {
        return [
            'success' => true,
            'checkout_group_uuid' => $result->checkoutGroupUuid,
            'order_uuids' => $result->orderUuids,
            'checkout_token' => $quoteToken,
            'replayed' => $result->replayed,
            'payment' => $payment,
            'data' => $result->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    private function recordPaymentState(
        string $quoteToken,
        string $orderIdempotencyKey,
        array $payment,
    ): array {
        try {
            $this->paymentRecoveryState->record($quoteToken, $orderIdempotencyKey, $payment);

            return $payment;
        } catch (\Throwable) {
            if (($payment['outcome'] ?? '') === 'paid') {
                return $payment;
            }
            $payment['recoverable'] = false;
            $payment['error_code'] = 'checkout_payment_recovery_state_store_failed';
            $payment['message'] = (string)__('订单已创建，但暂时无法恢复支付，请联系支持。');

            return $payment;
        }
    }

    private function currentScope(): ScopeIdentity
    {
        $scope = RequestContext::scopeIdentity();
        if ($scope instanceof ScopeIdentity && !$scope->isGlobal()) {
            return $scope;
        }

        // Binary API requests may not carry the HTML request's installed
        // RequestContext. Consume Cart's published server-owned resolver
        // contract without coupling Checkout to Cart's concrete Service.
        return $this->cartScopeResolver()->fromParams([]);
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

    private function cartScopeResolver(): CartScopeResolverInterface
    {
        if ($this->cartScopeResolver instanceof CartScopeResolverInterface) {
            return $this->cartScopeResolver;
        }
        $resolved = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(CartScopeResolverInterface::class);
        if (!$resolved instanceof CartScopeResolverInterface) {
            throw new \RuntimeException('checkout_cart_scope_resolver_missing');
        }

        return $this->cartScopeResolver = $resolved;
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

        $cart = $this->loadCartSummary($params);
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
            'default_shipping_address' => $this->customerAddressPrefill(),
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

    /**
     * Publish the authenticated customer's default delivery address as a
     * checkout-only projection. Missing address data must never prevent the
     * rest of checkout from loading.
     *
     * @return array<string, string|int>
     */
    private function customerAddressPrefill(): array
    {
        try {
            $identity = $this->customerAccounts()->current();
            if ($identity === null || $identity->getId() <= 0) {
                return [];
            }

            $address = ObjectManager::getInstance(DeliveryAddressService::class)
                ->getDefaultByCustomer($identity->getId());
            if (!$address instanceof DeliveryAddress) {
                return ['email' => trim($identity->getEmail())];
            }

            return [
                'address_id' => (int)$address->getData(DeliveryAddress::schema_fields_ID),
                'name' => trim((string)$address->getData(DeliveryAddress::schema_fields_CONTACT_NAME)),
                'phone' => trim((string)$address->getData(DeliveryAddress::schema_fields_CONTACT_PHONE)),
                'email' => trim($identity->getEmail()),
                'country_code' => strtoupper(trim((string)$address->getData(DeliveryAddress::schema_fields_COUNTRY_CODE))),
                'province' => trim((string)$address->getData(DeliveryAddress::schema_fields_PROVINCE)),
                'city' => trim((string)$address->getData(DeliveryAddress::schema_fields_CITY)),
                'address1' => trim((string)$address->getData(DeliveryAddress::schema_fields_STREET)),
                'postal_code' => trim((string)$address->getData(DeliveryAddress::schema_fields_POSTAL_CODE)),
            ];
        } catch (\Throwable) {
            return [];
        }
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

        $cart = $this->loadCartSummary($params);
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
    private function loadCartSummary(array $params = []): array
    {
        return ObjectManager::getInstance(\Weline\Checkout\Service\CheckoutPageViewModel::class)
            ->currentCart(trim((string)($params['guest_token'] ?? '')));
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
                        'guest_token' => ['type' => 'string', 'required' => false, 'max_length' => 64],
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
                        'guest_token' => ['type' => 'string', 'required' => false, 'max_length' => 64],
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
                        'guest_token' => ['type' => 'string', 'required' => false, 'max_length' => 64],
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
                        'guest_token' => ['type' => 'string', 'required' => false, 'max_length' => 64],
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
                        'payment_method' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'idempotency_key' => ['type' => 'string', 'required' => false, 'max_length' => 128],
                        'country_code' => ['type' => 'string', 'required' => false, 'max_length' => 8],
                        'expected_config_version' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'expected_tax_rule_set_hash' => ['type' => 'string', 'required' => false, 'max_length' => 128],
                        'client_hints' => ['type' => 'array', 'required' => false],
                        'guest_token' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Submit frozen Checkout V2 quote (config version must match)',
                ],
                [
                    'name' => 'resumePaymentV2',
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'quote_token' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'idempotency_key' => ['type' => 'string', 'required' => true, 'max_length' => 128],
                        'payment_idempotency_key' => ['type' => 'string', 'required' => true, 'max_length' => 128],
                        'payment_method' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'country_code' => ['type' => 'string', 'required' => false, 'max_length' => 8],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Retry payment for the same already-submitted Checkout V2 order group',
                ],
            ],
        ];
    }
}
