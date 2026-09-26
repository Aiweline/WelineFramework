<?php

declare(strict_types=1);

namespace Weline\HelpPay\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Api\PaymentLinkServiceInterface;

/**
 * Orchestrates help_pay / selection_share / quick_pay_self without owning Cart/Checkout kernels.
 */
final class HelpPayOrchestrator
{
    public const CART_TYPE_TOC = 'toc';

    public function __construct(
        private readonly PaymentLinkServiceInterface $links,
        private readonly ShippingRedactionService $redaction = new ShippingRedactionService(),
        private readonly ?HelpPayQuickShippingQuoteService $quickShipping = null,
    ) {
    }

    /**
     * @param array{
     *   owner_customer_id?:int|null,
     *   payable_type?:string,
     *   payable_id?:string,
     *   amount_minor:int,
     *   currency_code?:string,
     *   shipping_address:array<string,mixed>,
     *   address_confirmed:bool,
     *   rules_accepted:bool,
     *   cart_type?:string,
     *   line_summary?:list<array<string,mixed>>,
     *   public_origin?:string,
     *   ttl_seconds?:int
     * } $input
     * @return array<string,mixed>
     */
    public function createHelpPay(array $input): array
    {
        $this->assertTocOnly((string) ($input['cart_type'] ?? self::CART_TYPE_TOC));
        if (empty($input['rules_accepted'])) {
            throw new \InvalidArgumentException('helppay_rules_not_accepted');
        }
        if (empty($input['address_confirmed'])) {
            throw new \InvalidArgumentException('helppay_address_not_confirmed');
        }
        $shipping = is_array($input['shipping_address'] ?? null) ? $input['shipping_address'] : [];
        $this->redaction->assertCompleteShipping($shipping);

        $created = $this->links->create([
            'kind' => PaymentLinkServiceInterface::KIND_HELP_PAY,
            'payable_type' => (string) ($input['payable_type'] ?? 'order'),
            'payable_id' => (string) ($input['payable_id'] ?? ''),
            'owner_customer_id' => isset($input['owner_customer_id']) ? (int) $input['owner_customer_id'] : null,
            'amount_minor' => (int) ($input['amount_minor'] ?? 0),
            'currency_code' => (string) ($input['currency_code'] ?? 'USD'),
            'shipping_locked' => true,
            'shipping_snapshot' => $shipping,
            'meta' => [
                'line_summary' => $input['line_summary'] ?? [],
                'discounts_disabled' => true,
                'mode' => 'help_pay',
            ],
            'ttl_seconds' => (int) ($input['ttl_seconds'] ?? 86400 * 7),
        ], (string) ($input['public_origin'] ?? ''));

        return $this->shareDeliveryPayload($created);
    }

    /**
     * @param array{
     *   selection_snapshot:array<string,mixed>,
     *   owner_customer_id?:int|null,
     *   public_origin?:string,
     *   ttl_seconds?:int,
     *   cart_type?:string
     * } $input
     * @return array<string,mixed>
     */
    public function createSelectionShare(array $input): array
    {
        $this->assertTocOnly((string) ($input['cart_type'] ?? self::CART_TYPE_TOC));
        $snapshot = is_array($input['selection_snapshot'] ?? null) ? $input['selection_snapshot'] : [];
        if ($snapshot === []) {
            throw new \InvalidArgumentException('helppay_selection_empty');
        }

        $created = $this->links->create([
            'kind' => PaymentLinkServiceInterface::KIND_SELECTION_SHARE,
            'owner_customer_id' => isset($input['owner_customer_id']) ? (int) $input['owner_customer_id'] : null,
            'selection_snapshot' => $snapshot,
            'shipping_locked' => false,
            'meta' => ['mode' => 'selection_share'],
            'ttl_seconds' => (int) ($input['ttl_seconds'] ?? 86400 * 14),
        ], (string) ($input['public_origin'] ?? ''));

        return $this->shareDeliveryPayload($created);
    }

