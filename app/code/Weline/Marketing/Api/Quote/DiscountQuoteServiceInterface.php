<?php

declare(strict_types=1);

namespace Weline\Marketing\Api\Quote;

interface DiscountQuoteServiceInterface
{
    public function activeConfigVersion(): string;

    public function quote(DiscountQuoteRequest $request): DiscountQuote;

    public function validateToken(DiscountQuoteRequest $request, DiscountQuote $quote): bool;

    /**
     * Redeem coupon usage after checkout submit (MVP: no rollback on cancel).
     *
     * @param array<string, mixed> $context
     */
    public function redeemCoupon(string $couponCode, array $context, DiscountQuote $quote): void;
}
