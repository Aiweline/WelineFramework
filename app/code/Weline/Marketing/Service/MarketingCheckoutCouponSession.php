<?php

declare(strict_types=1);

namespace Weline\Marketing\Service;

use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Marketing\Api\Quote\DiscountQuoteRequest;
use Weline\Marketing\Api\Quote\DiscountQuoteServiceInterface;

class MarketingCheckoutCouponSession
{
    private const SESSION_KEY = 'weline_marketing_checkout_coupon';

    public function __construct(private readonly SessionFactory $sessionFactory)
    {
    }

    public function getCouponCode(): string
    {
        $session = $this->frontendSession();
        return strtoupper(trim((string)$session->getData(self::SESSION_KEY)));
    }

    /**
     * Persist coupon only after a successful quote for the current customer/cart facts.
     *
     * @param array<string, mixed> $params
     */
    public function applyCoupon(string $code, DiscountQuoteServiceInterface $quotes, array $params = []): array
    {
        $normalized = strtoupper(trim($code));
        if ($normalized === '') {
            return ['success' => false, 'message' => (string)__('请输入优惠券代码')];
        }

        $params = is_array($params) ? $params : [];
        $hasLines = is_array($params['lines'] ?? null) && $params['lines'] !== [];
        $request = $this->buildPreviewRequest($params, $normalized);
        $quote = $quotes->quote($request);
        // When the client only sends the code (no cart lines), persist first and let
        // the follow-up quoteDiscount / cart discount_preview render the amount.
        if ($hasLines && $quote->amountMinor <= 0) {
            return [
                'success' => false,
                'message' => (string)__('优惠券无效、不可用或已达使用上限'),
                'discount' => $quote->toArray(),
            ];
        }

        $this->frontendSession()->setData(self::SESSION_KEY, $normalized);

        return [
            'success' => true,
            'coupon_code' => $normalized,
            'discount' => $quote->amountMinor > 0 ? $quote->toArray() : null,
            'message' => (string)__('优惠券已保存，将在结账报价时生效'),
        ];
    }

    public function removeCoupon(): array
    {
        $this->frontendSession()->delete(self::SESSION_KEY);

        return ['success' => true, 'message' => (string)__('已移除优惠券')];
    }

    public function getCoupon(): array
    {
        return [
            'success' => true,
            'coupon_code' => $this->getCouponCode(),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    public function validateCoupon(array $params, DiscountQuoteServiceInterface $quotes): array
    {
        $code = strtoupper(trim((string)($params['coupon_code'] ?? $params['code'] ?? '')));
        if ($code === '') {
            return ['success' => false, 'message' => (string)__('请输入优惠券代码')];
        }
        $request = $this->buildPreviewRequest($params, $code);
        $quote = $quotes->quote($request);
        if ($quote->amountMinor <= 0) {
            return [
                'success' => false,
                'message' => (string)__('优惠券无效、不可用或已达使用上限'),
                'discount' => $quote->toArray(),
            ];
        }

        return [
            'success' => true,
            'coupon_code' => $code,
            'discount' => $quote->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    public function quoteDiscount(array $params, DiscountQuoteServiceInterface $quotes): array
    {
        $code = strtoupper(trim((string)($params['coupon_code'] ?? $this->getCouponCode())));
        $request = $this->buildPreviewRequest($params, $code !== '' ? $code : null);
        $quote = $quotes->quote($request);

        return [
            'success' => true,
            'discount' => $quote->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildPreviewRequest(array $params, ?string $couponCode): DiscountQuoteRequest
    {
        $lines = is_array($params['lines'] ?? null) ? $params['lines'] : [];
        $scope = is_array($params['scope'] ?? null) ? $params['scope'] : [];
        $address = is_array($params['address'] ?? null) ? $params['address'] : [];

        // Server-owned customer identity — never trust client customer_id.
        $customerId = $this->currentCustomerId();

        return new DiscountQuoteRequest(
            scope: $scope,
            address: $address,
            lines: $lines,
            orders: is_array($params['orders'] ?? null) ? $params['orders'] : [],
            currency: strtoupper(trim((string)($params['currency'] ?? 'CNY'))) ?: 'CNY',
            currencyPrecision: (int)($params['currency_precision'] ?? 2),
            customerId: $customerId,
            shippingAmountMinor: (int)($params['shipping_amount_minor'] ?? 0),
            couponCode: $couponCode,
            paymentMethod: trim((string)($params['payment_method'] ?? '')) ?: null,
            cartHash: (string)($params['cart_hash'] ?? ''),
        );
    }

    private function currentCustomerId(): ?int
    {
        try {
            $session = $this->frontendSession();
            if (!$session->isLoggedIn()) {
                return null;
            }
            $customerId = (int)$session->getUserId();

            return $customerId > 0 ? $customerId : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function frontendSession(): AuthenticatedSessionInterface
    {
        return $this->sessionFactory->createFrontendSession();
    }
}
