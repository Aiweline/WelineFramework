<?php

declare(strict_types=1);

namespace Weline\Marketing\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Marketing\Api\Quote\DiscountQuoteRequest;
use Weline\Marketing\Api\Quote\DiscountQuoteServiceInterface;

class MarketingCheckoutCouponSession
{
    /** @deprecated legacy flat key — migrated into BY_TYPE['toc'] */
    private const SESSION_KEY_LEGACY = 'weline_marketing_checkout_coupon';

    private const SESSION_KEY_BY_TYPE = 'weline_marketing_checkout_coupon_by_type';

    public function __construct(private readonly SessionFactory $sessionFactory)
    {
    }

    /**
     * Resolve cart/selling type. Empty → toc (retail default).
     */
    public function normalizeCartType(?string $cartType): string
    {
        $code = strtolower(trim((string)$cartType));

        return $code !== '' ? $code : 'toc';
    }

    /**
     * Coupons only apply to types that allow storefront discounts.
     * Retail `toc` is the default allow-list; unknown non-toc types fail closed.
     */
    public function couponsAllowedForCartType(?string $cartType): bool
    {
        $code = $this->normalizeCartType($cartType);
        try {
            if (class_exists(\Weline\Cart\Service\CommerceCartTypeRegistry::class)) {
                $registry = ObjectManager::getInstance(\Weline\Cart\Service\CommerceCartTypeRegistry::class);
                if ($registry instanceof \Weline\Cart\Service\CommerceCartTypeRegistry) {
                    $type = $registry->get($code);
                    if ($type !== null) {
                        return !$type->disablesStorefrontDiscounts();
                    }
                }
            }
        } catch (\Throwable) {
            // optional Cart SPI
        }

        return $code === 'toc';
    }