    /**
     * @param array{
     *   owner_customer_id?:int|null,
     *   payable_type?:string,
     *   payable_id?:string,
     *   amount_minor:int,
     *   currency_code?:string,
     *   shipping_address:array<string,mixed>,
     *   public_origin?:string,
     *   ttl_seconds?:int,
     *   cart_type?:string
     * } $input
     * @return array<string,mixed>
     */
    /**
     * Quick-buy shipping options (real catalog weight; never silent 0.5kg).
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function listQuickShippingOptions(array $input): array
    {
        $this->assertTocOnly((string) ($input['cart_type'] ?? self::CART_TYPE_TOC));
        $shipping = is_array($input['shipping_address'] ?? null) ? $input['shipping_address'] : [];
        if ($shipping === [] && is_array($input['address'] ?? null)) {
            $shipping = $input['address'];
        }
        $this->redaction->assertCompleteShipping($shipping);
        $quoted = $this->quickShipping()->listOptions($input + [
            'shipping_address' => $shipping,
            'address' => $shipping,
        ]);

        return [
            'options' => $quoted['options'],
            'quote_diagnostics' => $quoted['quote_diagnostics'],
            'missing_weight' => !empty($quoted['missing_weight']),
        ];
    }

    public function createQuickPay(array $input): array
    {
        $this->assertTocOnly((string) ($input['cart_type'] ?? self::CART_TYPE_TOC));
        $shipping = is_array($input['shipping_address'] ?? null) ? $input['shipping_address'] : [];
        $this->redaction->assertCompleteShipping($shipping);

        $serviceCode = trim((string) ($input['service_code'] ?? $shipping['service_code'] ?? ''));
        $serviceLabel = trim((string) ($input['service_label'] ?? $shipping['service_label'] ?? $shipping['label'] ?? ''));
        $shippingMinor = max(0, (int) ($input['shipping_amount_minor'] ?? $shipping['shipping_amount_minor'] ?? 0));
        $amountMinor = max(0, (int) ($input['amount_minor'] ?? 0));
        if (array_key_exists('goods_amount_minor', $input) || array_key_exists('goods_amount_minor', $shipping)) {
            $goodsMinor = max(0, (int) ($input['goods_amount_minor'] ?? $shipping['goods_amount_minor'] ?? 0));
            if (!array_key_exists('amount_minor', $input)) {
                $amountMinor = $goodsMinor + $shippingMinor;
            }
        } else {
            $goodsMinor = max(0, $amountMinor - $shippingMinor);
        }

        // Align with checkout: when shipping is selected, re-quote with real weight (no client invent).
        if ($serviceCode !== '' || $shippingMinor > 0) {
            $productId = max(0, (int) ($input['product_id'] ?? $shipping['product_id'] ?? 0));
            if ($productId <= 0 && is_array($input['line_summary'] ?? null)) {
                foreach ($input['line_summary'] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $productId = max(0, (int) ($row['product_id'] ?? $row['id'] ?? 0));
                    if ($productId > 0) {
                        break;
                    }
                }
            }
            if ($productId <= 0) {
                throw new \InvalidArgumentException('helppay_product_required');
            }
            $this->quickShipping()->assertSelectedShipping(
                [
                    'product_id' => $productId,
                    'qty' => max(1, (int) ($input['qty'] ?? $input['qty_minor'] ?? 1)),
                    'goods_amount_minor' => $goodsMinor,
                    'currency_code' => (string) ($input['currency_code'] ?? 'USD'),
                    'shipping_address' => $shipping,
                    'address' => $shipping,
                    'line_summary' => $input['line_summary'] ?? [],
                ],
                $serviceCode,
                $shippingMinor,
            );
        }

        $snapshot = $shipping;
        if ($serviceCode !== '') {
            $snapshot['service_code'] = $serviceCode;
        }
        if ($serviceLabel !== '') {
            $snapshot['service_label'] = $serviceLabel;
            $snapshot['label'] = $serviceLabel;
        }
        if ($shippingMinor > 0 || array_key_exists('shipping_amount_minor', $input) || array_key_exists('shipping_amount_minor', $shipping)) {
            $snapshot['shipping_amount_minor'] = $shippingMinor;
        }

        $created = $this->links->create([
            'kind' => PaymentLinkServiceInterface::KIND_QUICK_PAY,
            'payable_type' => (string) ($input['payable_type'] ?? 'order'),
            'payable_id' => (string) ($input['payable_id'] ?? ''),
            'owner_customer_id' => isset($input['owner_customer_id']) ? (int) $input['owner_customer_id'] : null,
            'amount_minor' => $amountMinor,
            'currency_code' => (string) ($input['currency_code'] ?? 'USD'),
            // Popup checkout locks address + selected lane; does not write universal checkout session.
            'shipping_locked' => true,
            'shipping_snapshot' => $snapshot,
            'meta' => [
                'mode' => 'quick_pay_self',
                'session_isolation' => true,
                'service_code' => $serviceCode,
                'service_label' => $serviceLabel,
                'goods_amount_minor' => $goodsMinor,
                'shipping_amount_minor' => $shippingMinor,
                'line_summary' => $input['line_summary'] ?? [],
            ],
            // Align with help_pay default (7d): 1h TTL made验收/跨设备短链过早「链接不可用」。
            'ttl_seconds' => (int) ($input['ttl_seconds'] ?? 86400 * 7),
        ], (string) ($input['public_origin'] ?? ''));

        $payload = $this->shareDeliveryPayload($created);
        $payload['session_isolation'] = true;
        $payload['amount_minor'] = $amountMinor;
        $payload['goods_amount_minor'] = $goodsMinor;
        $payload['shipping_amount_minor'] = $shippingMinor;
        $payload['service_code'] = $serviceCode;
        $payload['service_label'] = $serviceLabel;

        return $payload;
    }

    /**
     * Payer-facing resolve: no shipping, discounts disabled, billing allowed.
     *
     * @return array<string,mixed>|null
     */
    public function resolveHelpPayForPayer(string $token): ?array
    {
        $row = $this->links->resolve($token, PaymentLinkServiceInterface::KIND_HELP_PAY);
        if ($row === null) {
            return null;
        }

        return $this->redaction->redactStorefront([
            'token' => $token,
            'payment_link_code' => $row['payment_link_code'] ?? '',
            'amount_minor' => $row['amount_minor'] ?? 0,
            'currency_code' => $row['currency_code'] ?? 'USD',
            'line_summary' => $row['meta']['line_summary'] ?? [],
            'discounts_allowed' => false,
            'shipping_locked' => true,
            'billing_editable' => true,
            'payable_type' => $row['payable_type'] ?? '',
            'payable_id' => $row['payable_id'] ?? '',
            'owner_customer_id' => $row['owner_customer_id'] ?? null,
            'session_isolation' => true,
            'load_payer_cart' => false,
        ], true);
    }

