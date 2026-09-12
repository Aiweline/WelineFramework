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

/**
 * PDP/checkout express: start → review → confirm (defer capture) → cancel/abandon.
 */
final class ExpressCheckoutFlowService
{
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
        if (trim((string) ($address['country_code'] ?? '')) === '') {
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
            'cart_type' => $cartType,
            'selling_mode' => $cartType,
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
        $transactionNo = trim((string) ($params['transaction_no'] ?? ''));
        if ($transactionNo === '') {
            return [
                'success' => false,
                'message' => (string) __('缺少交易号'),
                'error_code' => 'express_review_transaction_required',
            ];
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

        $items = is_array($order['items'] ?? null) ? $order['items'] : [];
        $currency = (string) ($order['currency'] ?? 'CNY');
        $requiresShipping = $this->itemsRequireShipping($items);

        $evaluation = $this->evaluateProfile($profile !== [] ? $profile : $address, $requiresShipping);
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
        $embargo = $this->evaluateEmbargo($requiresShipping, $address);
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

        return [
            'success' => true,
            'message' => (string) __('尚未扣款，确认后向支付商收款'),
            'data' => [
                'transaction_no' => $transactionNo,
                'order_uuid' => $orderUuid,
                'checkout_group_uuid' => (string) ($order['checkout_group_uuid'] ?? ''),
                'address' => $address,
                'address_readonly' => true,
                'missing_fields' => $missing,
                'gap_fields' => $gapOnly,
                'complete' => $coreMissing === [],
                'shipping_methods' => $shippingMethods,
                'selected_service_code' => $serviceCode,
                'totals' => $totals,
                'payer_email' => $payerEmail,
                'embargo_blocked' => !empty($embargo['blocked']),
                'embargo_message' => (string) ($embargo['message'] ?? ''),
                'can_confirm' => $canConfirm,
                'requires_shipping' => $requiresShipping,
                'awaiting_confirm' => !empty($ctx['awaiting_confirm']),
                'copy_pending' => (string) __('尚未扣款，确认后向支付商收款'),
                'copy' => [
                    'headline' => (string) __('确认并付款'),
                    'not_charged' => (string) __('尚未扣款，确认后向支付商收款'),
                    'cta' => (string) __('确认并付款'),
                ],
            ],
        ] + [
            'transaction_no' => $transactionNo,
            'order_uuid' => $orderUuid,
            'totals' => $totals,
            'can_confirm' => $canConfirm,
            'address' => $address,
            'shipping_methods' => $shippingMethods,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function confirm(array $params): array
    {
        $transactionNo = trim((string) ($params['transaction_no'] ?? ''));
        if ($transactionNo === '') {
            return [
                'success' => false,
                'message' => (string) __('缺少交易号'),
                'error_code' => 'express_confirm_transaction_required',
            ];
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
            $redirect = $this->om()->getInstance(CheckoutSuccessUrlBuilder::class)
                ->buildForOrders([$orderUuid], [
                    'checkout_token' => (string) ($params['checkout_token'] ?? ''),
                ]);

            return [
                'success' => true,
                'message' => (string) __('订单已支付'),
                'redirect_url' => $redirect,
                'data' => ['redirect_url' => $redirect, 'already_paid' => true],
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
        $gaps = [
            'contact_phone' => trim((string) ($params['contact_phone'] ?? $params['phone'] ?? '')),
            'email' => trim((string) ($params['email'] ?? '')),
        ];
        $address = array_replace($address, array_filter($gaps, static fn ($v) => $v !== ''));
        $serviceCode = trim((string) ($params['service_code'] ?? ''));
        $amended = $this->amendUnpaidOrder($orderUuid, $address, [
            'service_code' => $serviceCode,
            'currency' => (string) ($order['currency'] ?? 'CNY'),
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
        if ($redirectPath !== '' && !str_starts_with($redirectPath, 'http')) {
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
        $transactionNo = trim((string) ($params['transaction_no'] ?? ''));
        if ($transactionNo === '') {
            return [
                'success' => false,
                'message' => (string) __('缺少交易号'),
                'error_code' => 'express_cancel_transaction_required',
            ];
        }

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
                    'unit_price_minor' => (int) ($item['unit_price_minor'] ?? 0),
                    'row_total_minor' => (int) ($item['row_total_minor'] ?? 0),
                    'weight_minor' => (int) ($item['weight_minor'] ?? 0),
                    'volume_minor' => (int) ($item['volume_minor'] ?? 0),
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
                return [];
            }
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
                $out[] = [
                    'code' => $code,
                    'title' => (string) ($option['title'] ?? $option['name'] ?? $code),
                    'amount_minor' => (int) ($option['amount_minor'] ?? 0),
                    'amount' => ((int) ($option['amount_minor'] ?? 0)) / 100,
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
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
