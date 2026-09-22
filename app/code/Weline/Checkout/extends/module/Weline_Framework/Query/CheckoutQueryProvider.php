<?php

declare(strict_types=1);

namespace Weline\Checkout\Extends\Module\Weline_Framework\Query;

use Weline\Cart\Api\CartScopeResolverInterface;
use Weline\Cart\Api\CheckoutCartSnapshotInterface;
use Weline\Cart\Service\CartConflictException;
use Weline\Cart\Service\CartService;
use Weline\Checkout\Service\CheckoutSessionFaultRecorder;
use Weline\Checkout\Service\CheckoutDeliveryContextService;
use Weline\Checkout\Service\CheckoutEntry;
use Weline\Checkout\Service\CheckoutQuoteLineWeightResolver;
use Weline\Checkout\Service\CheckoutShippingAddressResolver;
use Weline\Checkout\Service\CheckoutShippingUnavailablePresenter;
use Weline\Checkout\Service\ExpressCheckoutFlowService;
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
use Weline\Marketing\Service\MarketingCheckoutCouponSession;
use Weline\Order\Api\Data\CreateCheckoutGroupResult;
use Weline\Shipping\Model\DeliveryAddress;
use Weline\Shipping\Service\DeliveryAddressService;
use Weline\Shipping\Service\ShippingIncotermService;

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
        private readonly CheckoutDeliveryContextService $deliveryContextService,
        private readonly CheckoutShippingAddressResolver $shippingAddressResolver,
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
            'getDeliveryContext' => $this->deliveryContext($params),
            'getDeliveryCaptchaChallenge' => $this->deliveryCaptchaChallenge($params),
            'renderDeliveryAddressWidget' => $this->renderDeliveryAddressWidget($params),
            'setDeliveryCountry' => $this->deliveryCountry($params),
            'selectDeliveryAddress' => $this->deliverySelect($params),
            'saveDeliveryAddress' => $this->deliverySave($params),
            'clearDeliveryAddresses' => $this->deliveryClear($params),
            'placeOrder', 'createOrder' => $this->placeOrder($params),
            'freezeQuote' => $this->freezeQuote($params),
            'submitV2' => $this->submitV2($params),
            'resumePaymentV2' => $this->resumePaymentV2($params),
            'adoptContinuePaySession' => $this->adoptContinuePaySession($params),
            'releaseContinuePaySession' => $this->releaseContinuePaySession($params),
            'amendUnpaidCheckoutAddress' => $this->amendUnpaidCheckoutAddress($params),
            'listCheckoutSessionBuckets' => $this->listCheckoutSessionBuckets($params),
            'startExpressCheckout' => $this->startExpressCheckout($params),
            'getExpressReview' => $this->getExpressReview($params),
            'confirmExpressCheckout' => $this->confirmExpressCheckout($params),
            'cancelExpressCheckout' => $this->cancelExpressCheckout($params),
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
            $address = $this->shippingAddressResolver->resolve($address, $params);
            $clientHints = \is_array($params['client_hints'] ?? null) ? $params['client_hints'] : [];
            // Allow top-level money fields as client hints for rejection tests.
            foreach (['shipping_amount', 'shipping_amount_minor', 'tax_amount', 'tax_amount_minor', 'discount_amount', 'discount_amount_minor', 'grand_total', 'grand_total_minor'] as $moneyKey) {
                if (\array_key_exists($moneyKey, $params) && !\array_key_exists($moneyKey, $clientHints)) {
                    $clientHints[$moneyKey] = $params[$moneyKey];
                }
            }
            foreach (['lines', 'scope', 'website_id', 'store_id', 'currency', 'config_version', 'customer_id'] as $forgedFact) {
                if (\array_key_exists($forgedFact, $params)) {
                    $clientHints[$forgedFact] = $params[$forgedFact];
                }
            }
            if (\is_array($params['tax_identity'] ?? null)) {
                $clientHints['tax_identity'] = $params['tax_identity'];
            }
            if (\is_array($params['buyer_tax_identity'] ?? null)) {
                $clientHints['buyer_tax_identity'] = $params['buyer_tax_identity'];
                if (!isset($clientHints['tax_identity'])) {
                    $clientHints['tax_identity'] = $params['buyer_tax_identity'];
                }
            }

            $scopeIdentity = $this->currentScope();
            $customerId = $this->currentCustomerId();
            $guestToken = null;
            if ($customerId === null) {
                $guestToken = trim((string)($params['guest_token'] ?? ''));
                if ($guestToken === '') {
                    $guestToken = trim((string)Cookie::get(CartService::GUEST_TOKEN_COOKIE));
                }
            }
            $cartType = $this->resolveCartTypePreference($params);
            $cart = $this->cartSnapshots()->freeze($scopeIdentity, $guestToken, $customerId, $cartType);
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
                couponCode: $this->resolveFreezeCouponCode($params + ['cart_type' => $cartType]),
                paymentMethod: trim((string)($params['payment_method'] ?? '')) ?: null,
                billingAddress: \is_array($params['billing_address'] ?? null) ? $params['billing_address'] : null,
                cartType: strtolower(trim((string)($cart['cart_type'] ?? $cartType))) ?: $cartType,
                existingQuoteToken: trim((string)($params['quote_token'] ?? '')),
                cartFingerprint: $this->checkoutFingerprint($params),
                checkoutEntry: $this->resolveCheckoutEntry($params),
            );
            $frozenToken = (string)($payload['quote_token'] ?? '');
            $this->recordCheckoutFaultSafe(function () use ($frozenToken): void {
                $this->faultRecorder()->clear($frozenToken);
            });

            return [
                'success' => true,
                'quote_token' => $frozenToken,
                'config_version' => (string)($payload['config_version'] ?? ''),
                'currency' => (string)($payload['currency'] ?? ''),
                'data' => $payload,
            ];
        } catch (CartConflictException|CheckoutV2ConflictException $e) {
            $this->recordNamedFault(
                $params,
                'freeze_failed',
                $e->getMessage(),
            );
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode(),
                'context' => $e->context(),
            ];
        } catch (\Throwable $e) {
            $this->recordNamedFault($params, 'freeze_failed', $e->getMessage());
            $msg = $e->getMessage();
            $code = 'checkout_freeze_failed';
            if (str_contains($msg, 'buyer_tax') || $msg === 'buyer_tax_vat_invalid' || $msg === 'buyer_tax_vat_required') {
                $code = $msg !== '' ? $msg : 'buyer_tax_vat_invalid';
            }
            return [
                'success' => false,
                'message' => $msg !== '' ? $msg : (string)__('结账报价失败'),
                'error_code' => $code,
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
            foreach (['shipping_amount', 'shipping_amount_minor', 'tax_amount', 'tax_amount_minor', 'discount_amount', 'discount_amount_minor', 'grand_total', 'grand_total_minor'] as $moneyKey) {
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
                paymentMethod: trim((string)($params['payment_method'] ?? '')) ?: null,
                b2bCreditApplyMinor: array_key_exists('b2b_credit_apply_minor', $params)
                    ? max(0, (int)$params['b2b_credit_apply_minor'])
                    : null,
            );
            $payment = $this->paymentRecoveryState->get($quoteToken, $idempotencyKey);
            if (!is_array($payment)) {
                $payContext = [
                    'country_code' => (string)($params['country_code'] ?? ''),
                    'locale' => (string)($params['locale'] ?? ''),
                    'quote_token' => $quoteToken,
                    'checkout_token' => $quoteToken,
                ];
                $explicitEnvironment = strtolower(trim((string)($params['environment'] ?? '')));
                if ($explicitEnvironment === 'sandbox' || $explicitEnvironment === 'live') {
                    $payContext['environment'] = $explicitEnvironment;
                }
                if (!empty($params['express_checkout'])) {
                    $payContext['express_checkout'] = true;
                    $payContext['metadata'] = ['express_checkout' => true];
                }
                $guestTokenForPay = trim((string) ($params['guest_token'] ?? ''));
                if ($guestTokenForPay === '') {
                    $guestTokenForPay = trim((string) Cookie::get(CartService::GUEST_TOKEN_COOKIE));
                }
                if ($guestTokenForPay !== '') {
                    $payContext['guest_token'] = $guestTokenForPay;
                }
                if (array_key_exists('requires_shipping', $params)) {
                    $payContext['requires_shipping'] = (bool) $params['requires_shipping'];
                }
                $session = $this->checkoutGroupSubmitService->getSession($quoteToken);
                $deposit = is_array($session['deposit'] ?? null) ? $session['deposit'] : [];
                $cartType = strtolower(trim((string)($session['cart_type'] ?? '')));
                if ($cartType === 'tob' || $deposit !== []) {
                    $payContext['purpose'] = 'deposit';
                    $payContext['hang_purpose'] = 'deposit';
                    if (array_key_exists('b2b_credit_cash_deposit_minor', $deposit)) {
                        $payContext['deposit_amount_minor'] = max(0, (int)$deposit['b2b_credit_cash_deposit_minor']);
                    } elseif ((int)($deposit['deposit_amount_minor'] ?? 0) > 0) {
                        $payContext['deposit_amount_minor'] = (int)$deposit['deposit_amount_minor'];
                    }
                    if ((int)($deposit['balance_amount_minor'] ?? 0) > 0) {
                        $payContext['balance_amount_minor'] = (int)$deposit['balance_amount_minor'];
                    }
                }
                $payment = $this->payCreatedOrders(
                    $result->orderUuids,
                    (string)($params['payment_method'] ?? ''),
                    $idempotencyKey,
                    $payContext,
                );
                $payment = $this->recordPaymentState($quoteToken, $idempotencyKey, $payment);
            }

            // The Order/CheckoutSession transaction is already committed at
            // this point. Cart cleanup is a best-effort, identity-bound
            // follow-up and must never turn a successfully created order into
            // a client-visible submit failure.
            try {
                w_query('cart', 'clear', [
                    'guest_token' => trim((string)($params['guest_token'] ?? '')),
                ]);
            } catch (\Throwable) {
            }

            // 真下单成功：本单配送地址转化为结账地址（点选收货簿时未打标）。
            try {
                $this->deliveryContextService->promoteSelectedAddressToCheckout();
            } catch (\Throwable) {
            }

            return $this->createdCheckoutResponse($result, $quoteToken, $payment);
        } catch (CheckoutV2ConflictException $e) {
            $this->reportCheckoutPixelIncident($e->errorCode(), $e->getMessage());
            $this->recordNamedFault($params, 'submit_failed', $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode(),
                'context' => $e->context(),
            ];
        } catch (\Weline\Order\Api\OrderFacadeConflictException $e) {
            // OrderFacade 冲突（idempotency hash / writer gate 等）原样透出错误码
            $this->reportCheckoutPixelIncident($e->errorCode(), $e->getMessage());
            $this->recordNamedFault($params, 'submit_failed', $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode(),
                'context' => $e->context(),
            ];
        } catch (\Weline\Inventory\Api\InventoryConflictException $e) {
            $this->reportCheckoutPixelIncident($e->errorCode(), $e->getMessage());
            $this->recordNamedFault($params, 'submit_failed', $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode(),
                'context' => $e->context(),
            ];
        } catch (\Throwable $e) {
            $this->reportCheckoutPixelIncident('checkout_submit_v2_failed', $e->getMessage());
            $this->recordNamedFault($params, 'submit_failed', $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'checkout_submit_v2_failed',
            ];
        }
    }

    private function reportCheckoutPixelIncident(string $errorCode, string $message): void
    {
        if (!class_exists(\Weline\Visitor\Service\PixelServerErrorReporter::class)) {
            return;
        }
        try {
            /** @var \Weline\Visitor\Service\PixelServerErrorReporter $reporter */
            $reporter = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Visitor\Service\PixelServerErrorReporter::class
            );
            $reporter->reportBusinessFailure($errorCode, $message, [
                'page_url' => 'checkout/submit',
            ]);
        } catch (\Throwable) {
        }
    }

    /**
     * Adopt unpaid order checkout session as continue-pay binding (not a CheckoutType).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function adoptContinuePaySession(array $params): array
    {
        /** @var \Weline\Checkout\Service\ContinuePayBindingService $binding */
        $binding = ObjectManager::getInstance(\Weline\Checkout\Service\ContinuePayBindingService::class);
        $prior = trim((string)($params['prior_quote_token'] ?? ''));
        if ($prior === '') {
            $cookiePrior = trim((string)Cookie::get('weline_checkout_session'));
            // 禁止把续付 token 当 browse prior。
            if ($cookiePrior !== '' && !str_starts_with(strtolower($cookiePrior), 'qt_cpay_')) {
                $prior = $cookiePrior;
            }
        }
        $params['prior_quote_token'] = $prior;

        return $binding->adopt($params, $this->currentCustomerId());
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function releaseContinuePaySession(array $params): array
    {
        /** @var \Weline\Checkout\Service\ContinuePayBindingService $binding */
        $binding = ObjectManager::getInstance(\Weline\Checkout\Service\ContinuePayBindingService::class);
        $bucketId = trim((string)($params['bucket_id'] ?? ''));

        return $binding->release($bucketId !== '' ? $bucketId : null);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function listCheckoutSessionBuckets(array $params): array
    {
        /** @var \Weline\Checkout\Service\ContinuePayBindingService $binding */
        $binding = ObjectManager::getInstance(\Weline\Checkout\Service\ContinuePayBindingService::class);
        $buckets = $binding->listBuckets();

        return [
            'success' => true,
            'buckets' => $buckets,
            'active_bucket_id' => $binding->getActiveBucketId(),
            'switcher_visible' => count($buckets) >= 2,
        ];
    }

    /**
     * Write-back shipping address on an unpaid order before resumePaymentV2.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function amendUnpaidCheckoutAddress(array $params): array
    {
        $orderUuid = trim((string)($params['order_uuid'] ?? ''));
        $quoteToken = trim((string)($params['quote_token'] ?? ''));
        if ($orderUuid === '' || $quoteToken === '') {
            return [
                'success' => false,
                'message' => (string)__('改址参数不完整'),
                'error_code' => 'amend_params_incomplete',
            ];
        }
        /** @var \Weline\Checkout\Service\CheckoutSessionAccessService $access */
        $access = ObjectManager::getInstance(\Weline\Checkout\Service\CheckoutSessionAccessService::class);
        if (!$access->canAccess($quoteToken, $orderUuid, $this->currentCustomerId())) {
            return [
                'success' => false,
                'message' => (string)__('无权修改该订单地址'),
                'error_code' => 'amend_access_denied',
            ];
        }
        /** @var \Weline\Order\Model\Order $order */
        $order = ObjectManager::getInstance(\Weline\Order\Model\Order::class);
        $order->clear()->where(\Weline\Order\Model\Order::schema_fields_ORDER_UUID, $orderUuid)->find()->fetch();
        if (!(int)$order->getData(\Weline\Order\Model\Order::schema_fields_ID)) {
            return [
                'success' => false,
                'message' => (string)__('订单不存在'),
                'error_code' => 'amend_order_missing',
            ];
        }
        $address = \is_array($params['address'] ?? null) ? $params['address'] : [];
        $options = [
            'service_code' => trim((string)($params['service_code'] ?? $params['shipping_method'] ?? '')),
            'currency' => trim((string)($params['currency'] ?? '')),
        ];
        if (\array_key_exists('coupon_code', $params)) {
            $options['coupon_code'] = strtoupper(trim((string)$params['coupon_code']));
        }
        if (\is_array($params['tax_identity'] ?? null)) {
            $options['tax_identity'] = $params['tax_identity'];
        }
        /** @var \Weline\Checkout\Service\ExpressUnpaidOrderAmend $amend */
        $amend = ObjectManager::getInstance(\Weline\Checkout\Service\ExpressUnpaidOrderAmend::class);
        $result = $amend->amend($order, $address, $options);
        if (!($result['ok'] ?? false)) {
            return [
                'success' => false,
                'message' => (string)($result['message'] ?? __('改址失败')),
                'error_code' => 'amend_failed',
            ];
        }

        // 改券/改运费后：废掉金额漂移的 pending 支付，下次续付按新 grand 重开。
        $authorityMinor = (int)($result['totals']['grand_total_minor'] ?? 0);
        if ($authorityMinor > 0) {
            try {
                /** @var \Weline\Checkout\Api\CheckoutSessionStoreInterface $sessions */
                $sessions = ObjectManager::getInstance(\Weline\Checkout\Api\CheckoutSessionStoreInterface::class);
                $raw = $sessions->get($quoteToken);
                $idem = is_array($raw) ? trim((string)($raw['idempotency_key'] ?? '')) : '';
                if ($idem !== '') {
                    $this->paymentRecoveryState->invalidatePendingIfAmountDrifted(
                        $quoteToken,
                        $idem,
                        $authorityMinor,
                        [$orderUuid],
                    );
                }
            } catch (\Throwable) {
            }
        }

        return [
            'success' => true,
            'address' => $result['address'] ?? $address,
            'totals' => $result['totals'] ?? [],
            'shipping_amount_minor' => $result['shipping_amount_minor'] ?? 0,
        ];
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
                // 续付改券/运费后订单权威金额变了，禁止回放旧 pending PayPal（否则会出现页 15.90、网关 16.69）。
                $authorityMinor = $this->resolveOrdersAuthorityAmountMinor($result->orderUuids);
                $this->paymentRecoveryState->invalidatePendingIfAmountDrifted(
                    $quoteToken,
                    $idempotencyKey,
                    $authorityMinor,
                    $result->orderUuids,
                );
                $existingPayment = $this->paymentRecoveryState->get($quoteToken, $idempotencyKey);
            }
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
            $retryContext = [
                'country_code' => (string)($params['country_code'] ?? ''),
                'locale' => (string)($params['locale'] ?? ''),
                'quote_token' => $quoteToken,
                'checkout_token' => $quoteToken,
            ];
            $explicitEnvironment = strtolower(trim((string)($params['environment'] ?? '')));
            if ($explicitEnvironment === 'sandbox' || $explicitEnvironment === 'live') {
                $retryContext['environment'] = $explicitEnvironment;
            }
            $payment = $this->payCreatedOrders(
                $result->orderUuids,
                (string)($params['payment_method'] ?? ''),
                $paymentIdempotencyKey,
                $retryContext,
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
     * Sum current unpaid-order money authority (post-amend coupon/shipping).
     *
     * @param list<string> $orderUuids
     */
    private function resolveOrdersAuthorityAmountMinor(array $orderUuids): int
    {
        $sum = 0;
        foreach ($orderUuids as $orderUuid) {
            $orderUuid = trim((string)$orderUuid);
            if ($orderUuid === '') {
                continue;
            }
            try {
                /** @var \Weline\Order\Model\Order $order */
                $order = ObjectManager::getInstance(\Weline\Order\Model\Order::class);
                $order->clear()
                    ->where(\Weline\Order\Model\Order::schema_fields_ORDER_UUID, $orderUuid)
                    ->find()
                    ->fetch();
                if (!(int)$order->getData(\Weline\Order\Model\Order::schema_fields_ID)) {
                    continue;
                }
                $moneyRaw = $order->getData(\Weline\Order\Model\Order::schema_fields_MONEY_SNAPSHOT_JSON);
                $money = [];
                if (is_string($moneyRaw) && $moneyRaw !== '') {
                    $decoded = json_decode($moneyRaw, true);
                    if (is_array($decoded)) {
                        $money = $decoded;
                    }
                } elseif (is_array($moneyRaw)) {
                    $money = $moneyRaw;
                }
                $minor = (int)($money['grand_total_minor'] ?? 0);
                if ($minor <= 0) {
                    $minor = (int)round(((float)$order->getData(\Weline\Order\Model\Order::schema_fields_GRAND_TOTAL)) * 100);
                }
                $sum += max(0, $minor);
            } catch (\Throwable) {
            }
        }

        return $sum;
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

    /**
     * @param array<string, mixed> $params
     */
    private function resolveFreezeCouponCode(array $params): ?string
    {
        $cartType = strtolower(trim((string)($params['cart_type'] ?? $params['selling_mode'] ?? $params['sellingMode'] ?? 'toc')));
        if ($cartType === '') {
            $cartType = 'toc';
        }

        try {
            $session = ObjectManager::getInstance(MarketingCheckoutCouponSession::class);
            if (!$session->couponsAllowedForCartType($cartType)) {
                return null;
            }
            $couponCode = strtoupper(trim((string)($params['coupon_code'] ?? '')));
            if ($couponCode !== '') {
                return $couponCode;
            }
            $sessionCode = $session->getCouponCode($cartType);
        } catch (\Throwable) {
            return null;
        }

        return $sessionCode !== '' ? $sessionCode : null;
    }

    /**
     * Server-owned entry attribution. Whitelist only; default 万能结账.
     *
     * @param array<string, mixed> $params
     */
    private function resolveCheckoutEntry(array $params): string
    {
        $raw = trim((string)($params['checkout_entry'] ?? ''));
        if ($raw !== '') {
            return CheckoutEntry::normalize($raw, CheckoutEntry::CHECKOUT);
        }
        if (!empty($params['express_checkout'])) {
            return CheckoutEntry::EXPRESS;
        }

        return CheckoutEntry::CHECKOUT;
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
        $checkoutBlocked = !empty($cart['checkout_blocked']);
        $blockingMessage = trim((string)($cart['blocking_message'] ?? ''));
        if ($checkoutBlocked && $blockingMessage === '') {
            $blockingMessage = (string)__('购物车中有不可结算的商品，请移除后重试');
        }
        $shippingAddress = $this->shippingAddressResolver->resolve($shippingAddress, $params);
        $shippingQuoted = $checkoutBlocked
            ? ['methods' => [], 'quote_diagnostics' => []]
            : $this->loadShippingMethods($shippingAddress, $items, $currency);
        $shippingMethods = \is_array($shippingQuoted['methods'] ?? null) ? $shippingQuoted['methods'] : [];
        $quoteDiagnostics = \is_array($shippingQuoted['quote_diagnostics'] ?? null)
            ? $shippingQuoted['quote_diagnostics']
            : [];
        $shippingEmpty = $checkoutBlocked
            ? [
                'title' => (string)__('暂无法结账'),
                'message' => $blockingMessage,
                'reason_code' => 'checkout_blocked',
            ]
            : $this->shippingUnavailablePresenter()->present(
                $quoteDiagnostics,
                \is_array($shippingQuoted['quote_lines'] ?? null) ? $shippingQuoted['quote_lines'] : [],
                (string)($shippingAddress['country_code'] ?? $quoteDiagnostics['country_code'] ?? ''),
            );
        $shippingEmptyMessage = (string)($shippingEmpty['message'] ?? '');
        $shippingEmptyTitle = (string)($shippingEmpty['title'] ?? '');
        $shippingEmptyReason = (string)($shippingEmpty['reason_code'] ?? '');
        $paymentMethods = $checkoutBlocked ? [] : $this->loadPaymentMethods($params + [
            'currency' => $currency,
            'amount' => (float)($cart['grand_total'] ?? $cart['subtotal'] ?? 0),
        ]);
        $continuePayRequest = !empty($params['continue_pay'])
            || strtolower(trim((string)($params['payment_mode'] ?? ''))) === 'continue_pay';
        // 硬隔离：传统 getData 忽略续付绑定的 payment/shipping，禁止 selected_code 偷选。
        $selectedPayment = $continuePayRequest
            ? strtolower(trim((string)($params['payment_method'] ?? '')))
            : '';
        $selectedShipping = $continuePayRequest
            ? trim((string)($params['shipping_method'] ?? ''))
            : '';
        // Continue-pay / resume: empty cart may set checkout_blocked, but the bound
        // method must still render with name+icon (never bare code).
        // ONLY when explicitly continue_pay — never inject into traditional getData.
        if ($continuePayRequest && $selectedPayment !== '') {
            $paymentMethods = $this->ensurePaymentMethodListed(
                $paymentMethods,
                $selectedPayment,
                $params + [
                    'currency' => $currency,
                    'amount' => (float)($cart['grand_total'] ?? $cart['subtotal'] ?? 0),
                ],
            );
        }
        // Continue-pay: empty/blocked cart skips live quote — show the order lane only
        // (same as payment chrome). Do not append beside live Americas/etc., and never
        // label it 「锁定」 (users think address/edit is blocked).
        if ($continuePayRequest && $selectedShipping !== '') {
            $shippingMethods = $this->ensureShippingMethodListed(
                $shippingMethods,
                $selectedShipping,
                $currency,
            );
            if ($shippingMethods !== []) {
                $shippingEmptyMessage = '';
                $shippingEmptyTitle = '';
                $shippingEmptyReason = '';
                $shippingEmpty = [
                    'title' => '',
                    'message' => '',
                    'reason_code' => '',
                ];
            }
        }
        if (!$continuePayRequest) {
            // Strip any leftover continue-pay inject rows (stale client params / prior bugs).
            $shippingMethods = array_values(array_filter(
                $shippingMethods,
                static function ($method): bool {
                    return !\is_array($method)
                        || trim((string)($method['source'] ?? '')) !== 'continue_pay_order';
                }
            ));
            $paymentMethods = array_values(array_filter(
                $paymentMethods,
                static function ($method): bool {
                    return !\is_array($method)
                        || trim((string)($method['source'] ?? '')) !== 'continue_pay_order';
                }
            ));
        }
        $html = $this->htmlRenderer();
        // 续付 getData 禁止写入 browse 会话（指纹/地址会污染传统结账 quote_token）。
        $quoteToken = $continuePayRequest
            ? trim((string)($params['quote_token'] ?? ''))
            : $this->syncBrowseSession(
                $params,
                $shippingAddress,
                $items,
                $quoteDiagnostics,
                $shippingMethods,
                $checkoutBlocked,
                $shippingEmptyMessage,
                $currency,
            );

        return $this->ok(
            $checkoutBlocked ? $blockingMessage : (string)__('结账信息已加载'),
            [
            'quote_token' => $quoteToken,
            'currency' => $currency,
            'identity' => [
                'checkout_mode' => (string)($identity['checkout_mode'] ?? 'guest'),
                'is_guest_checkout' => !empty($identity['is_guest_checkout']),
                'guest_allowed' => !empty($identity['guest_allowed']),
                'customer_allowed' => !empty($identity['customer_allowed']),
                'requires_guest_email' => !empty($identity['requires_guest_email']),
            ],
            'default_shipping_address' => $this->customerAddressPrefill(),
            'delivery' => $this->deliveryContextForBin($this->deliveryContextService->getContext($params)),
            'cart' => [
                'subtotal' => (float)($cart['subtotal'] ?? 0),
                'grand_total' => (float)($cart['grand_total'] ?? $cart['subtotal'] ?? 0),
                'currency' => $currency,
                'is_empty' => (bool)($cart['is_empty'] ?? $items === []),
                'item_count' => (int)($cart['item_count'] ?? \count($items)),
                'cart_type' => strtolower(trim((string)($cart['cart_type'] ?? 'toc'))) ?: 'toc',
                'discount_preview' => \is_array($cart['discount_preview'] ?? null)
                    ? $cart['discount_preview']
                    : null,
                'checkout_blocked' => $checkoutBlocked,
                'line_issues' => \is_array($cart['line_issues'] ?? null) ? $cart['line_issues'] : [],
                'blocking_message' => $blockingMessage,
            ],
            'items' => $items,
            'shipping_methods' => $shippingMethods,
            'shipping_quote_diagnostics' => $quoteDiagnostics,
            'shipping_unavailable' => $shippingEmpty,
            'tax_estimate' => $this->resolveTaxDutyEstimatePreview(
                $shippingMethods,
                $items,
                $shippingAddress,
                $currency,
            ),
            'payment_methods' => $paymentMethods,
            // P2E-003：服务端 HTML；JS 只注入，不 createElement 拼商品/选项 DOM
            'items_html' => $html->renderItems($items, $currency, (string)__('购物车为空，请先加入商品。')),
            'shipping_methods_html' => $html->renderMethodOptions(
                $shippingMethods,
                'shipping_method',
                $currency,
                $shippingEmptyMessage,
                true,
                $shippingEmptyTitle,
                $shippingEmptyReason,
            ),
            'payment_methods_html' => $html->renderPaymentMethodOptions(
                $paymentMethods,
                'payment_method',
                $checkoutBlocked
                    ? $blockingMessage
                    : (string)__('暂无可用支付方式。'),
                $selectedPayment !== '' ? ['selected_code' => $selectedPayment] : [],
            ),
            'checkout_blocked' => $checkoutBlocked,
            'blocking_message' => $blockingMessage,
        ]);
    }

    /**
     * @deprecated Use CheckoutShippingAddressResolver; kept as a thin alias for contract scans.
     *
     * @param array<string, mixed> $shippingAddress
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function resolveShippingAddressForQuote(array $shippingAddress, array $params = []): array
    {
        return $this->shippingAddressResolver->resolve($shippingAddress, $params);
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
        $fromCustomer = [];
        try {
            $identity = $this->customerAccounts()->current();
            if ($identity !== null && $identity->getId() > 0) {
                $address = ObjectManager::getInstance(DeliveryAddressService::class)
                    ->getDefaultByCustomer($identity->getId());
                if ($address instanceof DeliveryAddress) {
                    $fromCustomer = [
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
                } else {
                    $fromCustomer = ['email' => trim($identity->getEmail())];
                }
            }
        } catch (\Throwable) {
            $fromCustomer = [];
        }

        try {
            $fromHeader = $this->deliveryContextService->checkoutFormAddress();
        } catch (\Throwable) {
            $fromHeader = [];
        }

        $merged = $fromCustomer;
        foreach ($fromHeader as $key => $value) {
            if ($value !== '' && $value !== 0 && $value !== '0') {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function deliveryContext(array $params): array
    {
        try {
            return $this->ok(
                (string)__('配送信息已加载'),
                $this->deliveryContextForBin($this->deliveryContextService->getContext($params))
            );
        } catch (\Throwable $e) {
            return $this->ok((string)__('配送信息加载失败，请稍后重试或手动填写地址。'), [
                'country_code' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($params['country_code'] ?? 'CN')) ?: 'CN', 0, 2)),
                'country_name' => '',
                'addresses' => [],
                'selected' => null,
                'is_logged_in' => false,
                'can_auto_detect' => false,
                'display_text' => (string)__('选择国家/地址'),
                'checkout_address' => [],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Lazy captcha HTML for header quick-add (must not SSR LocalImageCaptcha on every page).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function deliveryCaptchaChallenge(array $params): array
    {
        $intent = trim((string)($params['intent'] ?? \Weline\Checkout\Service\DeliveryAddressCaptchaGuard::INTENT));
        if (preg_match('/\A[A-Za-z0-9_.:-]{1,80}\z/D', $intent) !== 1) {
            $intent = \Weline\Checkout\Service\DeliveryAddressCaptchaGuard::INTENT;
        }
        $formId = trim((string)($params['form_id'] ?? \Weline\Checkout\Service\DeliveryAddressCaptchaGuard::FORM_ID));
        if (preg_match('/\A[A-Za-z0-9_-]{0,80}\z/D', $formId) !== 1) {
            $formId = \Weline\Checkout\Service\DeliveryAddressCaptchaGuard::FORM_ID;
        }
        $prefer = strtolower(trim((string)($params['prefer'] ?? '')));
        if ($prefer !== 'local_image') {
            $prefer = '';
        }

        /** @var \Weline\Captcha\Api\CaptchaManagerInterface $captcha */
        $captcha = ObjectManager::getInstance(\Weline\Captcha\Api\CaptchaManagerInterface::class);
        $html = $captcha->renderChallenge([
            'form_id' => $formId,
            'intent' => $intent,
            'required' => true,
            'prefer' => $prefer,
        ]);

        return $this->ok((string)__('验证码已就绪'), [
            'html' => $html,
        ]);
    }

    /**
     * Lazy checkout-shipping-address HTML for HelpPay quick-pay modal (must not SSR on PDP).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function renderDeliveryAddressWidget(array $params): array
    {
        unset($params);
        $templatePath = BP . '/app/code/Weline/Shipping/view/templates/frontend/widgets/checkout-shipping-address.phtml';
        if (!is_file($templatePath)) {
            return [
                'success' => false,
                'message' => (string)__('收货地址组件暂不可用'),
                'code' => 'shipping_address_widget_unavailable',
                'html' => '',
            ];
        }

        try {
            /** @var \Weline\Framework\View\Template $template */
            $template = ObjectManager::getInstance(\Weline\Framework\View\Template::class);
            // Template 单例会把 title 默认设为当前模块名（Query 下常为 Weline_Framework），
            // 若不显式覆盖，地址部件标题会泄漏成「Weline_Framework」。
            $template->assign([
                'title' => (string) __('收货地址'),
                'saved_heading' => (string) __('选择收货地址'),
            ]);
            $inner = trim((string)$template->fetch(
                'Weline_Shipping::templates/frontend/widgets/checkout-shipping-address.phtml'
            ));
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => (string)__('收货地址组件加载失败，请稍后重试。'),
                'code' => 'shipping_address_widget_unavailable',
                'html' => '',
                'error' => $e->getMessage(),
            ];
        }

        if ($inner === '') {
            return [
                'success' => false,
                'message' => (string)__('收货地址组件暂不可用'),
                'code' => 'shipping_address_widget_unavailable',
                'html' => '',
            ];
        }

        $html = '<form data-helppay-address-host data-testid="helppay-address-host"'
            . ' action="javascript:void(0)" method="post" onsubmit="return false;">'
            . $inner
            . '</form>';

        return $this->ok((string)__('收货地址已就绪'), [
            'html' => $html,
        ]);
    }

    /**
     * QueryBin 二进制协议列表上限为 200；完整国家目录仅由 SSR 注入，接口响应不再回传。
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function deliveryContextForBin(array $context): array
    {
        unset($context['countries']);

        return $context;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function deliveryCountry(array $params): array
    {
        return $this->ok(
            (string)__('配送国家已更新'),
            $this->deliveryContextForBin($this->deliveryContextService->setCountry($params))
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function deliverySelect(array $params): array
    {
        return $this->ok(
            (string)__('配送地址已选择'),
            $this->deliveryContextForBin($this->deliveryContextService->selectAddress($params))
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function deliverySave(array $params): array
    {
        /** @var \Weline\Checkout\Service\DeliveryAddressCaptchaGuard $captchaGuard */
        $captchaGuard = ObjectManager::getInstance(\Weline\Checkout\Service\DeliveryAddressCaptchaGuard::class);
        $captchaPayload = is_array($params['address'] ?? null)
            ? array_merge($params, (array)$params['address'])
            : $params;
        $identity = $this->resolveCheckoutIdentity($params);
        $requiresCaptcha = !empty($identity['is_guest_checkout']);
        if ($requiresCaptcha && !$captchaGuard->verify($captchaPayload)) {
            throw new \InvalidArgumentException((string)__('验证码校验失败，请重试。'));
        }

        try {
            return $this->ok(
                (string)__('配送地址已保存'),
                $this->deliveryContextForBin($this->deliveryContextService->saveAddress($params))
            );
        } catch (\Weline\Shipping\Service\AddressValidationException $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
                'field_errors' => $exception->toFieldErrors(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function deliveryClear(array $params): array
    {
        return $this->ok(
            (string)__('结账地址簿已清空'),
            $this->deliveryContextForBin($this->deliveryContextService->clearDeliveryBook($params))
        );
    }

    private function htmlRenderer(): \Weline\Checkout\Service\CheckoutHtmlRenderer
    {
        return \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Checkout\Service\CheckoutHtmlRenderer::class
        );
    }

    private function quoteLineWeight(): CheckoutQuoteLineWeightResolver
    {
        try {
            $resolved = ObjectManager::getInstance(CheckoutQuoteLineWeightResolver::class);
            if ($resolved instanceof CheckoutQuoteLineWeightResolver) {
                return $resolved;
            }
        } catch (\Throwable) {
        }

        return new CheckoutQuoteLineWeightResolver();
    }

    private function shippingUnavailablePresenter(): CheckoutShippingUnavailablePresenter
    {
        try {
            $resolved = ObjectManager::getInstance(CheckoutShippingUnavailablePresenter::class);
            if ($resolved instanceof CheckoutShippingUnavailablePresenter) {
                return $resolved;
            }
        } catch (\Throwable) {
        }

        return new CheckoutShippingUnavailablePresenter();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function placeOrder(array $params): array
    {
        foreach (['discount_amount', 'discount_amount_minor'] as $discountKey) {
            if (!\array_key_exists($discountKey, $params)) {
                continue;
            }
            if ((float)$params[$discountKey] !== 0.0) {
                return [
                    'success' => false,
                    'message' => (string)__('客户端折扣金额被拒绝，请使用结账优惠券。'),
                    'error_code' => CheckoutGroupSubmitService::ERROR_CLIENT_DISCOUNT,
                ];
            }
        }

        $identity = $this->resolveCheckoutIdentity($params);
        if (!empty($identity['is_guest_checkout'])) {
            $this->checkoutIdentityService->validateGuestCheckout($identity, $params);
        }

        $shippingAddress = \is_array($params['shipping_address'] ?? null) ? $params['shipping_address'] : [];
        $shippingAddress = $this->shippingAddressResolver->resolve($shippingAddress, $params);
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

        $shippingMethods = $this->loadShippingMethods($shippingAddress, $items, $currency);
        if ($shippingMethods === []) {
            throw new \InvalidArgumentException((string)__('当前收货地址暂不可配送，请更换地址后再下单。'));
        }
        $shippingAmount = null;
        foreach ($shippingMethods as $method) {
            if ((string)($method['code'] ?? '') === $shippingMethod) {
                $shippingAmount = (float)($method['amount'] ?? $method['fee'] ?? 0);
                break;
            }
        }
        if ($shippingAmount === null) {
            throw new \InvalidArgumentException((string)__('所选配送方式不可用，请重新选择配送方式。'));
        }
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
        $redirect = '/checkout/success?order_id=' . $orderId;
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

        try {
            $this->deliveryContextService->promoteSelectedAddressToCheckout();
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
        $guestToken = trim((string)($params['guest_token'] ?? ''));
        $cookieToken = trim((string)Cookie::get(CartService::GUEST_TOKEN_COOKIE));
        $mode = $this->resolveCartTypePreference($params);
        $queryParams = $guestToken !== '' ? ['guest_token' => $guestToken] : [];
        $queryParams['cart_type'] = $mode;
        $queryParams['selling_mode'] = $mode;
        $couponCode = strtoupper(trim((string)($params['coupon_code'] ?? '')));
        if ($couponCode !== '') {
            $queryParams['coupon_code'] = $couponCode;
        }
        try {
            $v2Result = w_query('cart', 'getCart', $queryParams);
        } catch (\Throwable) {
            $v2Result = null;
        }
        $view = ObjectManager::getInstance(\Weline\Checkout\Service\CheckoutPageViewModel::class);
        $cart = $view->fromQueryResult($v2Result);
        // Client sessionStorage may hold an orphan token while the HttpOnly cookie
        // still owns the real cart (header SSR / mini-cart). Fall back to cookie.
        if ($cart['is_empty'] && $cookieToken !== '' && !hash_equals($cookieToken, $guestToken)) {
            $cookieParams = [
                'guest_token' => $cookieToken,
                'cart_type' => $mode,
                'selling_mode' => $mode,
            ];
            if ($couponCode !== '') {
                $cookieParams['coupon_code'] = $couponCode;
            }
            try {
                $cookieResult = w_query('cart', 'getCart', $cookieParams);
            } catch (\Throwable) {
                $cookieResult = null;
            }
            $cookieCart = $view->fromQueryResult($cookieResult);
            if (!$cookieCart['is_empty']) {
                $cart = $cookieCart;
                $v2Result = $cookieResult;
                $guestToken = $cookieToken;
            }
        }
        if ($cart['is_empty']) {
            $cart = $view->currentCart($guestToken !== '' ? $guestToken : null);
        }
        // Logged-in: preferred toc empty while wholesale sibling has lines → use tob
        // (header/万能车按类型；结账不得卡在空零售车).
        if ($cart['is_empty'] && $mode === 'toc' && $this->currentCustomerId() !== null) {
            $altParams = $guestToken !== '' ? ['guest_token' => $guestToken] : [];
            $altParams['cart_type'] = 'tob';
            $altParams['selling_mode'] = 'tob';
            try {
                $altResult = w_query('cart', 'getCart', $altParams);
            } catch (\Throwable) {
                $altResult = null;
            }
            $altCart = $view->fromQueryResult($altResult);
            if (!$altCart['is_empty']) {
                $cart = $altCart;
                $v2Result = $altResult;
                $mode = 'tob';
            }
        }
        if (!isset($cart['cart_type'])) {
            $payload = \is_array($v2Result['data'] ?? null) ? $v2Result['data'] : (\is_array($v2Result) ? $v2Result : []);
            $cart['cart_type'] = strtolower(trim((string)($payload['cart_type'] ?? $mode))) ?: $mode;
        }

        return $cart;
    }

    /**
     * Resolve toc|tob for checkout reads/freeze (params → website cookie → global cookie → toc).
     *
     * @param array<string, mixed> $params
     */
    private function resolveCartTypePreference(array $params): string
    {
        $mode = strtolower(trim((string)($params['cart_type'] ?? $params['selling_mode'] ?? $params['sellingMode'] ?? '')));
        if ($mode === 'toc' || $mode === 'tob') {
            return $mode;
        }
        $websiteId = (int)RequestContext::getWelineWebsiteId();
        if ($websiteId > 0) {
            $scoped = strtolower(trim((string)Cookie::get('weline_selling_mode_w' . $websiteId)));
            if ($scoped === 'toc' || $scoped === 'tob') {
                return $scoped;
            }
        }
        $cookie = strtolower(trim((string)Cookie::get('weline_selling_mode')));
        if ($cookie === 'toc' || $cookie === 'tob') {
            return $cookie;
        }

        return 'toc';
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
     * @param list<array<string, mixed>> $cartItems
     * @return array{methods: list<array<string, mixed>>, quote_diagnostics: array<string, mixed>}
     */
    private function loadShippingMethods(array $shippingAddress, array $cartItems = [], string $currency = 'CNY'): array
    {
        $countryCode = strtoupper(trim((string)($shippingAddress['country_code'] ?? 'CN'))) ?: 'CN';
        $lines = [];
        foreach ($cartItems as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $line = [
                'requires_shipping' => (bool)($item['requires_shipping'] ?? true),
                'qty_minor' => max(1, (int)($item['qty_minor'] ?? $item['qty'] ?? 1)),
                'qty' => max(1, (int)($item['qty'] ?? $item['qty_minor'] ?? 1)),
                'unit_price_minor' => (int)($item['unit_price_minor'] ?? 0),
                'row_total_minor' => (int)($item['row_total_minor'] ?? 0),
                // 真实重量：行重优先，否则目录 weight_kg（与 Express/HelpPay 同源解析；禁止静默 0.5kg）。
                'weight_minor' => $this->quoteLineWeight()->resolveLineWeightMinor($item),
                'volume_minor' => (int)($item['volume_minor'] ?? 0),
                'length_cm' => (float)($item['length_cm'] ?? $item['length'] ?? 0),
                'width_cm' => (float)($item['width_cm'] ?? $item['width'] ?? 0),
                'height_cm' => (float)($item['height_cm'] ?? $item['height'] ?? 0),
                'offer_id' => (int)($item['offer_id'] ?? 0),
                'product_id' => (int)($item['product_id'] ?? $item['id'] ?? 0),
                'split_key' => (string)($item['split_key'] ?? ''),
            ];
            if (isset($item['fulfillment_metadata']) && \is_array($item['fulfillment_metadata'])) {
                $line['fulfillment_metadata'] = $item['fulfillment_metadata'];
            }
            $profile = trim((string)($item['shipping_profile_code']
                ?? ($item['fulfillment_metadata']['shipping_profile_code'] ?? '')));
            if ($profile !== '') {
                $line['shipping_profile_code'] = $profile;
                $line['fulfillment_metadata'] = ($line['fulfillment_metadata'] ?? []) + [
                    'shipping_profile_code' => $profile,
                ];
            }
            $hazard = trim((string)($item['shipping_hazard_class']
                ?? ($item['fulfillment_metadata']['shipping_hazard_class'] ?? '')));
            if ($hazard !== '') {
                $line['shipping_hazard_class'] = $hazard;
                $line['fulfillment_metadata'] = ($line['fulfillment_metadata'] ?? []) + [
                    'shipping_hazard_class' => $hazard,
                ];
            }
            $lines[] = $line;
        }

        try {
            $result = w_query('shippingInfo', 'listQuoteOptions', [
                'address' => [
                    'country_code' => $countryCode,
                    'country' => $countryCode,
                    'province' => (string)($shippingAddress['province'] ?? ''),
                    'province_code' => (string)($shippingAddress['province_code'] ?? ''),
                    'province_region_id' => (int)($shippingAddress['province_region_id'] ?? $shippingAddress['province_id'] ?? 0),
                    'city' => (string)($shippingAddress['city'] ?? ''),
                    'city_code' => (string)($shippingAddress['city_code'] ?? ''),
                    'city_region_id' => (int)($shippingAddress['city_region_id'] ?? $shippingAddress['city_id'] ?? 0),
                    'district' => (string)($shippingAddress['district'] ?? ''),
                    'district_code' => (string)($shippingAddress['district_code'] ?? ''),
                    'district_region_id' => (int)($shippingAddress['district_region_id'] ?? $shippingAddress['district_id'] ?? 0),
                    'postal_code' => (string)($shippingAddress['postal_code'] ?? ''),
                    'street_id' => (int)($shippingAddress['street_id'] ?? 0),
                    'delivery_point_type' => strtolower(trim((string)($shippingAddress['delivery_point_type']
                        ?? $shippingAddress['point_type']
                        ?? 'residential'))) ?: 'residential',
                ],
                'lines' => $lines,
                'currency' => $currency !== '' ? $currency : 'CNY',
                'currency_precision' => 2,
                'scope' => [
                    'website_id' => (int)RequestContext::getWelineWebsiteId(),
                    'store_id' => (int)RequestContext::getWelineStoreId(),
                    'channel_id' => (int)RequestContext::getWelineChannelId(),
                ],
                'website_id' => (int)RequestContext::getWelineWebsiteId(),
                'store_id' => (int)RequestContext::getWelineStoreId(),
                'channel_id' => (int)RequestContext::getWelineChannelId(),
            ]);
        } catch (\Throwable) {
            return ['methods' => [], 'quote_diagnostics' => [], 'quote_lines' => $lines];
        }

        if (!\is_array($result) || empty($result['success'])) {
            $failPayload = \is_array($result['data'] ?? null) ? $result['data'] : [];
            $failDiag = \is_array($failPayload['quote_diagnostics'] ?? null) ? $failPayload['quote_diagnostics'] : [];
            if ($failDiag === []) {
                // Preflight from enriched lines when provider omitted diagnostics.
                foreach ($lines as $line) {
                    if (!(bool)($line['requires_shipping'] ?? true)) {
                        continue;
                    }
                    if ((int)($line['weight_minor'] ?? 0) <= 0) {
                        $failDiag = [
                            'missing_weight' => true,
                            'unavailable_reasons' => ['missing_weight'],
                            'country_code' => $countryCode,
                        ];
                        break;
                    }
                }
            }

            return ['methods' => [], 'quote_diagnostics' => $failDiag, 'quote_lines' => $lines];
        }

        $payload = \is_array($result['data'] ?? null) ? $result['data'] : [];
        $options = \is_array($payload['options'] ?? null) ? $payload['options'] : [];
        $quoteDiagnostics = \is_array($payload['quote_diagnostics'] ?? null) ? $payload['quote_diagnostics'] : [];
        $methods = [];
        foreach ($options as $option) {
            if (!\is_array($option)) {
                continue;
            }
            $code = trim((string)($option['service_code'] ?? $option['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $amountMinor = (int)($option['amount_minor'] ?? 0);
            $amount = $amountMinor / 100;
            $rawLabel = trim((string)($option['label'] ?? $option['service_name'] ?? $code));
            // Storefront display name is translatable Chinese seed/admin copy (e.g. 欧洲).
            $label = $rawLabel !== '' ? (string)__($rawLabel) : $code;
            $description = !empty($option['is_free']) || !empty($option['free_reason'])
                ? (string)__('免邮')
                : '';
            $dutyNotice = trim((string)($option['duty_notice'] ?? ''));
            // duty_notice stays machine code in payload; description must show human label.
            $dutyNoticeLabel = $dutyNotice !== ''
                ? (string)__((new ShippingIncotermService())->labelForDutyNoticeCode($dutyNotice))
                : '';
            if ($dutyNoticeLabel !== '') {
                $description = $description !== ''
                    ? ($description . ' · ' . $dutyNoticeLabel)
                    : $dutyNoticeLabel;
            }
            $methods[] = [
                'code' => $code,
                'label' => $label,
                'title' => $label,
                'description' => $description,
                'eta_label' => '',
                'amount' => $amount,
                'fee' => $amount,
                'amount_minor' => $amountMinor,
                'incoterm' => (string)($option['incoterm'] ?? ''),
                'duty_notice' => $dutyNotice,
                'source' => 'Weline_Shipping',
            ];
        }

        return [
            'methods' => $this->enrichShippingMethods(
            $methods,
            $lines,
            $shippingAddress,
            [
                'website_id' => (int)RequestContext::getWelineWebsiteId(),
                'store_id' => (int)RequestContext::getWelineStoreId(),
                'channel_id' => (int)RequestContext::getWelineChannelId(),
            ],
            $currency !== '' ? $currency : 'CNY',
            ),
            'quote_diagnostics' => $quoteDiagnostics,
            'quote_lines' => $lines,
        ];
    }

    /**
     * Soft Tax duty preview for summary (even when no shipping method is selectable).
     * Amounts stay Tax-owned; Checkout only forwards.
     *
     * @param list<array<string,mixed>> $methods
     * @param list<array<string,mixed>> $items
     * @param array<string,mixed> $address
     * @return array{tax_amount_minor:int,duty_amount_minor:int,import_tax_amount_minor:int,reason:string,duty_notice:string}
     */
    private function resolveTaxDutyEstimatePreview(
        array $methods,
        array $items,
        array $address,
        string $currency,
    ): array {
        $empty = [
            'tax_amount_minor' => 0,
            'duty_amount_minor' => 0,
            'import_tax_amount_minor' => 0,
            'reason' => '',
            'duty_notice' => '',
        ];
        if (!class_exists(\Weline\Tax\Service\DutyEstimateService::class)) {
            return $empty;
        }

        $notice = '';
        $shippingMinor = 0;
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }
            $candidate = trim((string)($method['duty_notice'] ?? ''));
            if ($candidate !== '') {
                $notice = $candidate;
            }
            if ((int)($method['tax_amount_minor'] ?? 0) > 0) {
                return [
                    'tax_amount_minor' => (int)$method['tax_amount_minor'],
                    'duty_amount_minor' => (int)($method['duty_amount_minor'] ?? 0),
                    'import_tax_amount_minor' => (int)($method['import_tax_amount_minor'] ?? 0),
                    'reason' => (string)($method['duty_estimate_reason'] ?? ''),
                    'duty_notice' => $candidate !== '' ? $candidate : $notice,
                ];
            }
            $shippingMinor = max($shippingMinor, (int)($method['amount_minor'] ?? 0));
        }

        $dest = strtoupper(trim((string)($address['country_code'] ?? $address['country'] ?? '')));
        $origin = 'CN';
        // Cross-border with no selectable method: still preview DDU so summary is not blind.
        if ($notice === '' && $dest !== '' && $dest !== $origin) {
            $notice = \Weline\Tax\Service\DutyEstimateService::NOTICE_DDU;
        }
        if ($notice === '') {
            return $empty;
        }

        $goodsMinor = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (isset($item['row_total_minor'])) {
                $goodsMinor += max(0, (int)$item['row_total_minor']);
                continue;
            }
            $qty = (float)($item['qty'] ?? $item['quantity'] ?? 1);
            $price = (float)($item['price'] ?? 0);
            $row = (float)($item['row_total'] ?? ($qty * $price));
            $goodsMinor += (int) round($row * 100);
        }

        $estimate = (new \Weline\Tax\Service\DutyEstimateService())->estimate([
            'goods_subtotal_minor' => $goodsMinor,
            'shipping_amount_minor' => $shippingMinor,
            'destination_country' => $dest,
            'origin_country' => $origin,
            'duty_notice' => $notice,
            'currency' => $currency,
        ]);

        return [
            'tax_amount_minor' => (int)$estimate['charged_minor'],
            'duty_amount_minor' => (int)$estimate['duty_amount_minor'],
            'import_tax_amount_minor' => (int)$estimate['import_tax_amount_minor'],
            'reason' => (string)$estimate['reason'],
            'duty_notice' => (string)$estimate['duty_notice'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $methods
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $address
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    private function enrichShippingMethods(
        array $methods,
        array $lines,
        array $address,
        array $scope,
        string $currency,
    ): array {
        try {
            $events = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Framework\Event\EventsManager::class,
            );
            if (!$events instanceof \Weline\Framework\Event\EventsManager) {
                return $methods;
            }
            $payload = [
                'methods' => $methods,
                'lines' => $lines,
                'address' => $address,
                'scope' => $scope,
                'currency' => $currency,
                'error' => null,
            ];
            $events->dispatch('Weline_Checkout::checkout::shipping_methods::enrich', $payload);
            $out = is_array($payload['methods'] ?? null) ? $payload['methods'] : $methods;
            if (!empty($payload['error'])) {
                return [];
            }

            return $out;
        } catch (\Throwable) {
            return $methods;
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function loadPaymentMethods(array $params): array
    {
        try {
            /** @var \Weline\Checkout\Service\CheckoutPaymentMethodsProvider $provider */
            $provider = ObjectManager::getInstance(\Weline\Checkout\Service\CheckoutPaymentMethodsProvider::class);

            return $provider->listMethods($params);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 续付等场景：空车时目录可能为空，仍须展示绑定支付方式的名称+图标。
     *
     * @param list<array<string, mixed>> $methods
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function ensurePaymentMethodListed(array $methods, string $code, array $params): array
    {
        try {
            /** @var \Weline\Checkout\Service\CheckoutPaymentMethodsProvider $provider */
            $provider = ObjectManager::getInstance(\Weline\Checkout\Service\CheckoutPaymentMethodsProvider::class);

            return $provider->ensureMethodPresent($methods, $code, $params);
        } catch (\Throwable) {
            return $methods;
        }
    }

    /**
     * 续付：空车无法重算运费时，只展示订单原配送线路（与支付 chrome 同策略）。
     * 禁止追加到 live quote 旁、禁止「锁定」恐吓文案。
     *
     * @param list<array<string, mixed>> $methods
     * @return list<array<string, mixed>>
     */
    private function ensureShippingMethodListed(array $methods, string $code, string $currency = 'CNY'): array
    {
        $code = trim($code);
        if ($code === '') {
            return $methods;
        }
        foreach ($methods as $method) {
            if (!\is_array($method)) {
                continue;
            }
            if (trim((string)($method['code'] ?? '')) === $code) {
                // Prefer the bound lane alone so radio #1 is the order method.
                return [$method];
            }
        }

        $bound = $this->buildBoundShippingMethodOption($code, $currency);

        return $bound !== null ? [$bound] : $methods;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildBoundShippingMethodOption(string $code, string $currency = 'CNY'): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        $label = $code;
        $incoterm = 'ddu';
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
                $inc = strtolower(trim((string)$service->getData('incoterm')));
                if ($inc !== '') {
                    $incoterm = $inc;
                }
            }
        } catch (\Throwable) {
            // keep defaults
        }
        $dutyNotice = \Weline\Shipping\Service\ShippingIncotermService::NOTICE_DDU;
        $description = (string)__((new ShippingIncotermService())->labelForDutyNoticeCode($dutyNotice));

        return [
            'code' => $code,
            'label' => $label,
            'title' => $label,
            'description' => $description,
            'eta_label' => '',
            'amount' => 0.0,
            'fee' => 0.0,
            'amount_minor' => 0,
            'incoterm' => $incoterm,
            'duty_notice' => $dutyNotice,
            'source' => 'continue_pay_order',
        ];
    }

    /**
     * @param array<string, mixed> $shippingAddress
     * @param list<array<string, mixed>> $cartItems
     */
    private function resolveShippingAmount(
        string $shippingMethod,
        array $shippingAddress,
        array $cartItems = [],
        string $currency = 'CNY',
    ): float {
        foreach ($this->loadShippingMethods($shippingAddress, $cartItems, $currency) as $method) {
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

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function startExpressCheckout(array $params): array
    {
        $flow = ObjectManager::getInstance(ExpressCheckoutFlowService::class);
        if (!$flow instanceof ExpressCheckoutFlowService) {
            $flow = new ExpressCheckoutFlowService();
        }

        return $flow->start(
            $params,
            fn (array $p): array => $this->freezeQuote($p),
            fn (array $p): array => $this->submitV2($p),
            fn (array $p): array => $this->getData($p),
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function getExpressReview(array $params): array
    {
        $flow = ObjectManager::getInstance(ExpressCheckoutFlowService::class);
        if (!$flow instanceof ExpressCheckoutFlowService) {
            $flow = new ExpressCheckoutFlowService();
        }

        return $flow->getReview($params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function confirmExpressCheckout(array $params): array
    {
        $flow = ObjectManager::getInstance(ExpressCheckoutFlowService::class);
        if (!$flow instanceof ExpressCheckoutFlowService) {
            $flow = new ExpressCheckoutFlowService();
        }

        return $flow->confirm($params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function cancelExpressCheckout(array $params): array
    {
        $flow = ObjectManager::getInstance(ExpressCheckoutFlowService::class);
        if (!$flow instanceof ExpressCheckoutFlowService) {
            $flow = new ExpressCheckoutFlowService();
        }

        return $flow->cancel($params);
    }

    private function ok(string $message, array $data): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ] + $data;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $address
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $quoteDiagnostics
     * @param list<array<string, mixed>> $shippingMethods
     */
    private function syncBrowseSession(
        array $params,
        array $address,
        array $items,
        array $quoteDiagnostics,
        array $shippingMethods,
        bool $checkoutBlocked,
        string $emptyMessage,
        string $currency,
    ): string {
        $token = '';
        $this->recordCheckoutFaultSafe(function () use (
            $params,
            $address,
            $items,
            $quoteDiagnostics,
            $shippingMethods,
            $checkoutBlocked,
            $emptyMessage,
            $currency,
            &$token,
        ): void {
            $recorder = $this->faultRecorder();
            $fingerprint = $this->checkoutFingerprint($params);
            $guestToken = trim((string)($params['guest_token'] ?? ''));
            if ($guestToken === '') {
                $guestToken = trim((string)Cookie::get(CartService::GUEST_TOKEN_COOKIE));
            }
            $token = $recorder->ensureSession(
                trim((string)($params['quote_token'] ?? '')),
                $fingerprint,
                [
                    'currency' => $currency,
                    'scope' => [
                        'website_id' => (int)RequestContext::getWelineWebsiteId(),
                        'store_id' => (int)RequestContext::getWelineStoreId(),
                    ],
                    'address' => [
                        'country_code' => strtoupper(trim((string)($address['country_code'] ?? $address['country'] ?? ''))),
                        'province' => (string)($address['province'] ?? ''),
                        'city' => (string)($address['city'] ?? ''),
                        'postal_code' => (string)($address['postal_code'] ?? ''),
                    ],
                    'lines' => array_map(static function (array $item): array {
                        return [
                            'sku' => (string)($item['sku'] ?? ''),
                            'product_id' => (int)($item['product_id'] ?? $item['id'] ?? 0),
                            'weight_minor' => (int)($item['weight_minor'] ?? 0),
                            'qty' => max(1, (int)($item['qty'] ?? $item['qty_minor'] ?? 1)),
                        ];
                    }, array_values(array_filter($items, 'is_array'))),
                    'guest_token' => $guestToken,
                    'customer_id' => $this->currentCustomerId(),
                    'cart_type' => $this->resolveCartTypePreference($params),
                ],
            );
            $recorder->syncLoad(
                $token,
                $address,
                $items,
                $quoteDiagnostics,
                $shippingMethods,
                $checkoutBlocked,
                $emptyMessage,
            );
        });

        return $token;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function checkoutFingerprint(array $params): string
    {
        $guestToken = trim((string)($params['guest_token'] ?? ''));
        if ($guestToken === '') {
            $guestToken = trim((string)Cookie::get(CartService::GUEST_TOKEN_COOKIE));
        }

        return CheckoutSessionFaultRecorder::fingerprint(
            \is_array($params['shipping_address'] ?? null) ? $params['shipping_address'] : [],
            $this->currentCustomerId(),
            $guestToken,
            $this->resolveCartTypePreference($params),
            (int)RequestContext::getWelineWebsiteId(),
            (int)RequestContext::getWelineStoreId(),
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function recordNamedFault(array $params, string $code, string $message): void
    {
        $this->recordCheckoutFaultSafe(function () use ($params, $code, $message): void {
            $token = trim((string)($params['quote_token'] ?? ''));
            if ($token === '') {
                return;
            }
            $mapped = $code;
            if ($mapped !== CheckoutSessionFaultRecorder::CODE_FREEZE_FAILED
                && $mapped !== CheckoutSessionFaultRecorder::CODE_SUBMIT_FAILED
            ) {
                $mapped = str_contains($code, 'freeze')
                    ? CheckoutSessionFaultRecorder::CODE_FREEZE_FAILED
                    : CheckoutSessionFaultRecorder::CODE_SUBMIT_FAILED;
            }
            $this->faultRecorder()->recordCode($token, $mapped, $message);
        });
    }

    private function recordCheckoutFaultSafe(callable $work): void
    {
        try {
            $work();
        } catch (\Throwable) {
        }
    }

    private function faultRecorder(): CheckoutSessionFaultRecorder
    {
        $resolved = ObjectManager::getInstance(CheckoutSessionFaultRecorder::class);
        if ($resolved instanceof CheckoutSessionFaultRecorder) {
            return $resolved;
        }

        return new CheckoutSessionFaultRecorder(
            ObjectManager::getInstance(\Weline\Checkout\Api\CheckoutSessionStoreInterface::class),
        );
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
                    'external' => true,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 2,
                    'params' => [
                        'shipping_address' => ['type' => 'array', 'required' => false],
                        'guest_token' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'quote_token' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'cart_type' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                        'selling_mode' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                        'coupon_code' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        // Continue-pay: bind method so empty-cart getData still returns name+icon chrome.
                        'payment_method' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        // Continue-pay: bind order-locked shipping lane when live quote is empty.
                        'shipping_method' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        // Explicit continue-pay mode: gates inject + skips browse session sync.
                        'continue_pay' => ['type' => 'boolean', 'required' => false],
                        'payment_mode' => ['type' => 'string', 'required' => false, 'max_length' => 32],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Load checkout cart, shipping and payment options',
                ],
                [
                    'name' => 'getDeliveryContext',
                    'frontend' => true,
                    'external' => true,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 2,
                    'params' => [
                        'country_code' => ['type' => 'string', 'required' => false, 'max_length' => 8],
                        'list_all_addresses' => ['type' => 'boolean', 'required' => false],
                        'for_picker' => ['type' => 'boolean', 'required' => false],
                        'address_purpose' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                        'purpose' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Load header delivery country and addresses',
                ],
                [
                    'name' => 'getDeliveryCaptchaChallenge',
                    'frontend' => true,
                    'external' => true,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        'intent' => ['type' => 'string', 'required' => false, 'max_length' => 80],
                        'form_id' => ['type' => 'string', 'required' => false, 'max_length' => 80],
                        'prefer' => ['type' => 'string', 'required' => false, 'max_length' => 32],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Lazy-load captcha HTML for header delivery quick-add',
                ],
                [
                    'name' => 'renderDeliveryAddressWidget',
                    'frontend' => true,
                    'external' => true,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Lazy-load checkout shipping address widget HTML for HelpPay quick-pay modal',
                ],
                [
                    'name' => 'setDeliveryCountry',
                    'frontend' => true,
                    'external' => true,
                    'auth' => 'any',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        'country_code' => ['type' => 'string', 'required' => true, 'max_length' => 8],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Set delivery country for header and checkout',
                ],
                [
                    'name' => 'selectDeliveryAddress',
                    'frontend' => true,
                    'external' => true,
                    'auth' => 'any',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        'id' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'address_purpose' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                        'purpose' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Select one saved delivery address',
                ],
                [
                    'name' => 'saveDeliveryAddress',
                    'frontend' => true,
                    'external' => true,
                    'auth' => 'any',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        'address' => ['type' => 'array', 'required' => true],
                        'purpose_source' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                        'also_use_receiving' => ['type' => 'mixed', 'required' => false],
                        'also_use_checkout' => ['type' => 'mixed', 'required' => false],
                        'captcha_provider' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'captcha_token' => ['type' => 'string', 'required' => false, 'max_length' => 128],
                        'captcha_response' => ['type' => 'string', 'required' => false, 'max_length' => 8192],
                        'captcha_action' => ['type' => 'string', 'required' => false, 'max_length' => 100],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Save delivery address for customer or guest',
                ],
                [
                    'name' => 'clearDeliveryAddresses',
                    'frontend' => true,
                    'external' => true,
                    'auth' => 'any',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        'address_purpose' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Clear checkout-purpose addresses only; keep receiving addresses',
                ],
                [
                    'name' => 'placeOrder',
                    'frontend' => true,
                    'external' => true,
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
                    'external' => true,
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
                    'external' => true,
                    'auth' => 'any',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        'address' => ['type' => 'array', 'required' => false],
                        'billing_address' => ['type' => 'array', 'required' => false],
                        'billing_same_as_shipping' => ['type' => 'boolean', 'required' => false],
                        'service_code' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'payment_method' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'client_hints' => ['type' => 'array', 'required' => false],
                        'guest_token' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'quote_token' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'cart_type' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                        'selling_mode' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                        'checkout_entry' => ['type' => 'string', 'required' => false, 'max_length' => 32],
                        'tax_identity' => ['type' => 'array', 'required' => false],
                        'buyer_tax_identity' => ['type' => 'array', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Freeze the server-owned current Cart and create one Shipping Quote session',
                ],
                [
                    'name' => 'submitV2',
                    'frontend' => true,
                    'external' => true,
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
                        // Optional: traditional checkout omits; express / PayPal smart buttons set true.
                        'express_checkout' => ['type' => 'boolean', 'required' => false],
                        'b2b_credit_apply_minor' => ['type' => 'integer', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Submit frozen Checkout V2 quote (config version must match)',
                ],
                [
                    'name' => 'resumePaymentV2',
                    'frontend' => true,
                    'external' => true,
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
                [
                    'name' => 'adoptContinuePaySession',
                    'frontend' => true,
                    'external' => true,
                    'auth' => 'any',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        'quote_token' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'order_uuid' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'idempotency_key' => ['type' => 'string', 'required' => false, 'max_length' => 128],
                        'payment_method' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'prior_quote_token' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Adopt unpaid order session as continue-pay binding on order Type (not a CheckoutType)',
                ],
                [
                    'name' => 'releaseContinuePaySession',
                    'frontend' => true,
                    'external' => true,
                    'auth' => 'any',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        'bucket_id' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Release continue-pay binding and restore remaining session buckets',
                ],
                [
                    'name' => 'amendUnpaidCheckoutAddress',
                    'frontend' => true,
                    'external' => true,
                    'auth' => 'any',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        'quote_token' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'order_uuid' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'address' => ['type' => 'array', 'required' => true],
                        'shipping_method' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'service_code' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'currency' => ['type' => 'string', 'required' => false, 'max_length' => 8],
                        'tax_identity' => ['type' => 'array', 'required' => false],
                        // 续付加/撤券写回未付订单（与 ExpressUnpaidOrderAmend 对齐）。
                        'coupon_code' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Write-back shipping address/coupon on unpaid order before resumePaymentV2',
                ],
                [
                    'name' => 'listCheckoutSessionBuckets',
                    'frontend' => true,
                    'external' => true,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'List checkout session buckets for type switcher (visible when count >= 2)',
                ],
                [
                    'name' => 'startExpressCheckout',
                    'frontend' => true,
                    'external' => true,
                    'auth' => 'any',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'payment_method' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'guest_token' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'cart_type' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                        'selling_mode' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                        'address' => ['type' => 'array', 'required' => false],
                        'idempotency_key' => ['type' => 'string', 'required' => false, 'max_length' => 128],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Start express checkout (toc): freeze+submit with express_checkout and return approve URL',
                ],
                [
                    'name' => 'getExpressReview',
                    'frontend' => true,
                    'external' => true,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        'transaction_no' => ['type' => 'string', 'required' => false, 'max_length' => 128],
                        'checkout_group_uuid' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'guest_token' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'service_code' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'shipping_address' => ['type' => 'array', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Load express-review DTO for awaiting_confirm transaction (transaction_no or checkout_group_uuid)',
                ],
                [
                    'name' => 'confirmExpressCheckout',
                    'frontend' => true,
                    'external' => true,
                    'auth' => 'any',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'transaction_no' => ['type' => 'string', 'required' => false, 'max_length' => 128],
                        'checkout_group_uuid' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'guest_token' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'service_code' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'contact_phone' => ['type' => 'string', 'required' => false, 'max_length' => 32],
                        'email' => ['type' => 'string', 'required' => false, 'max_length' => 128],
                        'idempotency_key' => ['type' => 'string', 'required' => false, 'max_length' => 128],
                        'shipping_address' => ['type' => 'array', 'required' => false],
                        'tax_identity' => ['type' => 'array', 'required' => false],
                        'buyer_tax_identity' => ['type' => 'array', 'required' => false],
                        'billing_address' => ['type' => 'array', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Confirm express checkout: amend unpaid order then capture',
                ],
                [
                    'name' => 'cancelExpressCheckout',
                    'frontend' => true,
                    'external' => true,
                    'auth' => 'any',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        'transaction_no' => ['type' => 'string', 'required' => false, 'max_length' => 128],
                        'checkout_group_uuid' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'guest_token' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Cancel/abandon unpaid express checkout group',
                ],
            ],
        ];
    }
}