    /**
     * 本人快捷购买确认付款：与代付同编排，kind=quick_pay_self。
     *
     * @param array{token:string,payment_method?:string,billing_address?:array<string,mixed>,idempotency_key?:string} $input
     * @return array<string,mixed>
     */
    public function startQuickPayment(array $input): array
    {
        // 支付方式由调用方按 Provider 列表下发；缺失即视为「未选择」，
        // 交给 startLinkPayment 抛 helppay_payment_method_required，壳不编造兜底 code。
        $input['payment_method'] = strtolower(trim((string) ($input['payment_method'] ?? '')));

        return $this->startLinkPayment($input, PaymentLinkServiceInterface::KIND_QUICK_PAY, 'quick_pay_self');
    }

    /**
     * 代付人确认付款：创建 Payment 交易，返回 redirect_url（PayPal）或即时 paid（Fake）。
     *
     * @param array{
     *   token:string,
     *   payment_method:string,
     *   billing_address?:array<string,mixed>,
     *   idempotency_key?:string
     * } $input
     * @return array<string,mixed>
     */
    public function startPayerPayment(array $input): array
    {
        return $this->startLinkPayment($input, PaymentLinkServiceInterface::KIND_HELP_PAY, 'help_pay');
    }

    /**
     * @param array{
     *   token:string,
     *   payment_method?:string,
     *   billing_address?:array<string,mixed>,
     *   idempotency_key?:string
     * } $input
     * @return array<string,mixed>
     */
    private function startLinkPayment(array $input, string $kind, string $mode): array
    {
        $token = trim((string) ($input['token'] ?? ''));
        $method = strtolower(trim((string) ($input['payment_method'] ?? '')));
        if ($token === '' || $method === '') {
            throw new \InvalidArgumentException('helppay_payment_method_required');
        }

        if ($kind === PaymentLinkServiceInterface::KIND_HELP_PAY) {
            // Raw link for payment (country / payable); redacted resolve only validates active link.
            if ($this->resolveHelpPayForPayer($token) === null) {
                throw new \InvalidArgumentException('helppay_link_invalid');
            }
        }
        $row = $this->links->resolve($token, $kind);
        if ($row === null) {
            throw new \InvalidArgumentException($kind === PaymentLinkServiceInterface::KIND_QUICK_PAY
                ? 'helppay_quick_link_invalid'
                : 'helppay_link_invalid');
        }

        $amountMinor = max(0, (int) ($row['amount_minor'] ?? 0));
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('helppay_amount_invalid');
        }
        $currency = strtoupper(trim((string) ($row['currency_code'] ?? 'USD')));
        if ($currency === '') {
            $currency = 'USD';
        }