    public function getCouponCode(?string $cartType = null): string
    {
        $type = $this->normalizeCartType($cartType);
        if (!$this->couponsAllowedForCartType($type)) {
            return '';
        }
        $map = $this->readMap();

        return strtoupper(trim((string)($map[$type] ?? '')));
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
        $cartType = $this->cartTypeFromParams($params);
        if (!$this->couponsAllowedForCartType($cartType)) {
            return [
                'success' => false,
                'message' => (string)__('批发不可用'),
                'cart_type' => $cartType,
            ];
        }

        // Prefer client lines; when empty, resolve storefront cart lines so we never
        // persist a ghost coupon that paints a tag with 0 discount on checkout.
        $params['lines'] = $this->resolveCartLines($params);
        $request = $this->buildPreviewRequest($params, $normalized);
        $quote = $quotes->quote($request);
        if ($quote->amountMinor <= 0) {
            return [
                'success' => false,
                'message' => (string)__('优惠券无效、不可用或已达使用上限'),
                'discount' => $quote->toArray(),
                'cart_type' => $cartType,
            ];
        }

        $map = $this->readMap();
        $map[$cartType] = $normalized;
        $this->writeMap($map);

        return [
            'success' => true,
            'coupon_code' => $normalized,
            'cart_type' => $cartType,
            'discount' => $quote->toArray(),
            'message' => (string)__('优惠券已保存，将在结账报价时生效'),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    public function removeCoupon(array $params = []): array
    {
        $cartType = $this->cartTypeFromParams($params);
        $map = $this->readMap();
        unset($map[$cartType]);
        $this->writeMap($map);

        return [
            'success' => true,
            'cart_type' => $cartType,
            'message' => (string)__('已移除优惠券'),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    public function getCoupon(array $params = []): array
    {
        $cartType = $this->cartTypeFromParams($params);

        return [
            'success' => true,
            'cart_type' => $cartType,
            'coupons_allowed' => $this->couponsAllowedForCartType($cartType),
            'coupon_code' => $this->getCouponCode($cartType),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    public function validateCoupon(array $params, DiscountQuoteServiceInterface $quotes): array
    {
        $cartType = $this->cartTypeFromParams($params);
        if (!$this->couponsAllowedForCartType($cartType)) {
            return [
                'success' => false,
                'message' => (string)__('批发不可用'),
                'cart_type' => $cartType,
            ];
        }
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
            'cart_type' => $cartType,
            'discount' => $quote->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    public function quoteDiscount(array $params, DiscountQuoteServiceInterface $quotes): array
    {
        $cartType = $this->cartTypeFromParams($params);
        if (!$this->couponsAllowedForCartType($cartType)) {
            return [
                'success' => true,
                'cart_type' => $cartType,
                'discount' => [
                    'amount_minor' => 0,
                    'currency' => strtoupper(trim((string)($params['currency'] ?? 'CNY'))) ?: 'CNY',
                    'currency_precision' => (int)($params['currency_precision'] ?? 2),
                    'coupon_code' => '',
                    'lines' => [],
                ],
            ];
        }
        $code = strtoupper(trim((string)($params['coupon_code'] ?? $this->getCouponCode($cartType))));
        $request = $this->buildPreviewRequest($params, $code !== '' ? $code : null);
        $quote = $quotes->quote($request);

        return [
            'success' => true,
            'cart_type' => $cartType,
            'discount' => $quote->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function cartTypeFromParams(array $params): string
    {
        foreach (['cart_type', 'selling_mode', 'sellingMode'] as $key) {
            $value = strtolower(trim((string)($params[$key] ?? '')));
            if ($value !== '') {
                return $this->normalizeCartType($value);
            }
        }

        return 'toc';
    }

    /**
     * @return array<string, string>
     */
    private function readMap(): array
    {
        $session = $this->frontendSession();
        $map = [];
        $raw = $this->sessionGet($session, self::SESSION_KEY_BY_TYPE);
        if (is_array($raw)) {
            foreach ($raw as $type => $code) {
                $typeKey = $this->normalizeCartType((string)$type);
                $codeVal = strtoupper(trim((string)$code));
                if ($codeVal !== '') {
                    $map[$typeKey] = $codeVal;
                }
            }
        } elseif (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $type => $code) {
                    $typeKey = $this->normalizeCartType((string)$type);
                    $codeVal = strtoupper(trim((string)$code));
                    if ($codeVal !== '') {
                        $map[$typeKey] = $codeVal;
                    }
                }
            }
        }

        $legacy = strtoupper(trim((string)$this->sessionGet($session, self::SESSION_KEY_LEGACY)));
        if ($legacy !== '' && empty($map['toc'])) {
            $map['toc'] = $legacy;
            $this->writeMap($map);
            try {
                $session->delete(self::SESSION_KEY_LEGACY);
            } catch (\Throwable) {
            }
        }

        return $map;
    }

    /**
     * @param array<string, string> $map
     */
    private function writeMap(array $map): void
    {
        $clean = [];
        foreach ($map as $type => $code) {
            $typeKey = $this->normalizeCartType((string)$type);
            $codeVal = strtoupper(trim((string)$code));
            if ($codeVal === '' || !$this->couponsAllowedForCartType($typeKey)) {
                continue;
            }
            $clean[$typeKey] = $codeVal;
        }
        $this->sessionSet($this->frontendSession(), self::SESSION_KEY_BY_TYPE, $clean);
    }

    private function sessionGet(AuthenticatedSessionInterface $session, string $key): mixed
    {
        if (method_exists($session, 'getData')) {
            /** @phpstan-ignore-next-line */
            return $session->getData($key);
        }

        return $session->get($key);
    }

    private function sessionSet(AuthenticatedSessionInterface $session, string $key, mixed $value): void
    {
        if (method_exists($session, 'setData')) {
            /** @phpstan-ignore-next-line */
            $session->setData($key, $value);

            return;
        }
        $session->set($key, $value);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildPreviewRequest(array $params, ?string $couponCode): DiscountQuoteRequest
    {
        $lines = $this->resolveCartLines($params);
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

    /**
     * Prefer client quote lines; when empty, load the trusted storefront cart so
     * marketing apply/quote matches cart discount_preview (same items).
     *
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function resolveCartLines(array $params): array
    {
        $raw = is_array($params['lines'] ?? null) ? $params['lines'] : [];
        $normalized = $this->normalizeQuoteLines($raw);
        if ($normalized !== []) {
            return $normalized;
        }

        try {
            if (!class_exists(\Weline\Cart\Service\CartService::class)
                || !class_exists(\Weline\Cart\Service\CartScopeResolver::class)
                || !class_exists(\Weline\Cart\Service\CartCurrentCustomerResolver::class)
            ) {
                return [];
            }
            $cartService = ObjectManager::getInstance(\Weline\Cart\Service\CartService::class);
            $scopeResolver = ObjectManager::getInstance(\Weline\Cart\Service\CartScopeResolver::class);
            $customerResolver = ObjectManager::getInstance(\Weline\Cart\Service\CartCurrentCustomerResolver::class);
            if (!$cartService instanceof \Weline\Cart\Service\CartService
                || !$scopeResolver instanceof \Weline\Cart\Service\CartScopeResolver
                || !$customerResolver instanceof \Weline\Cart\Service\CartCurrentCustomerResolver
            ) {
                return [];
            }

            $guestToken = trim((string)($params['guest_token'] ?? ''));
            if ($guestToken === '') {
                $guestToken = trim((string)\Weline\Framework\Http\Cookie::get(
                    \Weline\Cart\Service\CartService::GUEST_TOKEN_COOKIE
                ));
            }
            $cartType = $this->cartTypeFromParams($params);
            $summary = $cartService->getCart(
                $scopeResolver->fromParams($params),
                $guestToken !== '' ? $guestToken : null,
                $customerResolver->currentCustomerId(),
                $cartType,
            );

            return $this->normalizeQuoteLines(is_array($summary['items'] ?? null) ? $summary['items'] : []);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<int|string, mixed> $raw
     * @return list<array<string, mixed>>
     */
    private function normalizeQuoteLines(array $raw): array
    {
        $lines = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qty = (int)($item['qty_minor'] ?? $item['qty'] ?? $item['quantity'] ?? 1);
            $unitMinor = (int)($item['unit_price_minor'] ?? 0);
            if ($unitMinor <= 0) {
                $unitMinor = (int)round(((float)($item['price'] ?? $item['unit_price'] ?? 0)) * 100);
            }
            if ($qty <= 0 && $unitMinor <= 0) {
                continue;
            }
            $lines[] = [
                'qty_minor' => max(1, $qty),
                'unit_price_minor' => max(0, $unitMinor),
                'sku' => trim((string)($item['sku'] ?? '')),
                'product_id' => (int)($item['product_id'] ?? 0),
            ];
        }

        return $lines;
    }

    private function currentCustomerId(): ?int
    {
        // Prefer Cart's storefront resolver so quoteDiscount and cart discount_preview
        // share the same customer_id (session + optional Customer facade).
        try {
            if (class_exists(\Weline\Cart\Service\CartCurrentCustomerResolver::class)) {
                $resolver = ObjectManager::getInstance(\Weline\Cart\Service\CartCurrentCustomerResolver::class);
                if ($resolver instanceof \Weline\Cart\Service\CartCurrentCustomerResolver) {
                    return $resolver->currentCustomerId();
                }
            }
        } catch (\Throwable) {
            // Fall through to session-only resolution.
        }

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
