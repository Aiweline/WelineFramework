<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Cart\Service\CartService;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Order\Model\Order;
use Weline\Order\Service\OrderService;
use Weline\Payment\Api\Data\PaymentResult;
use Weline\Payment\Api\PaymentExpressFacadeInterface;
use Weline\Payment\Model\PaymentTransaction;
use Weline\Payment\Service\ExpressCheckoutOrchestrator;
use Weline\Payment\Service\PaymentBrowserReturnDispatcher;
use Weline\Shipping\Service\ShippingIncotermService;

/**
 * PDP/checkout express: start → review → confirm (defer capture) → cancel/abandon.
 */
final class ExpressCheckoutFlowService
{
    /** @var array<string, mixed> */
    private array $lastListQuoteDiagnostics = [];

    public function __construct(
        private readonly ?ObjectManager $objectManager = null,
    ) {
    }

    private function om(): ObjectManager
    {
        return $this->objectManager ?? ObjectManager::getInstance();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function start(array $params, callable $freezeQuote, callable $submitV2, callable $getData): array
    {
        $cartType = strtolower(trim((string) ($params['cart_type'] ?? $params['selling_mode'] ?? 'toc'))) ?: 'toc';
        if ($cartType === 'tob') {
            return [
                'success' => false,
                'message' => (string) __('批发快捷支付请使用完整结账'),
                'error_code' => 'express_tob_use_full_checkout',
            ];
        }

        $paymentMethod = strtolower(trim((string) ($params['payment_method'] ?? '')));
        if ($paymentMethod === '') {
            return [
                'success' => false,
                'message' => (string) __('请选择支付方式'),
                'error_code' => 'express_payment_method_required',
            ];
        }

        $guestToken = trim((string) ($params['guest_token'] ?? ''));
        if ($guestToken === '') {
            $guestToken = trim((string) Cookie::get(CartService::GUEST_TOKEN_COOKIE));
        }

        $data = $getData([
            'guest_token' => $guestToken,
            'cart_type' => $cartType,
            'selling_mode' => $cartType,
            'shipping_address' => is_array($params['address'] ?? null) ? $params['address'] : [],
            'quote_token' => trim((string) ($params['quote_token'] ?? $params['checkout_token'] ?? '')),
        ]);
        $cart = is_array($data['cart'] ?? null) ? $data['cart'] : [];
        if (!empty($cart['is_empty'])) {
            return ['success' => false, 'message' => (string) __('购物车为空'), 'error_code' => 'express_cart_empty'];
        }
        if (!empty($cart['checkout_blocked'])) {
            return [
                'success' => false,
                'message' => (string) ($cart['blocking_message'] ?? __('购物车不可结算')),
                'error_code' => 'express_cart_blocked',
            ];
        }

        $items = is_array($cart['items'] ?? null) ? $cart['items'] : [];
        $requiresShipping = $this->itemsRequireShipping($items);

        $address = is_array($params['address'] ?? null) ? $params['address'] : [];
        if ($address === [] && is_array($data['default_shipping_address'] ?? null)) {
            $address = $data['default_shipping_address'];
        }
        if ($address === [] && is_array($data['delivery']['checkout_address'] ?? null)) {
            $address = $data['delivery']['checkout_address'];
        }
        /** @var CheckoutShippingAddressResolver $addressResolver */
        $addressResolver = $this->om()->getInstance(CheckoutShippingAddressResolver::class);
        $address = $addressResolver->resolve($address, $params + [
            'shipping_address' => $address,
            'address' => $address,
        ]);
        if (trim((string) ($address['country_code'] ?? '')) === '') {
            // Last resort only when resolver and delivery context have no ISO country.
            $address['country_code'] = 'CN';
        }

        $serviceCode = '';
        if ($requiresShipping) {
            $methods = is_array($data['shipping_methods'] ?? null) ? $data['shipping_methods'] : [];
            foreach ($methods as $method) {
                if (is_array($method) && trim((string) ($method['code'] ?? '')) !== '') {
                    $serviceCode = trim((string) $method['code']);
                    break;
                }
            }
            if ($serviceCode === '') {
                return [
                    'success' => false,
                    'message' => (string) __('当前地区暂无可用配送，请调整地址后重试或使用完整结账'),
                    'error_code' => 'express_no_shipping',
                ];
            }
        }

        $frozen = $freezeQuote([
            'address' => $address,
            'billing_address' => $address,
            'billing_same_as_shipping' => true,
            'service_code' => $serviceCode,
            'payment_method' => $paymentMethod,
            'guest_token' => $guestToken,
            'quote_token' => trim((string) ($data['quote_token'] ?? $params['quote_token'] ?? '')),
            'cart_type' => $cartType,
            'selling_mode' => $cartType,
            'checkout_entry' => \Weline\Checkout\Service\CheckoutEntry::EXPRESS,
            'tax_identity' => \is_array($params['tax_identity'] ?? null) ? $params['tax_identity'] : [],
            'buyer_tax_identity' => \is_array($params['buyer_tax_identity'] ?? null) ? $params['buyer_tax_identity'] : [],
        ]);
        if (empty($frozen['success'])) {
            return [
                'success' => false,
                'message' => (string) ($frozen['message'] ?? __('无法冻结结账报价')),
                'error_code' => (string) ($frozen['error_code'] ?? 'express_freeze_failed'),
            ];
        }

        $quoteToken = trim((string) ($frozen['quote_token'] ?? $frozen['data']['quote_token'] ?? ''));
        if ($quoteToken === '') {
            return ['success' => false, 'message' => (string) __('报价令牌缺失'), 'error_code' => 'express_quote_missing'];
        }

        $submitted = $submitV2([
            'quote_token' => $quoteToken,
            'payment_method' => $paymentMethod,
            'idempotency_key' => trim((string) ($params['idempotency_key'] ?? ''))
                ?: ('express_' . bin2hex(random_bytes(8))),
            'country_code' => (string) ($address['country_code'] ?? ''),
            'guest_token' => $guestToken,
            'express_checkout' => true,
            'requires_shipping' => $requiresShipping,
        ]);
        if (empty($submitted['success'])) {
            return [
                'success' => false,
                'message' => (string) ($submitted['message'] ?? __('快捷支付下单失败')),
                'error_code' => (string) ($submitted['error_code'] ?? 'express_submit_failed'),
            ];
        }

        $payment = is_array($submitted['payment'] ?? null) ? $submitted['payment'] : [];
        $redirect = trim((string) (
            $payment['redirect_url']
            ?? ($payment['transactions'][0]['response']['redirect_url'] ?? '')
            ?? ($payment['transactions'][0]['response']['approve_url'] ?? '')
            ?? ''
        ));
        $transactionNo = trim((string) ($payment['transactions'][0]['transaction_no'] ?? ''));

        return [
            'success' => true,
            'message' => (string) __('请在支付商窗口完成授权'),
            'redirect_url' => $redirect,
            'approve_url' => $redirect,
            'checkout_token' => (string) ($submitted['checkout_token'] ?? $quoteToken),
            'order_uuids' => is_array($submitted['order_uuids'] ?? null) ? $submitted['order_uuids'] : [],
            'checkout_group_uuid' => (string) ($submitted['checkout_group_uuid'] ?? ''),
            'transaction_no' => $transactionNo,
            'payment' => $payment,
            'data' => [
                'redirect_url' => $redirect,
                'approve_url' => $redirect,
                'checkout_token' => (string) ($submitted['checkout_token'] ?? $quoteToken),
                'order_uuids' => is_array($submitted['order_uuids'] ?? null) ? $submitted['order_uuids'] : [],
                'transaction_no' => $transactionNo,
                'payment' => $payment,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getReview(array $params): array
    {
        $resolved = $this->resolveTransactionNo($params);
        if (empty($resolved['success'])) {
            return $resolved;
        }
        $transactionNo = (string) $resolved['transaction_no'];
        $params['transaction_no'] = $transactionNo;

        $alreadyPaid = $this->buildAlreadyPaidResult($transactionNo, $params);
        if ($alreadyPaid !== null) {
            return $alreadyPaid;
        }

        $loaded = $this->loadContext($transactionNo, $params);
        if (empty($loaded['success'])) {
            return $loaded;
        }
        /** @var array<string, mixed> $ctx */
        $ctx = $loaded['context'];
        $orderUuid = (string) $ctx['order_uuid'];
        $order = is_array($ctx['order'] ?? null) ? $ctx['order'] : [];
        $profile = is_array($ctx['express_profile'] ?? null) ? $ctx['express_profile'] : [];
        $address = is_array($ctx['shipping_address'] ?? null) ? $ctx['shipping_address'] : [];
        if ($profile !== [] && $address === []) {
            $address = $this->profileToAddress($profile);
        } elseif ($profile !== []) {
            $address = array_replace($address, array_filter(
                $this->profileToAddress($profile),
                static fn ($v) => $v !== null && $v !== '',
            ));
        }
        $selectedAddress = $this->extractAddressFromParams($params);
        if ($selectedAddress !== []) {
            $address = array_replace($address, $selectedAddress);
        }
        /** @var CheckoutShippingAddressResolver $addressResolver */
        $addressResolver = $this->om()->getInstance(CheckoutShippingAddressResolver::class);
        $address = $addressResolver->resolve($address, $params + [
            'shipping_address' => $address,
            'address' => $address,
        ]);

        $items = is_array($order['items'] ?? null) ? $order['items'] : [];
        $currency = (string) ($order['currency'] ?? 'CNY');
        $requiresShipping = $this->itemsRequireShipping($items);

        $evaluation = $this->evaluateProfile($address !== [] ? $address : $profile, $requiresShipping);
        $serviceCode = trim((string) (
            $params['service_code']
            ?? ($order['shipping']['method'] ?? $order['shipping']['service_code'] ?? '')
        ));

        $amended = $this->amendUnpaidOrder($orderUuid, $address, [
            'service_code' => $serviceCode,
            'currency' => $currency,
        ]);
        if (!empty($amended['ok'])) {
            try {
                $fresh = $this->om()->getInstance(OrderFacadeInterface::class)->get($orderUuid);
                $order = $fresh->toArray();
            } catch (\Throwable) {
            }
            $money = is_array($amended['totals'] ?? null) ? $amended['totals'] : (is_array($order['money'] ?? null) ? $order['money'] : []);
            if (is_array($amended['address'] ?? null) && $amended['address'] !== []) {
                $address = $amended['address'];
            }
        } else {
            $money = is_array($order['money'] ?? null) ? $order['money'] : [];
        }

        $shippingMethods = $requiresShipping
            ? $this->listShippingMethods($address, $items, $currency)
            : [];
        if ($requiresShipping && $shippingMethods !== []) {
            $codes = [];
            foreach ($shippingMethods as $method) {
                if (is_array($method)) {
                    $code = trim((string) ($method['code'] ?? ''));
                    if ($code !== '') {
                        $codes[] = $code;
                    }
                }
            }
            // PayPal (and other express) may return a different country than freeze;
            // keep the first reachable lane instead of a stale domestic service_code.
            if ($serviceCode === '' || !\in_array($serviceCode, $codes, true)) {
                $serviceCode = $codes[0];
                $amended = $this->amendUnpaidOrder($orderUuid, $address, [
                    'service_code' => $serviceCode,
                    'currency' => $currency,
                ]);
                if (!empty($amended['ok'])) {
                    try {
                        $fresh = $this->om()->getInstance(OrderFacadeInterface::class)->get($orderUuid);
                        $order = $fresh->toArray();
                    } catch (\Throwable) {
                    }
                    $money = is_array($amended['totals'] ?? null)
                        ? $amended['totals']
                        : (is_array($order['money'] ?? null) ? $order['money'] : $money);
                    if (is_array($amended['address'] ?? null) && $amended['address'] !== []) {
                        $address = $amended['address'];
                    }
                }
            }
        }
        $embargo = $this->evaluateEmbargo($requiresShipping, $address);
        $missingWeight = false;
        $shippingEmptyMessage = '';
        $quoteDiagnostics = $this->lastListQuoteDiagnostics;
        if ($requiresShipping && $shippingMethods === []) {
            $presented = (new CheckoutShippingUnavailablePresenter())->present(
                is_array($quoteDiagnostics) ? $quoteDiagnostics : [],
                is_array($items) ? $items : [],
                (string)($address['country_code'] ?? ''),
            );
            $missingWeight = ($presented['reason_code'] ?? '') === 'missing_weight'
                || !empty($quoteDiagnostics['missing_weight']);
            $shippingEmptyMessage = (string)($presented['message'] ?? '');
            $this->syncExpressFaultSnapshot(
                $params,
                $address,
                $items,
                $quoteDiagnostics,
                $shippingMethods,
                $shippingEmptyMessage,
            );
        }
        $missing = is_array($evaluation['missing_fields'] ?? null) ? $evaluation['missing_fields'] : [];
        $gapOnly = array_values(array_intersect($missing, ['contact_phone', 'phone', 'email']));
        $coreMissing = array_values(array_diff($missing, ['contact_phone', 'phone', 'email']));
        $canConfirm = empty($embargo['blocked'])
            && (!$requiresShipping || $shippingMethods !== [])
            && $coreMissing === [];

        $totals = $this->moneyToTotals($money, $currency);
        $payerEmail = trim((string) (
            $profile['email']
            ?? $address['email']
            ?? ($ctx['payer_email'] ?? '')
        ));
        $methodCode = strtolower(trim((string) ($ctx['method_code'] ?? '')));
        $methodLabel = $methodCode !== ''
            ? ((string) __('支付方式') . '：' . $methodCode)
            : (string) __('快捷支付');

        return [
            'success' => true,
            'message' => (string) __('尚未扣款，确认后向支付商收款'),
            'data' => [
                'transaction_no' => $transactionNo,
                'order_uuid' => $orderUuid,
                'checkout_group_uuid' => (string) ($order['checkout_group_uuid'] ?? ''),
                'payment_method' => $methodCode,
                'method_code' => $methodCode,
                'method_label' => $methodLabel,
                'address' => $address,
                'address_readonly' => false,
                'address_editable' => true,
                'missing_fields' => $missing,
                'gap_fields' => $gapOnly,
                'complete' => $coreMissing === [],
                'shipping_methods' => $shippingMethods,
                'selected_service_code' => $serviceCode,
                'totals' => $totals,
                'payer_email' => $payerEmail,
                'embargo_blocked' => !empty($embargo['blocked']),
                'embargo_message' => (string) ($embargo['message'] ?? ''),
                'missing_weight' => $missingWeight,
                'shipping_empty_message' => $shippingEmptyMessage,
                'can_confirm' => $canConfirm,
                'requires_shipping' => $requiresShipping,
                'awaiting_confirm' => !empty($ctx['awaiting_confirm']),
                'copy_pending' => (string) __('尚未扣款，确认后向支付商收款'),
                'copy' => [
                    'headline' => (string) __('确认并付款'),
                    'not_charged' => (string) __('尚未扣款，确认后向支付商收款'),
                    'cta' => (string) __('确认并付款'),
                    'address_hint' => (string) __('请确认收货地址；不对可更换或新增'),
                    'payment_method_hint' => $methodLabel,
                ],
            ],
        ] + [
            'transaction_no' => $transactionNo,
            'order_uuid' => $orderUuid,
            'totals' => $totals,
            'can_confirm' => $canConfirm,
            'address' => $address,
            'shipping_methods' => $shippingMethods,
            'payment_method' => $methodCode,
            'method_code' => $methodCode,
            'method_label' => $methodLabel,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function confirm(array $params): array
    {
        $resolved = $this->resolveTransactionNo($params);
        if (empty($resolved['success'])) {
            return $resolved;
        }
        $transactionNo = (string) $resolved['transaction_no'];
        $params['transaction_no'] = $transactionNo;

        $alreadyPaid = $this->buildAlreadyPaidResult($transactionNo, $params);
        if ($alreadyPaid !== null) {
            return $alreadyPaid;
        }

        $loaded = $this->loadContext($transactionNo, $params);
        if (empty($loaded['success'])) {
            return $loaded;
        }
        /** @var array<string, mixed> $ctx */
        $ctx = $loaded['context'];
        $orderUuid = (string) $ctx['order_uuid'];
        $order = is_array($ctx['order'] ?? null) ? $ctx['order'] : [];
        $status = strtolower(trim((string) ($order['status'] ?? '')));
        if (in_array($status, ['paid', 'fulfilled', 'completed'], true)) {
            return $this->buildAlreadyPaidResult($transactionNo, $params)
                ?? [
                    'success' => true,
                    'message' => (string) __('订单已支付'),
                    'redirect_url' => $this->om()->getInstance(CheckoutSuccessUrlBuilder::class)
                        ->buildForOrders([$orderUuid], [
                            'checkout_token' => (string) ($params['checkout_token'] ?? ''),
                        ]),
                    'data' => [
                        'already_paid' => true,
                        'transaction_no' => $transactionNo,
                        'order_uuid' => $orderUuid,
                        'redirect_url' => $this->om()->getInstance(CheckoutSuccessUrlBuilder::class)
                            ->buildForOrders([$orderUuid], [
                                'checkout_token' => (string) ($params['checkout_token'] ?? ''),
                            ]),
                    ],
                ];
        }

        $items = is_array($order['items'] ?? null) ? $order['items'] : [];
        $sellable = $this->assertSellable($items, [
            'website_id' => (int) ($order['website_id'] ?? RequestContext::getWelineWebsiteId()),
            'store_id' => (int) ($order['store_id'] ?? RequestContext::getWelineStoreId()),
        ]);
        if (($sellable['ok'] ?? true) === false) {
            $this->abandon($transactionNo, (string) ($sellable['message'] ?? 'stock'));

            return [
                'success' => false,
                'message' => (string) ($sellable['message'] ?? __('商品暂不可售')),
                'error_code' => (string) ($sellable['error_code'] ?? 'express_stock_unavailable'),
            ];
        }

        $address = is_array($ctx['shipping_address'] ?? null) ? $ctx['shipping_address'] : [];
        $profile = is_array($ctx['express_profile'] ?? null) ? $ctx['express_profile'] : [];
        if ($profile !== [] && $address === []) {
            $address = $this->profileToAddress($profile);
        }
        $selectedAddress = $this->extractAddressFromParams($params);
        if ($selectedAddress !== []) {
            $address = array_replace($address, $selectedAddress);
        }
        $gaps = [
            'contact_phone' => trim((string) ($params['contact_phone'] ?? $params['phone'] ?? '')),
            'email' => trim((string) ($params['email'] ?? '')),
        ];
        $address = array_replace($address, array_filter($gaps, static fn ($v) => $v !== ''));
        /** @var CheckoutShippingAddressResolver $addressResolver */
        $addressResolver = $this->om()->getInstance(CheckoutShippingAddressResolver::class);
        $address = $addressResolver->resolve($address, $params + [
            'shipping_address' => $address,
            'address' => $address,
        ]);
        $serviceCode = trim((string) ($params['service_code'] ?? ''));
        $taxIdentity = \is_array($params['tax_identity'] ?? null) ? $params['tax_identity'] : [];
        if ($taxIdentity === [] && \is_array($params['buyer_tax_identity'] ?? null)) {
            $taxIdentity = $params['buyer_tax_identity'];
        }
        $billingForTax = \is_array($params['billing_address'] ?? null) ? $params['billing_address'] : $address;
        if (interface_exists(\Weline\Tax\Api\CheckoutTaxAdvisorInterface::class)) {
            try {
                /** @var \Weline\Tax\Api\CheckoutTaxAdvisorInterface $taxAdvisor */
                $taxAdvisor = $this->om()->getInstance(\Weline\Tax\Api\CheckoutTaxAdvisorInterface::class);
                $taxAdvisor->validateTaxIdentity($taxIdentity, $billingForTax);
            } catch (\Weline\Tax\Api\TaxConflictException $e) {
                return [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'error_code' => $e->errorCode(),
                ];
            } catch (\Throwable $e) {
                return [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'error_code' => 'checkout_tax_identity_invalid',
                ];
            }
        }
        $amended = $this->amendUnpaidOrder($orderUuid, $address, [
            'service_code' => $serviceCode,
            'currency' => (string) ($order['currency'] ?? 'CNY'),
            'tax_identity' => $taxIdentity,
            'buyer_tax_identity' => \is_array($params['buyer_tax_identity'] ?? null) ? $params['buyer_tax_identity'] : $taxIdentity,
            'billing_address' => $billingForTax,
        ]);
        if (empty($amended['ok'])) {
            return [
                'success' => false,
                'message' => (string) ($amended['message'] ?? __('无法更新订单金额')),
                'error_code' => (string) ($amended['error_code'] ?? 'express_amend_failed'),
            ];
        }

        $grandMinor = (int) ($amended['totals']['grand_total_minor'] ?? 0);
        if ($grandMinor <= 0) {
            $grandMinor = (int) round(((float) ($amended['totals']['grand_total'] ?? 0)) * 100);
        }
        $this->markConfirmCapture($transactionNo, $grandMinor, (string) ($order['currency'] ?? 'CNY'));

        $dispatchParams = $this->buildConfirmCaptureDispatchParams($transactionNo, $params);
        if ($dispatchParams === []) {
            return [
                'success' => false,
                'message' => (string) __('无法定位支付交易上下文'),
                'error_code' => 'express_confirm_context_missing',
            ];
        }

        try {
            /** @var PaymentBrowserReturnDispatcher $dispatcher */
            $dispatcher = $this->om()->getInstance(PaymentBrowserReturnDispatcher::class);
            $decision = $dispatcher->dispatch($dispatchParams);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'express_confirm_capture_failed',
            ];
        }

        if (!$this->decisionLooksCaptured($decision, $transactionNo)) {
            return [
                'success' => false,
                'message' => (string) (
                    $decision['message']
                    ?? __('确认扣款未完成，请重试或改用完整结账')
                ),
                'error_code' => (string) ($decision['error_code'] ?? 'express_confirm_capture_incomplete'),
                'data' => ['decision' => $decision],
            ];
        }

        $redirectPath = trim((string) ($decision['redirect_path'] ?? $decision['redirect_url'] ?? ''));
        $redirectParams = is_array($decision['redirect_params'] ?? null) ? $decision['redirect_params'] : [];
        $checkoutToken = trim((string) ($params['checkout_token'] ?? ''));
        if ($checkoutToken === '') {
            try {
                /** @var PaymentTransaction $txn */
                $txn = $this->om()->getInstance(PaymentTransaction::class);
                $txn->load(PaymentTransaction::schema_fields_TRANSACTION_NO, $transactionNo);
                $request = $txn->getRequestData();
                if (is_array($request)) {
                    $checkoutToken = trim((string) (
                        $request['checkout_token']
                        ?? $request['quote_token']
                        ?? ''
                    ));
                }
            } catch (\Throwable) {
            }
        }
        if ($checkoutToken !== '' && empty($redirectParams['checkout_token'])) {
            $redirectParams['checkout_token'] = $checkoutToken;
        }
        if ($orderUuid !== '' && empty($redirectParams['order_uuid'])) {
            $redirectParams['order_uuid'] = $orderUuid;
        }
        // Prefer Checkout L3 success after capture — avoid handoff→stale express-review→cart.
        if ($redirectPath === '' || str_contains($redirectPath, 'handoff') || str_contains($redirectPath, 'express-review')) {
            $redirectUrl = $this->om()->getInstance(CheckoutSuccessUrlBuilder::class)
                ->buildForOrders([$orderUuid], $redirectParams);
        } elseif ($redirectPath !== '' && !str_starts_with($redirectPath, 'http')) {
            $redirectUrl = $this->om()->getInstance(\Weline\Framework\Http\Url::class)
                ->getUrl($redirectPath, $redirectParams);
        } else {
            $redirectUrl = $redirectPath !== ''
                ? $redirectPath
                : $this->om()->getInstance(CheckoutSuccessUrlBuilder::class)
                    ->buildForOrders([$orderUuid], $redirectParams);
        }

        return [
            'success' => true,
            'message' => (string) __('确认成功'),
            'redirect_url' => $redirectUrl,
            'data' => [
                'redirect_url' => $redirectUrl,
                'decision' => $decision,
            ],
        ];
    }

    /**
     * Cancel / abandon unpaid express group (PayPal cancel or review cancel CTA).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function cancel(array $params): array
    {
        $resolved = $this->resolveTransactionNo($params);
        if (empty($resolved['success'])) {
            return $resolved;
        }
        $transactionNo = (string) $resolved['transaction_no'];
        $params['transaction_no'] = $transactionNo;

        $abandoned = $this->abandon($transactionNo, 'buyer_cancelled');

        return [
            'success' => true,
            'message' => (string) __('已取消快捷支付'),
            'data' => $abandoned,
            'redirect_url' => '/',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function abandon(string $transactionNo, string $reason = 'abandoned'): array
    {
        $transactionNo = trim($transactionNo);
        $out = ['transaction_no' => $transactionNo, 'order_cancelled' => false, 'reason' => $reason];
        if ($transactionNo === '') {
            return $out;
        }

        try {
            /** @var PaymentTransaction $txn */
            $txn = $this->om()->getInstance(PaymentTransaction::class);
            $txn->clear()
                ->where(PaymentTransaction::schema_fields_TRANSACTION_NO, $transactionNo)
                ->find()
                ->fetch();
            if (!(int) $txn->getId()) {
                return $out;
            }
            if ($txn->isSuccess()) {
                $out['skipped'] = 'already_paid';

                return $out;
            }

            $request = $txn->getRequestData();
            if (!is_array($request)) {
                $request = [];
            }
            $meta = is_array($request['metadata'] ?? null) ? $request['metadata'] : [];
            $meta['express_abandoned'] = 1;
            $meta['express_abandon_reason'] = $reason;
            unset($meta[ExpressCheckoutOrchestrator::META_AWAITING_CONFIRM]);
            $request['metadata'] = $meta;
            unset($request[ExpressCheckoutOrchestrator::META_AWAITING_CONFIRM]);
            $txn->setRequestData($request)
                ->setData(PaymentTransaction::schema_fields_STATUS, PaymentTransaction::STATUS_FAILED)
                ->save();

            $orderUuid = trim((string) $txn->getData(PaymentTransaction::schema_fields_ORDER_ID));
            if ($orderUuid !== '') {
                $out['order_uuid'] = $orderUuid;
                try {
                    /** @var Order $order */
                    $order = $this->om()->getInstance(Order::class);
                    $order->load(Order::schema_fields_ORDER_UUID, $orderUuid);
                    if ((int) $order->getId() && $order->canCancel()) {
                        /** @var OrderService $orders */
                        $orders = $this->om()->getInstance(OrderService::class);
                        $orders->cancelOrder((int) $order->getId(), 'express_' . $reason);
                        $out['order_cancelled'] = true;
                    }
                } catch (\Throwable $e) {
                    $out['order_cancel_error'] = $e->getMessage();
                }
            }
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }

        return $out;
    }

    /**
     * When payment already captured/succeeded, never surface missing-order / forbidden copy.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function buildAlreadyPaidResult(string $transactionNo, array $params = []): ?array
    {
        $transactionNo = trim($transactionNo);
        if ($transactionNo === '') {
            return null;
        }

        try {
            /** @var PaymentTransaction $txn */
            $txn = $this->om()->getInstance(PaymentTransaction::class);
            $txn->clear()
                ->where(PaymentTransaction::schema_fields_TRANSACTION_NO, $transactionNo)
                ->find()
                ->fetch();
            if (!(int) $txn->getId()) {
                return null;
            }

            $orderUuid = trim((string) $txn->getData(PaymentTransaction::schema_fields_ORDER_ID));
            $methodCode = trim((string) $txn->getData(PaymentTransaction::schema_fields_METHOD_CODE));
            $paidByTxn = $txn->isSuccess();
            $paidByOrder = false;
            $checkoutGroupUuid = '';
            if ($orderUuid !== '') {
                try {
                    /** @var OrderFacadeInterface $orderFacade */
                    $orderFacade = $this->om()->getInstance(OrderFacadeInterface::class);
                    $order = $orderFacade->get($orderUuid)->toArray();
                    $status = strtolower(trim((string) ($order['status'] ?? '')));
                    $paymentStatus = strtolower(trim((string) ($order['payment_status'] ?? '')));
                    $paidByOrder = in_array($status, ['paid', 'fulfilled', 'completed'], true)
                        || in_array($paymentStatus, ['paid', 'captured', 'success'], true);
                    $checkoutGroupUuid = trim((string) ($order['checkout_group_uuid'] ?? ''));
                } catch (\Throwable) {
                }
            }

            if (!$paidByTxn && !$paidByOrder) {
                return null;
            }

            $landingExtras = [];
            $checkoutToken = trim((string) ($params['checkout_token'] ?? ''));
            if ($checkoutToken === '') {
                try {
                    $request = $txn->getRequestData();
                    if (is_array($request)) {
                        $checkoutToken = trim((string) (
                            $request['checkout_token']
                            ?? $request['checkout_session_code']
                            ?? $request['quote_token']
                            ?? ''
                        ));
                    }
                } catch (\Throwable) {
                }
            }
            if ($checkoutToken !== '') {
                $landingExtras['checkout_token'] = $checkoutToken;
            }
            if ($checkoutGroupUuid !== '') {
                $landingExtras['checkout_group_uuid'] = $checkoutGroupUuid;
            } else {
                $groupFromParam = trim((string) ($params['checkout_group_uuid'] ?? ''));
                if ($groupFromParam !== '') {
                    $landingExtras['checkout_group_uuid'] = $groupFromParam;
                }
            }
            $redirect = $orderUuid !== ''
                ? $this->om()->getInstance(CheckoutSuccessUrlBuilder::class)->buildForOrders([$orderUuid], $landingExtras)
                : '/checkout/success';

            $methodLabel = $methodCode !== ''
                ? ((string) __('支付方式') . '：' . $methodCode)
                : (string) __('快捷支付');

            return [
                'success' => true,
                'message' => (string) __('该笔订单已支付'),
                'redirect_url' => $redirect,
                'already_paid' => true,
                'transaction_no' => $transactionNo,
                'order_uuid' => $orderUuid,
                'data' => [
                    'already_paid' => true,
                    'transaction_no' => $transactionNo,
                    'order_uuid' => $orderUuid,
                    'checkout_group_uuid' => (string) ($landingExtras['checkout_group_uuid'] ?? ''),
                    'checkout_token' => $checkoutToken,
                    'method_code' => $methodCode,
                    'method_label' => $methodLabel,
                    'redirect_url' => $redirect,
                    'copy' => [
                        'headline' => (string) __('订单已支付'),
                        'not_charged' => (string) __('该笔快捷支付已完成扣款'),
                        'cta' => (string) __('查看订单'),
                    ],
                    'can_confirm' => false,
                    'awaiting_confirm' => false,
                ],
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve payment transaction_no from explicit param or checkout_group_uuid (express landing resilience).
     *
     * @param array<string, mixed> $params
     * @return array{success:bool,transaction_no?:string,message?:string,error_code?:string}
     */
    public function resolvePaymentTransactionNo(array $params): array
    {
        return $this->resolveTransactionNo($params);
    }

    /**
     * Resolve payment transaction_no from explicit param or checkout_group_uuid (express landing resilience).
     *
     * @param array<string, mixed> $params
     * @return array{success:bool,transaction_no?:string,message?:string,error_code?:string}
     */
    private function resolveTransactionNo(array $params): array
    {
        $transactionNo = trim((string) ($params['transaction_no'] ?? ''));
        if ($transactionNo !== '') {
            return ['success' => true, 'transaction_no' => $transactionNo];
        }

        $groupUuid = trim((string) ($params['checkout_group_uuid'] ?? ''));
        if ($groupUuid === '') {
            return [
                'success' => false,
                'message' => (string) __('缺少支付单号'),
                'error_code' => 'express_review_transaction_required',
            ];
        }

        try {
            /** @var Order $order */
            $order = $this->om()->getInstance(Order::class);
            $order->clear()
                ->where(Order::schema_fields_CHECKOUT_GROUP_UUID, $groupUuid)
                ->order(Order::schema_fields_ID, 'DESC')
                ->find()
                ->fetch();
            if (!(int) $order->getId()) {
                return [
                    'success' => false,
                    'message' => (string) __('找不到快捷支付订单'),
                    'error_code' => 'express_review_group_order_missing',
                ];
            }
            $orderUuid = trim((string) $order->getData(Order::schema_fields_ORDER_UUID));
            if ($orderUuid === '') {
                return [
                    'success' => false,
                    'message' => (string) __('找不到快捷支付订单'),
                    'error_code' => 'express_review_group_order_missing',
                ];
            }

            /** @var PaymentTransaction $txn */
            $txn = $this->om()->getInstance(PaymentTransaction::class);
            $txn->clear()
                ->where(PaymentTransaction::schema_fields_ORDER_ID, $orderUuid)
                ->order(PaymentTransaction::schema_fields_ID, 'DESC')
                ->find()
                ->fetch();
            $transactionNo = trim((string) $txn->getData(PaymentTransaction::schema_fields_TRANSACTION_NO));
            if (!(int) $txn->getId() || $transactionNo === '') {
                return [
                    'success' => false,
                    'message' => (string) __('快捷支付单尚未创建，请返回商品页重试'),
                    'error_code' => 'express_review_group_txn_missing',
                ];
            }

            return ['success' => true, 'transaction_no' => $transactionNo];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => (string) __('无法解析支付单号'),
                'error_code' => 'express_review_resolve_failed',
            ];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array{success:bool,message?:string,error_code?:string,context?:array<string,mixed>}
     */
    private function loadContext(string $transactionNo, array $params): array
    {
        try {
            /** @var PaymentTransaction $txn */
            $txn = $this->om()->getInstance(PaymentTransaction::class);
            $txn->clear()
                ->where(PaymentTransaction::schema_fields_TRANSACTION_NO, $transactionNo)
                ->find()
                ->fetch();
            if (!(int) $txn->getId()) {
                return [
                    'success' => false,
                    'message' => (string) __('交易不存在'),
                    'error_code' => 'express_transaction_missing',
                ];
            }

            $orderUuid = trim((string) $txn->getData(PaymentTransaction::schema_fields_ORDER_ID));
            $request = $txn->getRequestData();
            if (!is_array($request)) {
                $request = [];
            }
            $response = $txn->getResponseData();
            if (!is_array($response)) {
                $response = [];
            }
            $payload = is_array($response['payload'] ?? null) ? $response['payload'] : $response;
            $profile = is_array($payload['express_profile'] ?? null) ? $payload['express_profile'] : [];

            /** @var OrderFacadeInterface $orderFacade */
            $orderFacade = $this->om()->getInstance(OrderFacadeInterface::class);
            $orderRead = $orderFacade->get($orderUuid);
            $order = $orderRead->toArray();

            $ownership = $this->assertOwnership($request, $orderRead->customerId, $params);
            if ($ownership !== null) {
                return $ownership;
            }

            $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
            $shippingAddress = is_array($shipping['address'] ?? null) ? $shipping['address'] : [];

            return [
                'success' => true,
                'context' => [
                    'transaction_no' => $transactionNo,
                    'order_uuid' => $orderUuid,
                    'order' => $order,
                    'express_profile' => $profile,
                    'shipping_address' => $shippingAddress,
                    'awaiting_confirm' => ExpressCheckoutOrchestrator::isExpressAwaitingConfirm($request),
                    'payer_email' => trim((string) ($profile['email'] ?? $request['payer_email'] ?? '')),
                    'method_code' => (string) $txn->getData(PaymentTransaction::schema_fields_METHOD_CODE),
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'express_review_load_failed',
            ];
        }
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function assertOwnership(array $request, mixed $orderCustomerId, array $params): ?array
    {
        $customerId = null;
        try {
            $accounts = $this->om()->getInstance(\Weline\Customer\Api\Auth\CustomerAccountFacadeInterface::class);
            if (is_object($accounts) && method_exists($accounts, 'current')) {
                $identity = $accounts->current();
                $customerId = (int) ($identity?->getId() ?? 0);
                $customerId = $customerId > 0 ? $customerId : null;
            }
        } catch (\Throwable) {
        }

        $orderCid = $orderCustomerId !== null && (int) $orderCustomerId > 0 ? (int) $orderCustomerId : null;
        if ($orderCid !== null) {
            if ($customerId === null || (int) $customerId !== $orderCid) {
                return [
                    'success' => false,
                    'message' => (string) __('无权查看该支付'),
                    'error_code' => 'express_review_forbidden',
                ];
            }

            return null;
        }

        $boundGuest = trim((string) ($request['guest_token'] ?? $request['metadata']['guest_token'] ?? ''));
        $guestToken = trim((string) ($params['guest_token'] ?? ''));
        if ($guestToken === '') {
            $guestToken = trim((string) Cookie::get(CartService::GUEST_TOKEN_COOKIE));
        }
        if ($boundGuest !== '' && $guestToken !== '' && !hash_equals($boundGuest, $guestToken)) {
            return [
                'success' => false,
                'message' => (string) __('无权查看该支付'),
                'error_code' => 'express_review_forbidden',
            ];
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function itemsRequireShipping(array $items): bool
    {
        if ($items === []) {
            return true;
        }
        foreach ($items as $item) {
            if (is_array($item) && (bool) ($item['requires_shipping'] ?? true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function profileToAddress(array $profile): array
    {
        return [
            'contact_name' => trim((string) ($profile['contact_name'] ?? $profile['name'] ?? '')),
            'contact_phone' => trim((string) ($profile['contact_phone'] ?? $profile['phone'] ?? '')),
            'email' => trim((string) ($profile['email'] ?? '')),
            'country_code' => strtoupper(trim((string) ($profile['country_code'] ?? ''))),
            'province' => trim((string) ($profile['province'] ?? '')),
            'city' => trim((string) ($profile['city'] ?? '')),
            'district' => trim((string) ($profile['district'] ?? '')),
            'street' => trim((string) ($profile['street'] ?? $profile['address1'] ?? '')),
            'address1' => trim((string) ($profile['address1'] ?? $profile['street'] ?? '')),
            'postal_code' => trim((string) ($profile['postal_code'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $profile
     * @return array{complete:bool,missing_fields:list<string>,requires_shipping:bool}
     */
    private function evaluateProfile(array $profile, bool $requiresShipping): array
    {
        try {
            /** @var PaymentExpressFacadeInterface $facade */
            $facade = $this->om()->getInstance(PaymentExpressFacadeInterface::class);

            return $facade->evaluateExpressProfile($profile, $requiresShipping);
        } catch (\Throwable) {
            return [
                'complete' => true,
                'missing_fields' => [],
                'requires_shipping' => $requiresShipping,
            ];
        }
    }

    /**
     * @param array<string, mixed> $address
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function listShippingMethods(array $address, array $items, string $currency): array
    {
        try {
            $lines = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $lines[] = [
                    'requires_shipping' => (bool) ($item['requires_shipping'] ?? true),
                    'qty_minor' => max(1, (int) ($item['qty_minor'] ?? $item['qty'] ?? 1)),
                    'qty' => max(1, (int) ($item['qty'] ?? $item['qty_minor'] ?? 1)),
                    'unit_price_minor' => (int) ($item['unit_price_minor'] ?? 0),
                    'row_total_minor' => (int) ($item['row_total_minor'] ?? 0),
                    // 真实重量：与结账同源 CheckoutQuoteLineWeightResolver（禁止静默 0.5kg）。
                    'weight_minor' => $this->quoteLineWeight()->resolveLineWeightMinor($item),
                    'volume_minor' => (int) ($item['volume_minor'] ?? 0),
                    'offer_id' => (int) ($item['offer_id'] ?? 0),
                    'product_id' => (int) ($item['product_id'] ?? 0),
                    'split_key' => (string) ($item['split_key'] ?? ''),
                ];
            }
            $result = w_query('shippingInfo', 'listQuoteOptions', [
                'address' => $address,
                'lines' => $lines,
                'currency' => $currency !== '' ? $currency : 'CNY',
                'currency_precision' => 2,
                'scope' => [
                    'website_id' => (int) RequestContext::getWelineWebsiteId(),
                    'store_id' => (int) RequestContext::getWelineStoreId(),
                    'channel_id' => (int) RequestContext::getWelineChannelId(),
                ],
            ]);
            if (!is_array($result) || empty($result['success'])) {
                $this->lastListQuoteDiagnostics = is_array($result['data']['quote_diagnostics'] ?? null)
                    ? $result['data']['quote_diagnostics']
                    : [];

                return [];
            }
            $this->lastListQuoteDiagnostics = is_array($result['data']['quote_diagnostics'] ?? null)
                ? $result['data']['quote_diagnostics']
                : [];
            $options = is_array($result['data']['options'] ?? null) ? $result['data']['options'] : [];
            $out = [];
            foreach ($options as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $code = trim((string) ($option['service_code'] ?? $option['code'] ?? ''));
                if ($code === '') {
                    continue;
                }
                // Express display: same label/duty tip translation as CheckoutQueryProvider storefront list.
                // Keep code as selection value — do not change CheckoutQueryProvider / 万能结账.
                $label = trim((string) ($option['label'] ?? $option['service_name'] ?? $option['title'] ?? $option['name'] ?? ''));
                if ($label === '') {
                    $label = $code;
                } else {
                    $label = (string) __($label);
                }
                $dutyNotice = trim((string) ($option['duty_notice'] ?? ''));
                $dutyNoticeLabel = $dutyNotice !== ''
                    ? (string) __((new ShippingIncotermService())->labelForDutyNoticeCode($dutyNotice))
                    : '';
                $description = !empty($option['is_free']) || !empty($option['free_reason'])
                    ? (string) __('免邮')
                    : '';
                if ($dutyNoticeLabel !== '') {
                    $description = $description !== ''
                        ? ($description . ' · ' . $dutyNoticeLabel)
                        : $dutyNoticeLabel;
                }
                $out[] = [
                    'code' => $code,
                    'label' => $label,
                    'title' => $label,
                    'description' => $description,
                    'amount_minor' => (int) ($option['amount_minor'] ?? 0),
                    'amount' => ((int) ($option['amount_minor'] ?? 0)) / 100,
                    'duty_notice' => $dutyNotice,
                ];
            }

            return $this->enrichShippingMethods(
                $out,
                $lines,
                $address,
                [
                    'website_id' => (int) RequestContext::getWelineWebsiteId(),
                    'store_id' => (int) RequestContext::getWelineStoreId(),
                    'channel_id' => (int) RequestContext::getWelineChannelId(),
                ],
                $currency !== '' ? $currency : 'CNY',
            );
        } catch (\Throwable) {
            $this->lastListQuoteDiagnostics = [];

            return [];
        }
    }

    /**
     * Prefer line snapshot weight; if missing, catalog weight_kg via shared resolver.
     * Still fail-closed at 0 — never invent 0.5kg.
     *
     * @deprecated Use quoteLineWeight(); kept for callers/tests that referenced resolveItemWeightMinor.
     * @param array<string, mixed> $item
     */
    private function resolveItemWeightMinor(array $item): int
    {
        return $this->quoteLineWeight()->resolveLineWeightMinor($item);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $address
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $quoteDiagnostics
     * @param list<array<string, mixed>> $shippingMethods
     */
    private function syncExpressFaultSnapshot(
        array $params,
        array $address,
        array $items,
        array $quoteDiagnostics,
        array $shippingMethods,
        string $emptyMessage,
    ): void {
        try {
            $token = trim((string) ($params['checkout_token'] ?? $params['quote_token'] ?? ''));
            if ($token === '') {
                return;
            }
            $recorder = $this->om()->getInstance(CheckoutSessionFaultRecorder::class);
            if (!$recorder instanceof CheckoutSessionFaultRecorder) {
                return;
            }
            $recorder->syncLoad(
                $token,
                $address,
                $items,
                $quoteDiagnostics,
                $shippingMethods,
                false,
                $emptyMessage,
            );
        } catch (\Throwable) {
        }
    }

    private function quoteLineWeight(): CheckoutQuoteLineWeightResolver
    {
        try {
            $resolved = $this->om()->getInstance(CheckoutQuoteLineWeightResolver::class);
            if ($resolved instanceof CheckoutQuoteLineWeightResolver) {
                return $resolved;
            }
        } catch (\Throwable) {
        }

        return new CheckoutQuoteLineWeightResolver();
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
            $events = $this->om()->getInstance(\Weline\Framework\Event\EventsManager::class);
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
            if (!empty($payload['error'])) {
                return [];
            }

            return is_array($payload['methods'] ?? null) ? $payload['methods'] : $methods;
        } catch (\Throwable) {
            return $methods;
        }
    }

    /**
     * @param array<string, mixed> $address
     * @return array{blocked:bool,message:string}
     */
    private function evaluateEmbargo(bool $requiresShipping, array $address): array
    {
        if (!$requiresShipping || $address === []) {
            return ['blocked' => false, 'message' => ''];
        }
        try {
            $embargo = $this->om()->getInstance(\Weline\Shipping\Service\EmbargoService::class);
            $result = $embargo->evaluateAddress($address, []);

            return [
                'blocked' => !empty($result['blocked']),
                'message' => trim((string) ($result['message'] ?? '')),
            ];
        } catch (\Throwable) {
            return ['blocked' => false, 'message' => ''];
        }
    }

    /**
     * @param array<string, mixed> $money
     * @return array<string, mixed>
     */
    private function moneyToTotals(array $money, string $currency): array
    {
        $sub = (int) ($money['subtotal_minor'] ?? 0);
        $ship = (int) ($money['shipping_amount_minor'] ?? 0);
        $tax = (int) ($money['tax_amount_minor'] ?? 0);
        $disc = (int) ($money['discount_amount_minor'] ?? 0);
        $grand = (int) ($money['grand_total_minor'] ?? max(0, $sub + $ship + $tax - $disc));

        return [
            'currency' => $currency,
            'subtotal_minor' => $sub,
            'shipping_amount_minor' => $ship,
            'tax_amount_minor' => $tax,
            'discount_amount_minor' => $disc,
            'grand_total_minor' => $grand,
            'subtotal' => $sub / 100,
            'shipping_amount' => $ship / 100,
            'tax_amount' => $tax / 100,
            'discount_amount' => $disc / 100,
            'grand_total' => $grand / 100,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $scopeParams
     * @return array{ok:bool,message?:string,error_code?:string}
     */
    private function assertSellable(array $items, array $scopeParams): array
    {
        try {
            $gate = $this->om()->getInstance(\Weline\Cart\Service\CartPriceSellabilityGate::class);
            if (!is_object($gate) || !method_exists($gate, 'assertOrAllow')) {
                return ['ok' => true];
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $result = $gate->assertOrAllow($scopeParams + [
                    'product_id' => (int) ($item['product_id'] ?? $item['id'] ?? 0),
                    'offer_id' => (int) ($item['offer_id'] ?? 0),
                ]);
                if (($result['ok'] ?? true) === false) {
                    return $result;
                }
            }
        } catch (\Throwable) {
        }

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function extractAddressFromParams(array $params): array
    {
        $raw = $params['shipping_address']
            ?? $params['address']
            ?? $params['checkout_address']
            ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($raw)) {
            $raw = [];
            foreach ([
                'contact_name', 'name', 'phone', 'contact_phone', 'email',
                'country_code', 'country', 'province', 'city', 'district',
                'street', 'address1', 'address2', 'postal_code',
            ] as $key) {
                if (array_key_exists($key, $params) && trim((string) $params[$key]) !== '') {
                    $raw[$key] = trim((string) $params[$key]);
                }
            }
        }
        if ($raw === []) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $trimmed = trim((string) $value);
                if ($trimmed !== '') {
                    $out[$key] = $trimmed;
                }
            }
        }
        if (isset($out['phone']) && !isset($out['contact_phone'])) {
            $out['contact_phone'] = $out['phone'];
        }
        if (isset($out['contact_phone']) && !isset($out['phone'])) {
            $out['phone'] = $out['contact_phone'];
        }
        if (isset($out['name']) && !isset($out['contact_name'])) {
            $out['contact_name'] = $out['name'];
        }
        if (isset($out['street']) && !isset($out['address1'])) {
            $out['address1'] = $out['street'];
        }
        if (isset($out['address1']) && !isset($out['street'])) {
            $out['street'] = $out['address1'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $address
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function amendUnpaidOrder(string $orderUuid, array $address, array $options = []): array
    {
        $orderUuid = trim($orderUuid);
        if ($orderUuid === '') {
            return ['ok' => false, 'message' => 'order_uuid required', 'error_code' => 'express_amend_order_required'];
        }
        try {
            /** @var Order $order */
            $order = $this->om()->getInstance(Order::class);
            $order->load(Order::schema_fields_ORDER_UUID, $orderUuid);
            if (!(int) $order->getId()) {
                return ['ok' => false, 'message' => 'order missing', 'error_code' => 'express_amend_order_missing'];
            }

            return (new ExpressUnpaidOrderAmend())->amend($order, $address, $options);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'error_code' => 'express_amend_failed'];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function buildConfirmCaptureDispatchParams(string $transactionNo, array $params): array
    {
        $transactionNo = trim($transactionNo);
        if ($transactionNo === '') {
            return [];
        }

        $out = [
            'transaction_no' => $transactionNo,
            'express_confirm_capture' => '1',
        ];

        $methodCode = strtolower(trim((string) ($params['method_code'] ?? $params['payment_method'] ?? '')));
        $shellToken = trim((string) ($params['shell_token'] ?? ''));
        $targetScope = strtolower(trim((string) ($params['target_scope'] ?? '')));
        $gatewayToken = trim((string) ($params['token'] ?? $params['order_id'] ?? ''));

        try {
            /** @var PaymentTransaction $txn */
            $txn = $this->om()->getInstance(PaymentTransaction::class);
            $txn->clear()
                ->where(PaymentTransaction::schema_fields_TRANSACTION_NO, $transactionNo)
                ->find()
                ->fetch();
            if ((int) $txn->getId()) {
                if ($methodCode === '') {
                    $methodCode = strtolower(trim((string) $txn->getData(PaymentTransaction::schema_fields_METHOD_CODE)));
                }
                $request = $txn->getRequestData();
                if (!is_array($request)) {
                    $request = [];
                }
                if ($targetScope === '') {
                    $targetScope = strtolower(trim((string) ($request['scope'] ?? $txn->getData('scope') ?? '')));
                }
                if ($shellToken === '') {
                    $returnUrl = trim((string) ($request['return_url'] ?? ''));
                    if ($returnUrl !== '' && preg_match('/[?&]shell_token=([^&]+)/', $returnUrl, $m)) {
                        $shellToken = rawurldecode((string) $m[1]);
                    }
                }
                if ($gatewayToken === '') {
                    $response = $txn->getResponseData();
                    $gatewayToken = trim((string) (
                        $response[PaymentResult::FIELD_PROVIDER_REFERENCE]
                        ?? ($response['payload']['order_id'] ?? '')
                        ?? ''
                    ));
                }
            }
        } catch (\Throwable) {
        }

        if ($methodCode !== '') {
            $out['method_code'] = $methodCode;
        }
        if ($shellToken !== '') {
            $out['shell_token'] = $shellToken;
        }
        if ($targetScope !== '') {
            $out['target_scope'] = $targetScope;
        }
        if ($gatewayToken !== '') {
            $out['token'] = $gatewayToken;
            $out['order_id'] = $gatewayToken;
        }

        if ($shellToken === '' && $methodCode === '') {
            return [];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $decision
     */
    private function decisionLooksCaptured(array $decision, string $transactionNo): bool
    {
        if (!empty($decision['render']) && ($decision['template'] ?? '') === 'browser-return') {
            return false;
        }

        try {
            /** @var PaymentTransaction $txn */
            $txn = $this->om()->getInstance(PaymentTransaction::class);
            $txn->clear()
                ->where(PaymentTransaction::schema_fields_TRANSACTION_NO, $transactionNo)
                ->find()
                ->fetch();
            if ((int) $txn->getId() && $txn->isSuccess()) {
                return true;
            }
        } catch (\Throwable) {
        }

        $path = trim((string) ($decision['redirect_path'] ?? $decision['redirect_url'] ?? ''));
        if ($path === '') {
            return false;
        }

        return str_contains($path, 'checkout/success')
            || str_contains($path, 'payment/handoff')
            || !empty($decision['absolute']);
    }

    private function markConfirmCapture(string $transactionNo, int $grandMinor, string $currency): void
    {
        try {
            /** @var PaymentTransaction $txn */
            $txn = $this->om()->getInstance(PaymentTransaction::class);
            $txn->clear()
                ->where(PaymentTransaction::schema_fields_TRANSACTION_NO, $transactionNo)
                ->find()
                ->fetch();
            if (!(int) $txn->getId()) {
                return;
            }
            $request = $txn->getRequestData();
            if (!is_array($request)) {
                $request = [];
            }
            $request['express_confirm_capture'] = true;
            $currency = strtoupper(trim($currency)) ?: 'CNY';
            $providerCurrency = $this->detectProviderOrderCurrency($txn->getResponseData());
            // Patch only when provider currency is known and matches; otherwise capture approved amount.
            if ($providerCurrency !== '' && $providerCurrency === $currency) {
                $request['patch_amount_minor'] = $grandMinor;
                $request['patch_currency'] = $currency;
            } else {
                unset($request['patch_amount_minor'], $request['patch_currency']);
            }
            $txn->setRequestData($request)->save();
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string, mixed>|mixed $response
     */
    private function detectProviderOrderCurrency(mixed $response): string
    {
        if (!is_array($response)) {
            return '';
        }
        $candidates = [
            $response['payload']['order']['purchase_units'][0]['amount']['currency_code'] ?? null,
            $response['payload']['purchase_units'][0]['amount']['currency_code'] ?? null,
            $response['order']['purchase_units'][0]['amount']['currency_code'] ?? null,
            $response['purchase_units'][0]['amount']['currency_code'] ?? null,
        ];
        foreach ($candidates as $code) {
            $code = strtoupper(trim((string) $code));
            if ($code !== '') {
                return $code;
            }
        }

        return '';
    }

}