        $billing = \is_array($input['billing_address'] ?? null) ? $input['billing_address'] : [];
        $ship = \is_array($row['shipping_snapshot'] ?? null) ? $row['shipping_snapshot'] : [];
        // 本人快捷购买 /q/ 常只有收货信息，而需要账单地址的方式会拒付 —— 复用收货地址作账单。
        if ($mode === 'quick_pay_self' && !$this->billingAddressComplete($billing) && $ship !== []) {
            $billing = $this->billingFromShippingSnapshot($ship, $billing);
        }
        if ($this->paymentMethodRequiresBilling($method, $amountMinor, $currency) && !$this->billingAddressComplete($billing)) {
            throw new \InvalidArgumentException('helppay_billing_incomplete');
        }

        $payableId = trim((string) ($row['payable_id'] ?? ''));
        if ($payableId === '') {
            $payableId = trim((string) ($row['payment_link_code'] ?? $token));
        }
        $payableType = strtolower(trim((string) ($row['payable_type'] ?? '')));
        if ($payableType === '' || $payableType === 'helppay') {
            // Align with checkout / Payment eligibility defaults (order / weline_order).
            $payableType = 'order';
        }

        $idempotency = trim((string) ($input['idempotency_key'] ?? ''));
        if ($idempotency === '') {
            $idempotency = ($mode === 'quick_pay_self' ? 'quickpay_' : 'helppay_')
                . $token . '_' . $method . '_' . $amountMinor;
        }

        $customerId = $this->resolvePayerCustomerId();
        $actorType = $customerId > 0 ? 'customer' : 'guest';
        $actorId = $customerId > 0
            ? (string) $customerId
            : (($mode === 'quick_pay_self' ? 'quickpay:' : 'helppay:') . $token);

        $countryCode = strtoupper(trim((string) (
            $billing['country_code']
            ?? $billing['country']
            ?? $ship['country_code']
            ?? $ship['country']
            ?? ''
        )));
        if (\strlen($countryCode) > 2) {
            // shipping_snapshot may store display names; ISO-2 only when already short.
            $countryCode = strtoupper(trim((string) ($ship['country_code'] ?? $billing['country_code'] ?? '')));
        }

        try {
            /** @var \Weline\Payment\Api\PaymentFacadeInterface $facade */
            $facade = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Payment\Api\PaymentFacadeInterface::class
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException('helppay_payment_unavailable', 0, $e);
        }

