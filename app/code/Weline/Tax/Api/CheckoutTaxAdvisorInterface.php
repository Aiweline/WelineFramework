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
     * @return array<string,mixed>
     */
    public function quoteTax(
        array $orders,
        array $scope,
        array $address,
        string $currency,
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
}
