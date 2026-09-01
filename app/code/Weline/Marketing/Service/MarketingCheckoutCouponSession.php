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

    public function applyCoupon(string $code): array
    {
        $normalized = strtoupper(trim($code));
        if ($normalized === '') {
            return ['success' => false, 'message' => (string)__('请输入优惠券代码')];
        }
        $this->frontendSession()->setData(self::SESSION_KEY, $normalized);

        return [
            'success' => true,
            'coupon_code' => $normalized,
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
        if ($quote->amountMinor <= 0 && $quote->couponCode === '') {
            return ['success' => false, 'message' => (string)__('优惠券无效或不可用')];
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

        return new DiscountQuoteRequest(
            scope: $scope,
            address: $address,
            lines: $lines,
            orders: is_array($params['orders'] ?? null) ? $params['orders'] : [],
            currency: strtoupper(trim((string)($params['currency'] ?? 'CNY'))) ?: 'CNY',
            currencyPrecision: (int)($params['currency_precision'] ?? 2),
            customerId: isset($params['customer_id']) ? (int)$params['customer_id'] : null,
            shippingAmountMinor: (int)($params['shipping_amount_minor'] ?? 0),
            couponCode: $couponCode,
            paymentMethod: trim((string)($params['payment_method'] ?? '')) ?: null,
            cartHash: (string)($params['cart_hash'] ?? ''),
        );
    }

    private function frontendSession(): AuthenticatedSessionInterface
    {
        return $this->sessionFactory->createFrontendSession();
    }
}