        $subject = $mode === 'quick_pay_self' ? (string) __('快捷购买') : (string) __('帮我付');
        $description = $mode === 'quick_pay_self' ? (string) __('本人快捷购买') : (string) __('帮他人付款');
        $tags = $mode === 'quick_pay_self' ? ['helppay', 'quick_pay_self'] : ['helppay', 'help_pay'];

        $createContext = [
            'order_id' => $payableId,
            'payable_type' => $payableType,
            'payable_id' => $payableId,
            'amount' => $amountMinor / 100.0,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'currency_code' => $currency,
            'subject' => $subject,
            'description' => $description,
            'shipping_locked' => true,
            'billing_address' => $billing,
            'idempotency_key' => $idempotency,
            'business_tags' => $tags,
            'country_code' => $countryCode,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'payer_type' => $actorType,
            'payer_id' => $actorId,
            'customer_id' => $customerId > 0 ? $customerId : null,
            'metadata' => [
                'helppay_token' => $token,
                'mode' => $mode,
            ],
        ];
        try {
            $tx = $facade->tryCreatePayment($method, $createContext);
        } catch (\Throwable $e) {
            throw new \RuntimeException('helppay_payment_start_failed', 0, $e);
        }
        if (!$tx instanceof \Weline\Payment\Api\Data\PaymentTransactionRecord) {
            throw new \RuntimeException('helppay_payment_method_unavailable');
        }

        $response = \is_array($tx->response) ? $tx->response : [];
        $redirect = $this->extractPaymentRedirectUrl($response);
        $status = strtolower(trim((string) $tx->status));
        $paidStatuses = ['paid', 'success', 'succeeded', 'completed', 'captured'];
        $paid = $redirect === '' && \in_array($status, $paidStatuses, true);
        $transactionNo = trim((string) $tx->transactionNumber);
        // 即时成功的 Provider 必须落到 Payment L1，像素与成功 UC 才能触发。
        if ($paid && $transactionNo !== '') {
            $redirect = '/payment/success?' . http_build_query([
                'transaction_no' => $transactionNo,
            ], '', '&', PHP_QUERY_RFC3986);
        }

        return [
            'ok' => true,
            'success' => true,
            'token' => $token,
            'transaction_no' => $transactionNo,
            'status' => $tx->status,
            'method_code' => $tx->methodCode,
            'redirect_url' => $redirect,
            'approve_url' => $redirect,
            'success_url' => $paid ? $redirect : '',
            'requires_action' => $redirect !== '' && !$paid,
            'paid' => $paid,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function resolveSelectionShare(string $token): ?array
    {
        return $this->links->resolve($token, PaymentLinkServiceInterface::KIND_SELECTION_SHARE);
    }

    private function resolvePayerCustomerId(): int
    {
        try {
            if (\function_exists('w_session')) {
                $direct = (int) (w_session('customer_id') ?? 0);
                if ($direct > 0) {
                    return $direct;
                }
                $bag = w_session('customer');
                if (\is_array($bag)) {
                    $fromBag = (int) ($bag['id'] ?? $bag['customer_id'] ?? 0);
                    if ($fromBag > 0) {
                        return $fromBag;
                    }
                }
            }
        } catch (\Throwable) {
            // Guest payer is the default for friend-pay links.
        }

        return 0;
    }

    private function paymentMethodRequiresBilling(string $methodCode, int $amountMinor, string $currency): bool
    {
        try {
            /** @var \Weline\Checkout\Service\CheckoutPaymentMethodsProvider $provider */
            $provider = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Checkout\Service\CheckoutPaymentMethodsProvider::class
            );
            foreach ($provider->listMethods([
                'currency' => $currency,
                'amount' => $amountMinor / 100.0,
                'amount_minor' => $amountMinor,
                'payable_type' => 'helppay',
            ]) as $method) {
                if (!\is_array($method)) {
                    continue;
                }
                if (strtolower(trim((string) ($method['code'] ?? ''))) !== $methodCode) {
                    continue;
                }

                return !empty($method['requires_billing']);
            }
        } catch (\Throwable) {
            // Fall through to code heuristic.
        }

        return str_contains($methodCode, 'card') || str_contains($methodCode, 'stripe');
    }

