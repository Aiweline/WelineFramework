<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\B2B\Model\B2BOrderHang;
use Weline\Checkout\Service\CheckoutOrderPaymentService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\OrderFacadeInterface;

/**
 * Storefront hang deposit/balance payment entry (account CTA → checkout purpose).
 */
final class B2BHangPaymentService
{
    public const ERROR_AUTH = 'b2b_hang_payment_auth_required';
    public const ERROR_FORBIDDEN = 'b2b_hang_payment_forbidden';
    public const ERROR_PURPOSE = 'b2b_hang_payment_purpose_invalid';
    public const ERROR_STATE = 'b2b_hang_payment_state_invalid';
    public const ERROR_METHOD = 'b2b_hang_payment_method_required';

    public function __construct(
        private readonly B2BHangOrderService $hangOrders,
        private readonly ?OrderFacadeInterface $orders = null,
        private readonly ?CheckoutOrderPaymentService $payments = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function paymentContext(string $orderUuid, string $purpose, ?int $customerId): array
    {
        $purpose = strtolower(trim($purpose));
        $hang = $this->requireHangOwnedByCustomer($orderUuid, $purpose, $customerId);
        $amountMinor = $purpose === B2BHangOrderService::PURPOSE_DEPOSIT
            ? $hang->depositAmountMinor
            : $hang->balanceAmountMinor;
        $fullDeposit = $hang->depositAmountMinor;
        $cashDeposit = $fullDeposit;
        $creditApply = 0;
        $currency = 'CNY';
        $orderSummary = $this->emptyOrderSummary($hang, $amountMinor);
        try {
            $read = $this->orders()->get($hang->orderRef);
            $currency = strtoupper((string)($read->currency ?: $currency));
            $tp = $read->typePayload;
            if ($purpose === B2BHangOrderService::PURPOSE_DEPOSIT
                && array_key_exists('b2b_credit_cash_deposit_minor', $tp)
            ) {
                $cashDeposit = max(0, (int)$tp['b2b_credit_cash_deposit_minor']);
                $amountMinor = $cashDeposit;
                $creditApply = max(0, (int)($tp['b2b_credit_apply_checkout_minor'] ?? 0));
            }
            $orderSummary = $this->buildOrderSummary($hang, $read, $amountMinor);
        } catch (\Throwable) {
            // Fall back to hang full deposit / empty lines.
        }

        $canPay = $this->isHangPurposePayable($hang, $purpose);
        $viewState = 'payable';
        $blockMessage = '';
        $label = $purpose === B2BHangOrderService::PURPOSE_DEPOSIT
            ? (string)__('支付定金')
            : (string)__('支付尾款');
        if (!$canPay) {
            if ($purpose === B2BHangOrderService::PURPOSE_BALANCE && $this->isBalanceRevisionPending($hang->orderRef)) {
                $viewState = 'revision_pending';
                $blockMessage = (string)__('尾款改价待确认，暂不可支付');
            } elseif ($hang->hangStatus === B2BOrderHang::STATUS_COMPLETED) {
                $viewState = 'completed';
                $label = $purpose === B2BHangOrderService::PURPOSE_DEPOSIT
                    ? (string)__('定金已结清')
                    : (string)__('挂单已完成');
                $blockMessage = $purpose === B2BHangOrderService::PURPOSE_DEPOSIT
                    ? (string)__('本单定金已付清，无需再支付')
                    : (string)__('本单尾款已结清，无需再支付');
            } else {
                $viewState = 'blocked';
                $statusLabel = $this->hangStatusLabel($hang->hangStatus);
                $blockMessage = (string)__(
                    '当前挂单状态为「%{1}」，无法继续支付',
                    [$statusLabel !== '' ? $statusLabel : $hang->hangStatus]
                );
            }
        }

        return [
            'success' => true,
            'ok' => true,
            'order_uuid' => $hang->orderRef,
            'purpose' => $purpose,
            'hang_status' => $hang->hangStatus,
            'can_pay' => $canPay,
            'view_state' => $viewState,
            'message' => $blockMessage,
            'amount_minor' => $amountMinor,
            'deposit_amount_minor' => $fullDeposit,
            'b2b_credit_cash_deposit_minor' => $cashDeposit,
            'b2b_credit_apply_checkout_minor' => $creditApply,
            'balance_amount_minor' => $hang->balanceAmountMinor,
            'currency' => $currency,
            'payment_methods' => $canPay ? $this->loadPaymentMethods($amountMinor, $currency) : [],
            'revision_pending' => $this->isBalanceRevisionPending($hang->orderRef),
            'revision_version' => $this->balanceRevisionVersion($hang->orderRef),
            'order_summary' => $orderSummary,
            'label' => $label,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyOrderSummary(B2BOrderHang $hang, int $payableMinor): array
    {
        return [
            'display_number' => '',
            'order_uuid' => $hang->orderRef,
            'hang_status' => $hang->hangStatus,
            'hang_status_label' => $this->hangStatusLabel($hang->hangStatus),
            'lines' => [],
            'goods_subtotal_minor' => max(0, (int)$hang->goodsSubtotalTaxedMinor),
            'deposit_amount_minor' => max(0, (int)$hang->depositAmountMinor),
            'balance_amount_minor' => max(0, (int)$hang->balanceAmountMinor),
            'payable_minor' => max(0, $payableMinor),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildOrderSummary(B2BOrderHang $hang, \Weline\Order\Api\Data\OrderReadResult $read, int $payableMinor): array
    {
        $lines = [];
        $goodsSubtotal = 0;
        $imageByProduct = $this->resolveProductImageUrls(
            (int)$read->websiteId,
            $read->items,
        );
        foreach ($read->items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qty = max(0, (int)($item['qty_minor'] ?? 0));
            $unit = max(0, (int)($item['unit_price_minor'] ?? 0));
            $row = max(0, (int)($item['row_total_minor'] ?? ($qty * $unit)));
            $goodsSubtotal += $row;
            $name = trim((string)($item['name'] ?? $item['product_name'] ?? ''));
            $sku = trim((string)($item['sku'] ?? ''));
            if ($name === '' && $sku === '' && $qty <= 0) {
                continue;
            }
            $productId = (int)($item['product_id'] ?? 0);
            $imageUrl = trim((string)($item['image_url'] ?? $item['image'] ?? $item['thumbnail'] ?? ''));
            if ($imageUrl === '' && $productId > 0) {
                $imageUrl = (string)($imageByProduct[$productId] ?? '');
            }
            if ($imageUrl !== '' && str_starts_with(strtolower($imageUrl), 'asset://')) {
                $imageUrl = $this->presentStorefrontImageUrl($imageUrl, (int)$read->websiteId);
            }
            $lines[] = [
                'name' => $name !== '' ? $name : ($sku !== '' ? $sku : (string)__('商品')),
                'sku' => $sku,
                'qty_minor' => $qty,
                'unit_price_minor' => $unit,
                'row_total_minor' => $row,
                'image_url' => $imageUrl,
                'product_id' => $productId,
            ];
        }
        if ($goodsSubtotal <= 0) {
            $goodsSubtotal = max(0, (int)$hang->goodsSubtotalTaxedMinor);
        }
        $display = trim((string)($read->displayNumber ?? ''));

        return [
            'display_number' => $display,
            'order_uuid' => $hang->orderRef,
            'hang_status' => $hang->hangStatus,
            'hang_status_label' => $this->hangStatusLabel($hang->hangStatus),
            'lines' => $lines,
            'goods_subtotal_minor' => $goodsSubtotal,
            'deposit_amount_minor' => max(0, (int)$hang->depositAmountMinor),
            'balance_amount_minor' => max(0, (int)$hang->balanceAmountMinor),
            'payable_minor' => max(0, $payableMinor),
        ];
    }

    /**
     * Batch-resolve storefront-presentable product thumbnails for hang order lines.
     *
     * @param list<mixed> $items
     * @return array<int, string>
     */
    private function resolveProductImageUrls(int $websiteId, array $items): array
    {
        $productIds = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = (int)($item['product_id'] ?? 0);
            if ($productId > 0) {
                $productIds[$productId] = $productId;
            }
        }
        if ($productIds === []) {
            return [];
        }
        try {
            if (!class_exists(\Weline\Product\Repository\MediaRepository::class)) {
                return [];
            }
            $media = ObjectManager::getInstance(\Weline\Product\Repository\MediaRepository::class);
            if (!$media instanceof \Weline\Product\Repository\MediaRepository) {
                return [];
            }
            $rows = $media->listByProductIds($websiteId, array_values($productIds));
            $pathByProduct = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $productId = (int)($row['product_id'] ?? 0);
                if ($productId <= 0 || isset($pathByProduct[$productId])) {
                    continue;
                }
                $role = strtolower(trim((string)($row['role'] ?? '')));
                $path = trim((string)($row['path'] ?? ''));
                if ($path === '') {
                    continue;
                }
                // Prefer main; otherwise keep first seen path.
                if ($role === 'main' || !isset($pathByProduct[$productId])) {
                    $pathByProduct[$productId] = $path;
                }
            }
            // Second pass: ensure main wins even if seen later.
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (strtolower(trim((string)($row['role'] ?? ''))) !== 'main') {
                    continue;
                }
                $productId = (int)($row['product_id'] ?? 0);
                $path = trim((string)($row['path'] ?? ''));
                if ($productId > 0 && $path !== '') {
                    $pathByProduct[$productId] = $path;
                }
            }
            $out = [];
            foreach ($pathByProduct as $productId => $path) {
                $url = $this->presentStorefrontImageUrl($path, $websiteId);
                if ($url !== '') {
                    $out[(int)$productId] = $url;
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private function presentStorefrontImageUrl(string $reference, int $websiteId): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return '';
        }
        $lower = strtolower($reference);
        if (str_starts_with($lower, 'https://')
            || str_starts_with($lower, 'http://')
            || str_starts_with($lower, 'data:image/')
            || (str_starts_with($reference, '/') && !str_starts_with($reference, '//'))
        ) {
            return $reference;
        }
        try {
            if (!class_exists(\Weline\Product\Service\StorefrontProductMediaUrlResolver::class)
                || !class_exists(\Weline\Framework\Runtime\ScopeIdentity::class)
            ) {
                return str_starts_with($lower, 'asset://') ? '' : $reference;
            }
            $resolver = ObjectManager::getInstance(\Weline\Product\Service\StorefrontProductMediaUrlResolver::class);
            if (!$resolver instanceof \Weline\Product\Service\StorefrontProductMediaUrlResolver) {
                return '';
            }
            $websiteCode = 'base';
            if (class_exists(\Weline\Websites\Data\WebsiteData::class)) {
                $code = \Weline\Websites\Data\WebsiteData::getCode();
                if (is_string($code) && trim($code) !== '') {
                    $websiteCode = trim($code);
                }
            }
            $scope = \Weline\Framework\Runtime\ScopeIdentity::website(max(0, $websiteId), $websiteCode);

            return $resolver->resolveReference($reference, $scope, 'zh_Hans_CN');
        } catch (\Throwable) {
            return '';
        }
    }

    private function hangStatusLabel(string $status): string
    {
        return match ($status) {
            B2BOrderHang::STATUS_AWAITING_DEPOSIT => (string)__('待付定金'),
            B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL => (string)__('待商家确认'),
            B2BOrderHang::STATUS_AWAITING_BALANCE => (string)__('待付尾款'),
            B2BOrderHang::STATUS_COMPLETED => (string)__('挂单已完成'),
            default => $status,
        };
    }

    /**
     * Active checkout payment methods for hang panel (fail-soft empty list).
     *
     * @return list<array{code:string,title:string}>
     */
    private function loadPaymentMethods(int $amountMinor, string $currency): array
    {
        try {
            if (!function_exists('w_query')) {
                return $this->fallbackActiveMethodCodes();
            }
            $result = w_query('payment', 'getCheckoutPaymentMethods', [
                'currency' => $currency,
                'amount' => $amountMinor / 100.0,
                'amount_minor' => $amountMinor,
            ]);
            $list = [];
            if (is_array($result)) {
                $raw = is_array($result['data'] ?? null) ? $result['data'] : $result;
                if (is_array($raw)) {
                    $list = $raw;
                }
            }
            $out = [];
            foreach ($list as $method) {
                if (!is_array($method)) {
                    continue;
                }
                $code = strtolower(trim((string)($method['code'] ?? '')));
                if ($code === '') {
                    continue;
                }
                if (array_key_exists('enabled', $method) && !$method['enabled']) {
                    continue;
                }
                $title = trim((string)($method['title'] ?? $method['name'] ?? $code));
                $out[] = [
                    'code' => $code,
                    'title' => $title !== '' ? $title : $code,
                ];
            }

            return $out !== [] ? $out : $this->fallbackActiveMethodCodes();
        } catch (\Throwable) {
            return $this->fallbackActiveMethodCodes();
        }
    }

    /**
     * @return list<array{code:string,title:string}>
     */
    private function fallbackActiveMethodCodes(): array
    {
        try {
            if (!class_exists(\Weline\Payment\Service\PaymentMethodManager::class)) {
                return [];
            }
            $mgr = ObjectManager::getInstance(\Weline\Payment\Service\PaymentMethodManager::class);
            if (!$mgr instanceof \Weline\Payment\Service\PaymentMethodManager) {
                return [];
            }
            $out = [];
            foreach ($mgr->getActiveMethods([]) as $method) {
                if (!$method instanceof \Weline\Payment\Model\PaymentMethod) {
                    continue;
                }
                $code = strtolower(trim((string)$method->getData(\Weline\Payment\Model\PaymentMethod::schema_fields_CODE)));
                if ($code === '') {
                    continue;
                }
                $title = trim((string)$method->getData(\Weline\Payment\Model\PaymentMethod::schema_fields_NAME));
                $out[] = [
                    'code' => $code,
                    'title' => $title !== '' ? $title : $code,
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function startPayment(
        string $orderUuid,
        string $purpose,
        string $paymentMethod,
        string $paymentIdempotencyKey,
        ?int $customerId,
        array $context = [],
    ): array {
        $hang = $this->requireHangForCustomer($orderUuid, $purpose, $customerId);
        $paymentMethod = strtolower(trim($paymentMethod));
        $paymentIdempotencyKey = trim($paymentIdempotencyKey);
        if ($paymentMethod === '') {
            throw new B2BConflictException(self::ERROR_METHOD, __('请选择支付方式'));
        }
        if ($paymentIdempotencyKey === '' || strlen($paymentIdempotencyKey) > 128) {
            throw new B2BConflictException(self::ERROR_PURPOSE, __('支付幂等键无效'));
        }

        $payContext = [
            'purpose' => $purpose,
            'hang_purpose' => $purpose,
            'balance_amount_minor' => $hang->balanceAmountMinor,
            'country_code' => (string)($context['country_code'] ?? ''),
            'locale' => (string)($context['locale'] ?? ''),
            'quote_token' => (string)($context['quote_token'] ?? ''),
            'checkout_token' => (string)($context['checkout_token'] ?? $context['quote_token'] ?? ''),
        ];
        $explicitEnvironment = strtolower(trim((string)($context['environment'] ?? '')));
        if ($explicitEnvironment === 'sandbox' || $explicitEnvironment === 'live') {
            $payContext['environment'] = $explicitEnvironment;
        }
        // Prefer order type_payload cash deposit; do not force full hang deposit.
        if ($purpose === B2BHangOrderService::PURPOSE_DEPOSIT) {
            try {
                $tp = $this->orders()->get($hang->orderRef)->typePayload;
                if (array_key_exists('b2b_credit_cash_deposit_minor', $tp)) {
                    $payContext['deposit_amount_minor'] = max(0, (int)$tp['b2b_credit_cash_deposit_minor']);
                } else {
                    $payContext['deposit_amount_minor'] = $hang->depositAmountMinor;
                }
            } catch (\Throwable) {
                $payContext['deposit_amount_minor'] = $hang->depositAmountMinor;
            }
        }

        $payment = $this->payments()->pay(
            [$hang->orderRef],
            $paymentMethod,
            $paymentIdempotencyKey,
            $payContext,
        );

        $updated = $this->hangOrders->getByOrderRef($hang->orderRef);

        return [
            'success' => true,
            'ok' => true,
            'order_uuid' => $hang->orderRef,
            'purpose' => $purpose,
            'hang_status' => $updated?->hangStatus ?? $hang->hangStatus,
            'payment' => $payment,
        ];
    }

    private function requireHangForCustomer(string $orderUuid, string $purpose, ?int $customerId): B2BOrderHang
    {
        $hang = $this->requireHangOwnedByCustomer($orderUuid, $purpose, $customerId);
        $this->assertHangPurposePayable($hang, $purpose);

        return $hang;
    }

    private function requireHangOwnedByCustomer(string $orderUuid, string $purpose, ?int $customerId): B2BOrderHang
    {
        $orderUuid = trim($orderUuid);
        $purpose = strtolower(trim($purpose));
        if ($customerId === null || $customerId <= 0) {
            throw new B2BConflictException(self::ERROR_AUTH, __('请先登录后再继续支付'));
        }
        if (!in_array($purpose, [
            B2BHangOrderService::PURPOSE_DEPOSIT,
            B2BHangOrderService::PURPOSE_BALANCE,
        ], true)) {
            throw new B2BConflictException(self::ERROR_PURPOSE, __('支付用途无效'));
        }
        if ($orderUuid === '') {
            throw new B2BConflictException(self::ERROR_PURPOSE, __('订单编号无效'));
        }

        $hang = $this->hangOrders->getByOrderRef($orderUuid);
        if ($hang === null) {
            $msg = $purpose === B2BHangOrderService::PURPOSE_BALANCE
                ? __('尾款挂单不存在或已失效，请从「批发身份」打开有效挂单')
                : __('定金挂单不存在或已失效');
            throw new B2BConflictException(B2BHangOrderService::ERROR_NOT_FOUND, $msg, ['order_ref' => $orderUuid]);
        }
        if ((string)$hang->customerId !== (string)$customerId) {
            throw new B2BConflictException(self::ERROR_FORBIDDEN, __('无权支付该挂单'));
        }

        // Soft ownership check against Order projection when available.
        try {
            $order = $this->orders()->get($orderUuid);
            $owner = (int)($order->customerId ?? 0);
            if ($owner > 0 && $owner !== $customerId) {
                throw new B2BConflictException(self::ERROR_FORBIDDEN, __('无权支付该订单'));
            }
        } catch (B2BConflictException $e) {
            throw $e;
        } catch (\Throwable) {
            // Order read is optional for hang-only fixtures.
        }

        return $hang;
    }

    private function isHangPurposePayable(B2BOrderHang $hang, string $purpose): bool
    {
        $purpose = strtolower(trim($purpose));
        $expected = $purpose === B2BHangOrderService::PURPOSE_DEPOSIT
            ? B2BOrderHang::STATUS_AWAITING_DEPOSIT
            : B2BOrderHang::STATUS_AWAITING_BALANCE;
        if ($hang->hangStatus !== $expected) {
            return false;
        }
        if ($purpose === B2BHangOrderService::PURPOSE_BALANCE && $this->isBalanceRevisionPending($hang->orderRef)) {
            return false;
        }

        return true;
    }

    private function assertHangPurposePayable(B2BOrderHang $hang, string $purpose): void
    {
        $purpose = strtolower(trim($purpose));
        $expected = $purpose === B2BHangOrderService::PURPOSE_DEPOSIT
            ? B2BOrderHang::STATUS_AWAITING_DEPOSIT
            : B2BOrderHang::STATUS_AWAITING_BALANCE;
        if ($hang->hangStatus !== $expected) {
            throw new B2BConflictException(
                self::ERROR_STATE,
                __('当前挂单状态不可支付'),
                ['hang_status' => $hang->hangStatus, 'purpose' => $purpose],
            );
        }
        if ($purpose === B2BHangOrderService::PURPOSE_BALANCE) {
            $this->assertBalanceRevisionNotPending($hang->orderRef);
        }
    }

    private function assertBalanceRevisionNotPending(string $orderRef): void
    {
        if ($this->isBalanceRevisionPending($orderRef)) {
            throw new B2BConflictException(
                self::ERROR_STATE,
                __('尾款改价待确认，暂不可支付'),
                ['order_ref' => $orderRef],
            );
        }
    }

    private function isBalanceRevisionPending(string $orderRef): bool
    {
        try {
            $tp = $this->orders()->get($orderRef)->typePayload;

            return (bool)($tp['hang_revision_pending'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    private function balanceRevisionVersion(string $orderRef): int
    {
        try {
            $tp = $this->orders()->get($orderRef)->typePayload;

            return max(0, (int)($tp['hang_revision_version'] ?? 0));
        } catch (\Throwable) {
            return 0;
        }
    }

    private function orders(): OrderFacadeInterface
    {
        if ($this->orders instanceof OrderFacadeInterface) {
            return $this->orders;
        }
        $resolved = ObjectManager::getInstance(OrderFacadeInterface::class);
        if (!$resolved instanceof OrderFacadeInterface) {
            throw new \RuntimeException('b2b_hang_payment_order_facade_missing');
        }

        return $resolved;
    }

    private function payments(): CheckoutOrderPaymentService
    {
        if ($this->payments instanceof CheckoutOrderPaymentService) {
            return $this->payments;
        }
        $resolved = ObjectManager::getInstance(CheckoutOrderPaymentService::class);
        if (!$resolved instanceof CheckoutOrderPaymentService) {
            throw new \RuntimeException('b2b_hang_payment_checkout_payment_missing');
        }

        return $resolved;
    }
}
