<?php

declare(strict_types=1);

namespace Weline\Tax\Api;

/**
 * Optional Tax extension boundary consumed by Checkout.
 *
 * Checkout keeps a none snapshot when no provider is injected. When Tax is
 * active, quote and submit must rebuild the same request facts so a rule
 * version is never inferred from worker-local state.
 */
interface CheckoutTaxAdvisorInterface
{
    /**
     * @param list<array<string,mixed>> $orders
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $address
     * @param array{
     *   duty_notice?:string,
     *   incoterm?:string,
     *   shipping_amount_minor?:int,
     *   origin_country?:string,
     *   goods_subtotal_minor?:int
     * } $shippingContext Shipping Incoterm notice + amounts; Tax owns duty money.
     * @return array<string,mixed>
     */
    public function quoteTax(
        array $orders,
        array $scope,
        array $address,
        string $currency,
        array $shippingContext = [],
    ): array;

    /**
     * @param array<string,mixed> $sessionTax
     * @param list<array<string,mixed>> $orders
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $address
     */
    public function assertRuleVersion(
        array $sessionTax,
        array $orders,
        array $scope,
        array $address,
        string $currency,
        ?string $expectedRuleSetHash,
    ): void;

    /**
     * Validate buyer tax identity for billing country (format / required).
     * Does not change tax amounts.
     *
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $billingAddress
     */
    public function validateTaxIdentity(array $identity, array $billingAddress): void;
}