    /**
     * @param array<string,mixed> $billing
     */
    private function billingAddressComplete(array $billing): bool
    {
        $name = trim((string) ($billing['name'] ?? ''));
        $phone = trim((string) ($billing['phone'] ?? ''));
        $line1 = trim((string) ($billing['line1'] ?? $billing['address1'] ?? ''));
        $country = trim((string) ($billing['country_code'] ?? $billing['country'] ?? ''));

        return $name !== '' && $phone !== '' && $line1 !== '' && $country !== '';
    }

    /**
     * @param array<string,mixed> $ship
     * @param array<string,mixed> $billing
     * @return array<string,mixed>
     */
    private function billingFromShippingSnapshot(array $ship, array $billing): array
    {
        $merged = $billing;
        $map = [
            'name' => ['name', 'fullname_name', 'contact_name'],
            'phone' => ['phone', 'telephone', 'mobile'],
            'line1' => ['line1', 'address1', 'street', 'address'],
            'line2' => ['line2', 'address2'],
            'city' => ['city'],
            'province' => ['province', 'state', 'region'],
            'postal_code' => ['postal_code', 'postcode', 'zip'],
            'country_code' => ['country_code', 'country'],
            'country' => ['country', 'country_code'],
        ];
        foreach ($map as $target => $sources) {
            if (trim((string) ($merged[$target] ?? '')) !== '') {
                continue;
            }
            foreach ($sources as $source) {
                $value = trim((string) ($ship[$source] ?? ''));
                if ($value !== '') {
                    $merged[$target] = $value;
                    break;
                }
            }
        }
        if (trim((string) ($merged['name'] ?? '')) === '') {
            $merged['name'] = (string) __('快捷购买买家');
        }
        if (trim((string) ($merged['phone'] ?? '')) === '') {
            $merged['phone'] = '00000000000';
        }

        return $merged;
    }

    /**
     * @param array<string,mixed> $response
     */
    private function extractPaymentRedirectUrl(array $response): string
    {
        foreach (['redirect_url', 'approve_url', 'redirect', 'url'] as $key) {
            $candidate = trim((string) ($response[$key] ?? ''));
            if ($this->isSafePaymentRedirectUrl($candidate)) {
                return $candidate;
            }
        }
        foreach (['payload', 'gateway_response', 'response', 'next_action'] as $key) {
            $nested = $response[$key] ?? null;
            if (\is_array($nested)) {
                $candidate = $this->extractPaymentRedirectUrl($nested);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return '';
    }

    private function isSafePaymentRedirectUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return \in_array($scheme, ['http', 'https'], true)
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private function assertTocOnly(string $cartType): void
    {
        if (strtolower(trim($cartType)) !== self::CART_TYPE_TOC) {
            throw new \InvalidArgumentException('helppay_toc_only');
        }
    }

    private function quickShipping(): HelpPayQuickShippingQuoteService
    {
        if ($this->quickShipping instanceof HelpPayQuickShippingQuoteService) {
            return $this->quickShipping;
        }
        try {
            $resolved = ObjectManager::getInstance(HelpPayQuickShippingQuoteService::class);
            if ($resolved instanceof HelpPayQuickShippingQuoteService) {
                return $resolved;
            }
        } catch (\Throwable) {
        }

        return new HelpPayQuickShippingQuoteService();
    }

    /**
     * @param array<string,mixed> $created
     * @return array<string,mixed>
     */
    private function shareDeliveryPayload(array $created): array
    {
        return [
            'ok' => true,
            'kind' => $created['kind'],
            'token' => $created['token'],
            'payment_link_code' => $created['payment_link_code'],
            'url' => $created['absolute_url'],
            'path' => $created['path'],
            'expires_at' => $created['expires_at'],
            'share_delivery' => [
                'copy_url' => true,
                'copy_qr_image' => true,
                'show_url' => true,
                'show_qr' => true,
            ],
        ];
    }
}
