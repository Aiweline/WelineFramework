<?php

declare(strict_types=1);

namespace Weline\Points\Service;

use Weline\CustomerAsset\Api\CustomerAssetFacadeInterface;

/**
 * Points checkout discount quote.
 *
 * Points are converted to money through the Payment exchange ratio, then
 * clamped by the configured discount cap (default 30%) and the customer's
 * spendable points balance.
 */
final class PointsCheckoutDiscountQuote
{
    public const REASON_DISABLED = 'points_discount_disabled';
    public const REASON_PAYMENT_OFF = 'payment_points_disabled';
    public const REASON_NOT_LOGGED_IN = 'not_logged_in';
    public const REASON_NO_BALANCE = 'no_points_balance';
    public const REASON_FX_UNAVAILABLE = 'fx_unavailable';
    public const REASON_ZERO_CAP = 'zero_cap';

    private readonly PointsBaseCurrencyResolver $baseCurrency;

    public function __construct(
        private readonly CustomerAssetFacadeInterface $assets,
        private readonly PointsConfig $config,
        ?PointsBaseCurrencyResolver $baseCurrency = null,
    ) {
        $this->baseCurrency = $baseCurrency ?? new PointsBaseCurrencyResolver();
    }

    /**
     * @return array<string, mixed>
     */
    public function quote(
        string $customerId,
        int $websiteId,
        string $checkoutCurrency,
        int $cartAmountMinor,
        string $namespace = 'live',
    ): array {
        $base = $this->baseCurrency->forWebsite($websiteId);
        $checkout = strtoupper(trim($checkoutCurrency)) ?: $base;
        $cartAmountMinor = max(0, $cartAmountMinor);
        $exchangeRatio = $this->config->paymentExchangeRatio();
        $maxRatio = $this->config->discountMaxRatio();
        $empty = [
            'enabled' => false,
            'reason' => '',
            'website_id' => $websiteId,
            'base_currency' => $base,
            'checkout_currency' => $checkout,
            'exchange_ratio' => $exchangeRatio,
            'max_ratio' => $maxRatio,
            'points_available' => 0,
            'points_max' => 0,
            'cart_amount_minor' => $cartAmountMinor,
            'available_base_minor' => 0,
            'available_checkout_minor' => 0,
            'max_apply_checkout_minor' => 0,
            'fx' => null,
            'hint_short' => '',
            'hint_detail' => '',
        ];

        if (!$this->config->discountEnabled()) {
            return array_merge($empty, [
                'reason' => self::REASON_DISABLED,
                'hint_short' => (string) __('站点未开启积分抵扣'),
            ]);
        }
        if (!$this->config->paymentPointsEnabled() || $exchangeRatio <= 0) {
            return array_merge($empty, [
                'reason' => self::REASON_PAYMENT_OFF,
                'hint_short' => (string) __('积分暂不可抵扣'),
                'hint_detail' => (string) __('后台尚未启用积分支付资产或兑换比例。'),
            ]);
        }
        $customerId = trim($customerId);
        if ($customerId === '') {
            return array_merge($empty, [
                'reason' => self::REASON_NOT_LOGGED_IN,
                'hint_short' => (string) __('请先登录后再使用积分'),
            ]);
        }
        if ($cartAmountMinor <= 0) {
            return array_merge($empty, [
                'reason' => self::REASON_ZERO_CAP,
                'hint_short' => (string) __('购物车金额为 0，暂不能使用积分'),
            ]);
        }

        $namespace = in_array($namespace, ['live', 'sandbox'], true) ? $namespace : 'live';
        $balance = $this->assets->getBalance($customerId, $websiteId, PointsService::ASSET_CODE, $namespace);
        $pointsAvailable = max(0, (int) ($balance['available_minor'] ?? 0));
        if ($pointsAvailable <= 0) {
            return array_merge($empty, [
                'reason' => self::REASON_NO_BALANCE,
                'hint_short' => (string) __('暂无可用积分'),
            ]);
        }

        $basePrecision = OrderPointsContext::precisionForCurrency($base);
        $availableBase = (int) round($pointsAvailable * $exchangeRatio * (10 ** $basePrecision));
        $availableCheckout = $this->baseCurrency->convertMinor(
            $availableBase,
            $base,
            $checkout,
            $websiteId,
            $basePrecision,
        );
        if ($availableCheckout === null) {
            return array_merge($empty, [
                'reason' => self::REASON_FX_UNAVAILABLE,
                'points_available' => $pointsAvailable,
                'available_base_minor' => $availableBase,
                'hint_short' => (string) __('暂无汇率，无法使用积分'),
            ]);
        }

        $checkoutPrecision = OrderPointsContext::precisionForCurrency($checkout);
        $capCheckoutMinor = (int) floor($cartAmountMinor * $maxRatio);
        $maxApplyCheckout = min((int) $availableCheckout['amount_minor'], $capCheckoutMinor);
        $maxApplyBase = $this->baseCurrency->convertMinor(
            $maxApplyCheckout,
            $checkout,
            $base,
            $websiteId,
            $checkoutPrecision,
        );
        $maxPoints = ($exchangeRatio > 0 && $maxApplyBase !== null)
            ? (int) floor(($maxApplyBase['amount_minor'] / (10 ** $basePrecision)) / $exchangeRatio)
            : 0;
        $maxPoints = min($pointsAvailable, max(0, $maxPoints));

        $maxAmt = number_format($maxApplyCheckout / (10 ** $checkoutPrecision), $checkoutPrecision, '.', '');
        $pointsAvail = (string) $pointsAvailable;

        return [
            'enabled' => $maxApplyCheckout > 0,
            'reason' => $maxApplyCheckout > 0 ? '' : self::REASON_ZERO_CAP,
            'website_id' => $websiteId,
            'base_currency' => $base,
            'checkout_currency' => $checkout,
            'exchange_ratio' => $exchangeRatio,
            'max_ratio' => $maxRatio,
            'points_available' => $pointsAvailable,
            'points_max' => $maxPoints,
            'cart_amount_minor' => $cartAmountMinor,
            'available_base_minor' => $availableBase,
            'available_checkout_minor' => (int) $availableCheckout['amount_minor'],
            'max_apply_checkout_minor' => $maxApplyCheckout,
            'fx' => $availableCheckout['fx'],
            'hint_short' => $maxApplyCheckout > 0
                ? (string) __('可用 %{1} 积分，本单最多抵扣 %{2} %{3}', [$pointsAvail, $maxAmt, $checkout])
                : (string) __('本单可抵扣积分为 0'),
            'hint_detail' => (string) __('积分按后台兑换比例折算，单笔最多抵扣订单金额的 %{1}%；抵扣后余额不足部分不可透支。', [
                number_format($maxRatio * 100, 0, '.', ''),
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $quote
     * @return array{
     *   points:int,
     *   apply_checkout_minor:int,
     *   apply_base_minor:int,
     *   fx:?array<string,mixed>,
     *   adjusted:bool
     * }
     */
    public function clampApply(array $quote, int $requestedCheckoutMinor): array
    {
        $requestedCheckoutMinor = max(0, (int) $requestedCheckoutMinor);
        $maxApply = max(0, (int) ($quote['max_apply_checkout_minor'] ?? 0));
        $applyCheckout = min($requestedCheckoutMinor, $maxApply);
        $checkout = strtoupper(trim((string) ($quote['checkout_currency'] ?? '')));
        $base = strtoupper(trim((string) ($quote['base_currency'] ?? '')));
        $websiteId = max(0, (int) ($quote['website_id'] ?? 0));
        $ratio = max(0.0, (float) ($quote['exchange_ratio'] ?? 0));
        $pointsAvailable = max(0, (int) ($quote['points_available'] ?? 0));
        $checkoutPrecision = OrderPointsContext::precisionForCurrency($checkout);
        $basePrecision = OrderPointsContext::precisionForCurrency($base);

        if ($applyCheckout <= 0 || $ratio <= 0) {
            return [
                'points' => 0,
                'apply_checkout_minor' => 0,
                'apply_base_minor' => 0,
                'fx' => \is_array($quote['fx'] ?? null) ? $quote['fx'] : null,
                'adjusted' => $requestedCheckoutMinor > 0,
            ];
        }

        $toBase = $this->baseCurrency->convertMinor(
            $applyCheckout,
            $checkout,
            $base,
            $websiteId,
            $checkoutPrecision,
        );
        if ($toBase === null) {
            return [
                'points' => 0,
                'apply_checkout_minor' => 0,
                'apply_base_minor' => 0,
                'fx' => null,
                'adjusted' => true,
            ];
        }

        $applyBaseMinor = (int) $toBase['amount_minor'];
        $pointsNeeded = (int) ceil(
            ($applyBaseMinor / (10 ** $basePrecision)) / $ratio,
        );
        $points = min($pointsAvailable, max(0, $pointsNeeded));
        $applyBaseMinor = (int) round($points * $ratio * (10 ** $basePrecision));
        $applyCheckoutFinal = $this->baseCurrency->convertMinor(
            $applyBaseMinor,
            $base,
            $checkout,
            $websiteId,
            $basePrecision,
        );
        if ($applyCheckoutFinal === null) {
            return [
                'points' => 0,
                'apply_checkout_minor' => 0,
                'apply_base_minor' => 0,
                'fx' => null,
                'adjusted' => true,
            ];
        }
        $applyCheckoutMinor = min($applyCheckout, (int) $applyCheckoutFinal['amount_minor']);

        return [
            'points' => $points,
            'apply_checkout_minor' => max(0, $applyCheckoutMinor),
            'apply_base_minor' => $applyBaseMinor,
            'fx' => \is_array($toBase['fx'] ?? null) ? $toBase['fx'] : null,
            'adjusted' => $applyCheckoutMinor !== $requestedCheckoutMinor,
        ];
    }
}
